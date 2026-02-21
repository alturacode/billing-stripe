<?php

declare(strict_types=1);

namespace AlturaCode\Billing\Stripe;

use AlturaCode\Billing\Core\Provider\ExternalIdMapper;
use AlturaCode\Billing\Core\Subscriptions\Subscription;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionId;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stripe\Event;

final readonly class WebhookHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private SubscriptionRepository $subscriptionRepository,
        private ExternalIdMapper       $idMapper,
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
            $existingMapping = $this->idMapper->getInternalId('subscription', 'stripe', $stripeSub->id);
        } catch (\Throwable) {
            $existingMapping = null;
        }
        if ($existingMapping === null) {
            $this->idMapper->store('subscription', 'stripe', $internalId, $stripeSub->id);

            // Also store item-level mappings
            foreach ($stripeSub->items->data as $stripeItem) {
                $internalItemId = $stripeItem->metadata->internal_item_id ?? null;
                if ($internalItemId !== null) {
                    $this->idMapper->store('subscription_item', 'stripe', $internalItemId, $stripeItem->id);
                }
            }
        }

        $subscription = $this->subscriptionRepository->find(SubscriptionId::fromString($internalId));
        if ($subscription === null) {
            $this->logger->warning('WebhookHandler: internal subscription not found for created event.', [
                'internal_subscription_id' => $internalId,
                'stripe_subscription_id' => $stripeSub->id,
            ]);
            return;
        }

        // Activate if the subscription is in an activatable state
        $status = (string) ($stripeSub->status ?? '');
        if (in_array($status, ['active', 'trialing'], true)) {
            $subscription = SubscriptionActivator::activate($stripeSub, $subscription);
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
            $subscription = SubscriptionActivator::activate($stripeSub, $subscription);
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
            $subscription = SubscriptionActivator::activate($stripeSub, $subscription);
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
        $internalId = $this->idMapper->getInternalId('subscription', 'stripe', $stripeSubscriptionId);

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
}
