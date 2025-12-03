<?php

declare(strict_types=1);

namespace AlturaCode\Billing\Stripe;

use AlturaCode\Billing\Core\Provider\BillingProvider;
use AlturaCode\Billing\Core\Provider\BillingProviderResult;
use AlturaCode\Billing\Core\Provider\ExternalIdMapper;
use AlturaCode\Billing\Core\SubscriptionItemId;
use AlturaCode\Billing\Core\Subscriptions\Subscription;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Component\Uid\Ulid;

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
        $params = $this->buildCreateParams($subscription, $options);

        $stripeSub = $this->stripeClient->subscriptions->create($params);
        $redirectUrl = $this->extractRedirectUrl($stripeSub);

        if ($redirectUrl) {
            return BillingProviderResult::redirect($subscription, $redirectUrl);
        }

        if ($stripeSub->status === 'active') {
            return BillingProviderResult::completed(SubscriptionActivator::activate($stripeSub, $subscription));
        }

        throw new LogicException('Stripe subscription status is not active and no redirect URL was found.');
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

    private function buildCreateParams(Subscription $subscription, array $options): array
    {
        $customerId = $this->requireStripeCustomerId($subscription);
        $items = $this->buildItemsParam($subscription);

        $params = [
            'customer' => $customerId,
            'items' => $items,
        ];

        $this->applyOptionalCreateParams($params, $options, $subscription);

        return $params;
    }

    private function applyOptionalCreateParams(array &$params, array $options, Subscription $subscription): void
    {
        $keys = [
            'payment_behavior',
            'proration_behavior',
            'collection_method',
            'days_until_due',
            'default_payment_method',
            'coupon',
        ];

        foreach ($keys as $optKey) {
            if (array_key_exists($optKey, $options)) {
                $params[$optKey] = $options[$optKey];
            }
        }

        if ($subscription->trialEndsAt() !== null) {
            $params['trial_end'] = $subscription->trialEndsAt()->getTimestamp();
        }

        if ($subscription->cancelAtPeriodEnd()) {
            $params['cancel_at_period_end'] = true;
        }
    }

    private function buildItemsParam(Subscription $subscription): array
    {
        $priceMap = $this->idMapper->getExternalId(
            'price',
            array_map(static fn($item) => $item->priceId()->value(), $subscription->items()),
            'stripe'
        );

        $items = [];
        foreach ($subscription->items() as $item) {
            $internalPriceId = (string)$item->priceId();
            $stripePriceId = $priceMap[$internalPriceId] ?? null;
            if (!$stripePriceId) {
                throw new InvalidArgumentException(
                    sprintf('Missing Stripe price for internal price id %s. Provide a mapping via ExternalIdMapper.', $internalPriceId)
                );
            }

            $items[] = [
                'price' => $stripePriceId,
                'quantity' => $item->quantity(),
                'metadata' => ['internal_price_id' => $internalPriceId],
            ];
        }

        return $items;
    }

    private function extractRedirectUrl(object $stripeSub): ?string
    {
        if (isset($stripeSub->latest_invoice) && is_object($stripeSub->latest_invoice)) {
            $invoice = $stripeSub->latest_invoice;
            if (!empty($invoice->hosted_invoice_url)) {
                return $invoice->hosted_invoice_url;
            }
            if (isset($invoice->payment_intent) && is_object($invoice->payment_intent)) {
                $pi = $invoice->payment_intent;
                if (isset($pi->next_action->redirect_to_url->url)) {
                    return $pi->next_action->redirect_to_url->url;
                }
            }
        }

        return null;
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