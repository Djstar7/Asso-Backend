<?php

use App\Models\RealEstate\Cabinet;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('real_estate_reservations', function (Blueprint $table) {
            $table->string('partner_cabinet_name')->nullable();
            $table->string('net_payee_kind', 20)->nullable();
        });

        DB::transaction(function () {
            Setting::set('immo_asso_markup_rate', 10, 'string', 'real_estate', 'Part ASSO (%)');
            Setting::set('immo_partner_commission_rate', 5, 'string', 'real_estate', 'Commission du cabinet partenaire (%)');
            // Cette migration précède l'ajout de deleted_at : ne pas dépendre
            // du scope SoftDeletes du modèle actuel lors d'une installation neuve.
            $defaultId = (int) Setting::get('immo_default_cabinet_id', 0);
            $cabinets = Cabinet::withTrashed()->where('is_active', true);
            $cabinet = $defaultId > 0 ? $cabinets->find($defaultId) : $cabinets->orderBy('id')->first();
            if (! $cabinet) {
                return;
            }
            if (! $cabinet->user_id) {
                $user = $cabinet->email ? User::where('email', $cabinet->email)->first() : null;
                if (! $user) {
                    $user = new User;
                    $user->forceFill([
                        'first_name' => Str::limit($cabinet->name, 60, ''),
                        'last_name' => 'Cabinet',
                        'email' => $cabinet->email ?: 'cabinet-'.Str::lower(Str::random(16)).'@partners.asso.app',
                        'password' => Hash::make(Str::random(32)),
                    ])->save();
                }
                $cabinet->update(['user_id' => $user->id]);
            }
            Setting::set('immo_default_cabinet_id', $cabinet->id, 'integer', 'real_estate', 'Cabinet partenaire par défaut');
            // Le rattachement des annonces ne modifie aucun paiement historique.
            DB::table('real_estate_listings')->whereNull('cabinet_id')->update(['cabinet_id' => $cabinet->id]);
        });
    }

    public function down(): void
    {
        Schema::table('real_estate_reservations', function (Blueprint $table) {
            $table->dropColumn(['partner_cabinet_name', 'net_payee_kind']);
        });
        // Conserver le compte et les rattachements : ils peuvent porter des fonds.
    }
};
