<?php

declare(strict_types=1);

namespace AlturaCode\Billing\Stripe;

use RuntimeException;

final class MissingStripeIdMapping extends RuntimeException
{
    public function __construct(string $internalId)
    {
        parent::__construct("Stripe ID mapping not found for internal ID: $internalId");
    }
}