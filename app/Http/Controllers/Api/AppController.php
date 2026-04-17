<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class AppController extends Controller
{
    /**
     * Get app information and about page
     */
    public function about()
    {
        $appName = Setting::get('app_name', 'ASSO');
        $appLogo = Setting::get('app_logo');
        $contactEmail = Setting::get('contact_email');
        $contactPhone = Setting::get('contact_phone');
        $contactAddress = Setting::get('contact_address');

        return response()->json([
            'success' => true,
            'about' => [
                'app_name' => $appName,
                'version' => config('app.version', '1.0.0'),
                'build_number' => config('app.build_number', '1'),
                'description' => Setting::get('app_description', 'Marketplace camerounaise'),
                'logo' => $appLogo ? asset('storage/' . $appLogo) : null,
                'contact' => [
                    'email' => $contactEmail,
                    'phone' => $contactPhone,
                    'address' => $contactAddress,
                    'website' => config('app.url'),
                ],
                'legal' => [
                    'terms_url' => config('app.url') . '/terms',
                    'privacy_url' => config('app.url') . '/privacy',
                    'licenses_url' => config('app.url') . '/licenses',
                ],
                'social' => [
                    'facebook' => Setting::get('social_facebook'),
                    'twitter' => Setting::get('social_twitter'),
                    'instagram' => Setting::get('social_instagram'),
                ],
                'credits' => [
                    'developed_by' => Setting::get('developed_by', 'ASSO Team'),
                    'copyright' => '© ' . date('Y') . ' ASSO. Tous droits réservés.',
                ],
            ],
        ]);
    }

    /**
     * Get app version info
     */
    public function version()
    {
        $currentVersion = config('app.version', '1.0.0');
        $latestVersion = config('app.latest_version', $currentVersion);
        $minVersion = config('app.min_version', '1.0.0');

        $updateRequired = version_compare($currentVersion, $minVersion, '<');
        $updateAvailable = version_compare($currentVersion, $latestVersion, '<');

        return response()->json([
            'success' => true,
            'version' => [
                'current' => $currentVersion,
                'build_number' => config('app.build_number', '1'),
                'min_supported' => $minVersion,
                'latest_available' => $latestVersion,
                'update_required' => $updateRequired,
                'update_available' => $updateAvailable,
                'changelog_url' => config('app.url') . '/changelog',
            ],
        ]);
    }
}
