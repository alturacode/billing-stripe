<?php

declare(strict_types=1);

namespace AlturaCode\Billing\Stripe;

use AlturaCode\Billing\Core\Common\BillableDetails;
use AlturaCode\Billing\Core\Common\BillableIdentity;
use AlturaCode\Billing\Core\Products\Product;
use AlturaCode\Billing\Core\Products\ProductPrice;
use AlturaCode\Billing\Core\Provider\BillingProvider;
use AlturaCode\Billing\Core\Provider\BillingProviderResult;
use AlturaCode\Billing\Core\Provider\CustomerAwareBillingProvider;
use AlturaCode\Billing\Core\Provider\CustomerSyncResult;
use AlturaCode\Billing\Core\Provider\PausableBillingProvider;
use AlturaCode\Billing\Core\Provider\ProductAwareBillingProvider;
use AlturaCode\Billing\Core\Provider\ProductSyncResult;
use AlturaCode\Billing\Core\Subscriptions\Subscription;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionRepository;
use Exception;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

final readonly class StripeBillingProvider implements
    BillingProvider,
    CustomerAwareBillingProvider,
    PausableBillingProvider,
    ProductAwareBillingProvider
{
    public function __construct(
        private StripeClient       $stripeClient,
        private StripeIdStore      $ids,
        private CreateSubscription $createSubscription,
        private SubscriptionRepository $subscriptionRepository,
    )
    {
    }

    public function create(Subscription $subscription, array $options = []): BillingProviderResult
    {
        if ($subscription->isFree()) {
            $subscription = $subscription->activate();
            $this->subscriptionRepository->save($subscription);
            return BillingProviderResult::completed($subscription);
        }

        return $this->createSubscription->create($subscription, $options);
    }

    /**
     * @throws ApiErrorException
     */
    public function cancel(Subscription $subscription, bool $atPeriodEnd, array $options): BillingProviderResult
    {
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
        $stripeSubscriptionId = $this->ids->requireSubscriptionId($subscription);

        if (!empty($options['clear_cancel_at_period_end'])) {
            $update['cancel_at_period_end'] = false;
        }

        // Clear pause_collection by setting it to null to resume charges
        $update['pause_collection'] = null;

        $this->stripeClient->subscriptions->update($stripeSubscriptionId, $update);
        return BillingProviderResult::completed($subscription->resume());
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
                    $this->ids->storePriceId($price->id()->value(), $stripePrice->id);
                    $result = $result->markSyncedPrice($price->id()->value(), $stripePrice->id);
                } catch (Exception $e) {
                    $result = $result->markFailedPrice($price->id()->value(), $e->getMessage());
                }
            }
        }

        return $result;
    }
}