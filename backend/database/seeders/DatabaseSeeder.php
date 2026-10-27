<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['name' => 'Everyday Backpack', 'description' => 'A considered carryall for commutes, coffee runs and everything in between.', 'price' => 2499, 'image_url' => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=1000&q=85'],
            ['name' => 'Trail Bottle', 'description' => 'Double-wall stainless steel keeps your daily pour cold or comforting.', 'price' => 899, 'image_url' => 'https://images.unsplash.com/photo-1602143407151-7111542de6e8?auto=format&fit=crop&w=1000&q=85'],
            ['name' => 'Weekend Tote', 'description' => 'Roomy recycled canvas with sturdy handles for market mornings and short escapes.', 'price' => 1299, 'image_url' => 'https://images.unsplash.com/photo-1590874103328-eac38a683ce7?auto=format&fit=crop&w=1000&q=85'],
            ['name' => 'Field Journal Set', 'description' => 'Two soft-cover notebooks for plans, sketches and ideas worth keeping.', 'price' => 599, 'image_url' => 'https://images.unsplash.com/photo-1531346878377-a5be20888e57?auto=format&fit=crop&w=1000&q=85'],
        ];

        $legacyBackpack = Product::query()->where('name', 'The Everyday Backpack')->first();
        $currentBackpack = Product::query()->where('name', 'Everyday Backpack')->exists();
        if ($legacyBackpack && ! $currentBackpack) {
            $legacyBackpack->update($products[0]);
        }

        foreach ($products as $product) {
            Product::updateOrCreate(['name' => $product['name']], $product);
        }
    }
}
