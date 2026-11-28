<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\Suppliers\Cj\CjClient;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Http\Request;

/**
 * Fournisseurs de dropshipping (admin uniquement : routes absentes de
 * config/admin_access.php). Lot 1 : connexion CJ, solde et réglages de prix.
 */
class SupplierController extends Controller
{
    public function index()
    {
        return view('admin.suppliers.index', [
            'cj' => Supplier::forCode(Supplier::CJ),
        ]);
    }

    public function updateCj(Request $request)
    {
        $data = $request->validate([
            'api_key' => 'nullable|string|max:255',
            'is_sandbox' => 'boolean',
            'is_active' => 'boolean',
            'auto_publish' => 'boolean',
            'margin_percent' => 'required|numeric|min:0|max:300',
            'fx_buffer_percent' => 'required|numeric|min:0|max:50',
            'balance_alert_usd' => 'required|numeric|min:0|max:1000000',
        ]);

        $cj = Supplier::forCode(Supplier::CJ);
        $keyChanged = filled($data['api_key'] ?? null) && $data['api_key'] !== $cj->api_key;

        if ($keyChanged) {
            $cj->api_key = trim($data['api_key']);
            $cj->connected_at = null;
            $cj->account_id = null;
            $cj->save();
            (new CjClient($cj))->forgetTokens();
        }

        $active = $request->boolean('is_active');
        if ($active && ! $cj->isConnected()) {
            return back()->withInput()->with('error', 'Testez d’abord la connexion à CJ avant d’activer le fournisseur.');
        }

        $cj->forceFill([
            'is_sandbox' => $request->boolean('is_sandbox'),
            'is_active' => $active,
            'settings' => array_merge($cj->settings ?? [], [
                'margin_percent' => (float) $data['margin_percent'],
                'fx_buffer_percent' => (float) $data['fx_buffer_percent'],
                'balance_alert_usd' => (float) $data['balance_alert_usd'],
                'auto_publish' => $request->boolean('auto_publish'),
            ]),
        ])->save();

        return redirect()->route('admin.suppliers.index')->with('success', $keyChanged
            ? 'Clé API CJ enregistrée. Testez maintenant la connexion.'
            : 'Réglages CJ enregistrés.');
    }

    public function testCj(SupplierManager $suppliers)
    {
        $result = $suppliers->driver(Supplier::CJ)->testConnection();

        if (! $result['ok']) {
            return redirect()->route('admin.suppliers.index')->with('error', $result['message']);
        }

        $balance = $result['balance_usd'] !== null ? number_format($result['balance_usd'], 2, ',', ' ').' USD' : 'inconnu';

        return redirect()->route('admin.suppliers.index')
            ->with('success', "{$result['message']} Solde CJ : {$balance}.");
    }
}
