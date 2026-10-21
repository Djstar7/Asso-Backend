@php
    $additionalFees = $r->hasAdditionalPlatformFee();
    $isLand = $r->kind === 'land';
    $cabinetPayee = $r->net_payee_kind === 'cabinet' || $isLand;
    $fmt = fn ($v) => number_format((float) $v, 0, ',', ' ').' FCFA';
    $payeeName = $cabinetPayee ? ($r->partner_cabinet_name ?? $listing->cabinet?->name ?? '—') : trim(($r->payee?->first_name ?? '').' '.($r->payee?->last_name ?? ''));
    $logoDataUri = 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('logo/Asso.png')));
@endphp
<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8">@include('pdf._real_estate_style')
    <style>
        h2 { margin-top: 12px; }
        p { margin: 8px 0; }
        .brand { margin-bottom: 6px; }
        .logo { width: 75px; }
        .amounts td { padding-top: 4px; padding-bottom: 4px; }
        .signature-section { page-break-inside: avoid; }
    </style>
</head>
<body>
    <table class="brand">
        <tr>
            <td><img src="{{ $logoDataUri }}" alt="ASSO" class="logo"></td>
            <td class="right muted">Plateforme de réservation immobilière</td>
        </tr>
    </table>
    <h1>{{ $isLand ? 'Engagement d\'acompte — terrain' : 'Contrat de réservation de logement' }}</h1>
    <p class="muted">Référence {{ $r->reference }} · établi le {{ ($r->paid_at ?? now())->format('d/m/Y') }} sur ASSO</p>

    <h2>Les parties</h2>
    <table>
        <tr>
            <td><b>{{ $cabinetPayee ? 'Cabinet' : 'Bailleur' }}</b><br>{{ $payeeName }}<br>{{ $isLand ? ($listing->cabinet?->phone) : $r->payee?->phone }}</td>
            <td><b>{{ $isLand ? 'Acheteur' : 'Locataire' }}</b><br>{{ $r->tenant?->first_name }} {{ $r->tenant?->last_name }}<br>{{ $r->tenant?->phone }}</td>
        </tr>
    </table>

    <h2>Le bien</h2>
    <p><b>{{ $listing->title }}</b> ({{ $listing->reference }})<br>
        {{ $listing->district ? $listing->district.', ' : '' }}{{ $listing->city }}{{ $listing->address ? ' — '.$listing->address : '' }}
        @if($r->unit)<br>{{ $isLand ? 'Lot' : 'Logement' }} : {{ $r->unit->label }}@endif
    </p>

    <h2>Conditions</h2>
    <table class="amounts">
        @if($isLand)
            <tr><td>Prix du lot</td><td class="right">{{ $fmt($r->displayed_unit_price) }}</td></tr>
            <tr><td>Acompte{{ $additionalFees ? ' au cabinet' : '' }} ({{ rtrim(rtrim(number_format((float) $r->deposit_percent, 2, ',', ''), '0'), ',') }} %)</td><td class="right">{{ $fmt($r->base_amount) }}</td></tr>
        @else
            <tr><td>Loyer mensuel</td><td class="right">{{ $fmt($r->displayed_unit_price) }}</td></tr>
            <tr><td>Durée de location prévue</td><td class="right">{{ $r->months ? $r->months.' mois' : 'Indéterminée' }}</td></tr>
            <tr><td>Date d'emménagement</td><td class="right">{{ $r->move_in_date?->format('d/m/Y') ?? '—' }}</td></tr>
            <tr><td>Avance{{ $additionalFees ? ' au '.($cabinetPayee ? 'cabinet' : 'bailleur') : '' }} ({{ $r->advance_months }} mois)</td><td class="right">{{ $fmt($r->base_amount) }}</td></tr>
        @endif
        @if($r->partner_payee_id)
            @unless($additionalFees)
                <tr><td>Dont montant net au {{ $cabinetPayee ? 'cabinet' : 'bailleur' }}</td><td class="right">{{ $fmt($r->owner_amount) }}</td></tr>
            @endunless
            <tr><td>{{ $additionalFees ? 'Part ASSO' : 'Dont part ASSO' }} (non remboursable)</td><td class="right">{{ $fmt($r->platform_amount) }}</td></tr>
        @endif
        @if($r->commission_amount > 0)<tr><td>{{ $r->partner_payee_id ? 'Commission du cabinet partenaire' : 'Commission ASSO' }} (non remboursable)</td><td class="right">{{ $fmt($r->commission_amount) }}</td></tr>@endif
        <tr class="total"><td>Montant payé sur ASSO</td><td class="right">{{ $fmt($r->total_amount) }}</td></tr>
    </table>

    <div class="box">
        <p><b>Paiement sécurisé.</b> La part revenant au {{ $cabinetPayee ? 'cabinet' : 'bailleur' }} ({{ $fmt($r->owner_amount) }}) reste bloquée par ASSO pendant {{ $days }} jours.
            Elle est versée dès que le {{ $isLand ? 'l\'acheteur' : 'locataire' }} confirme sur ASSO que le bien est conforme à l'annonce, ou à la fin de ce délai si aucun litige n'est ouvert.</p>
        <p><b>Litige.</b> Si le bien ne correspond pas à l'annonce, un litige peut être ouvert sur ASSO avant la fin du délai. ASSO examine les éléments et peut rembourser la part bloquée.</p>
        <p><b>Frais non remboursables.</b> Les frais de service ASSO ({{ $fmt($r->platform_amount) }}) et la commission du cabinet partenaire ({{ $fmt($r->partner_commission_amount) }}) restent acquis, quelle que soit l'issue.</p>
        @if($isLand)
            <p>ASSO met l'acheteur en relation avec le cabinet partenaire et n'est pas partie à la vente : la suite (documents, signature, solde du prix) se fait directement avec le cabinet.</p>
        @endif
    </div>

    <p style="margin-top:14px">Le {{ $isLand ? 'l\'acheteur' : 'locataire' }} a accepté ces conditions sur l'application ASSO le {{ $r->contract_accepted_at?->format('d/m/Y à H:i') }}.</p>

    <p class="muted">ASSO intervient comme plateforme de mise en relation et de paiement. ASSO n'est ni signataire ni partie au présent contrat, conclu entre les parties ci-dessous.</p>
    <div class="signature-section">
    <h2>Signatures manuscrites des parties</h2>
    <p class="muted">Les zones ci-dessous sont réservées à la signature manuscrite des parties ; elles ne sont pas préremplies.</p>
    <table class="signatures">
        <tr>
            <td>
                <b>{{ $cabinetPayee ? 'Cabinet' : 'Bailleur' }}</b><br>
                {{ $payeeName }}<br>
                Signature manuscrite du {{ $cabinetPayee ? 'cabinet' : 'bailleur' }} :<br>
                <div class="signature-line"></div>
                Date : ____ / ____ / ______
            </td>
            <td>
                <b>{{ $isLand ? 'Acheteur' : 'Locataire' }}</b><br>
                {{ trim(($r->tenant?->first_name ?? '').' '.($r->tenant?->last_name ?? '')) }}<br>
                Signature manuscrite {{ $isLand ? 'de l’acheteur' : 'du locataire' }} :<br>
                <div class="signature-line"></div>
                Date : ____ / ____ / ______
            </td>
        </tr>
    </table>
    </div>
</body></html>
