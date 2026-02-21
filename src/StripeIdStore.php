<?php

declare(strict_types=1);

namespace AlturaCode\Billing\Stripe;

use AlturaCode\Billing\Core\Common\BillableIdentity;
use AlturaCode\Billing\Core\Provider\ExternalIdMapper;
use AlturaCode\Billing\Core\Subscriptions\Subscription;
use InvalidArgumentException;

/**
 * @internal
 */
final readonly class StripeIdStore
{
    public function __construct(
        private ExternalIdMapper $idMapper
    )
    {
    }

    public function getCustomerId(BillableIdentity $billable): ?string
    {
        return $this->idMapper->getExternalId('customer', 'stripe', implode('_', [
            $billable->type(),
            $billable->id()
        ]));
    }

    public function requireCustomerId(BillableIdentity $billable): string
    {
        $stripeCustomerId = $this->getCustomerId($billable);
        if (!$stripeCustomerId) {
            throw new InvalidArgumentException('Missing Stripe customer id mapping for customer.');
        }
        return $stripeCustomerId;
    }

    public function getSubscriptionId(Subscription $subscription): ?string
    {
        return $this->idMapper->getExternalId('subscription', 'stripe', $subscription->id()->value());
    }

    public function getInternalSubscriptionId(string $stripeSubscriptionId): ?string
    {
        return $this->idMapper->getInternalId('subscription', 'stripe', $stripeSubscriptionId);
    }

    public function requireSubscriptionId(Subscription $subscription): string
    {
        $subscriptionId = $this->getSubscriptionId($subscription);
        if (!$subscriptionId) {
            throw new InvalidArgumentException('Missing Stripe subscription id mapping for subscription.');
        }
        return $subscriptionId;
    }

    public function getProductId(string $internalId): ?string
    {
        return $this->idMapper->getExternalId('product', 'stripe', $internalId);
    }

    public function getProductIds(array $internalIds): array
    {
        return $this->idMapper->getExternalIdMap('product', 'stripe', $internalIds);
    }

    public function getPriceIds(array $internalIds): array
    {
        return $this->idMapper->getExternalIdMap('price', 'stripe', $internalIds);
    }

    public function getInternalPriceIdMap(array $stripePriceIds): array
    {
        return $this->idMapper->getInternalIdMap('price', 'stripe', $stripePriceIds);
    }

    public function getInternalSubscriptionItemIdMap(array $stripeItemIds): array
    {
        return $this->idMapper->getInternalIdMap('subscription_item', 'stripe', $stripeItemIds);
    }

    public function storeCustomerId(BillableIdentity $billable, string $id): void
    {
        $this->idMapper->store('customer', 'stripe', implode('_', [
            $billable->type(),
            $billable->id()
        ]), $id);
    }

    public function storeSubscriptionId(string $internalSubscriptionId, string $stripeSubscriptionId): void
    {
        $this->idMapper->store('subscription', 'stripe', $internalSubscriptionId, $stripeSubscriptionId);
    }

    public function storeMultipleSubscriptionItemIdMappings(array $mappings): void
    {
        $data = [];
        foreach ($mappings as $internalId => $stripeId) {
            $data[] = [
                'type' => 'subscription_item',
                'provider' => 'stripe',
                'internalId' => $internalId,
                'externalId' => $stripeId,
            ];
        }
        $this->idMapper->storeMultiple($data);
    }

    public function storeProductId(string $internalProductId, string $stripeProductId): void
    {
        $this->idMapper->store('product', 'stripe', $internalProductId, $stripeProductId);
    }

    public function storePriceId(string $internalPriceId, string $stripePriceId): void
    {
        $this->idMapper->store('price', 'stripe', $internalPriceId, $stripePriceId);
    }
}