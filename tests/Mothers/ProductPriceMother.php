<?php

declare(strict_types=1);

namespace Tests\Mothers;

use AlturaCode\Billing\Core\Common\BillableIdentity;
use AlturaCode\Billing\Core\Common\Money;
use AlturaCode\Billing\Core\Products\ProductPrice;
use AlturaCode\Billing\Core\Products\ProductPriceId;
use AlturaCode\Billing\Core\Products\ProductPriceInterval;
use AlturaCode\Billing\Core\Subscriptions\Subscription;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionId;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionName;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionProvider;

final readonly class ProductPriceMother
{
    public static function createMonthly(): ProductPrice
    {
        return ProductPrice::create(
            id: ProductPriceId::generate(),
            price: Money::usd(1000),
            interval: ProductPriceInterval::monthly(),
        );
    }
}