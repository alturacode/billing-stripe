<?php

declare(strict_types=1);

namespace AlturaCode\Billing\Stripe;

use AlturaCode\Billing\Core\Provider\ExternalIdMapper;
use AlturaCode\Billing\Core\Subscriptions\Subscription;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionId;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionItemId;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stripe\Event;

final readonly class WebhookHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private SubscriptionRepository $subscriptionRepository,
        private StripeIdStore          $idStore,
        ?LoggerInterface               $logger = null,
    )
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function handle(Event $payload): void
    {
        $type = $payload->type;
        $object = $payload->data['object'] ?? $payload->data->object ?? null;

        if (!($object instanceof \Stripe\Subscription)) {
            $this->logger->debug('WebhookHandler: ignoring non-subscription event.', ['type' => $type]);
            return;
        }

        $stripeSub = $object;

        $this->logger->debug('WebhookHandler: processing event.', [
            'type' => $type,
            'stripe_subscription_id' => $stripeSub->id,
            'status' => $stripeSub->status ?? 'unknown',
        ]);

        switch ($type) {
            case 'customer.subscription.created':
                $this->handleSubscriptionCreated($stripeSub);
                break;

            case 'customer.subscription.updated':
                $this->handleSubscriptionUpdated($stripeSub);
                break;

            case 'customer.subscription.deleted':
                $this->handleSubscriptionDeleted($stripeSub);
                break;

            case 'customer.subscription.paused':
                $this->handleSubscriptionPaused($stripeSub);
                break;

            case 'customer.subscription.resumed':
                $this->handleSubscriptionResumed($stripeSub);
                break;

            default:
                $this->logger->debug('WebhookHandler: unhandled subscription event type.', ['type' => $type]);
                break;
        }
    }

    private function handleSubscriptionCreated(\Stripe\Subscription $stripeSub): void
    {
        $internalId = $this->resolveInternalSubscriptionId($stripeSub);

        if ($internalId === null) {
            $this->logger->warning('WebhookHandler: no internal subscription ID found for created subscription.', [
                'stripe_subscription_id' => $stripeSub->id,
            ]);
            return;
        }

        // Store the mapping if it doesn't already exist
        try {
            $existingMapping = $this->idStore->getInternalSubscriptionId($stripeSub->id);
        } catch (\Throwable) {
            $existingMapping = null;
        }

        if ($existingMapping === null) {
            $this->idStore->storeSubscriptionId($internalId, $stripeSub->id);
        }

        $subscription = $this->subscriptionRepository->find(SubscriptionId::fromString($internalId));
        if ($subscription === null) {
            $this->logger->warning('WebhookHandler: internal subscription not found for created event.', [
                'internal_subscription_id' => $internalId,
                'stripe_subscription_id' => $stripeSub->id,
            ]);
            return;
        }

        // Also store item-level mappings
        if ($existingMapping === null) {
            $this->storeSubscriptionItemMappings($stripeSub, $subscription);
        }

        // Activate if the subscription is in an activatable state
        $status = (string) ($stripeSub->status ?? '');
        if (in_array($status, ['active', 'trialing'], true)) {
            $subscription = $this->activateSubscription($stripeSub, $subscription);
        }

        $this->subscriptionRepository->save($subscription);
    }

    private function handleSubscriptionUpdated(\Stripe\Subscription $stripeSub): void
    {
        $subscription = $this->findSubscriptionByStripeId($stripeSub->id);
        if ($subscription === null) {
            return;
        }

        $status = (string) ($stripeSub->status ?? '');

        // Canceled status via update event
        if ($status === 'canceled') {
            $updated = $subscription->cancel(false);
            $this->subscriptionRepository->save($updated);
            return;
        }

        // Activate or sync periods for active/trialing
        if (in_array($status, ['active', 'trialing'], true)) {
            $subscription = $this->activateSubscription($stripeSub, $subscription);
        }

        // Pending cancellation at period end
        if ($stripeSub->cancel_at_period_end ?? false) {
            $subscription = $subscription->cancel(true);
        }

        // Paused
        if (isset($stripeSub->pause_collection) && $stripeSub->pause_collection !== null) {
            $subscription = $subscription->pause();
        }

        $this->subscriptionRepository->save($subscription);
    }

    private function handleSubscriptionDeleted(\Stripe\Subscription $stripeSub): void
    {
        $subscription = $this->findSubscriptionByStripeId($stripeSub->id);
        if ($subscription === null) {
            return;
        }

        $updated = $subscription->cancel(false);
        $this->subscriptionRepository->save($updated);
    }

    private function handleSubscriptionPaused(\Stripe\Subscription $stripeSub): void
    {
        $subscription = $this->findSubscriptionByStripeId($stripeSub->id);
        if ($subscription === null) {
            return;
        }

        $updated = $subscription->pause();
        $this->subscriptionRepository->save($updated);
    }

    private function handleSubscriptionResumed(\Stripe\Subscription $stripeSub): void
    {
        $subscription = $this->findSubscriptionByStripeId($stripeSub->id);
        if ($subscription === null) {
            return;
        }

        $status = (string) ($stripeSub->status ?? '');
        if (in_array($status, ['active', 'trialing'], true)) {
            $subscription = $this->activateSubscription($stripeSub, $subscription);
        }

        $this->subscriptionRepository->save($subscription);
    }

    /**
     * Resolve the internal subscription ID from the Stripe subscription metadata.
     * This is set during checkout session creation.
     */
    private function resolveInternalSubscriptionId(\Stripe\Subscription $stripeSub): ?string
    {
        $internalId = $stripeSub->metadata->internal_subscription_id ?? null;

        if ($internalId !== null && is_string($internalId) && $internalId !== '') {
            return $internalId;
        }

        return null;
    }

    private function findSubscriptionByStripeId(string $stripeSubscriptionId): ?Subscription
    {
        $internalId = $this->idStore->getInternalSubscriptionId($stripeSubscriptionId);

        if (!$internalId || !is_string($internalId)) {
            $this->logger->debug('WebhookHandler: no internal mapping for Stripe subscription.', [
                'stripe_subscription_id' => $stripeSubscriptionId,
            ]);
            return null;
        }

        $subscription = $this->subscriptionRepository->find(SubscriptionId::fromString($internalId));

        if ($subscription === null) {
            $this->logger->warning('WebhookHandler: subscription not found in repository.', [
                'internal_subscription_id' => $internalId,
                'stripe_subscription_id' => $stripeSubscriptionId,
            ]);
        }

        return $subscription;
    }

    private function activateSubscription(\Stripe\Subscription $stripeSub, Subscription $subscription): Subscription
    {
        // Loop through the stripe subscription items to sync their period (start/end)
        // with our internal subscription items. This synchronization ensures the periods
        // match between Stripe and our system before activating the subscription.
        foreach ($this->resolveSubscriptionItems($stripeSub, $subscription) as $resolved) {
            $stripeItem = $resolved['stripe_item'];
            $internalItemId = $resolved['internal_item_id'];

            $subscription = $subscription->setItemPeriod(
                itemId: SubscriptionItemId::fromString($internalItemId),
                currentPeriodStartsAt: new DateTimeImmutable("@{$stripeItem->current_period_start}"),
                currentPeriodEndsAt: new DateTimeImmutable("@{$stripeItem->current_period_end}"),
            );
        }

        return $subscription->activate();
    }

    private function storeSubscriptionItemMappings(\Stripe\Subscription $stripeSub, Subscription $subscription): void
    {
        $newMappings = [];

        foreach ($this->resolveSubscriptionItems($stripeSub, $subscription) as $resolved) {
            $newMappings[$resolved['internal_item_id']] = $resolved['stripe_item']->id;
        }

        if (!empty($newMappings)) {
            $this->idStore->storeMultipleSubscriptionItemIdMappings($newMappings);
        }
    }

    /**
     * @return array<array{stripe_item: \Stripe\SubscriptionItem, internal_item_id: string}>
     */
    private function resolveSubscriptionItems(\Stripe\Subscription $stripeSub, Subscription $subscription): array
    {
        $stripeItems = $stripeSub->items->data;

        $stripeItemIds = array_values(array_filter(array_map(fn($item) => $item->id ?? null, $stripeItems)));
        $stripePriceIds = array_values(array_filter(array_unique(array_map(fn($item) => $item->price->id ?? null, $stripeItems))));

        $itemIdsMapping = $this->idStore->getInternalSubscriptionItemIdMap($stripeItemIds);
        $priceIdsMapping = $this->idStore->getInternalPriceIdMap($stripePriceIds);

        $resolved = [];

        foreach ($stripeItems as $stripeItem) {
            $internalItemId = $this->resolveInternalItemId($stripeItem, $subscription, $itemIdsMapping, $priceIdsMapping);

            if ($internalItemId === null) {
                $this->logger->warning('WebhookHandler: could not resolve internal item ID for Stripe item.', [
                    'stripe_item_id' => $stripeItem->id,
                    'stripe_subscription_id' => $stripeSub->id,
                ]);

                continue;
            }

            $resolved[] = [
                'stripe_item' => $stripeItem,
                'internal_item_id' => $internalItemId,
            ];
        }

        return $resolved;
    }

    private function resolveInternalItemId(
        $stripeItem,
        Subscription $subscription,
        array $itemIdsMapping,
        array $priceIdsMapping
    ): ?string {
        // 1. Try metadata (fallback for older subscriptions/other creation methods)
        $internalItemId = $stripeItem->metadata->internal_item_id ?? null;
        if ($internalItemId) {
            return (string)$internalItemId;
        }

        // 2. Try stored mapping
        $internalItemId = $itemIdsMapping[$stripeItem->id] ?? null;
        if ($internalItemId) {
            return (string)$internalItemId;
        }

        // 3. Try matching by price
        $stripePriceId = $stripeItem->price->id ?? null;
        $internalPriceId = $stripePriceId ? ($priceIdsMapping[$stripePriceId] ?? null) : null;
        if ($internalPriceId) {
            foreach ($subscription->items() as $item) {
                if ((string)$item->priceId() === $internalPriceId && $item->quantity() === $stripeItem->quantity) {
                    return (string)$item->id();
                }
            }

            // If quantity doesn't match, still try matching by price as fallback
            foreach ($subscription->items() as $item) {
                if ((string)$item->priceId() === $internalPriceId) {
                    return (string)$item->id();
                }
            }
        }

        return null;
    }
}
