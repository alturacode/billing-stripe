<?php

declare(strict_types=1);

namespace Tests\Unit;

use AlturaCode\Billing\Core\Common\BillableIdentity;
use AlturaCode\Billing\Core\Provider\MemoryExternalIdMapper;
use AlturaCode\Billing\Core\Subscriptions\Subscription;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionId;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionName;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionProvider;
use AlturaCode\Billing\Stripe\CreateSubscriptionUsingCheckout;
use AlturaCode\Billing\Stripe\MissingStripeIdMapping;
use AlturaCode\Billing\Stripe\StripeIdStore;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Stripe\StripeClient;
use Tests\Fixtures\SubscriptionItemMother;
use Tests\Fixtures\SubscriptionMother;

beforeEach(function () {
    $this->sessionService = $this->getMockBuilder(\Stripe\Service\Checkout\SessionService::class)
        ->disableOriginalConstructor()
        ->getMock();

    $this->stripeClient = new class(['api_key' => 'sk_test_123'], $this->sessionService) extends StripeClient {
        private $mockCheckout;

        public function __construct($config, $sessionService)
        {
            $this->mockCheckout = new class($sessionService) {
                public $sessions;

                public function __construct($sessionService)
                {
                    $this->sessions = $sessionService;
                }
            };
        }

        public function __get($name)
        {
            if ($name === 'checkout') {
                return $this->mockCheckout;
            }
            return null;
        }
    };

    $this->idStore = new StripeIdStore(new MemoryExternalIdMapper());
    $this->action = new CreateSubscriptionUsingCheckout($this->stripeClient, $this->idStore);
});

it('throws exception if success_url is missing', function () {
    $subscription = SubscriptionMother::create();
    $this->idStore->storeCustomerId($subscription->billable(), 'cus_123');

    $this->action->create($subscription, ['cancel_url' => 'https://example.com/cancel']);
})->throws(InvalidArgumentException::class, 'Missing required Checkout URLs');

it('throws exception if cancel_url is missing', function () {
    $subscription = SubscriptionMother::create();
    $this->idStore->storeCustomerId($subscription->billable(), 'cus_123');

    $this->action->create($subscription, ['success_url' => 'https://example.com/success']);
})->throws(InvalidArgumentException::class, 'Missing required Checkout URLs');

it('throws exception if customer id is missing', function () {
    $subscription = SubscriptionMother::create();

    $this->action->create($subscription, [
        'success_url' => 'https://example.com/success',
        'cancel_url' => 'https://example.com/cancel'
    ]);
})->throws(InvalidArgumentException::class, 'Missing Stripe customer id mapping for customer.');

it('throws exception if price mapping is missing', function () {
    $item = SubscriptionItemMother::createMonthly();
    $subscription = SubscriptionMother::create()
        ->withItems($item)
        ->withPrimaryItem($item);

    $this->idStore->storeCustomerId($subscription->billable(), 'cus_123');

    $this->action->create($subscription, [
        'success_url' => 'https://example.com/success',
        'cancel_url' => 'https://example.com/cancel'
    ]);
})->throws(MissingStripeIdMapping::class);

it('creates a checkout session and returns redirect result', function () {
    $item = SubscriptionItemMother::createMonthly();
    $subscription = SubscriptionMother::create()
        ->withItems($item)
        ->withPrimaryItem($item);

    $this->idStore->storeCustomerId($subscription->billable(), 'cus_123');
    $this->idStore->storePriceId($item->priceId()->value(), 'price_stripe_123');

    $expectedParams = [
        'mode' => 'subscription',
        'customer' => 'cus_123',
        'line_items' => [
            [
                'price' => 'price_stripe_123',
                'quantity' => 1,
                'metadata' => [
                    'internal_item_id' => $item->id()->value(),
                    'internal_price_id' => $item->priceId()->value(),
                ],
            ]
        ],
        'success_url' => 'https://example.com/success',
        'cancel_url' => 'https://example.com/cancel',
        'client_reference_id' => $subscription->id()->value(),
        'subscription_data' => [
            'metadata' => [
                'internal_subscription_id' => $subscription->id()->value(),
            ],
        ]
    ];

    $mockSession = (object)['url' => 'https://checkout.stripe.com/pay/session_123'];

    $this->sessionService->expects($this->once())
        ->method('create')
        ->with($expectedParams)
        ->willReturn($mockSession);

    $result = $this->action->create($subscription, [
        'success_url' => 'https://example.com/success',
        'cancel_url' => 'https://example.com/cancel'
    ]);

    expect($result->requiresAction())->toBeTrue()
        ->and($result->clientAction->url)->toBe('https://checkout.stripe.com/pay/session_123');
});

