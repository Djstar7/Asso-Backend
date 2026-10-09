<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\FirebaseMessagingService;
use Illuminate\Http\Request;

class ShopVerificationController extends Controller
{
    /**
     * Verify/Approve a shop
     */
    public function verify($shopId)
    {
        $shop = Shop::findOrFail($shopId);

        if ($shop->isVerified()) {
            return redirect()->back()
                ->with('info', "La boutique {$shop->name} est déjà vérifiée.");
        }

        $shop->update([
            'verified_at' => now(),
            'verified_by' => auth()->id(),
            'rejection_reason' => null,
            'rejected_at' => null,
            'rejected_by' => null,
            'status' => 'active',
        ]);

        // Send FCM notification to vendor
        try {
            $fcmService = app(FirebaseMessagingService::class);
            $fcmService->sendToUser(
                $shop->user,
                $shop->user->localized('notifications.shop_verified.title'),
                $shop->user->localized('notifications.shop_verified.body', ['shop' => $shop->name]),
                [
                    'type' => 'shop_verified',
                    'shop_id' => (string) $shop->id,
                    'action' => 'open_vendor_dashboard',
                ]
            );

            \Log::info("[SHOP_VERIFICATION] FCM notification sent to user {$shop->user_id}");
        } catch (\Exception $e) {
            \Log::error("[SHOP_VERIFICATION] Failed to send FCM notification: {$e->getMessage()}");
        }

        \Log::info("[SHOP_VERIFICATION] Shop verified and activated", [
            'shop_id' => $shop->id,
            'shop_name' => $shop->name,
            'verified_by' => auth()->id(),
            'status' => 'active',
        ]);

        return redirect()->back()
            ->with('success', "Boutique {$shop->name} vérifiée et activée avec succès !");
    }

    /**
     * Reject a shop
     */
    public function reject(Request $request, $shopId)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $shop = Shop::findOrFail($shopId);

        $shop->update([
            'rejection_reason' => $request->reason,
            'rejected_at' => now(),
            'rejected_by' => auth()->id(),
            'verified_at' => null,
            'verified_by' => null,
            'status' => 'inactive',
        ]);

        // Send FCM notification to vendor
        try {
            $fcmService = app(FirebaseMessagingService::class);
            $fcmService->sendToUser(
                $shop->user,
                $shop->user->localized('notifications.shop_rejected.title'),
                $shop->user->localized('notifications.shop_rejected.body', ['shop' => $shop->name, 'reason' => $request->reason]),
                [
                    'type' => 'shop_rejected',
                    'shop_id' => (string) $shop->id,
                    'reason' => $request->reason,
                    'action' => 'open_vendor_dashboard',
                ]
            );

            \Log::info("[SHOP_VERIFICATION] FCM notification sent to user {$shop->user_id}");
        } catch (\Exception $e) {
            \Log::error("[SHOP_VERIFICATION] Failed to send FCM notification: {$e->getMessage()}");
        }

        \Log::info("[SHOP_VERIFICATION] Shop rejected", [
            'shop_id' => $shop->id,
            'shop_name' => $shop->name,
            'reason' => $request->reason,
            'rejected_by' => auth()->id(),
        ]);

        return redirect()->back()
            ->with('success', "Boutique {$shop->name} rejetée");
    }

    /**
     * Toggle shop status (activate/deactivate)
     */
    public function toggleStatus($shopId)
    {
        $shop = Shop::findOrFail($shopId);

        $newStatus = $shop->status === 'active' ? 'inactive' : 'active';

        $shop->update([
            'status' => $newStatus,
        ]);

        // Send FCM notification to vendor
        try {
            $fcmService = app(FirebaseMessagingService::class);

            if ($newStatus === 'active') {
                $fcmService->sendToUser(
                    $shop->user,
                    $shop->user->localized('notifications.shop_activated.title'),
                    $shop->user->localized('notifications.shop_activated.body', ['shop' => $shop->name]),
                    [
                        'type' => 'shop_activated',
                        'shop_id' => (string) $shop->id,
                        'action' => 'open_vendor_dashboard',
                    ]
                );
            } else {
                $fcmService->sendToUser(
                    $shop->user,
                    $shop->user->localized('notifications.shop_deactivated.title'),
                    $shop->user->localized('notifications.shop_deactivated.body', ['shop' => $shop->name]),
                    [
                        'type' => 'shop_deactivated',
                        'shop_id' => (string) $shop->id,
                        'action' => 'open_vendor_dashboard',
                    ]
                );
            }

            \Log::info("[SHOP_STATUS] FCM notification sent to user {$shop->user_id}");
        } catch (\Exception $e) {
            \Log::error("[SHOP_STATUS] Failed to send FCM notification: {$e->getMessage()}");
        }

        $message = $newStatus === 'active'
            ? "Boutique {$shop->name} activée"
            : "Boutique {$shop->name} désactivée";

        return redirect()->back()
            ->with('success', $message);
    }
}
