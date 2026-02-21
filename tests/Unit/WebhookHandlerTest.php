<?php

declare(strict_types=1);

use AlturaCode\Billing\Core\Common\Money;
use AlturaCode\Billing\Core\Products\ProductPriceId;
use AlturaCode\Billing\Core\Products\ProductPriceInterval;
use AlturaCode\Billing\Core\Provider\MemoryExternalIdMapper;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionId;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionItem;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionItemId;
use AlturaCode\Billing\Stripe\StripeIdStore;
use AlturaCode\Billing\Stripe\WebhookHandler;
use Stripe\Event;
use Tests\Fixtures\InMemorySubscriptionRepository;
use Tests\Fixtures\SpyLogger;
use Tests\Fixtures\SubscriptionItemMother;
use Tests\Fixtures\SubscriptionMother;

beforeEach(function () {
    $this->repository = new InMemorySubscriptionRepository();
    $this->idMapper = new MemoryExternalIdMapper();

    // Seed mapper types so getInternalId won't throw on empty map
    foreach (['subscription', 'subscription_item', 'price', 'product', 'customer'] as $type) {
        $this->idMapper->store($type, 'stripe', '__seed__', '__seed__');
    }

    $this->idStore = new StripeIdStore($this->idMapper);
    $this->logger = new SpyLogger();
    $this->handler = new WebhookHandler($this->repository, $this->idStore, $this->logger);
});

// ---------------------------------------------------------------------------
// General / Non-subscription events
// ---------------------------------------------------------------------------

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

it('logs debug message for non-subscription events', function () {
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

    expect($this->logger->hasLogThatContains('debug', 'ignoring non-subscription event'))->toBeTrue();
});

it('logs debug message for unhandled subscription event types', function () {
    $event = Event::constructFrom([
        'type' => 'customer.subscription.trial_will_end',
        'data' => [
            'object' => [
                'id' => 'sub_123',
                'object' => 'subscription',
                'status' => 'trialing',
            ]
        ]
    ]);

    $this->handler->handle($event);

    expect($this->logger->hasLogThatContains('debug', 'unhandled subscription event type'))->toBeTrue();
});