it('passes optional flags and subscription data', function () {
    $item = SubscriptionItemMother::createMonthly();
    $subscription = SubscriptionMother::create()
        ->withItems($item)
        ->withPrimaryItem($item);

    $this->idStore->storeCustomerId($subscription->billable(), 'cus_123');
    $this->idStore->storePriceId($item->priceId()->value(), 'price_stripe_123');

    $options = [
        'success_url' => 'https://example.com/success',
        'cancel_url' => 'https://example.com/cancel',
        'allow_promotion_codes' => true,
        'locale' => 'es',
        'proration_behavior' => 'none',
        'coupon' => 'SUMMER_SALE',
        'default_payment_method' => 'pm_123',
    ];

    $mockSession = (object)['url' => 'https://checkout.stripe.com/pay/session_123'];

    $this->sessionService->expects($this->once())
        ->method('create')
        ->with($this->callback(function ($params) {
            return $params['allow_promotion_codes'] === true &&
                $params['locale'] === 'es' &&
                $params['subscription_data']['proration_behavior'] === 'none' &&
                $params['subscription_data']['coupon'] === 'SUMMER_SALE' &&
                $params['subscription_data']['default_payment_method'] === 'pm_123';
        }))
        ->willReturn($mockSession);

    $this->action->create($subscription, $options);
});

it('handles trial and cancel_at_period_end', function () {
    $item = SubscriptionItemMother::createMonthly();
    $trialEnd = new DateTimeImmutable('+1 week');
    $subscription = Subscription::create(
        id: SubscriptionId::generate(),
        name: SubscriptionName::fromString('default'),
        billable: BillableIdentity::fromString('user', '1'),
        provider: SubscriptionProvider::fromString('stripe'),
        trialEndsAt: $trialEnd
    )->withItems($item)->withPrimaryItem($item)->cancel(true);

    $this->idStore->storeCustomerId($subscription->billable(), 'cus_123');
    $this->idStore->storePriceId($item->priceId()->value(), 'price_stripe_123');

    $mockSession = (object)['url' => 'https://checkout.stripe.com/pay/session_123'];

    $this->sessionService->expects($this->once())
        ->method('create')
        ->with($this->callback(function ($params) use ($trialEnd) {
            return $params['subscription_data']['cancel_at_period_end'] === true &&
                $params['subscription_data']['trial_end'] === $trialEnd->getTimestamp();
        }))
        ->willReturn($mockSession);

    $this->action->create($subscription, [
        'success_url' => 'https://example.com/success',
        'cancel_url' => 'https://example.com/cancel'
    ]);
});

it('throws exception if session url is missing', function () {
    $item = SubscriptionItemMother::createMonthly();
    $subscription = SubscriptionMother::create()
        ->withItems($item)
        ->withPrimaryItem($item);

    $this->idStore->storeCustomerId($subscription->billable(), 'cus_123');
    $this->idStore->storePriceId($item->priceId()->value(), 'price_stripe_123');

    $mockSession = (object)['url' => null];

    $this->sessionService->expects($this->once())
        ->method('create')
        ->willReturn($mockSession);

    $this->action->create($subscription, [
        'success_url' => 'https://example.com/success',
        'cancel_url' => 'https://example.com/cancel'
    ]);
})->throws(LogicException::class, 'Failed to create Stripe Checkout session or missing session URL.');
