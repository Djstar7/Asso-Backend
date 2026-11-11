<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Suppression d'un compte = anonymisation, jamais effacement.
 *
 * Les données personnelles (identité, contact, adresse, photo, documents de
 * connexion) sont effacées et le compte ne peut plus se connecter ; l'historique
 * financier (commandes, lignes, Wallet, transactions, litiges, forfaits,
 * réservations) reste intact pour la comptabilité et les litiges des autres
 * utilisateurs.
 *
 * Refusée tant que quelque chose est en cours : argent sur le Wallet (disponible
 * ou bloqué), commande payée non terminée (comme acheteur ou vendeur), litige
 * ouvert, retrait en cours, réservation DIASPO / Maritime / Immobilier en cours.
 */
class AccountDeletionService
{
    /** Clés de traduction (auth.account_deletion_blockers.*) de ce qui empêche la suppression. */
    public function blockers(User $user): array
    {
        $blockers = [];

        if ($user->isBackofficeStaff()) {
            $blockers[] = 'staff_account';
        }

        $money = DB::table('wallet_balances')->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('balance', '>', 0)->orWhere('locked_balance', '>', 0))
            ->exists();
        if ($money) {
            $blockers[] = 'wallet_balance';
        }

        $openOrders = fn ($q) => $q->where('orders.payment_status', Order::PAYMENT_PAID)
            ->whereNull('orders.refunded_at')
            ->where(fn ($w) => $w->whereNotIn('orders.status', ['delivered', 'cancelled'])
                ->orWhere('orders.vendor_funds_status', Order::VENDOR_FUNDS_HELD));
        if ($openOrders(DB::table('orders')->where('orders.user_id', $user->id))->exists()) {
            $blockers[] = 'buyer_orders';
        }
        if ($openOrders(DB::table('orders')->whereIn('orders.id', DB::table('order_items')->where('seller_id', $user->id)->select('order_id')))->exists()) {
            $blockers[] = 'seller_orders';
        }

        if (Dispute::withoutGlobalScopes()->whereIn('status', Dispute::OPEN_STATUSES)
            ->where(fn ($q) => $q->where('client_id', $user->id)->orWhere('seller_id', $user->id))->exists()) {
            $blockers[] = 'open_disputes';
        }

        if (DB::table('platform_withdrawals')->where('user_id', $user->id)->whereIn('status', ['pending', 'processing'])->exists()) {
            $blockers[] = 'pending_withdrawals';
        }

        $travel = DB::table('diaspo_bookings')->where('status', 'paid')
            ->where(fn ($q) => $q->where('buyer_user_id', $user->id)->orWhere('seller_user_id', $user->id))->exists()
            || (Schema::hasTable('maritime_bookings') && DB::table('maritime_bookings')->where('sender_user_id', $user->id)
                ->whereIn('status', ['paid', 'dropped_off', 'shipped', 'arrived'])->exists());
        if ($travel) {
            $blockers[] = 'active_bookings';
        }

        if (DB::table('real_estate_reservations')->whereIn('status', ['paid', 'confirmed', 'disputed'])
            ->where(fn ($q) => $q->where('tenant_id', $user->id)->orWhere('payee_id', $user->id))->exists()) {
            $blockers[] = 'active_reservations';
        }

        return $blockers;
    }

    /**
     * Anonymise le compte si rien ne s'y oppose.
     *
     * @return array liste vide si le compte est anonymisé, sinon ce qui l'empêche
     */
    public function delete(User $user, ?User $actor = null): array
    {
        return DB::transaction(function () use ($user, $actor) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->anonymized_at) {
                return [];
            }
            $blockers = $this->blockers($locked);
            if ($blockers !== []) {
                return $blockers;
            }

            $avatar = $locked->avatar;
            $locked->forceFill([
                'first_name' => 'Compte',
                'last_name' => 'supprimé',
                'email' => 'supprime-'.$locked->id.'@deleted.asso.invalid',
                'phone' => null,
                'password' => Str::random(64),
                'remember_token' => null,
                'gender' => null,
                'birth_date' => null,
                'address' => null,
                'latitude' => null,
                'longitude' => null,
                'avatar' => null,
                'company_name' => null,
                'company_logo' => null,
                'otp_code' => null,
                'otp_expires_at' => null,
                'fcm_token' => null,
                'preferences' => null,
                'notification_preferences' => null,
                'stripe_account_holder_name' => null,
                'anonymized_at' => now(),
            ])->save();

            $locked->tokens()->delete();
            DB::table('device_tokens')->where('user_id', $locked->id)->delete();
            // Plus rien de ce compte n'est proposé à la vente.
            DB::table('shops')->where('user_id', $locked->id)->update(['status' => 'inactive', 'updated_at' => now()]);
            DB::table('products')->where('user_id', $locked->id)->update(['status' => 'inactive', 'updated_at' => now()]);

            DB::afterCommit(function () use ($avatar) {
                if ($avatar && ! str_starts_with($avatar, 'http')) {
                    try {
                        \Illuminate\Support\Facades\Storage::disk('public')->delete(ltrim(preg_replace('#^/?storage/#', '', $avatar), '/'));
                    } catch (\Throwable) {
                        // Fichier absent : sans incidence.
                    }
                }
            });

            Log::info('[AccountDeletion] Compte anonymisé', ['user_id' => $locked->id, 'by' => $actor?->id ?? $locked->id]);

            return [];
        });
    }
}
