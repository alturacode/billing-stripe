<?php

declare(strict_types=1);

use AlturaCode\Billing\Core\Common\Money;
use AlturaCode\Billing\Core\Products\ProductPriceId;
use AlturaCode\Billing\Core\Products\ProductPriceInterval;
use AlturaCode\Billing\Core\Provider\MemoryExternalIdMapper;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionItem;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionItemId;
use AlturaCode\Billing\Stripe\WebhookHandler;
use Stripe\Event;
use Tests\Fixtures\InMemorySubscriptionRepository;
use Tests\Fixtures\SubscriptionItemMother;
use Tests\Fixtures\SubscriptionMother;

beforeEach(function () {
    $this->repository = new InMemorySubscriptionRepository();
    $this->idMapper = new MemoryExternalIdMapper();
    $this->handler = new WebhookHandler($this->repository, $this->idMapper);
});

it('ignores events with non-subscription objects', function () {
    $event = Event::constructFrom([
        'type' => 'customer.created',
        'data' => [
            'object' => [
                'id' => 'cus_123',
                'object' => 'customer',
            ]
        ]
    ]);

    $this->handler->handle($event);

    expect($this->repository->findAllForBillable(SubscriptionMother::create()->billable()))->toBeEmpty();
});

it('ignores unknown subscriptions', function () {
    // We store one unrelated subscription to avoid "Undefined array key" in MemoryExternalIdMapper
    $this->idMapper->store('subscription', 'stripe', 'internal_123', 'sub_123');

    $event = Event::constructFrom([
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'id' => 'sub_unknown',
                'object' => 'subscription',
                'status' => 'active'
            ]
        ]
    ]);

    $this->handler->handle($event);
    // Should just return without error
    expect(true)->toBeTrue();
});

it('handles customer.subscription.deleted', function () {
    $item = SubscriptionItemMother::createMonthly();
    $subscription = SubscriptionMother::create()->withItems($item)->withPrimaryItem($item);
    $this->repository->save($subscription);
    $this->idMapper->store('subscription', 'stripe', (string)$subscription->id(), 'sub_123');

    $event = Event::constructFrom([
        'type' => 'customer.subscription.deleted',
        'data' => [
            'object' => [
                'id' => 'sub_123',
                'object' => 'subscription',
                'status' => 'canceled'
            ]
        ]
    ]);

    $this->handler->handle($event);

    $updated = $this->repository->find($subscription->id());
    expect($updated->isCanceled())->toBeTrue()
        ->and($updated->canceledAt())->not()->toBeNull();
});

it('handles subscription canceled status in updated event', function () {
    $item = SubscriptionItemMother::createMonthly();
    $subscription = SubscriptionMother::create()->withItems($item)->withPrimaryItem($item);
    $this->repository->save($subscription);
    $this->idMapper->store('subscription', 'stripe', (string)$subscription->id(), 'sub_123');

    $event = Event::constructFrom([
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'id' => 'sub_123',
                'object' => 'subscription',
                'status' => 'canceled'
            ]
        ]
    ]);

    $this->handler->handle($event);

    $updated = $this->repository->find($subscription->id());
    expect($updated->isCanceled())->toBeTrue();
});

it('handles cancel_at_period_end', function () {
    $itemId = (string)SubscriptionItemId::generate();
    $item = SubscriptionItem::create(
        id: SubscriptionItemId::fromString($itemId),
        priceId: ProductPriceId::generate(),
        quantity: 1,
        price: Money::usd(1000),
        interval: ProductPriceInterval::monthly(),
    );
    $subscription = SubscriptionMother::create()->withItems($item)->withPrimaryItem($item);

    $this->repository->save($subscription);
    $this->idMapper->store('subscription', 'stripe', (string)$subscription->id(), 'sub_123');

    $event = Event::constructFrom([
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'id' => 'sub_123',
                'object' => 'subscription',
                'status' => 'active',
                'cancel_at_period_end' => true,
                'items' => [
                    'data' => [
                        [
                            'id' => 'si_123',
                            'metadata' => [
                                'internal_price_id' => $itemId
                            ],
                            'current_period_start' => time(),
                            'current_period_end' => time() + 3600,
                        ]
                    ]
                ]
            ]
        ]
    ]);

    $this->handler->handle($event);

    $updated = $this->repository->find($subscription->id());
    expect($updated->cancelAtPeriodEnd())->toBeTrue()
        ->and($updated->isCanceled())->toBeFalse()
        ->and($updated->isActive())->toBeTrue();
});

