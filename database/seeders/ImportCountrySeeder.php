<?php

namespace Database\Seeders;

use App\Models\ImportCountry;
use Illuminate\Database\Seeder;

class ImportCountrySeeder extends Seeder
{
    public function run(): void
    {
        $countries = [
            ['code' => 'CN', 'name' => 'Chine',   'flag' => '🇨🇳', 'sort_order' => 1],
            ['code' => 'TR', 'name' => 'Turquie', 'flag' => '🇹🇷', 'sort_order' => 2],
            ['code' => 'AE', 'name' => 'Dubaï',   'flag' => '🇦🇪', 'sort_order' => 3],
        ];

        foreach ($countries as $c) {
            // Idempotent : ne recrée pas si le pays existe déjà (updateOrCreate par code)
            ImportCountry::updateOrCreate(
                ['code' => $c['code']],
                ['name' => $c['name'], 'flag' => $c['flag'], 'sort_order' => $c['sort_order'], 'is_active' => true]
            );
        }
    }
}
