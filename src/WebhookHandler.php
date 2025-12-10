<?php

declare(strict_types=1);

namespace AlturaCode\Billing\Stripe;

use AlturaCode\Billing\Core\Provider\ExternalIdMapper;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionId;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionRepository;
use Stripe\Event;

final readonly class WebhookHandler
{
    public function __construct(
        private SubscriptionRepository $subscriptionRepository,
        private ExternalIdMapper       $idMapper
    )
    {
    }

    public function handle(Event $payload): void
    {
        $type = $payload->type;

        // We only handle subscription lifecycle events
        $object = $payload->data['object'] ?? $payload->data->object ?? null;
        if (!($object instanceof \Stripe\Subscription)) {
            return;
        }

        $stripeSub = $object;

        // Map Stripe subscription id -> internal subscription id
        $internalId = $this->idMapper->getInternalId('subscription', $stripeSub->id, 'stripe');
        if (!$internalId || !is_string($internalId)) {
            // Unknown subscription mapping; nothing to sync
            return;
        }

        $subscription = $this->subscriptionRepository->find(SubscriptionId::fromString($internalId));
        if ($subscription === null) {
            return;
        }

        $status = (string)($stripeSub->status ?? '');
        $isCanceled = $type === 'customer.subscription.deleted' || $status === 'canceled';
        $cancelAtPeriodEnd = (bool)($stripeSub->cancel_at_period_end ?? false);
        $isPaused = isset($stripeSub->pause_collection) && $stripeSub->pause_collection !== null;

        // Apply state changes based on event/status
        if ($isCanceled) {
            // Immediate cancellation
            $updated = $subscription->cancel(false);
            $this->subscriptionRepository->save($updated);
            return;
        }

        if ($cancelAtPeriodEnd) {
            $updated = $subscription->cancel(true);
            $this->subscriptionRepository->save($updated);
            return;
        }

        if ($isPaused) {
            $updated = $subscription->pause();
            $this->subscriptionRepository->save($updated);
            return;
        }

        // Activated or resumed (active/trialing without pause and not canceling at the period end)
        if (in_array($status, ['active', 'trialing'], true)) {
            $updated = SubscriptionActivator::activate($stripeSub, $subscription);
            $this->subscriptionRepository->save($updated);
        }
    }
}