<?php

declare(strict_types=1);

namespace AlturaCode\Billing\Stripe;

use AlturaCode\Billing\Core\Common\BillableDetails;
use AlturaCode\Billing\Core\Common\BillableIdentity;
use AlturaCode\Billing\Core\Products\Product;
use AlturaCode\Billing\Core\Products\ProductFeature;
use AlturaCode\Billing\Core\Products\ProductPrice;
use AlturaCode\Billing\Core\Products\ProductPriceId;
use AlturaCode\Billing\Core\Products\ProductRepository;
use AlturaCode\Billing\Core\Provider\BillingProvider;
use AlturaCode\Billing\Core\Provider\BillingProviderResult;
use AlturaCode\Billing\Core\Provider\CustomerAwareBillingProvider;
use AlturaCode\Billing\Core\Provider\CustomerSyncResult;
use AlturaCode\Billing\Core\Provider\PausableBillingProvider;
use AlturaCode\Billing\Core\Provider\ProductAwareBillingProvider;
use AlturaCode\Billing\Core\Provider\ProductSyncResult;
use AlturaCode\Billing\Core\Provider\SwappableItemPriceBillingProvider;
use AlturaCode\Billing\Core\Subscriptions\Subscription;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionItem;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionItemEntitlement;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionItemEntitlementId;
use Exception;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

