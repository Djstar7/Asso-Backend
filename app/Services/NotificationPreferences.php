<?php

namespace App\Services;

use App\Models\User;

class NotificationPreferences
{
    public const CATEGORIES = ['orders', 'payments', 'messages', 'disputes', 'real_estate', 'marketing', 'system'];

    public static function for(User $user): array
    {
        $saved = $user->notification_preferences ?? [];

        return ['enabled' => (bool) ($saved['enabled'] ?? true), 'categories' => collect(self::CATEGORIES)->mapWithKeys(fn ($c) => [$c => (bool) ($saved['categories'][$c] ?? true)])->all()];
    }

    public static function allows(User $user, string $category): bool
    {
        $preferences = self::for($user);

        return $preferences['enabled'] && ($preferences['categories'][$category] ?? false);
    }

    public static function category(string $type): string
    {
        if (str_contains($type, 'dispute')) {
            return 'disputes';
        }
        if (in_array($type, ['new_product', 'sponsored_product', 'new_diaspo_offer', 'announcement', 'marketing_digest'], true)) {
            return 'marketing';
        }
        if (str_starts_with($type, 'real_estate_') || $type === 'new_real_estate_listing') {
            return 'real_estate';
        }
        if (preg_match('/^(wallet_|payment_|stripe_)/', $type)) {
            return 'payments';
        }
        if (preg_match('/^(order_|vendor_order_|delivery_|new_delivery_|shipment_|maritime_|agency_)/', $type) || $type === 'vendor_funds_released') {
            return 'orders';
        }
        if ($type === 'new_message' || preg_match('/^(chat_|conversation_)/', $type)) {
            return 'messages';
        }

        return 'system';
    }
}