it('works without a logger (defaults to NullLogger)', function () {
    $handler = new WebhookHandler($this->repository, $this->idStore);

    $event = Event::constructFrom([
        'type' => 'customer.created',
        'data' => [
            'object' => [
                'id' => 'cus_123',
                'object' => 'customer',
            ]
        ]
    ]);

    // Should not throw
    $handler->handle($event);
    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// customer.subscription.created
// ---------------------------------------------------------------------------

it('handles customer.subscription.created: stores mapping and activates subscription', function () {
    $itemId = (string) SubscriptionItemId::generate();
    $item = SubscriptionItem::create(
        id: SubscriptionItemId::fromString($itemId),
        priceId: ProductPriceId::generate(),
        quantity: 1,
        price: Money::usd(1000),
        interval: ProductPriceInterval::monthly(),
    );
    $subscription = SubscriptionMother::create()->withItems($item)->withPrimaryItem($item);
    $this->repository->save($subscription);

    $startsAt = time();
    $endsAt = $startsAt + 3600;

    $event = Event::constructFrom([
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => 'sub_new_123',
                'object' => 'subscription',
                'status' => 'active',
                'metadata' => [
                    'internal_subscription_id' => (string) $subscription->id(),
                ],
                'items' => [
                    'data' => [
                        [
                            'id' => 'si_new_123',
                            'metadata' => [
                                'internal_item_id' => $itemId,
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

    // Mapping was stored
    $mappedInternalId = $this->idMapper->getInternalId('subscription', 'stripe', 'sub_new_123');
    expect($mappedInternalId)->toBe((string) $subscription->id());

    // Item mapping was stored
    $mappedItemId = $this->idMapper->getInternalId('subscription_item', 'stripe', 'si_new_123');
    expect($mappedItemId)->toBe($itemId);

    // Subscription was activated
    $updated = $this->repository->find($subscription->id());
    expect($updated->isActive())->toBeTrue();

    // Periods were synced
    $updatedItem = $updated->primaryItem();
    expect($updatedItem->currentPeriodStartsAt()->getTimestamp())->toBe($startsAt)
        ->and($updatedItem->currentPeriodEndsAt()->getTimestamp())->toBe($endsAt);
});

it('handles customer.subscription.created with trialing status', function () {
    $itemId = (string) SubscriptionItemId::generate();
    $item = SubscriptionItem::create(
        id: SubscriptionItemId::fromString($itemId),
        priceId: ProductPriceId::generate(),
        quantity: 1,
        price: Money::usd(1000),
        interval: ProductPriceInterval::monthly(),
    );
    $subscription = SubscriptionMother::create()->withItems($item)->withPrimaryItem($item);
    $this->repository->save($subscription);

    $startsAt = time();
    $endsAt = $startsAt + 3600;

    $event = Event::constructFrom([
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => 'sub_trial_123',
                'object' => 'subscription',
                'status' => 'trialing',
                'metadata' => [
                    'internal_subscription_id' => (string) $subscription->id(),
                ],
                'items' => [
                    'data' => [
                        [
                            'id' => 'si_trial_123',
                            'metadata' => [
                                'internal_item_id' => $itemId,
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
});

it('handles customer.subscription.created: idempotent when mapping already exists', function () {
    $itemId = (string) SubscriptionItemId::generate();
    $item = SubscriptionItem::create(
        id: SubscriptionItemId::fromString($itemId),
        priceId: ProductPriceId::generate(),
        quantity: 1,
        price: Money::usd(1000),
        interval: ProductPriceInterval::monthly(),
    );
    $subscription = SubscriptionMother::create()->withItems($item)->withPrimaryItem($item);
    $this->repository->save($subscription);

    // Pre-store the mapping (simulating duplicate webhook delivery)
    $this->idMapper->store('subscription', 'stripe', (string) $subscription->id(), 'sub_dup_123');

    $startsAt = time();
    $endsAt = $startsAt + 3600;

    $event = Event::constructFrom([
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => 'sub_dup_123',
                'object' => 'subscription',
                'status' => 'active',
                'metadata' => [
                    'internal_subscription_id' => (string) $subscription->id(),
                ],
                'items' => [
                    'data' => [
                        [
                            'id' => 'si_dup_123',
                            'metadata' => [
                                'internal_item_id' => $itemId,
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

    // Should still activate without error
    $updated = $this->repository->find($subscription->id());
    expect($updated->isActive())->toBeTrue();
});

it('handles customer.subscription.created: ignores when no metadata internal_subscription_id', function () {
    $event = Event::constructFrom([
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => 'sub_no_meta_123',
                'object' => 'subscription',
                'status' => 'active',
                'metadata' => [],
                'items' => [
                    'data' => []
                ]
            ]
        ]
    ]);

    $this->handler->handle($event);

    expect($this->logger->hasLogThatContains('warning', 'no internal subscription ID found'))->toBeTrue();
});

it('handles customer.subscription.created: logs warning when subscription not in repository', function () {
    $nonExistentId = (string) SubscriptionId::generate();

    $event = Event::constructFrom([
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => 'sub_orphan_123',
                'object' => 'subscription',
                'status' => 'active',
                'metadata' => [
                    'internal_subscription_id' => $nonExistentId,
                ],
                'items' => [
                    'data' => []
                ]
            ]
        ]
    ]);

    $this->handler->handle($event);

    expect($this->logger->hasLogThatContains('warning', 'internal subscription not found for created event'))->toBeTrue();
});

it('handles customer.subscription.created: saves subscription even with incomplete status', function () {
    $itemId = (string) SubscriptionItemId::generate();
    $item = SubscriptionItem::create(
        id: SubscriptionItemId::fromString($itemId),
        priceId: ProductPriceId::generate(),
        quantity: 1,
        price: Money::usd(1000),
        interval: ProductPriceInterval::monthly(),
    );
    $subscription = SubscriptionMother::create()->withItems($item)->withPrimaryItem($item);
    $this->repository->save($subscription);

    $event = Event::constructFrom([
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => 'sub_incomplete_123',
                'object' => 'subscription',
                'status' => 'incomplete',
                'metadata' => [
                    'internal_subscription_id' => (string) $subscription->id(),
                ],
                'items' => [
                    'data' => [
                        [
                            'id' => 'si_incomplete_123',
                            'metadata' => [
                                'internal_item_id' => $itemId,
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

    // Mapping stored
    $mappedInternalId = $this->idMapper->getInternalId('subscription', 'stripe', 'sub_incomplete_123');
    expect($mappedInternalId)->toBe((string) $subscription->id());

    // Subscription is NOT activated (status is incomplete)
    $updated = $this->repository->find($subscription->id());
    expect($updated->isActive())->toBeFalse()
        ->and($updated->isIncomplete())->toBeTrue();
});

// ---------------------------------------------------------------------------
// customer.subscription.updated
// ---------------------------------------------------------------------------

it('ignores unknown subscriptions on updated event', function () {
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
    expect(true)->toBeTrue();
});

it('handles subscription canceled status in updated event', function () {
    $item = SubscriptionItemMother::createMonthly();
    $subscription = SubscriptionMother::create()->withItems($item)->withPrimaryItem($item);
    $this->repository->save($subscription);
    $this->idMapper->store('subscription', 'stripe', (string) $subscription->id(), 'sub_123');

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
    $itemId = (string) SubscriptionItemId::generate();
    $item = SubscriptionItem::create(
        id: SubscriptionItemId::fromString($itemId),
        priceId: ProductPriceId::generate(),
        quantity: 1,
        price: Money::usd(1000),
        interval: ProductPriceInterval::monthly(),
    );
    $subscription = SubscriptionMother::create()->withItems($item)->withPrimaryItem($item);

    $this->repository->save($subscription);
    $this->idMapper->store('subscription', 'stripe', (string) $subscription->id(), 'sub_123');

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
                                'internal_item_id' => $itemId
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

it('handles paused subscription via updated event', function () {
    $itemId = (string) SubscriptionItemId::generate();
    $item = SubscriptionItem::create(
        id: SubscriptionItemId::fromString($itemId),
        priceId: ProductPriceId::generate(),
        quantity: 1,
        price: Money::usd(1000),
        interval: ProductPriceInterval::monthly(),
    );
    $subscription = SubscriptionMother::create()->withItems($item)->withPrimaryItem($item);

    $this->repository->save($subscription);
    $this->idMapper->store('subscription', 'stripe', (string) $subscription->id(), 'sub_123');

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
                                'internal_item_id' => $itemId
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

it('handles resuming a paused subscription via updated event', function () {
    $itemId = (string) SubscriptionItemId::generate();
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
    $this->idMapper->store('subscription', 'stripe', (string) $subscription->id(), 'sub_123');

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
                                'internal_item_id' => $itemId
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
    $itemId = (string) SubscriptionItemId::generate();
    $item = SubscriptionItem::create(
        id: SubscriptionItemId::fromString($itemId),
        priceId: ProductPriceId::generate(),
        quantity: 1,
        price: Money::usd(1000),
        interval: ProductPriceInterval::monthly(),
    );
    $subscription = SubscriptionMother::create()->withItems($item)->withPrimaryItem($item);

    $this->repository->save($subscription);
    $this->idMapper->store('subscription', 'stripe', (string) $subscription->id(), 'sub_123');

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
                                'internal_item_id' => $itemId
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

// ---------------------------------------------------------------------------
// customer.subscription.deleted
// ---------------------------------------------------------------------------

it('handles customer.subscription.deleted', function () {
    $item = SubscriptionItemMother::createMonthly();
    $subscription = SubscriptionMother::create()->withItems($item)->withPrimaryItem($item);
    $this->repository->save($subscription);
    $this->idMapper->store('subscription', 'stripe', (string) $subscription->id(), 'sub_123');

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

it('handles customer.subscription.deleted for unknown subscription without error', function () {
    $this->idMapper->store('subscription', 'stripe', 'internal_123', 'sub_123');

    $event = Event::constructFrom([
        'type' => 'customer.subscription.deleted',
        'data' => [
            'object' => [
                'id' => 'sub_unknown',
                'object' => 'subscription',
                'status' => 'canceled'
            ]
        ]
    ]);

    $this->handler->handle($event);
    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// customer.subscription.paused
// ---------------------------------------------------------------------------

it('handles customer.subscription.paused', function () {
    $item = SubscriptionItemMother::createMonthly();
    $subscription = SubscriptionMother::create()->withItems($item)->withPrimaryItem($item)->activate();
    $this->repository->save($subscription);
    $this->idMapper->store('subscription', 'stripe', (string) $subscription->id(), 'sub_123');

    $event = Event::constructFrom([
        'type' => 'customer.subscription.paused',
        'data' => [
            'object' => [
                'id' => 'sub_123',
                'object' => 'subscription',
                'status' => 'paused',
                'pause_collection' => [
                    'behavior' => 'void'
                ],
            ]
        ]
    ]);

    $this->handler->handle($event);

    $updated = $this->repository->find($subscription->id());
    expect($updated->isPaused())->toBeTrue();
});

it('handles customer.subscription.paused for unknown subscription without error', function () {
    $this->idMapper->store('subscription', 'stripe', 'internal_123', 'sub_123');

    $event = Event::constructFrom([
        'type' => 'customer.subscription.paused',
        'data' => [
            'object' => [
                'id' => 'sub_unknown',
                'object' => 'subscription',
                'status' => 'paused',
            ]
        ]
    ]);

    $this->handler->handle($event);
    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// customer.subscription.resumed
// ---------------------------------------------------------------------------

it('handles customer.subscription.resumed', function () {
    $itemId = (string) SubscriptionItemId::generate();
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
    $this->idMapper->store('subscription', 'stripe', (string) $subscription->id(), 'sub_123');

    $startsAt = time();
    $endsAt = $startsAt + 3600;

    $event = Event::constructFrom([
        'type' => 'customer.subscription.resumed',
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
                                'internal_item_id' => $itemId,
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
    expect($updated->isActive())->toBeTrue()
        ->and($updated->isPaused())->toBeFalse();
});

it('handles customer.subscription.resumed for unknown subscription without error', function () {
    $this->idMapper->store('subscription', 'stripe', 'internal_123', 'sub_123');

    $event = Event::constructFrom([
        'type' => 'customer.subscription.resumed',
        'data' => [
            'object' => [
                'id' => 'sub_unknown',
                'object' => 'subscription',
                'status' => 'active',
            ]
        ]
    ]);

    $this->handler->handle($event);
    expect(true)->toBeTrue();
});
