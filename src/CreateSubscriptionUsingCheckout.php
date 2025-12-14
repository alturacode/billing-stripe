<?php

declare(strict_types=1);

namespace AlturaCode\Billing\Stripe;

use AlturaCode\Billing\Core\Provider\BillingProviderResult;
use AlturaCode\Billing\Core\Subscriptions\Subscription;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionItem;
use InvalidArgumentException;
use LogicException;
use Stripe\StripeClient;

final readonly class CreateSubscriptionUsingCheckout implements CreateSubscription
{
    public function __construct(
        private StripeClient  $stripeClient,
        private StripeIdStore $idStore,
    )
    {
    }

    public function create(Subscription $subscription, array $options = []): BillingProviderResult
    {
        $customerId = $this->idStore->requireCustomerId($subscription->billable());

        $successUrl = $options['success_url'] ?? null;
        $cancelUrl = $options['cancel_url'] ?? null;

        if (!$successUrl || !$cancelUrl) {
            throw new InvalidArgumentException(
                'Missing required Checkout URLs: provide both success_url and cancel_url in options.'
            );
        }

        $lineItems = $this->buildCheckoutLineItems($subscription);

        $params = [
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => $lineItems,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $subscription->id()->value(),
            'metadata' => [
                'internal_subscription_id' => $subscription->id()->value(),
            ],
        ];

        // Optional flags passed through from $options when provided
        foreach (['allow_promotion_codes', 'locale'] as $optKey) {
            if (array_key_exists($optKey, $options)) {
                $params[$optKey] = $options[$optKey];
            }
        }

        // Allow passing subscription-level options supported by Checkout via subscription_data
        $subscriptionData = [];
        foreach ([
                     'proration_behavior',
                     'default_payment_method',
                     'coupon',
                 ] as $optKey) {
            if (array_key_exists($optKey, $options)) {
                $subscriptionData[$optKey] = $options[$optKey];
            }
        }

        if ($subscription->cancelAtPeriodEnd()) {
            $subscriptionData['cancel_at_period_end'] = true;
        }

        if ($subscription->trialEndsAt() !== null) {
            $subscriptionData['trial_end'] = $subscription->trialEndsAt()->getTimestamp();
        }

        if (!empty($subscriptionData)) {
            $params['subscription_data'] = $subscriptionData;
        }

        $session = $this->stripeClient->checkout->sessions->create($params);

        if (!empty($session->url)) {
            return BillingProviderResult::redirect($subscription, $session->url);
        }

        throw new LogicException('Failed to create Stripe Checkout session or missing session URL.');
    }

    private function buildCheckoutLineItems(Subscription $subscription): array
    {
        $priceMap = $this->idStore->getPriceIds(array_map(
            static fn($item) => $item->priceId()->value(), $subscription->items()
        ));

        $lineItems = [];
        foreach ($subscription->items() as $item) {
            $internalPriceId = (string)$item->priceId();
            $stripePriceId = $priceMap[$internalPriceId] ?? $this->createStripePriceAndGetId($item);

            $lineItems[] = [
                'price' => $stripePriceId,
                'quantity' => $item->quantity(),
            ];
        }

        return $lineItems;
    }

    private function createStripePriceAndGetId(SubscriptionItem $item): string
    {

    }
}