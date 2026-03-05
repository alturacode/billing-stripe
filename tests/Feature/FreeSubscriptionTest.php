<?php

use AlturaCode\Billing\Core\Common\Currency;
use AlturaCode\Billing\Core\Common\Money;
use AlturaCode\Billing\Core\Products\ProductPrice;
use AlturaCode\Billing\Core\Products\ProductPriceId;
use AlturaCode\Billing\Core\Products\ProductPriceInterval;
use AlturaCode\Billing\Core\Provider\MemoryExternalIdMapper;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionStatus;
use AlturaCode\Billing\Stripe\CreateSubscriptionUsingCheckout;
use AlturaCode\Billing\Stripe\StripeBillingProvider;
use AlturaCode\Billing\Stripe\StripeIdStore;
use Stripe\StripeClient;
use Tests\Fixtures\ProductMother;
use Tests\Fixtures\ProductPriceMother;
use Tests\Fixtures\SubscriptionItemMother;
use Tests\Fixtures\SubscriptionMother;

beforeEach(function () {
    $this->idStore = new StripeIdStore(new MemoryExternalIdMapper());
    $this->stripe = new StripeClient(['api_key' => 'sk_test_unused']);
    $this->productRepository = new Tests\Fixtures\InMemoryProductRepository();

    $this->provider = new StripeBillingProvider(
        stripeClient: $this->stripe,
        ids: $this->idStore,
        createSubscription: new CreateSubscriptionUsingCheckout(
            stripeClient: $this->stripe,
            idStore: $this->idStore
        ),
        productRepository: $this->productRepository,
    );
});

test('cancel free subscription', function () {
    $product = ProductMother::createPlan()->withPrices(ProductPriceMother::createFree());
    $price = $product->findPriceForIntervalAndCurrency(ProductPriceInterval::monthly(), Currency::usd());
    $subscription = SubscriptionMother::create()->withPrimaryItem(SubscriptionItemMother::fromProductPrice($price));
    $subscription = $subscription->activate();

    $result = $this->provider->cancel($subscription, false, []);

    expect($result->subscription->status())->toBe(SubscriptionStatus::Canceled);
});

test('pause free subscription', function () {
    $product = ProductMother::createPlan()->withPrices(ProductPriceMother::createFree());
    $price = $product->findPriceForIntervalAndCurrency(ProductPriceInterval::monthly(), Currency::usd());
    $subscription = SubscriptionMother::create()->withPrimaryItem(SubscriptionItemMother::fromProductPrice($price));
    $subscription = $subscription->activate();

    $result = $this->provider->pause($subscription, []);

    expect($result->subscription->status())->toBe(SubscriptionStatus::Paused);
});

test('resume free subscription', function () {
    $product = ProductMother::createPlan()->withPrices(ProductPriceMother::createFree());
    $price = $product->findPriceForIntervalAndCurrency(ProductPriceInterval::monthly(), Currency::usd());
    $subscription = SubscriptionMother::create()->withPrimaryItem(SubscriptionItemMother::fromProductPrice($price));
    $subscription = $subscription->pause();

    $result = $this->provider->resume($subscription, []);

    expect($result->subscription->status())->toBe(SubscriptionStatus::Active);
});

test('doNotCancel free subscription', function () {
    $product = ProductMother::createPlan()->withPrices(ProductPriceMother::createFree());
    $price = $product->findPriceForIntervalAndCurrency(ProductPriceInterval::monthly(), Currency::usd());
    $subscription = SubscriptionMother::create()->withPrimaryItem(SubscriptionItemMother::fromProductPrice($price));
    $subscription = $subscription->cancel(true);

    $result = $this->provider->doNotCancel($subscription, []);

    expect($result->subscription->cancelAtPeriodEnd())->toBeFalse();
});

test('swap free subscription item price', function () {
    $freePrice1 = ProductPrice::create(
        id: ProductPriceId::generate(),
        price: Money::usd(0),
        interval: ProductPriceInterval::monthly(),
    );
    $freePrice2 = ProductPrice::create(
        id: ProductPriceId::generate(),
        price: Money::usd(0),
        interval: ProductPriceInterval::monthly(),
    );
    $product = ProductMother::createPlan()->withPrices($freePrice1, $freePrice2);
    $this->productRepository->save($product);

    $subscription = SubscriptionMother::create()->withPrimaryItem(
        SubscriptionItemMother::fromProductPrice($freePrice1)
    );
    $subscription = $subscription->activate();

    $result = $this->provider->swapItemPrice(
        $subscription,
        $subscription->primaryItem(),
        $freePrice2->id()->value()
    );

    expect($result->subscription->primaryItem()->priceId()->value())->toBe($freePrice2->id()->value());
});
