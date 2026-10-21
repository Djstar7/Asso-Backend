<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Point d’entrée historique : prépare désormais le réseau territorial complet. */
class DataSolexVillesSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SolexNetworkSeeder::class);
    }
}
