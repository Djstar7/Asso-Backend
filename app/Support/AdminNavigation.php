<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/** Builds navigation from authorized routes; menu grouping never grants access. */
class AdminNavigation
{
    public function forUser(User $user, Request $request, array $counters = []): array
    {
        $groups = [];
        $hasActiveItem = false;
        foreach (config('admin_navigation', []) as $definition) {
            $items = [];
            foreach ($definition['items'] as $item) {
                if (! $user->canAccessAdminRoute($item['route'])) {
                    continue;
                }
                $scoped = AssoScope::restricts($user);
                if ($scoped && $item['id'] === 'asso_products') {
                    continue;
                }
                $active = $request->routeIs(...$item['match']);
                if (isset($item['filter']) && ! $scoped) {
                    $active = $active && ($request->boolean('asso') === ($item['filter'] === 'asso_products'));
                }
                // Related filtered pages can share a route, but never two current entries.
                $active = $active && ! $hasActiveItem;
                $hasActiveItem = $hasActiveItem || $active;
                $items[] = [
                    'id' => $item['id'],
                    'label' => __('admin_nav.items.'.($scoped && $item['id'] === 'products' ? 'asso_products' : $item['id']), [], $user->preferredLocale()),
                    'url' => route($item['route'], $item['parameters'] ?? []),
                    'keywords' => isset($item['keywords']) ? __($item['keywords'], [], $user->preferredLocale()) : '',
                    'icon' => $item['icon'],
                    'active' => $active,
                    'badge' => $this->badge($item, $counters),
                    'tone' => $item['tone'] ?? 'attention',
                ];
            }
            if ($items !== []) {
                $groups[] = [
                    'id' => $definition['id'],
                    'label' => __('admin_nav.groups.'.$definition['id'], [], $user->preferredLocale()),
                    'icon' => $definition['icon'],
                    'items' => $items,
                ];
            }
        }

        return $groups;
    }

    private function badge(array $item, array $counters): int
    {
        // Called only for visible entries. These counts never escape route permissions.
        $count = match ($item['id']) {
            'listings' => \App\Models\RealEstate\Listing::where('status', 'pending_review')->count(),
            'landlords' => \App\Models\RealEstate\LandlordVerification::where('status', 'pending')->count(),
            'property_disputes' => \App\Models\RealEstate\Dispute::where('status', 'open')->count(),
            'stripe_accounts' => User::whereNotNull('stripe_account_id')->where('stripe_account_status', 'pending')->count(),
            default => $counters[$item['badge'] ?? ''] ?? 0,
        };

        return max(0, (int) $count);
    }
}
