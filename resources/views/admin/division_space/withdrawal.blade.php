@extends('admin.layouts.app')

@section('title', 'Retrait de mes gains')
@section('header', 'Ma rubrique')

@section('content')
<div class="p-6 space-y-6">
    @include('admin.division_space._header', ['title' => 'Retrait'])
    <p class="text-sm text-gray-400">
        Vos commissions acquises sont versées sur votre Wallet ASSO. Retirez-les vers votre compte Mobile Money, ou par virement sur votre IBAN une fois celui-ci validé par l'équipe ASSO.
        Les frais de retrait sont à la charge du bénéficiaire. Votre mot de passe est demandé à chaque retrait.
    </p>
    @include('admin.withdrawals.panel', ['historyTitle' => 'Mes derniers retraits'])
</div>
@endsection
