<?php

use App\Models\ImportShippingOption;
use App\Support\ImportHub;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import en gros : Chine / Dubaï / Turquie → Douala (boutique « ASSO Import Douala »),
 * puis SOLEX jusqu'au client. La commande garde le prix de chaque étape :
 * `import_shipping_fee` pour le trajet jusqu'à Douala, `base_delivery_price` pour SOLEX.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('import_shipping_fee', 12, 2)->nullable()->after('delivery_fee');
        });

        // Anciennes commandes en gros : leurs frais de livraison étaient le seul trajet import.
        \DB::table('orders')->where('is_wholesale', true)->update(['import_shipping_fee' => \DB::raw('delivery_fee')]);

        // Une seule boutique de réception : tous les produits en gros y sont rattachés.
        if ($hub = ImportHub::ensureShop()) {
            ImportHub::attachWholesaleProducts($hub);
        }

        // Les options d'expédition vont jusqu'à Douala, plus vers « Afrique, Europe… ».
        ImportShippingOption::query()->update(['destinations' => json_encode([ImportHub::CITY])]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('import_shipping_fee');
        });
    }
};
