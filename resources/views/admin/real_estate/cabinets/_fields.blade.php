@php
    $in = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none';
    $label = 'block text-sm text-gray-400 mb-1';
    $v = fn ($f) => old($f, $cabinet?->$f);
@endphp
<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div><label class="{{ $label }}">Nom du cabinet</label><input name="name" required value="{{ $v('name') }}" placeholder="Cabinet Gwoda et Associés" class="{{ $in }}"></div>
    <div class="md:col-span-2"><label class="{{ $label }}">Accroche</label><input name="tagline" value="{{ $v('tagline') }}" placeholder="Votre partenaire de confiance pour la sécurisation de vos biens." class="{{ $in }}"></div>
    <div class="md:col-span-3"><label class="{{ $label }}">Spécialités</label><input name="specialties" value="{{ $v('specialties') }}" placeholder="Ingénierie juridique - Conseil - Assistance fiscale et foncière" class="{{ $in }}"></div>
    <div class="md:col-span-3"><label class="{{ $label }}">Présentation</label><textarea name="description" rows="3" class="{{ $in }}">{{ $v('description') }}</textarea></div>
    <div class="md:col-span-2"><label class="{{ $label }}">« Pourquoi nous choisir ? » (une puce par ligne)</label><textarea name="why_choose_us" rows="6" class="{{ $in }}">{{ old('why_choose_us', implode("\n", $cabinet?->why_choose_us ?? [])) }}</textarea></div>
    <div><label class="{{ $label }}">Mot aux propriétaires partenaires</label><textarea name="owners_message" rows="6" class="{{ $in }}">{{ $v('owners_message') }}</textarea></div>
    <div><label class="{{ $label }}">Région couverte</label><input name="region" value="{{ $v('region') }}" placeholder="Centre" class="{{ $in }}"></div>
    <div><label class="{{ $label }}">Siège (ville)</label><input name="headquarters_city" value="{{ $v('headquarters_city') }}" placeholder="Yaoundé" class="{{ $in }}"></div>
    <div><label class="{{ $label }}">Adresse</label><input name="address" value="{{ $v('address') }}" class="{{ $in }}"></div>
    <div><label class="{{ $label }}">Téléphone</label><input name="phone" value="{{ $v('phone') }}" class="{{ $in }}"></div>
    <div><label class="{{ $label }}">WhatsApp</label><input name="whatsapp" value="{{ $v('whatsapp') }}" class="{{ $in }}"></div>
    <div><label class="{{ $label }}">E-mail (compte du cabinet)</label><input name="email" type="email" value="{{ $v('email') }}" class="{{ $in }}"></div>
    <div><label class="{{ $label }}">Logo</label><input type="file" name="logo" accept="image/*" class="{{ $in }}"></div>
    <div><label class="{{ $label }}">Bannière (affiche, photo de terrain)</label><input type="file" name="banner" accept="image/*" class="{{ $in }}"></div>
</div>
<p class="text-xs text-gray-500 mt-3">Le nom du cabinet est affiché sur ses terrains. Téléphone, WhatsApp, e-mail et adresse ne sont montrés qu'à l'acheteur qui a versé l'acompte. Le compte du cabinet reçoit les acomptes sur son Wallet, bloqués jusqu'à la confirmation.</p>
