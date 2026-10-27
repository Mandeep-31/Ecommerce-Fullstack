<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(1, 5),
            'total_amount' => fake()->randomFloat(2, 100, 50000),
            'transaction_uuid' => Str::uuid()->toString(),
            'status' => 'PENDING',
            'esewa_ref' => null,
        ];
    }
}
