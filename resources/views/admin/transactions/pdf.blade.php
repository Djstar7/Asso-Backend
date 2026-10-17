<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapport des Transactions - ASSO</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            color: #333;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #f97316;
        }

        .header h1 {
            color: #f97316;
            font-size: 28px;
            margin-bottom: 10px;
        }

        .header p {
            color: #666;
            font-size: 14px;
        }

        .stats {
            display: flex;
            justify-content: space-around;
            margin-bottom: 30px;
            gap: 20px;
        }

        .stat-box {
            flex: 1;
            background: #f5f5f5;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid #f97316;
        }

        .stat-box .label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .stat-box .value {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }

        .stat-box .currency {
            font-size: 14px;
            color: #666;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        thead {
            background-color: #f97316;
            color: white;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            font-size: 12px;
        }

        th {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 11px;
        }

        tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        tbody tr:hover {
            background-color: #f5f5f5;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
        }

        .badge-success {
            background-color: #d4edda;
            color: #155724;
        }

        .badge-warning {
            background-color: #fff3cd;
            color: #856404;
        }

        .badge-danger {
            background-color: #f8d7da;
            color: #721c24;
        }

        .badge-paypal {
            background-color: #e3f2fd;
            color: #1976d2;
        }

        .badge-visa {
            background-color: #ede7f6;
            color: #5e35b1;
        }

        .badge-mastercard {
            background-color: #fff3e0;
            color: #ef6c00;
        }

        .badge-fedapay {
            background-color: #e8f5e9;
            color: #2e7d32;
        }

        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #ddd;
            text-align: center;
            color: #666;
            font-size: 12px;
        }

        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #f97316;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .print-btn:hover {
            background-color: #ea580c;
        }

        @media print {
            .print-btn {
                display: none;
            }

            body {
                padding: 0;
            }

            .header {
                page-break-after: avoid;
            }

            tbody tr {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">
        <i class="fas fa-print"></i> Imprimer / Télécharger PDF
    </button>

    <div class="header">
        <h1>📊 Rapport des Transactions</h1>
        <p>ASSO - Généré le {{ now()->displayTz()->format('d/m/Y à H:i') }}</p>
    </div>

    <div class="stats">
        <div class="stat-box">
            <div class="label">Revenu Total</div>
            <div class="value">{{ number_format($totalRevenue, 0, ',', ' ') }}</div>
            <div class="currency">XOF</div>
        </div>

        <div class="stat-box">
            <div class="label">Frais Totaux</div>
            <div class="value">{{ number_format($totalFees, 0, ',', ' ') }}</div>
            <div class="currency">XOF</div>
        </div>

        <div class="stat-box">
            <div class="label">Revenu Net</div>
            <div class="value">{{ number_format($netRevenue, 0, ',', ' ') }}</div>
            <div class="currency">XOF</div>
        </div>

        <div class="stat-box">
            <div class="label">Transactions</div>
            <div class="value">{{ $transactions->count() }}</div>
            <div class="currency">Total</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Référence</th>
                <th>Date</th>
                <th>Acheteur</th>
                <th>Montant</th>
                <th>Frais</th>
                <th>Net</th>
                <th>Méthode</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transactions as $transaction)
                <tr>
                    <td style="font-family: monospace; font-size: 11px;">
                        <strong>{{ $transaction->reference }}</strong>
                    </td>
                    <td>{{ $transaction->created_at->displayTz()->format('d/m/Y H:i') }}</td>
                    <td>{{ $transaction->buyer?->first_name }} {{ $transaction->buyer?->last_name }}</td>
                    <td><strong>{{ number_format($transaction->amount, 0, ',', ' ') }} XOF</strong></td>
                    <td>{{ number_format($transaction->fees, 0, ',', ' ') }} XOF</td>
                    <td><strong>{{ number_format($transaction->net_amount, 0, ',', ' ') }} XOF</strong></td>
                    <td>
                        <span class="badge badge-{{ $transaction->payment_method }}">
                            {{ $transaction->payment_method_label }}
                        </span>
                    </td>
                    <td>
                        @if($transaction->status == 'completed')
                            <span class="badge badge-success">Complété</span>
                        @elseif($transaction->status == 'pending')
                            <span class="badge badge-warning">En attente</span>
                        @else
                            <span class="badge badge-danger">Annulé</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p><strong>ASSO</strong> - Système de Gestion des Transactions</p>
        <p>Ce rapport contient {{ $transactions->count() }} transaction(s)</p>
        <p>© {{ date('Y') }} ASSO. Tous droits réservés.</p>
    </div>
</body>
</html>
