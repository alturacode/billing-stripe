<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use AlturaCode\Billing\Core\Products\Product;
use AlturaCode\Billing\Core\Products\ProductId;
use AlturaCode\Billing\Core\Products\ProductPriceId;
use AlturaCode\Billing\Core\Products\ProductRepository;
use AlturaCode\Billing\Core\Products\ProductSlug;

final class InMemoryProductRepository implements ProductRepository
{
    private array $products = [];

    public function all(): array
    {
        return array_values($this->products);
    }

    public function find(ProductId $productId): ?Product
    {
        return $this->products[$productId->value()] ?? null;
    }

    public function findByPriceId(ProductPriceId $priceId): ?Product
    {
        foreach ($this->products as $product) {
            foreach ($product->prices() as $price) {
                if ($price->id()->value() === $priceId->value()) {
                    return $product;
                }
            }
        }

        return null;
    }

    public function findBySlug(ProductSlug $slug): ?Product
    {
        foreach ($this->products as $product) {
            if ($product->slug()->value() === $slug->value()) {
                return $product;
            }
        }

        return null;
    }

    public function findMultipleByPriceIds(array $priceIds): array
    {
        $products = [];

        foreach ($priceIds as $priceId) {
            $product = $this->findByPriceId($priceId);
            if ($product && !in_array($product, $products, true)) {
                $products[] = $product;
            }
        }

        return $products;
    }

    public function save(Product $product): void
    {
        $this->products[$product->id()->value()] = $product;
    }
}
