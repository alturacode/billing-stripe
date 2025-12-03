<?php

declare(strict_types=1);

namespace AlturaCode\Billing\Stripe;

use Stripe\Event;

final readonly class WebhookHandler
{
    public function handle(Event $payload): void
    {
    }
}