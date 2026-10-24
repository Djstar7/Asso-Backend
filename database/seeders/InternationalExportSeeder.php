<?php

namespace Database\Seeders;

use App\Services\InternationalCarrierDemoService;
use Illuminate\Database\Seeder;

/** Synthetic international tariffs. Existing administrator edits are preserved. */
class InternationalExportSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $counts = app(InternationalCarrierDemoService::class)->load();
        } catch (\RuntimeException $e) {
            $this->command?->warn($e->getMessage());

            return;
        }
        $this->command?->info("{$counts['destinations']} destinations par transporteur : {$counts['created']} trajets créés, {$counts['converted']} exemples convertis au kilo, {$counts['preserved']} trajets conservés.");
    }
}
