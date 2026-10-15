{{-- IBAN du compte plateforme : même soumission Stripe Connect que dans l'app (KYC exigé par Stripe). --}}
@php
    $field = 'w-full bg-dark-200 border border-dark-300 rounded-lg px-3 py-2 text-gray-100 focus:outline-none focus:border-primary-500';
@endphp
<form method="POST" action="{{ route('admin.revenue.withdrawal.iban') }}" class="space-y-3">
    @csrf
    <p class="text-sm font-semibold text-gray-200"><i class="fas fa-sync mr-1"></i>Synchroniser l'IBAN</p>
    @if($errors->any())
        <div class="p-3 rounded-lg bg-red-500/10 border border-red-500/30 text-sm text-red-300">{{ $errors->first() }}</div>
    @endif
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
            <label class="block text-sm text-gray-300 mb-1">Pays</label>
            <input type="text" name="country" value="{{ old('country', $platform->stripe_bank_country ?? 'FR') }}" maxlength="2" required class="{{ $field }} uppercase">
        </div>
        <div class="sm:col-span-2">
            <label class="block text-sm text-gray-300 mb-1">IBAN</label>
            <input type="text" name="iban" required autocomplete="off" placeholder="FR76 …" class="{{ $field }} uppercase">
        </div>
    </div>
    <div>
        <label class="block text-sm text-gray-300 mb-1">Titulaire du compte</label>
        <input type="text" name="account_holder_name" value="{{ old('account_holder_name', $platform->stripe_account_holder_name) }}" required class="{{ $field }}">
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
            <label class="block text-sm text-gray-300 mb-1">Prénom</label>
            <input type="text" name="first_name" value="{{ old('first_name', $platform->first_name) }}" class="{{ $field }}">
        </div>
        <div>
            <label class="block text-sm text-gray-300 mb-1">Nom</label>
            <input type="text" name="last_name" value="{{ old('last_name', $platform->last_name) }}" class="{{ $field }}">
        </div>
        <div>
            <label class="block text-sm text-gray-300 mb-1">Date de naissance</label>
            <input type="date" name="birth_date" value="{{ old('birth_date') }}" required class="{{ $field }}">
        </div>
        <div>
            <label class="block text-sm text-gray-300 mb-1">Téléphone</label>
            <input type="tel" name="phone" value="{{ old('phone', $platform->phone) }}" required class="{{ $field }}">
        </div>
    </div>
    <div>
        <label class="block text-sm text-gray-300 mb-1">Adresse</label>
        <input type="text" name="address_line1" value="{{ old('address_line1') }}" required class="{{ $field }}">
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
            <label class="block text-sm text-gray-300 mb-1">Ville</label>
            <input type="text" name="address_city" value="{{ old('address_city') }}" required class="{{ $field }}">
        </div>
        <div>
            <label class="block text-sm text-gray-300 mb-1">Code postal</label>
            <input type="text" name="address_postal_code" value="{{ old('address_postal_code') }}" required class="{{ $field }}">
        </div>
    </div>
    <button type="submit" onclick="this.disabled=true; this.form.submit();"
            class="w-full px-4 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-semibold">
        Enregistrer l'IBAN
    </button>
</form>
