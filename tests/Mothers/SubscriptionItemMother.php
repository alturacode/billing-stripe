<?php

declare(strict_types=1);

namespace Tests\Mothers;

use AlturaCode\Billing\Core\Common\Money;
use AlturaCode\Billing\Core\Products\Product;
use AlturaCode\Billing\Core\Products\ProductPrice;
use AlturaCode\Billing\Core\Products\ProductPriceId;
use AlturaCode\Billing\Core\Products\ProductPriceInterval;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionItem;
use AlturaCode\Billing\Core\Subscriptions\SubscriptionItemId;

final readonly class SubscriptionItemMother
{
    public static function createMonthly(): SubscriptionItem
    {
        return SubscriptionItem::create(
            id: SubscriptionItemId::generate(),
            priceId: ProductPriceId::generate(),
            quantity: 1,
            price: Money::usd(1000),
            interval: ProductPriceInterval::monthly(),
        );
    }

    public static function fromProductPrice(ProductPrice $price): SubscriptionItem
    {
        return SubscriptionItem::create(
            id: SubscriptionItemId::generate(),
            priceId: $price->id(),
            quantity: 1,
            price: $price->price(),
            interval: $price->interval()
        );
    }
}