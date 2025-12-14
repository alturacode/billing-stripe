<?php

namespace AlturaCode\Billing\Stripe;

use AlturaCode\Billing\Core\Provider\BillingProviderResult;
use AlturaCode\Billing\Core\Subscriptions\Subscription;

interface CreateSubscription
{
    public function create(Subscription $subscription, array $options = []): BillingProviderResult;
}