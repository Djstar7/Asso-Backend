<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VendorPackage;
use App\Services\InvoiceService;
use App\Services\InvoiceGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoiceController extends Controller
{
    protected InvoiceService $invoiceService;
    protected InvoiceGenerator $invoiceGenerator;

    public function __construct(InvoiceService $invoiceService, InvoiceGenerator $invoiceGenerator)
    {
        $this->invoiceService = $invoiceService;
        $this->invoiceGenerator = $invoiceGenerator;
    }

    /**
     * Show package invoice
     */
    public function showPackageInvoice($token)
    {
        try {
            // Decode token
            $decoded = base64_decode($token);
            [$vendorPackageId, $paymentReference] = explode(':', $decoded);

            // Find vendor package
            $vendorPackage = VendorPackage::with(['package', 'user'])
                ->where('id', $vendorPackageId)
                ->where('payment_reference', $paymentReference)
                ->firstOrFail();

            // Get wallet transaction
            $transaction = $vendorPackage->user->walletTransactions()
                ->where('reference_type', 'vendor_package')
                ->where('reference_id', $vendorPackage->id)
                ->where('type', 'debit')
                ->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction non trouvée',
                ], 404);
            }

            // Generate invoice data
            $invoiceData = $this->invoiceService->generatePackageInvoice($vendorPackage, $transaction);

            return response()->json([
                'success' => true,
                'invoice' => $invoiceData,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Facture non trouvée',
            ], 404);
        }
    }

    /**
     * View invoice HTML for a vendor package (authenticated)
     */
    public function download(Request $request, $vendorPackageId)
    {
        $user = $request->user();

        try {
            // Find vendor package
            $vendorPackage = VendorPackage::findOrFail($vendorPackageId);

            // Ensure user owns this package
            if ($vendorPackage->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé à cette facture',
                ], 403);
            }

            Log::info('[INVOICE] Generating invoice HTML', [
                'vendor_package_id' => $vendorPackage->id,
                'user_id' => $user->id,
            ]);

            $html = $this->invoiceGenerator->generateInvoiceHtml($vendorPackage);

            // Return HTML for WebView display
            return response($html)
                ->header('Content-Type', 'text/html; charset=UTF-8');

        } catch (\Exception $e) {
            Log::error('[INVOICE] Error generating invoice', [
                'vendor_package_id' => $vendorPackageId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération de la facture',
            ], 500);
        }
    }

    /**
     * Download invoice PDF for a vendor package (authenticated)
     */
    public function downloadPdf(Request $request, $vendorPackageId)
    {
        $user = $request->user();

        Log::info('[INVOICE] 📥 PDF Download request received', [
            'vendor_package_id' => $vendorPackageId,
            'user_id' => $user->id,
        ]);

        try {
            // Find vendor package
            $vendorPackage = VendorPackage::findOrFail($vendorPackageId);

            // Ensure user owns this package
            if ($vendorPackage->user_id !== $user->id) {
                Log::warning('[INVOICE] ⚠️ Unauthorized access attempt', [
                    'vendor_package_id' => $vendorPackageId,
                    'user_id' => $user->id,
                    'package_owner_id' => $vendorPackage->user_id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé à cette facture',
                ], 403);
            }

            Log::info('[INVOICE] 📄 Generating invoice PDF', [
                'vendor_package_id' => $vendorPackage->id,
                'user_id' => $user->id,
            ]);

            $html = $this->invoiceGenerator->generateInvoiceHtml($vendorPackage);

            Log::info('[INVOICE] 🔧 HTML generated, creating PDF...');

            // Generate PDF from HTML
            $pdf = Pdf::loadHTML($html)
                ->setPaper('a4', 'portrait')
                ->setOption('isHtml5ParserEnabled', true)
                ->setOption('isRemoteEnabled', true);

            $filename = 'facture-' . $vendorPackage->payment_reference . '.pdf';

            Log::info('[INVOICE] ✅ PDF generated successfully', [
                'filename' => $filename,
            ]);

            return $pdf->download($filename);

        } catch (\Exception $e) {
            Log::error('[INVOICE] ❌ Error generating invoice PDF', [
                'vendor_package_id' => $vendorPackageId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération de la facture: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user's invoice history
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $type = $request->query('type', 'all');
        $perPage = min($request->query('per_page', 20), 100);

        Log::info('[INVOICE] ========== GET INVOICE HISTORY ==========', [
            'user_id' => $user->id,
            'type' => $type,
            'per_page' => $perPage,
        ]);

        $invoices = collect();

        // Vendor packages invoices
        if (in_array($type, ['all', 'package'])) {
            $packages = $user->vendorPackages()
                ->where('status', 'active')
                ->whereNotNull('payment_reference')
                ->with('package')
                ->get()
                ->map(function ($vp) {
                    return [
                        'id' => $vp->id,
                        'type' => 'vendor_package',
                        'invoice_number' => 'INV-PKG-' . $vp->payment_reference,
                        'date' => $vp->purchased_at->toIso8601String(),
                        'amount' => $vp->package ? (float) $vp->package->price : 0,
                        'status' => 'paid',
                        'description' => $vp->package ? $vp->package->name : ($vp->custom_name ?? 'Package Vendeur'),
                        'payment_method' => 'wallet',
                        'download_url' => url("/api/v1/invoices/download/{$vp->id}"),
                        'pdf_url' => url("/api/v1/invoices/pdf/{$vp->id}"),
                    ];
                });

            $invoices = $invoices->merge($packages);
            Log::info('[INVOICE] Vendor packages found', ['count' => $packages->count()]);
        }

        // Wallet transactions (recharges)
        if (in_array($type, ['all', 'wallet'])) {
            $walletTxs = $user->walletTransactions()
                ->where('type', 'credit')
                ->where('status', 'completed')
                ->whereNotNull('payment_id')
                ->with('payment')
                ->get()
                ->map(function ($tx) {
                    return [
                        'id' => $tx->id,
                        'type' => 'wallet_recharge',
                        'invoice_number' => 'INV-WALLET-' . $tx->id,
                        'date' => $tx->created_at->toIso8601String(),
                        'amount' => (float) $tx->amount,
                        'status' => 'paid',
                        'description' => 'Recharge Wallet ' . ucfirst($tx->provider),
                        'payment_method' => $tx->provider,
                    ];
                });

            $invoices = $invoices->merge($walletTxs);
            Log::info('[INVOICE] Wallet recharges found', ['count' => $walletTxs->count()]);
        }

        // Sort by date (most recent first)
        $invoices = $invoices->sortByDesc('date')->values();

        // Manual pagination
        $page = max(1, (int) $request->query('page', 1));
        $total = $invoices->count();
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $offset = ($page - 1) * $perPage;

        $paginatedInvoices = $invoices->slice($offset, $perPage)->values();

        Log::info('[INVOICE] Invoice history retrieved', [
            'user_id' => $user->id,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'returned' => $paginatedInvoices->count(),
        ]);

        return response()->json([
            'success' => true,
            'invoices' => [
                'current_page' => $page,
                'data' => $paginatedInvoices,
                'total' => $total,
                'per_page' => $perPage,
                'last_page' => $lastPage,
                'from' => $total > 0 ? $offset + 1 : 0,
                'to' => $total > 0 ? min($offset + $perPage, $total) : 0,
            ],
        ]);
    }
}
