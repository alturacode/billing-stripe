<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use AlturaCode\Billing\Core\Common\BillableIdentity;
use AlturaCode\Billing\Core\Subscriptions\Subscription;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionId;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionName;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionProvider;

final readonly class SubscriptionMother
{
    public static function create(): Subscription
    {
        return Subscription::create(
            id: SubscriptionId::generate(),
            name: SubscriptionName::fromString('default'),
            billable: BillableIdentity::fromString('user', 1),
            provider: SubscriptionProvider::fromString('stripe'),
        );
    }
}