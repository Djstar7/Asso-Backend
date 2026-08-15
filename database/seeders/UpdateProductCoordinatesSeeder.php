<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class UpdateProductCoordinatesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Base coordinates for Yaoundé, Cameroun
        $baseLatitude = 3.8480;
        $baseLongitude = 11.5021;

        // Get all products for shop_id 10 (or all products without coordinates)
        $products = Product::where('shop_id', 10)
            ->whereNull('latitude')
            ->orWhereNull('longitude')
            ->get();

        $this->command->info("Found {$products->count()} products to update with GPS coordinates.");

        foreach ($products as $product) {
            // Generate random coordinates around Yaoundé (~2km radius)
            $latitude = $baseLatitude + (mt_rand(-200, 200) / 10000);
            $longitude = $baseLongitude + (mt_rand(-200, 200) / 10000);

            $product->update([
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);

            $this->command->info("Updated product: {$product->name} with coordinates ({$latitude}, {$longitude})");
        }

        $this->command->info('All products updated successfully with GPS coordinates!');
    }
}
