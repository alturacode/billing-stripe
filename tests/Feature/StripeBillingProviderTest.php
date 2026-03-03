<?php

use AlturaCode\Billing\Core\Common\BillableIdentity;
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
use Tests\Fixtures\BillableDetailsMother;
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

    $this->idStore = new StripeIdStore(new MemoryExternalIdMapper());
    $this->stripe = new StripeClient(['api_key' => $stripeSecret]);
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

test('swaps subscription item price from basic to pro (upgrade)', function () {
    // Create a product with two price tiers: basic and pro
    $basicPrice = ProductPrice::create(
        id: ProductPriceId::generate(),
        price: Money::usd(1000),
        interval: ProductPriceInterval::monthly(),
    );
    $proPrice = ProductPrice::create(
        id: ProductPriceId::generate(),
        price: Money::usd(2000),
        interval: ProductPriceInterval::monthly(),
    );
    $product = ProductMother::createPlan()->withPrices($basicPrice, $proPrice);

    // Save product to repository so it can be found by price ID
    $this->productRepository->save($product);

    // Sync product with Stripe
    $productSyncResult = $this->provider->syncProduct($product);

    // Create subscription with basic price
    $subscription = SubscriptionMother::create()->withPrimaryItem(
        SubscriptionItemMother::fromProductPrice($basicPrice)
    );

    // Create customer and activate subscription directly in Stripe
    $customerSyncResult = $this->provider->syncCustomer($subscription->billable(), BillableDetailsMother::createMinimal());

    // Add a payment method to the customer
    $this->stripe->customers->update($customerSyncResult->providerCustomerId(), [
        'source' => 'tok_visa',
    ]);

    $stripeSubscription = $this->stripe->subscriptions->create([
        'customer' => $customerSyncResult->providerCustomerId(),
        'items' => [['price' => $productSyncResult->syncedPriceIds()[$basicPrice->id()->value()]]],
    ]);

    // Store the subscription and item ID mappings
    $this->idStore->storeSubscriptionId($subscription->id()->value(), $stripeSubscription->id);
    $this->idStore->storeMultipleSubscriptionItemIdMappings([
        $subscription->primaryItem()->id()->value() => $stripeSubscription->items->data[0]->id,
    ]);

    // Swap from basic to pro
    $result = $this->provider->swapItemPrice(
        $subscription,
        $subscription->primaryItem(),
        $proPrice->id()->value()
    );

    // Verify the swap was successful
    expect($result->subscription->id()->value())->toBe($subscription->id()->value())
        ->and($result->subscription->primaryItem()->priceId()->value())->toBe($proPrice->id()->value());

    // Verify on the Stripe side that the price was actually swapped
    $updatedStripeSubscription = $this->stripe->subscriptions->retrieve($stripeSubscription->id);
    $proPriceStripeId = $this->provider->syncProduct($product)->syncedPriceIds()[$proPrice->id()->value()];
    expect($updatedStripeSubscription->items->data[0]->price->id)->toBe($proPriceStripeId);
});

test('swaps subscription item price from pro to basic (downgrade)', function () {
    // Create a product with two price tiers: basic and pro
    $basicPrice = ProductPrice::create(
        id: ProductPriceId::generate(),
        price: Money::usd(1000),
        interval: ProductPriceInterval::monthly(),
    );
    $proPrice = ProductPrice::create(
        id: ProductPriceId::generate(),
        price: Money::usd(2000),
        interval: ProductPriceInterval::monthly(),
    );
    $product = ProductMother::createPlan()->withPrices($basicPrice, $proPrice);

    // Save product to repository
    $this->productRepository->save($product);

    // Sync product with Stripe
    $productSyncResult = $this->provider->syncProduct($product);

    // Create subscription with pro price
    $subscription = SubscriptionMother::create()->withPrimaryItem(
        SubscriptionItemMother::fromProductPrice($proPrice)
    );

    // Create customer and activate subscription directly in Stripe
    $customerSyncResult = $this->provider->syncCustomer($subscription->billable(), BillableDetailsMother::createMinimal());

    $stripeCustomerId = $customerSyncResult->providerCustomerId();

    // Add a payment method to the customer
    $this->stripe->customers->update($stripeCustomerId, [
        'source' => 'tok_visa',
    ]);

    $stripePriceId = $productSyncResult->syncedPriceIds()[$proPrice->id()->value()];

    $stripeSubscription = $this->stripe->subscriptions->create([
        'customer' => $stripeCustomerId,
        'items' => [['price' => $stripePriceId]],
    ]);

    // Store mappings
    $this->idStore->storeCustomerId($subscription->billable(), $stripeCustomerId);
    $this->idStore->storeSubscriptionId($subscription->id()->value(), $stripeSubscription->id);
    $this->idStore->storeMultipleSubscriptionItemIdMappings([
        $subscription->primaryItem()->id()->value() => $stripeSubscription->items->data[0]->id,
    ]);

    // Swap from pro to basic with no proration (common for downgrades)
    $result = $this->provider->swapItemPrice(
        $subscription,
        $subscription->primaryItem(),
        $basicPrice->id()->value(),
        ['proration_behavior' => 'none']
    );

    // Verify the swap was successful
    expect($result->subscription->id()->value())->toBe($subscription->id()->value())
        ->and($result->subscription->primaryItem()->priceId()->value())->toBe($basicPrice->id()->value());

    // Verify on Stripe side that the price was actually swapped
    $updatedStripeSubscription = $this->stripe->subscriptions->retrieve($stripeSubscription->id);
    $basicPriceStripeId = $this->provider->syncProduct($product)->syncedPriceIds()[$basicPrice->id()->value()];
    expect($updatedStripeSubscription->items->data[0]->price->id)->toBe($basicPriceStripeId);
});