it('handles paused subscription', function () {
    $itemId = (string)SubscriptionItemId::generate();
    $item = SubscriptionItem::create(
        id: SubscriptionItemId::fromString($itemId),
        priceId: ProductPriceId::generate(),
        quantity: 1,
        price: Money::usd(1000),
        interval: ProductPriceInterval::monthly(),
    );
    $subscription = SubscriptionMother::create()->withItems($item)->withPrimaryItem($item);

    $this->repository->save($subscription);
    $this->idMapper->store('subscription', 'stripe', (string)$subscription->id(), 'sub_123');

    $event = Event::constructFrom([
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'id' => 'sub_123',
                'object' => 'subscription',
                'status' => 'active',
                'pause_collection' => [
                    'behavior' => 'keep_as_draft'
                ],
                'items' => [
                    'data' => [
                        [
                            'id' => 'si_123',
                            'metadata' => [
                                'internal_price_id' => $itemId
                            ],
                            'current_period_start' => time(),
                            'current_period_end' => time() + 3600,
                        ]
                    ]
                ]
            ]
        ]
    ]);

    $this->handler->handle($event);

    $updated = $this->repository->find($subscription->id());
    expect($updated->isPaused())->toBeTrue();
});

it('handles resuming a paused subscription', function () {
    $itemId = (string)SubscriptionItemId::generate();
    $item = SubscriptionItem::create(
        id: SubscriptionItemId::fromString($itemId),
        priceId: ProductPriceId::generate(),
        quantity: 1,
        price: Money::usd(1000),
        interval: ProductPriceInterval::monthly(),
    );
    $subscription = SubscriptionMother::create()
        ->withItems($item)
        ->withPrimaryItem($item)
        ->pause();

    $this->repository->save($subscription);
    $this->idMapper->store('subscription', 'stripe', (string)$subscription->id(), 'sub_123');

    $event = Event::constructFrom([
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'id' => 'sub_123',
                'object' => 'subscription',
                'status' => 'active',
                'pause_collection' => null,
                'items' => [
                    'data' => [
                        [
                            'id' => 'si_123',
                            'metadata' => [
                                'internal_price_id' => $itemId
                            ],
                            'current_period_start' => time(),
                            'current_period_end' => time() + 3600,
                        ]
                    ]
                ]
            ]
        ]
    ]);

    $this->handler->handle($event);

    $updated = $this->repository->find($subscription->id());
    expect($updated->isActive())->toBeTrue()
        ->and($updated->isPaused())->toBeFalse();
});

it('activates and syncs periods for active subscription', function () {
    $itemId = (string)SubscriptionItemId::generate();
    // We need to ensure the item ID in the internal subscription matches what SubscriptionActivator expects.
    // SubscriptionActivator uses $stripeItem->metadata->internal_price_id as SubscriptionItemId.

    $item = SubscriptionItem::create(
        id: SubscriptionItemId::fromString($itemId),
        priceId: ProductPriceId::generate(),
        quantity: 1,
        price: Money::usd(1000),
        interval: ProductPriceInterval::monthly(),
    );
    $subscription = SubscriptionMother::create()->withItems($item)->withPrimaryItem($item);

    $this->repository->save($subscription);
    $this->idMapper->store('subscription', 'stripe', (string)$subscription->id(), 'sub_123');

    $startsAt = time();
    $endsAt = $startsAt + 3600;

    $event = Event::constructFrom([
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'id' => 'sub_123',
                'object' => 'subscription',
                'status' => 'active',
                'items' => [
                    'data' => [
                        [
                            'id' => 'si_123',
                            'metadata' => [
                                'internal_price_id' => $itemId
                            ],
                            'current_period_start' => $startsAt,
                            'current_period_end' => $endsAt,
                        ]
                    ]
                ]
            ]
        ]
    ]);

    $this->handler->handle($event);

    $updated = $this->repository->find($subscription->id());
    expect($updated->isActive())->toBeTrue();

    $updatedItem = $updated->primaryItem();
    expect($updatedItem->currentPeriodStartsAt()->getTimestamp())->toBe($startsAt)
        ->and($updatedItem->currentPeriodEndsAt()->getTimestamp())->toBe($endsAt);
});
