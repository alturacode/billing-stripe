<?php

declare(strict_types=1);

namespace Tests\Mothers;

use AlturaCode\Billing\Core\Products\Product;
use AlturaCode\Billing\Core\Products\ProductId;
use AlturaCode\Billing\Core\Products\ProductKind;
use AlturaCode\Billing\Core\Products\ProductSlug;

final readonly class ProductMother
{
    public static function createPlan(): Product
    {
        return Product::create(
            id: ProductId::generate(),
            kind: ProductKind::Plan,
            slug: ProductSlug::fromString('plan'),
            name: 'Plan',
            description: 'Plan description'
        );
    }
}