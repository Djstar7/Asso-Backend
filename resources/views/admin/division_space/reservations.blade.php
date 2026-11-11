@extends('admin.layouts.app')

@section('title', 'Réservations')
@section('header', 'Ma rubrique')

@section('content')
@php extract(\App\Support\DivisionPresenter::helpers()) @endphp
<div class="p-6 space-y-6">
    @include('admin.division_space._header', ['title' => 'Réservations'])
    <div class="bg-dark-100 rounded-xl shadow-lg overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-400 border-b border-dark-200">
                <th class="px-4 py-3">Référence</th><th class="px-4 py-3">Annonce</th><th class="px-4 py-3">Payée le</th>
                <th class="px-4 py-3 text-right">Montant</th><th class="px-4 py-3 text-right">Commission ASSO</th><th class="px-4 py-3">Statut</th>
            </tr></thead>
            <tbody>
                @forelse($reservations as $r)
                    <tr class="border-b border-dark-200">
                        <td class="px-4 py-3 text-white">{{ $r->reference }}</td>
                        <td class="px-4 py-3 text-gray-300">{{ $r->listing?->title }} <span class="text-xs text-gray-500">{{ $r->listing?->city }}</span></td>
                        <td class="px-4 py-3 text-gray-300">{{ $r->paid_at?->displayTz()->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-right text-gray-200">{{ $money($r->total_amount) }}</td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ $money($r->platform_amount) }}</td>
                        <td class="px-4 py-3 text-gray-200">{{ $reservationStatus($r->status) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-500">Aucune réservation payée.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="p-4">{{ $reservations->links() }}</div>
    </div>
</div>
@endsection
