<?php

namespace Tests\Fixtures;

use AlturaCode\Billing\Core\Subscriptions\SubscriptionRepository;
use AlturaCode\Billing\Core\Common\BillableIdentity;
use AlturaCode\Billing\Core\Subscriptions\Subscription;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionId;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionItemId;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionName;

class InMemorySubscriptionRepository implements SubscriptionRepository {
    private array $subscriptionsById = [];
    private array $subscriptionIdsByBillable = [];
    public function find(SubscriptionId $subscriptionId): ?Subscription
    {
        return $this->subscriptionsById[(string) $subscriptionId] ?? null;
    }

    public function findByItemId(SubscriptionItemId $itemId): ?Subscription
    {
        return $this->subscriptionsById[(string) $itemId] ?? null;
    }

    public function save(Subscription $subscription): void
    {
        $this->subscriptionsById[(string) $subscription->id()] = $subscription;
        $this->subscriptionIdsByBillable[$subscription->billable()->id()][] = (string) $subscription->id();
    }

    public function findForBillable(BillableIdentity $billable, SubscriptionName $subscriptionName): ?Subscription
    {
        $ids = $this->subscriptionIdsByBillable[$billable->id()] ?? null;
        return $ids ? $this->find(SubscriptionId::fromString($ids[0])) : null;
    }

    public function findAllForBillable(BillableIdentity $billable): array
    {
        $ids = $this->subscriptionIdsByBillable[$billable->id()] ?? [];
        return array_map(fn ($id) => $this->find(SubscriptionId::fromString($id)), $ids);
    }
}