final readonly class StripeBillingProvider implements
    BillingProvider,
    CustomerAwareBillingProvider,
    PausableBillingProvider,
    ProductAwareBillingProvider,
    SwappableItemPriceBillingProvider
{
    public function __construct(
        private StripeClient       $stripeClient,
        private StripeIdStore      $ids,
        private CreateSubscription $createSubscription,
        private ProductRepository  $productRepository,
    )
    {
    }

    public function create(Subscription $subscription, array $options = []): BillingProviderResult
    {
        if ($subscription->isFree()) {
            $subscription = $subscription->activate();
            return BillingProviderResult::completed($subscription);
        }

        return $this->createSubscription->create($subscription, $options);
    }

    /**
     * @throws ApiErrorException
     */
    public function cancel(Subscription $subscription, bool $atPeriodEnd, array $options): BillingProviderResult
    {
        if ($subscription->isFree()) {
            return BillingProviderResult::completed($subscription->cancel($atPeriodEnd));
        }

        $stripeSubscriptionId = $this->ids->requireSubscriptionId($subscription);

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
        if ($subscription->isFree()) {
            return BillingProviderResult::completed($subscription->pause());
        }

        $stripeSubscriptionId = $this->ids->requireSubscriptionId($subscription);

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
        if ($subscription->isFree()) {
            return BillingProviderResult::completed($subscription->resume());
        }

        $stripeSubscriptionId = $this->ids->requireSubscriptionId($subscription);

        if (!empty($options['clear_cancel_at_period_end'])) {
            $update['cancel_at_period_end'] = false;
        }

        // Clear pause_collection by setting it to null to resume charges
        $update['pause_collection'] = null;

        $this->stripeClient->subscriptions->update($stripeSubscriptionId, $update);
        return BillingProviderResult::completed($subscription->resume());
    }

    /**
     * @throws ApiErrorException
     */
    public function swapItemPrice(
        Subscription     $subscription,
        SubscriptionItem $subscriptionItem,
        string           $newPriceId,
        array            $options = []
    ): BillingProviderResult
    {
        // Retrieve the product by the new price ID to get the price details
        $product = $this->productRepository->findByPriceId(ProductPriceId::fromString($newPriceId));

        if (!$product) {
            throw new Exception("Product not found for price ID: $newPriceId");
        }

        // Find the specific price from the product
        $newPrice = null;
        foreach ($product->prices() as $price) {
            if ($price->id()->value() === $newPriceId) {
                $newPrice = $price;
                break;
            }
        }

        if (!$newPrice) {
            throw new Exception("Price not found in product: $newPriceId");
        }

        $newSubscription = $subscription->changeItemPrice($subscriptionItem->id(), $newPrice->id(), $newPrice->price(), array_map(fn(ProductFeature $feature) => SubscriptionItemEntitlement::create(
            id: SubscriptionItemEntitlementId::generate(),
            key: $feature->key(),
            value: $feature->value(),
        ), $product->features()));

        if ($subscription->isFree()) {
            return BillingProviderResult::completed($newSubscription);
        }

        $stripeSubscriptionItemId = $this->ids->requireSubscriptionItemId($subscriptionItem);

        // Get the Stripe price ID for the new price
        $stripePriceId = $this->ids->getPriceIds([$newPriceId])[$newPriceId] ?? null;

        if (!$stripePriceId) {
            throw new Exception("Stripe price ID mapping not found for price: $newPriceId");
        }

        // Update the subscription item with the new price
        $this->stripeClient->subscriptionItems->update($stripeSubscriptionItemId, [
            'price' => $stripePriceId,
            'proration_behavior' => $options['proration_behavior'] ?? 'create_prorations',
        ]);

        return BillingProviderResult::completed($newSubscription);
    }

    public function syncCustomer(
        BillableIdentity $billable,
        ?BillableDetails $details = null,
        array            $options = []
    ): CustomerSyncResult
    {
        $stripeCustomerId = $this->ids->getCustomerId($billable);

        $data = [
            'email' => $details->email(),
            'phone' => $details->phone(),
            'name' => $details->displayName(),
            'preferred_locales' => $details->locales(),
        ];

        if (!$stripeCustomerId) {
            $stripeCustomer = $this->stripeClient->customers->create($data);
            $this->ids->storeCustomerId($billable, $stripeCustomer->id);
            return CustomerSyncResult::completed($stripeCustomer->id, [
                'customer' => $stripeCustomer,
            ]);
        }

        $stripeCustomer = $this->stripeClient->customers->update($stripeCustomerId, $data);
        return CustomerSyncResult::completed($stripeCustomerId, [
            'customer' => $stripeCustomer,
        ]);
    }

    public function syncProduct(Product $product, array $options = []): ProductSyncResult
    {
        $stripeProductId = $this->ids->getProductId($product->id()->value());
        return $this->syncProductWithStripe(ProductSyncResult::makeEmpty(), $product, $stripeProductId);
    }

    /**
     * @throws ApiErrorException
     */
    public function syncProducts(array $products, array $options = []): ProductSyncResult
    {
        $internalToStripeIdMap = $this->ids->getProductIds(
            array_map(fn(Product $product) => $product->id()->value(), $products)
        );

        $result = ProductSyncResult::makeEmpty();

        foreach ($products as $product) {
            $stripeProductId = $internalToStripeIdMap[$product->id()->value()] ?? null;
            $result = $this->syncProductWithStripe($result, $product, $stripeProductId);
        }

        return $result;
    }

    private function syncProductWithStripe(ProductSyncResult $result, Product $product, ?string $stripeProductId): ProductSyncResult
    {
        $data = [
            'name' => $product->name(),
            'description' => $product->description(),
            'active' => true, // @todo add once core implements product lifecycle
            'metadata' => [
                'internal_product_id' => $product->id()->value(),
            ],
        ];

        try {
            if (!$stripeProductId) {
                $stripeProduct = $this->stripeClient->products->create($data);
                $stripeProductId = $stripeProduct->id;
                $this->ids->storeProductId($product->id()->value(), $stripeProductId);
            } else {
                $this->stripeClient->products->update($stripeProductId, $data);
            }
        } catch (Exception $e) {
            $result = $result->markFailedProduct($product->id()->value(), $e->getMessage());
        }

        $result = $result->markSyncedProduct($product->id()->value(), $stripeProductId);
        return $this->syncProductPricesWithStripe($result, $stripeProductId, $product->prices());
    }

    private function syncProductPricesWithStripe(ProductSyncResult $result, string $stripeProductId, array $prices): ProductSyncResult
    {
        $internalToStripeIdMap = $this->ids->getPriceIds(
            array_map(fn(ProductPrice $price) => $price->id()->value(), $prices)
        );

        foreach ($prices as $price) {
            $stripePriceId = $internalToStripeIdMap[$price->id()->value()] ?? null;

            // Currently, we only sync by creating the price if it doesn't exist'. We don't delete or update prices.
            if (!$stripePriceId) {
                try {
                    $stripePrice = $this->stripeClient->prices->create([
                        'recurring' => [
                            'interval' => $price->interval()->type(),
                            'interval_count' => $price->interval()->count(),
                        ],
                        'unit_amount' => $price->price()->amount(),
                        'currency' => $price->price()->currency(),
                        'product' => $stripeProductId,
                        'active' => true,
                    ]);
                    $stripePriceId = $stripePrice->id;
                    $this->ids->storePriceId($price->id()->value(), $stripePriceId);
                } catch (Exception $e) {
                    $result = $result->markFailedPrice($price->id()->value(), $e->getMessage());
                    continue;
                }
            }

            $result = $result->markSyncedPrice($price->id()->value(), $stripePriceId);
        }

        return $result;
    }

    /**
     * @throws ApiErrorException
     */
    public function doNotCancel(Subscription $subscription, array $options): BillingProviderResult
    {
        if ($subscription->isFree()) {
            return BillingProviderResult::completed($subscription->doNotCancel());
        }

        $stripeSubscriptionId = $this->ids->requireSubscriptionId($subscription);

        $this->stripeClient->subscriptions->update($stripeSubscriptionId, [
            'cancel_at_period_end' => false,
        ]);

        return BillingProviderResult::completed($subscription->doNotCancel());
    }
}