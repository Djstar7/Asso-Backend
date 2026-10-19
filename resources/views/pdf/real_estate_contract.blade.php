@php
    $isLand = $r->kind === 'land';
    $fmt = fn ($v) => number_format((float) $v, 0, ',', ' ').' FCFA';
    $payeeName = $isLand ? ($listing->cabinet?->name ?? '—') : trim(($r->payee?->first_name ?? '').' '.($r->payee?->last_name ?? ''));
@endphp
<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8">@include('pdf._real_estate_style')</head>
<body>
    <h1>{{ $isLand ? 'Engagement d\'acompte — terrain' : 'Contrat de réservation de logement' }}</h1>
    <p class="muted">Référence {{ $r->reference }} · établi le {{ ($r->paid_at ?? now())->format('d/m/Y') }} via ASSO</p>

    <h2>Les parties</h2>
    <table>
        <tr>
            <td><b>{{ $isLand ? 'Cabinet' : 'Bailleur' }}</b><br>{{ $payeeName }}<br>{{ $isLand ? ($listing->cabinet?->phone) : $r->payee?->phone }}</td>
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
            <tr><td>Acompte ({{ rtrim(rtrim(number_format((float) $r->deposit_percent, 2, ',', ''), '0'), ',') }} %)</td><td class="right">{{ $fmt($r->base_amount) }}</td></tr>
        @else
            <tr><td>Loyer mensuel</td><td class="right">{{ $fmt($r->displayed_unit_price) }}</td></tr>
            <tr><td>Durée de location prévue</td><td class="right">{{ $r->months ? $r->months.' mois' : 'Indéterminée' }}</td></tr>
            <tr><td>Date d'emménagement</td><td class="right">{{ $r->move_in_date?->format('d/m/Y') ?? '—' }}</td></tr>
            <tr><td>Avance ({{ $r->advance_months }} mois)</td><td class="right">{{ $fmt($r->base_amount) }}</td></tr>
        @endif
        @if($r->commission_amount > 0)<tr><td>Commission ASSO (non remboursable)</td><td class="right">{{ $fmt($r->commission_amount) }}</td></tr>@endif
        <tr class="total"><td>Montant payé sur ASSO</td><td class="right">{{ $fmt($r->total_amount) }}</td></tr>
    </table>

    <div class="box">
        <p><b>Paiement sécurisé.</b> La part revenant au {{ $isLand ? 'cabinet' : 'bailleur' }} ({{ $fmt($r->owner_amount) }}) reste bloquée par ASSO pendant {{ $days }} jours.
            Elle est versée dès que le {{ $isLand ? 'l\'acheteur' : 'locataire' }} confirme sur ASSO que le bien est conforme à l'annonce, ou à la fin de ce délai si aucun litige n'est ouvert.</p>
        <p><b>Litige.</b> Si le bien ne correspond pas à l'annonce, un litige peut être ouvert sur ASSO avant la fin du délai. ASSO examine les éléments et peut rembourser la part bloquée.</p>
        <p><b>Frais ASSO non remboursables.</b> La commission et les frais de service ASSO ({{ $fmt($r->platform_amount) }}) restent acquis, quelle que soit l'issue.</p>
        @if($isLand)
            <p>ASSO met l'acheteur en relation avec le cabinet partenaire et n'est pas partie à la vente : la suite (documents, signature, solde du prix) se fait directement avec le cabinet.</p>
        @endif
    </div>

    <p style="margin-top:14px">Le {{ $isLand ? 'l\'acheteur' : 'locataire' }} a accepté ces conditions sur l'application ASSO le {{ $r->contract_accepted_at?->format('d/m/Y à H:i') }}.</p>
</body></html>
