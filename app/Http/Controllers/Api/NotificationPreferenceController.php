<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPushDelivery;
use App\Services\NotificationPreferences;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationPreferenceController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(['success' => true, 'preferences' => NotificationPreferences::for($request->user())]);
    }

    public function update(Request $request)
    {
        $rules = ['enabled' => 'sometimes|required|boolean', 'categories' => 'sometimes|required|array:'.implode(',', NotificationPreferences::CATEGORIES)];
        foreach (NotificationPreferences::CATEGORIES as $category) {
            $rules['categories.'.$category] = 'sometimes|required|boolean';
        }
        $data = $request->validate($rules);
        $preferences = DB::transaction(function () use ($request, $data) {
            $user = $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $current = NotificationPreferences::for($user);
            $current['enabled'] = $data['enabled'] ?? $current['enabled'];
            $current['categories'] = array_replace($current['categories'], $data['categories'] ?? []);
            $user->forceFill(['notification_preferences' => $current])->save();

            return $current;
        });

        return response()->json(['success' => true, 'preferences' => $preferences, 'message' => __('notifications.preferences_saved')]);
    }

    public function diagnostics(Request $request)
    {
        $user = $request->user();

        return response()->json(['success' => true, 'diagnostics' => [
            'active_devices' => $user->deviceTokens()->active()->count(),
            'latest_registration_at' => $user->deviceTokens()->max('last_used_at'),
            'preferences' => NotificationPreferences::for($user),
            'pending_deliveries' => NotificationPushDelivery::whereHas('notification', fn ($q) => $q->where('user_id', $user->id))->whereIn('status', ['pending', 'sending'])->count(),
            'latest_error_code' => NotificationPushDelivery::whereHas('notification', fn ($q) => $q->where('user_id', $user->id))->whereNotNull('error_code')->latest('updated_at')->value('error_code'),
        ]]);
    }
}
