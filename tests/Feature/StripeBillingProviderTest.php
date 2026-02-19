<?php

use AlturaCode\Billing\Core\Common\BillableIdentity;
use AlturaCode\Billing\Core\Common\Currency;
use AlturaCode\Billing\Core\Products\ProductPriceInterval;
use AlturaCode\Billing\Core\Provider\MemoryExternalIdMapper;
use AlturaCode\Billing\Core\Subscriptions\Subscription;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionId;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionItemId;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionName;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionRepository;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionStatus;
use AlturaCode\Billing\Stripe\CreateSubscriptionUsingCheckout;
use AlturaCode\Billing\Stripe\StripeBillingProvider;
use AlturaCode\Billing\Stripe\StripeIdStore;
use Stripe\StripeClient;
use Tests\Fixtures\BillableDetailsMother;
use Tests\Fixtures\InMemorySubscriptionRepository;
use Tests\Fixtures\ProductMother;
use Tests\Fixtures\ProductPriceMother;
use Tests\Fixtures\SubscriptionItemMother;
use Tests\Fixtures\SubscriptionMother;

beforeEach(function () {
    $stripeSecret = $_ENV['STRIPE_SECRET'];

    if (!$stripeSecret) {
        throw new RuntimeException('Missing STRIPE_SECRET environment variable.');
    }

    if (!str_starts_with($stripeSecret, 'sk_test')) {
        throw new RuntimeException('STRIPE_SECRET must be a test key starting with "sk_test_". Production keys are not allowed in tests.');
    }

    $idStore = new StripeIdStore(new MemoryExternalIdMapper());
    $this->stripe = new StripeClient(['api_key' => $stripeSecret]);

    $this->provider = new StripeBillingProvider(
        stripeClient: $this->stripe,
        ids: $idStore,
        createSubscription: new CreateSubscriptionUsingCheckout(
            stripeClient: $this->stripe,
            idStore: $idStore
        ),
        subscriptionRepository: new InMemorySubscriptionRepository(),
    );
});

test('create customer', function () {
    $result = $this->provider->syncCustomer(
        BillableIdentity::fromString('user', 1),
        BillableDetailsMother::createMinimal()
    );

    expect($result->providerCustomerId())->not()->toBeNull()
        ->and($result->providerCustomerId())->not()->toBe(1)
        ->and($result->metadata()['customer'])->not()->toBeNull()
        ->and($result->metadata()['customer']->name)->toBe('Jane Doe');

    // Assert customer was properly created on the Stripe side.
    $stripeCustomer = $this->stripe->customers->retrieve($result->providerCustomerId());
    expect($stripeCustomer->name)->toBe('Jane Doe');
});

test('sync product', function () {
    $price = ProductPriceMother::createMonthly();
    $product = ProductMother::createPlan()->withPrices($price);
    $result = $this->provider->syncProduct($product);

    expect($result->failedProductIds())->toBeEmpty()
        ->and($result->failedPriceIds())->toBeEmpty()
        ->and($result->syncedProductsCount())->toBe(1)
        ->and($result->syncedPricesCount())->toBe(1)
        ->and($result->syncedProductIds())->toHaveKey($product->id()->value())
        ->and($result->syncedPriceIds())->toHaveKey($price->id()->value());

    // Assert product & price was properly created on the Stripe side.
    $stripeProduct = $this->stripe->products->retrieve($result->syncedProductIds()[$product->id()->value()]);
    expect($stripeProduct->name)->toBe($product->name());

    $stripePrice = $this->stripe->prices->retrieve($result->syncedPriceIds()[$price->id()->value()]);
    expect($stripePrice->unit_amount)->toBe($price->price()->amount())
        ->and($stripePrice->currency)->toBe($price->price()->currency()->code());
});

test('creates a free subscription', function () {
    $product = ProductMother::createPlan()->withPrices(ProductPriceMother::createFree());
    $price = $product->findPriceForIntervalAndCurrency(ProductPriceInterval::monthly(), Currency::usd());
    $subscription = SubscriptionMother::create()->withPrimaryItem(SubscriptionItemMother::fromProductPrice($price));

    $this->provider->syncCustomer($subscription->billable(), BillableDetailsMother::createMinimal());
    $result = $this->provider->create($subscription);

    expect($result->subscription->id()->value())->toBe($subscription->id()->value())
        ->and($result->subscription->status())->toBe(SubscriptionStatus::Active)
        ->and($result->requiresAction())->toBeFalse();
});

test('creates a paid subscription', function () {
    $product = ProductMother::createPlan()->withPrices(ProductPriceMother::createMonthly());
    $price = $product->findPriceForIntervalAndCurrency(ProductPriceInterval::monthly(), Currency::usd());
    $subscription = SubscriptionMother::create()->withItems(SubscriptionItemMother::fromProductPrice($price));

    $this->provider->syncProduct($product);
    $this->provider->syncCustomer($subscription->billable(), BillableDetailsMother::createMinimal());
    $result = $this->provider->create($subscription, [
        'success_url' => 'https://example.com/success',
        'cancel_url' => 'https://example.com/cancel',
    ]);

    expect($result->subscription->id()->value())->toBe($subscription->id()->value())
        ->and($result->subscription->status())->toBe(SubscriptionStatus::Incomplete)
        ->and($result->requiresAction())->toBeTrue()
        ->and($result->clientAction->url)->not()->toBeNull();
});
