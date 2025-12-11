<?php

declare(strict_types=1);

namespace AlturaCode\Billing\Stripe;

use AlturaCode\Billing\Core\Subscriptions\Subscription;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionItemId;
use DateTimeImmutable;

final readonly class SubscriptionActivator
{
    public static function activate(\Stripe\Subscription $stripeSub, Subscription $subscription): Subscription
    {
        // Loop through the stripe subscription items to sync their period (start/end)
        // with our internal subscription items. This synchronization ensures the periods
        // match between Stripe and our system before activating the subscription.
        foreach ($stripeSub->items->data as $stripeItem) {
            $internalPriceId = $stripeItem->metadata->internal_price_id;
            $currentPeriodStart = $stripeItem->current_period_start;
            $currentPeriodEnd = $stripeItem->current_period_end;
            $subscription = $subscription->setItemPeriod(
                itemId: SubscriptionItemId::fromString($internalPriceId),
                currentPeriodStartsAt: new DateTimeImmutable("@$currentPeriodStart"),
                currentPeriodEndsAt: new DateTimeImmutable("@$currentPeriodEnd"),
            );
        }
        return $subscription->activate();
    }
}