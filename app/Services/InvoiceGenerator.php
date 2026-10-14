<?php

namespace App\Services;

use App\Models\VendorPackage;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class InvoiceGenerator
{
    /**
     * Generate invoice HTML for a vendor package purchase
     */
    public function generateInvoiceHtml(VendorPackage $vendorPackage): string
    {
        $vendorPackage->load('user', 'package');

        $user = $vendorPackage->user;
        $package = $vendorPackage->package;

        // Get the latest wallet transaction for this vendor package to retrieve purchased package info
        $walletTransaction = $user->walletTransactions()
            ->where('reference_type', 'vendor_package')
            ->where('reference_id', $vendorPackage->id)
            ->where('type', 'debit')
            ->orderBy('created_at', 'desc')
            ->first();

        // Use metadata from transaction if available (for cumulated packages), otherwise use package relation
        if ($walletTransaction && isset($walletTransaction->metadata['package_name'])) {
            $packageName = $walletTransaction->metadata['package_name'];
            $packagePriceAmount = $walletTransaction->metadata['package_price'] ?? $walletTransaction->amount;
            $packagePriceFormatted = number_format($packagePriceAmount, 0, ',', ' ') . ' FCFA';
            $storageSizeMb = $walletTransaction->metadata['storage_size_mb'] ?? 0;
            $packageStorage = $storageSizeMb >= 1024
                ? number_format($storageSizeMb / 1024, 1) . ' Go'
                : $storageSizeMb . ' Mo';
        } else {
            // Fallback to package relation if no transaction found
            $packageName = $package?->name ?? 'Package';
            $packagePriceFormatted = $package?->formatted_price ?? 'Variable';
            $packagePriceFormatted = str_replace('XOF', 'FCFA', $packagePriceFormatted);
            $packageStorage = $package?->formatted_storage_size ?? number_format($vendorPackage->storage_total_mb, 0) . ' MB';
        }

        $packagePrice = $packagePriceFormatted;

        $invoiceDate = $vendorPackage->purchased_at->format('d/m/Y');
        $invoiceNumber = 'INV-' . $vendorPackage->payment_reference;

        $paymentInfoHtml = '<p><strong>Référence:</strong> ' . e($vendorPackage->payment_reference) . '</p>'
            . '<p><strong>Date d\'achat:</strong> ' . $vendorPackage->purchased_at->format('d/m/Y H:i') . '</p>'
            . '<p><strong>Expire le:</strong> ' . $vendorPackage->expires_at->format('d/m/Y') . '</p>';

        // Bouton « Télécharger PDF » de la page affichée dans l'app (WebView).
        $actionsHtml = <<<ACTIONS
        <div class="actions">
            <button class="btn" onclick="downloadPDF()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: inline-block; vertical-align: middle; margin-right: 8px;">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                Télécharger PDF
            </button>
        </div>
ACTIONS;
        $scriptHtml = <<<SCRIPT
    <script>
        function downloadPDF() {
            if (typeof FlutterChannel !== 'undefined') {
                FlutterChannel.postMessage(JSON.stringify({
                    action: 'downloadInvoice',
                    vendorPackageId: {$vendorPackage->id}
                }));
            }
        }
    </script>
SCRIPT;

        return $this->renderInvoice(
            $invoiceNumber,
            $invoiceDate,
            $user,
            $paymentInfoHtml,
            'Package',
            e($packageName),
            e($packagePrice),
            $actionsHtml,
            $scriptHtml,
        );
    }

    /**
     * Facture d'une recharge Wallet (crédit payé par Mobile Money ou carte).
     */
    public function generateRechargeInvoiceHtml(WalletTransaction $transaction): string
    {
        $transaction->loadMissing('user', 'payment');
        $user = $transaction->user;

        $invoiceNumber = 'INV-WALLET-' . $transaction->id;
        $invoiceDate = $transaction->created_at->format('d/m/Y');
        $provider = match (strtolower((string) $transaction->provider)) {
            'kpay', 'elgiopay' => 'Mobile Money',
            'stripe' => 'Carte bancaire',
            'paypal' => 'PayPal',
            '' => '—',
            default => ucfirst($transaction->provider),
        };
        $reference = $transaction->payment?->reference ?? (string) $transaction->id;
        // Le Wallet est tenu en FCFA (pivot) : c'est le montant réellement crédité.
        $amount = number_format((float) $transaction->amount, 0, ',', ' ') . ' FCFA';

        $paymentInfoHtml = '<p><strong>Référence:</strong> ' . e($reference) . '</p>'
            . '<p><strong>Moyen de paiement:</strong> ' . e($provider) . '</p>'
            . '<p><strong>Date:</strong> ' . $transaction->created_at->format('d/m/Y H:i') . '</p>';

        return $this->renderInvoice(
            $invoiceNumber,
            $invoiceDate,
            $user,
            $paymentInfoHtml,
            'Désignation',
            'Recharge Wallet ASSO',
            $amount,
            '',
            '',
        );
    }

    /** Logo ASSO en base64 (compatible PDF), ou pastille « A » à défaut. */
    private function logoHtml(): string
    {
        // Use logo fallback for better PDF compatibility
        $logoHtml = '<div class="logo-fallback">A</div>';

        // Try to use actual logo if available
        $logoPath = public_path('images/logo.png');
        if (file_exists($logoPath)) {
            try {
                $logoData = base64_encode(file_get_contents($logoPath));
                $logoBase64 = 'data:image/png;base64,' . $logoData;
                $logoHtml = '<img src="' . $logoBase64 . '" alt="ASSO Logo" class="logo">';
            } catch (\Exception $e) {
                // Keep fallback logo if image processing fails
                Log::warning('Failed to load logo for invoice', ['error' => $e->getMessage()]);
            }
        }

        return $logoHtml;
    }

    /**
     * Gabarit commun des factures (forfait, recharge Wallet).
     */
    private function renderInvoice(
        string $invoiceNumber,
        string $invoiceDate,
        $user,
        string $paymentInfoHtml,
        string $itemLabel,
        string $itemName,
        string $price,
        string $actionsHtml,
        string $scriptHtml,
    ): string {
        $logoHtml = $this->logoHtml();
        $userName = e($user?->name ?? '');
        $userEmail = e($user?->email ?? '');
        $userPhone = e($user?->phone ?? '');

        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facture {$invoiceNumber}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            color: #1a1a1a;
            line-height: 1.4;
            padding: 15px;
            background: #f5f7fa;
        }

        .invoice-container {
            max-width: 650px;
            width: 100%;
            background: white;
            padding: 30px;
            margin: 0 auto;
            border: 1px solid #e9ecef;
        }

        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            .invoice-container {
                padding: 20px;
            }
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #F58A3A;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo {
            width: 45px;
            height: 45px;
            object-fit: contain;
        }

        .logo-fallback {
            width: 45px;
            height: 45px;
            background: #F58A3A;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            font-weight: bold;
        }

        .company-info h1 {
            font-size: 22px;
            font-weight: 800;
            color: #F58A3A;
            margin-bottom: 2px;
        }

        .company-info p {
            font-size: 11px;
            color: #6c757d;
        }

        .invoice-info {
            text-align: right;
        }

        .invoice-title {
            font-size: 12px;
            font-weight: 600;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .invoice-number {
            font-size: 16px;
            font-weight: 700;
            color: #F58A3A;
            margin-bottom: 2px;
        }

        .invoice-date {
            font-size: 11px;
            color: #6c757d;
        }

        @media (max-width: 600px) {
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            .invoice-info {
                text-align: center;
            }
        }

        /* Details */
        .details-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
        }

        .detail-block h3 {
            font-size: 10px;
            text-transform: uppercase;
            color: #6c757d;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .detail-block p {
            font-size: 11px;
            margin-bottom: 4px;
            color: #495057;
        }

        .detail-block strong {
            font-weight: 600;
            color: #1a1a1a;
        }

        @media (max-width: 600px) {
            .details-section {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }

        /* Table */
        .items-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .items-table thead {
            background: #F58A3A;
            color: white;
        }

        .items-table th {
            padding: 10px 12px;
            text-align: left;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .items-table tbody tr {
            border-bottom: 1px solid #e9ecef;
        }

        .items-table tbody tr:last-child {
            border-bottom: none;
        }

        .items-table td {
            padding: 12px;
            font-size: 12px;
            color: #495057;
        }

        .items-table td strong {
            color: #1a1a1a;
            font-size: 13px;
        }

        @media (max-width: 600px) {
            .items-table th,
            .items-table td {
                padding: 8px;
                font-size: 10px;
            }
        }

        /* Footer */
        .footer {
            margin-top: 25px;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
            text-align: center;
        }

        .payment-badge {
            display: inline-block;
            background: #4CAF50;
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 12px;
            text-transform: uppercase;
        }

        .footer-note {
            font-size: 10px;
            color: #6c757d;
            line-height: 1.5;
        }

        /* Action Buttons */
        .actions {
            margin-top: 20px;
            text-align: center;
        }

        .btn {
            padding: 10px 25px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            background: #F58A3A;
            color: white;
        }

        .btn:hover {
            background: #e67a2a;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 138, 58, 0.3);
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }
            .invoice-container {
                box-shadow: none;
                padding: 15px;
            }
            .actions {
                display: none;
            }
        }

        @media (max-width: 600px) {
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="invoice-container" id="invoice">
        <!-- Header -->
        <div class="header">
            <div class="logo-section">
                {$logoHtml}
                <div class="company-info">
                    <h1>ASSO</h1>
                    <p>Plateforme de Commerce Digital</p>
                </div>
            </div>
            <div class="invoice-info">
                <div class="invoice-title">FACTURE</div>
                <div class="invoice-number">{$invoiceNumber}</div>
                <div class="invoice-date">Date: {$invoiceDate}</div>
            </div>
        </div>

        <!-- Details -->
        <div class="details-section">
            <div class="detail-block">
                <h3>Facturé à</h3>
                <p><strong>{$userName}</strong></p>
                <p>{$userEmail}</p>
                <p>{$userPhone}</p>
            </div>
            <div class="detail-block">
                <h3>Informations de paiement</h3>
                {$paymentInfoHtml}
            </div>
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th>{$itemLabel}</th>
                    <th style="text-align: center;">Quantité</th>
                    <th style="text-align: right;">Prix</th>
                    <th style="text-align: right;">Total</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>{$itemName}</strong></td>
                    <td style="text-align: center;">1</td>
                    <td style="text-align: right;">{$price}</td>
                    <td style="text-align: right;"><strong>{$price}</strong></td>
                </tr>
            </tbody>
        </table>

        <!-- Footer -->
        <div class="footer">
            <div class="payment-badge">PAYÉ</div>
            <p class="footer-note">
                Merci pour votre confiance !<br>
                Cette facture est générée électroniquement et ne nécessite pas de signature.<br>
                Pour toute question, contactez-nous à support@asso.com
            </p>
        </div>

        {$actionsHtml}
    </div>

{$scriptHtml}
</body>
</html>
HTML;
    }

    /**
     * Get the public URL for downloading the invoice
     */
    public function getInvoiceDownloadUrl(VendorPackage $vendorPackage): string
    {
        return route('api.invoices.download', ['vendorPackageId' => $vendorPackage->id]);
    }
}