test('cancel subscription at period end', function () {
    $price = ProductPriceMother::createMonthly();
    $product = ProductMother::createPlan()->withPrices($price);
    $subscription = SubscriptionMother::create()->withPrimaryItem(SubscriptionItemMother::fromProductPrice($price));

    $this->provider->syncProduct($product);
    $customerSyncResult = $this->provider->syncCustomer($subscription->billable(), BillableDetailsMother::createMinimal());

    // Add a payment method to the customer
    $this->stripe->customers->update($customerSyncResult->providerCustomerId(), [
        'source' => 'tok_visa',
    ]);

    // Create a subscription in Stripe directly
    $stripeSubscription = $this->stripe->subscriptions->create([
        'customer' => $customerSyncResult->providerCustomerId(),
        'items' => [['price' => $this->idStore->getPriceIds([$price->id()->value()])[$price->id()->value()]]],
    ]);

    // Store mappings
    $this->idStore->storeSubscriptionId($subscription->id()->value(), $stripeSubscription->id);

    // Cancel at period end
    $result = $this->provider->cancel($subscription, true, []);

    // Verify
    expect($result->subscription->cancelAtPeriodEnd())->toBeTrue();

    // Verify on Stripe side
    $updatedStripeSubscription = $this->stripe->subscriptions->retrieve($stripeSubscription->id);
    expect($updatedStripeSubscription->cancel_at_period_end)->toBeTrue();
});

test('undo cancel at period end', function () {
    $price = ProductPriceMother::createMonthly();
    $product = ProductMother::createPlan()->withPrices($price);
    $subscription = SubscriptionMother::create()->withPrimaryItem(SubscriptionItemMother::fromProductPrice($price));

    $this->provider->syncProduct($product);
    $customerSyncResult = $this->provider->syncCustomer($subscription->billable(), BillableDetailsMother::createMinimal());

    // Add a payment method to the customer
    $this->stripe->customers->update($customerSyncResult->providerCustomerId(), [
        'source' => 'tok_visa',
    ]);

    // Create a subscription in Stripe directly
    $stripeSubscription = $this->stripe->subscriptions->create([
        'customer' => $customerSyncResult->providerCustomerId(),
        'items' => [['price' => $this->idStore->getPriceIds([$price->id()->value()])[$price->id()->value()]]],
        'cancel_at_period_end' => true,
    ]);

    // Store mappings
    $this->idStore->storeSubscriptionId($subscription->id()->value(), $stripeSubscription->id);

    // Initial state: subscription is marked to cancel
    $subscription = $subscription->cancel(true);
    expect($subscription->cancelAtPeriodEnd())->toBeTrue();

    // Undo cancel
    $result = $this->provider->doNotCancel($subscription, []);

    // Verify
    expect($result->subscription->cancelAtPeriodEnd())->toBeFalse();

    // Verify on Stripe side
    $updatedStripeSubscription = $this->stripe->subscriptions->retrieve($stripeSubscription->id);
    expect($updatedStripeSubscription->cancel_at_period_end)->toBeFalse();
});

test('undo cancel at period end fails if not scheduled for cancellation', function () {
    $price = ProductPriceMother::createMonthly();
    $product = ProductMother::createPlan()->withPrices($price);
    $subscription = SubscriptionMother::create()->withPrimaryItem(SubscriptionItemMother::fromProductPrice($price));

    $this->provider->syncProduct($product);
    $customerSyncResult = $this->provider->syncCustomer($subscription->billable(), BillableDetailsMother::createMinimal());

    // Add a payment method to the customer
    $this->stripe->customers->update($customerSyncResult->providerCustomerId(), [
        'source' => 'tok_visa',
    ]);

    // Create a subscription in Stripe directly, NOT marked to cancel
    $stripeSubscription = $this->stripe->subscriptions->create([
        'customer' => $customerSyncResult->providerCustomerId(),
        'items' => [['price' => $this->idStore->getPriceIds([$price->id()->value()])[$price->id()->value()]]],
    ]);

    // Store mappings
    $this->idStore->storeSubscriptionId($subscription->id()->value(), $stripeSubscription->id);

    // Try to undo cancel (it should fail because it's not marked to cancel)
    expect(fn() => $this->provider->doNotCancel($subscription, []))
        ->toThrow(DomainException::class, 'Subscription is not scheduled for cancellation.');
});
