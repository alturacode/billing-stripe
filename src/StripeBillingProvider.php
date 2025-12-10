<?php

declare(strict_types=1);

namespace AlturaCode\Billing\Stripe;

use AlturaCode\Billing\Core\Provider\BillingProvider;
use AlturaCode\Billing\Core\Provider\BillingProviderResult;
use AlturaCode\Billing\Core\Provider\ExternalIdMapper;
use AlturaCode\Billing\Core\Subscriptions\Subscription;
use InvalidArgumentException;
use LogicException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

final readonly class StripeBillingProvider implements BillingProvider
{
    public function __construct(
        private StripeClient     $stripeClient,
        private ExternalIdMapper $idMapper
    )
    {
    }

    /**
     * @throws ApiErrorException
     */
    public function create(Subscription $subscription, array $options = []): BillingProviderResult
    {
        $customerId = $this->requireStripeCustomerId($subscription);

        $successUrl = $options['success_url'] ?? null;
        $cancelUrl = $options['cancel_url'] ?? null;

        if (!$successUrl || !$cancelUrl) {
            throw new InvalidArgumentException('Missing required Checkout URLs: provide both success_url and cancel_url in options.');
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

    /**
     * @throws ApiErrorException
     */
    public function cancel(Subscription $subscription, bool $atPeriodEnd, array $options): BillingProviderResult
    {
        $stripeSubscriptionId = $this->requireStripeSubscriptionId($subscription);

        if ($atPeriodEnd) {
            $this->stripeClient->subscriptions->update($stripeSubscriptionId, ['cancel_at_period_end' => true]);
            return BillingProviderResult::completed($subscription->cancel());
        }

        $this->stripeClient->subscriptions->cancel($stripeSubscriptionId, []);
        return BillingProviderResult::completed($subscription->cancel(false));
    }

    /**
     * @throws ApiErrorException
     */
    public function pause(Subscription $subscription, array $options): BillingProviderResult
    {
        $stripeSubscriptionId = $this->requireStripeSubscriptionId($subscription);

        /** @var string $behavior */
        $behavior = $options['pause_behavior'] ?? 'mark_uncollectible';

        /** @noinspection PhpParamsInspection */
        $this->stripeClient->subscriptions->update($stripeSubscriptionId, [
            'pause_collection' => [
                'behavior' => $behavior,
            ],
        ]);
        return BillingProviderResult::completed($subscription->pause());
    }

    /**
     * @throws ApiErrorException
     */
    public function resume(Subscription $subscription, array $options): BillingProviderResult
    {
        $stripeSubscriptionId = $this->requireStripeSubscriptionId($subscription);

        if (!empty($options['clear_cancel_at_period_end'])) {
            $update['cancel_at_period_end'] = false;
        }

        // Clear pause_collection by setting it to null to resume charges
        $update['pause_collection'] = null;

        $this->stripeClient->subscriptions->update($stripeSubscriptionId, $update);
        return BillingProviderResult::completed($subscription->resume());
    }

    private function buildCheckoutLineItems(Subscription $subscription): array
    {
        $priceMap = $this->idMapper->getExternalId(
            'price',
            array_map(static fn($item) => $item->priceId()->value(), $subscription->items()),
            'stripe'
        );

        $lineItems = [];
        foreach ($subscription->items() as $item) {
            $internalPriceId = (string)$item->priceId();
            $stripePriceId = $priceMap[$internalPriceId] ?? null;
            if (!$stripePriceId) {
                throw new InvalidArgumentException(
                    sprintf('Missing Stripe price for internal price id %s. Provide a mapping via ExternalIdMapper.', $internalPriceId)
                );
            }

            $lineItems[] = [
                'price' => $stripePriceId,
                'quantity' => $item->quantity(),
            ];
        }

        return $lineItems;
    }

    private function requireStripeCustomerId(Subscription $subscription): string
    {
        $stripeCustomerId = $this->idMapper->getExternalId('customer', $subscription->customerId()->value(), 'stripe');
        if (!$stripeCustomerId) {
            throw new InvalidArgumentException('Missing Stripe customer id mapping for customer.');
        }

        return $stripeCustomerId;
    }

    private function requireStripeSubscriptionId(Subscription $subscription): string
    {
        $stripeSubscriptionId = $this->idMapper->getExternalId('subscription', $subscription->id()->value(), 'stripe');
        if (!$stripeSubscriptionId) {
            throw new InvalidArgumentException('Missing Stripe subscription id mapping for subscription.');
        }

        return $stripeSubscriptionId;
    }
}