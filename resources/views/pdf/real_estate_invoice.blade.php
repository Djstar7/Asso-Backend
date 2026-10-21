@php
    $isLand = $r->kind === 'land';
    $cabinetPayee = $r->net_payee_kind === 'cabinet' || $isLand;
    $fmt = fn ($v) => number_format((float) $v, 0, ',', ' ').' FCFA';
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
@endphp
<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8">@include('pdf._real_estate_style')</head>
<body>
    <h1>Facture</h1>
    <p class="muted">N° {{ $r->reference }} · {{ ($r->paid_at ?? now())->format('d/m/Y') }}</p>
    <table>
        <tr>
            <td><b>ASSO</b><br>Plateforme de mise en relation</td>
            <td class="right"><b>Client</b><br>{{ $r->tenant?->first_name }} {{ $r->tenant?->last_name }}<br>{{ $r->tenant?->phone }}</td>
        </tr>
    </table>

    <h2>{{ $listing->title }} ({{ $listing->reference }})</h2>
    <table class="amounts">
        <tr><th style="text-align:left">Désignation</th><th class="right">Montant</th></tr>
        @if($isLand)
            <tr><td>Acompte de {{ $pct($r->deposit_percent) }} % sur {{ $r->unit?->label ?? 'le terrain' }} (prix {{ $fmt($r->displayed_unit_price) }})</td><td class="right">{{ $fmt($r->base_amount) }}</td></tr>
        @else
            <tr><td>Avance de loyer : {{ $r->advance_months }} mois (part ASSO incluse)</td><td class="right">{{ $fmt($r->base_amount) }}</td></tr>
        @endif
        @if($r->partner_payee_id)
            <tr><td>Dont montant net au {{ $cabinetPayee ? 'cabinet' : 'bailleur' }}</td><td class="right">{{ $fmt($r->owner_amount) }}</td></tr>
            <tr><td>Dont part ASSO</td><td class="right">{{ $fmt($r->platform_amount) }}</td></tr>
        @endif
        @if($r->commission_amount > 0)
            <tr><td>{{ $r->partner_payee_id ? 'Commission du cabinet partenaire' : 'Commission ASSO' }} ({{ $pct($r->owner_amount > 0 ? $r->commission_amount * 100 / $r->owner_amount : 0) }} %) — non remboursable</td><td class="right">{{ $fmt($r->commission_amount) }}</td></tr>
        @endif
        <tr class="total"><td>Total payé</td><td class="right">{{ $fmt($r->total_amount) }}</td></tr>
    </table>
    <p class="muted" style="margin-top:12px">Moyen de paiement : {{ ['wallet' => 'Wallet ASSO', 'kpay_direct' => 'Mobile Money', 'stripe_direct' => 'Carte bancaire'][$r->payment_method] ?? $r->payment_method }}.
        Part du {{ $cabinetPayee ? 'cabinet' : 'bailleur' }} bloquée jusqu'à la confirmation : {{ $fmt($r->owner_amount) }}.</p>
</body></html>
