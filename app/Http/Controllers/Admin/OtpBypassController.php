<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OtpBypassPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class OtpBypassController extends Controller
{
    /**
     * Display a listing of the bypass phone numbers.
     */
    public function index()
    {
        $bypassPhones = OtpBypassPhone::with('addedBy')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('admin.otp-bypass.index', compact('bypassPhones'));
    }

    /**
     * Store a newly created bypass phone number.
     */
    public function store(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|unique:otp_bypass_phones,phone',
            'reason' => 'nullable|string|max:500',
        ], [
            'phone.required' => 'Le numéro de téléphone est requis.',
            'phone.unique' => 'Ce numéro existe déjà dans la liste de bypass.',
            'reason.max' => 'La raison ne peut pas dépasser 500 caractères.',
        ]);

        try {
            // Normalize phone number
            $phone = OtpBypassPhone::normalizePhone($request->phone);

            $bypassPhone = OtpBypassPhone::create([
                'phone' => $phone,
                'reason' => $request->reason,
                'added_by' => Auth::id(),
                'is_active' => true,
            ]);

            Log::info('OTP bypass phone added', [
                'phone' => $phone,
                'added_by' => Auth::id(),
                'admin_email' => Auth::user()->email,
            ]);

            return redirect()
                ->route('admin.otp-bypass.index')
                ->with('success', 'Numéro ajouté avec succès à la liste de bypass OTP.');
        } catch (\Exception $e) {
            Log::error('Error adding OTP bypass phone', [
                'error' => $e->getMessage(),
                'phone' => $request->phone,
            ]);

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Erreur lors de l\'ajout du numéro : ' . $e->getMessage());
        }
    }

    /**
     * Toggle the active status of a bypass phone number.
     */
    public function toggleStatus($id)
    {
        try {
            $bypassPhone = OtpBypassPhone::findOrFail($id);
            $bypassPhone->is_active = !$bypassPhone->is_active;
            $bypassPhone->save();

            $status = $bypassPhone->is_active ? 'activé' : 'désactivé';

            Log::info('OTP bypass phone status toggled', [
                'phone' => $bypassPhone->phone,
                'is_active' => $bypassPhone->is_active,
                'modified_by' => Auth::id(),
            ]);

            return redirect()
                ->route('admin.otp-bypass.index')
                ->with('success', "Le bypass a été {$status} avec succès.");
        } catch (\Exception $e) {
            Log::error('Error toggling OTP bypass phone status', [
                'error' => $e->getMessage(),
                'id' => $id,
            ]);

            return redirect()
                ->back()
                ->with('error', 'Erreur lors du changement de statut : ' . $e->getMessage());
        }
    }

    /**
     * Update the specified bypass phone number.
     */
    public function update(Request $request, $id)
    {
        $bypassPhone = OtpBypassPhone::findOrFail($id);

        $request->validate([
            'phone' => 'required|string|unique:otp_bypass_phones,phone,' . $id,
            'reason' => 'nullable|string|max:500',
        ], [
            'phone.required' => 'Le numéro de téléphone est requis.',
            'phone.unique' => 'Ce numéro existe déjà dans la liste de bypass.',
            'reason.max' => 'La raison ne peut pas dépasser 500 caractères.',
        ]);

        try {
            $phone = OtpBypassPhone::normalizePhone($request->phone);

            $bypassPhone->update([
                'phone' => $phone,
                'reason' => $request->reason,
            ]);

            Log::info('OTP bypass phone updated', [
                'id' => $id,
                'phone' => $phone,
                'modified_by' => Auth::id(),
            ]);

            return redirect()
                ->route('admin.otp-bypass.index')
                ->with('success', 'Numéro mis à jour avec succès.');
        } catch (\Exception $e) {
            Log::error('Error updating OTP bypass phone', [
                'error' => $e->getMessage(),
                'id' => $id,
            ]);

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Erreur lors de la mise à jour : ' . $e->getMessage());
        }
    }

    /**
     * Remove the specified bypass phone number.
     */
    public function destroy($id)
    {
        try {
            $bypassPhone = OtpBypassPhone::findOrFail($id);
            $phone = $bypassPhone->phone;

            $bypassPhone->delete();

            Log::info('OTP bypass phone deleted', [
                'phone' => $phone,
                'deleted_by' => Auth::id(),
            ]);

            return redirect()
                ->route('admin.otp-bypass.index')
                ->with('success', 'Numéro supprimé avec succès de la liste de bypass.');
        } catch (\Exception $e) {
            Log::error('Error deleting OTP bypass phone', [
                'error' => $e->getMessage(),
                'id' => $id,
            ]);

            return redirect()
                ->back()
                ->with('error', 'Erreur lors de la suppression : ' . $e->getMessage());
        }
    }
}
