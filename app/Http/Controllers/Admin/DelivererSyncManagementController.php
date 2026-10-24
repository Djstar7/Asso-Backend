<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DelivererSyncCode;
use App\Models\DelivererCodeSync;
use App\Models\DelivererCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DelivererSyncManagementController extends Controller
{
    /**
     * Show all syncs for a specific code
     */
    public function showCodeSyncs(DelivererSyncCode $syncCode)
    {
        $syncCode->load([
            'company',
            'codeSyncs' => function($query) {
                $query->with(['user', 'bannedByUser'])
                      ->orderBy('synced_at', 'desc');
            }
        ]);

        return view('admin.deliverers.code-syncs', compact('syncCode'));
    }

    public function assignAgency(Request $request, DelivererCodeSync $codeSync)
    {
        $data = $request->validate(['delivery_agency_id' => ['nullable', 'integer',
            \Illuminate\Validation\Rule::exists('delivery_agencies', 'id')->where('deliverer_company_id', $codeSync->company_id)
                ->where(fn ($query) => $query->where('is_active', true)->where('is_international_reference', false)),
        ]]);
        $codeSync->update(['delivery_agency_id' => $data['delivery_agency_id'] ?? null]);
        return back()->with('success', 'Rattachement du livreur à l’agence mis à jour.');
    }

    /**
     * Show all syncs for a specific company
     */
    public function showCompanySyncs(DelivererCompany $company)
    {
        $company->load([
            'codeSyncs' => function($query) {
                $query->with(['user', 'syncCode', 'bannedByUser'])
                      ->orderBy('synced_at', 'desc');
            }
        ]);

        return view('admin.deliverers.company-syncs', compact('company'));
    }

    /**
     * Unsync (deactivate) a user from a code
     */
    public function unsyncUser(Request $request, DelivererCodeSync $codeSync)
    {
        if (!$codeSync->is_active) {
            return redirect()->back()
                ->with('error', 'Cet utilisateur est déjà désynchronisé');
        }

        $codeSync->unsync();

        Log::info("Admin {$request->user()->id} unsynced user {$codeSync->user_id} from code {$codeSync->syncCode->sync_code}");

        return redirect()->back()
            ->with('success', "L'utilisateur {$codeSync->user->first_name} {$codeSync->user->last_name} a été désynchronisé avec succès");
    }

    /**
     * Reactivate a sync
     */
    public function reactivateSync(Request $request, DelivererCodeSync $codeSync)
    {
        if ($codeSync->is_banned) {
            return redirect()->back()
                ->with('error', 'Cet utilisateur est banni. Veuillez d\'abord lever le bannissement.');
        }

        if ($codeSync->is_active) {
            return redirect()->back()
                ->with('error', 'Cet utilisateur est déjà actif');
        }

        $codeSync->reactivate();

        Log::info("Admin {$request->user()->id} reactivated sync {$codeSync->id} for user {$codeSync->user_id}");

        return redirect()->back()
            ->with('success', "L'utilisateur {$codeSync->user->first_name} {$codeSync->user->last_name} a été réactivé avec succès");
    }

    /**
     * Ban a user from a code
     */
    public function banUser(Request $request, DelivererCodeSync $codeSync)
    {
        $validated = $request->validate([
            'ban_reason' => 'nullable|string|max:500',
        ]);

        if ($codeSync->is_banned) {
            return redirect()->back()
                ->with('error', 'Cet utilisateur est déjà banni');
        }

        $codeSync->ban(
            $request->user()->id,
            $validated['ban_reason'] ?? 'Aucune raison fournie'
        );

        Log::info("Admin {$request->user()->id} banned user {$codeSync->user_id} from code {$codeSync->syncCode->sync_code}");

        return redirect()->back()
            ->with('success', "L'utilisateur {$codeSync->user->first_name} {$codeSync->user->last_name} a été banni avec succès");
    }

    /**
     * Unban a user from a code
     */
    public function unbanUser(Request $request, DelivererCodeSync $codeSync)
    {
        if (!$codeSync->is_banned) {
            return redirect()->back()
                ->with('error', 'Cet utilisateur n\'est pas banni');
        }

        $codeSync->update([
            'is_banned' => false,
            'banned_at' => null,
            'banned_by' => null,
            'ban_reason' => null,
        ]);

        Log::info("Admin {$request->user()->id} unbanned user {$codeSync->user_id} from code {$codeSync->syncCode->sync_code}");

        return redirect()->back()
            ->with('success', "L'utilisateur {$codeSync->user->first_name} {$codeSync->user->last_name} a été débanni avec succès");
    }

    /**
     * Delete a sync permanently
     */
    public function deleteSync(Request $request, DelivererCodeSync $codeSync)
    {
        $userName = $codeSync->user->first_name . ' ' . $codeSync->user->last_name;
        $syncCodeValue = $codeSync->syncCode->sync_code;

        $codeSync->delete();

        Log::info("Admin {$request->user()->id} deleted sync {$codeSync->id} (User: {$codeSync->user_id}, Code: {$syncCodeValue})");

        return redirect()->back()
            ->with('success', "La synchronisation de {$userName} a été supprimée définitivement");
    }
}
