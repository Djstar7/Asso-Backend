<?php

namespace App\Support;

/**
 * Données liées à un utilisateur qui ne doivent jamais disparaître avec lui :
 * argent, commandes, litiges et leurs preuves, partenaires, réservations.
 * Leurs clés étrangères vers users sont en RESTRICT (migration
 * 2026_11_12_100000) ; un compte se supprime par anonymisation
 * (App\Services\AccountDeletionService). Contrôlé par deploy:integrity-check.
 *
 * Restent en cascade : préférences et traces sans valeur comptable (favoris,
 * notifications, jetons d'appareil, mentions « j'aime », alertes, sessions).
 */
class ProtectedUserHistory
{
    /** Table => colonnes vers users. */
    public const COLUMNS = [
        'orders' => ['user_id'],
        'order_items' => ['seller_id'],
        'transactions' => ['buyer_id', 'seller_id'],
        'wallet_transactions' => ['user_id'],
        'wallet_balances' => ['user_id'],
        'disputes' => ['client_id'],
        'package_subscriptions' => ['user_id'],
        'vendor_packages' => ['user_id'],
        'payout_accounts' => ['user_id'],
        'product_boosts' => ['user_id'],
        'affiliate_commissions' => ['affiliate_id', 'referred_user_id'],
        'diaspo_bookings' => ['buyer_user_id', 'seller_user_id'],
        'diaspo_bookings_legacy_preunify' => ['buyer_user_id', 'seller_user_id'],
        'diaspo_offers' => ['user_id'],
        'diaspo_offers_legacy_preunify' => ['user_id'],
        'real_estate_reservations' => ['tenant_id'],
        'deliverer_companies' => ['user_id'],
        'shops' => ['user_id'],
        'products' => ['user_id'],
        'order_ratings' => ['user_id'],
        'product_reviews' => ['user_id'],
        'shop_location_requests' => ['vendor_id'],
        // Preuves en cas de litige.
        'conversations' => ['user1_id', 'user2_id'],
        'messages' => ['sender_id'],
        'support_tickets' => ['user_id'],
        'support_replies' => ['user_id'],
        'exchanges' => ['user1_id', 'user2_id'],
    ];
}
