<?php

namespace App\Services;

use App\Models\VendorPackage;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    /**
     * Generate invoice data for a package subscription
     */
    public function generatePackageInvoice(VendorPackage $vendorPackage, WalletTransaction $transaction): array
    {
        $user = $vendorPackage->user;
        $package = $vendorPackage->package;

        $invoiceNumber = 'INV-' . date('Ymd') . '-' . str_pad($vendorPackage->id, 6, '0', STR_PAD_LEFT);

        Log::info('[InvoiceService] Generating package invoice', [
            'vendor_package_id' => $vendorPackage->id,
            'invoice_number' => $invoiceNumber,
        ]);

        return [
            'invoice_number' => $invoiceNumber,
            'invoice_date' => now()->format('d/m/Y H:i'),
            'payment_reference' => $vendorPackage->payment_reference,

            // Client info
            'client' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? 'N/A',
            ],

            // Package details
            'package' => [
                'name' => $package->name,
                'description' => $package->description,
                'storage_size' => $package->formatted_storage_size,
                'duration' => $package->formatted_duration,
            ],

            // Financial details
            'amount' => [
                'subtotal' => $package->price,
                'tax' => 0, // No tax for now
                'total' => $package->price,
                'formatted_subtotal' => number_format($package->price, 0, ',', ' ') . ' FCFA',
                'formatted_tax' => '0 FCFA',
                'formatted_total' => number_format($package->price, 0, ',', ' ') . ' FCFA',
            ],

            // Payment info
            'payment' => [
                'method' => $transaction->provider === 'kpay' ? 'KPay' : 'PayPal',
                'status' => 'Payé',
                'transaction_id' => $transaction->id,
            ],

            // Dates
            'purchased_at' => $vendorPackage->purchased_at->format('d/m/Y H:i'),
            'expires_at' => $vendorPackage->expires_at->format('d/m/Y'),
        ];
    }

    /**
     * Get invoice URL
     */
    public function getInvoiceUrl(VendorPackage $vendorPackage): string
    {
        $token = base64_encode($vendorPackage->id . ':' . $vendorPackage->payment_reference);
        return url("/api/v1/invoices/package/{$token}");
    }
}
