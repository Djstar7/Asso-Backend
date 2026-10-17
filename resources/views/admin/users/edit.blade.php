@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center gap-3 mb-2">
            <a href="{{ route('admin.users.index') }}"
               class="text-gray-400 hover:text-primary-600 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h1 class="text-2xl font-bold text-white">Modifier l'Utilisateur</h1>
        </div>
        <p class="text-gray-400 ml-10">Modifiez les informations de {{ $user->name }}</p>
    </div>

    <!-- Form -->
    <div class="bg-dark-100 rounded-xl shadow-lg p-6">
        <form action="{{ route('admin.users.update', $user) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- First Name -->
                <div>
                    <label for="first_name" class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-user text-primary-500 mr-1"></i>
                        Prénom <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           name="first_name"
                           id="first_name"
                           value="{{ old('first_name', $user->first_name) }}"
                           required
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 @error('first_name') border-red-500 @enderror">
                    @error('first_name')
                        <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Last Name -->
                <div>
                    <label for="last_name" class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-user text-primary-500 mr-1"></i>
                        Nom <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           name="last_name"
                           id="last_name"
                           value="{{ old('last_name', $user->last_name) }}"
                           required
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 @error('last_name') border-red-500 @enderror">
                    @error('last_name')
                        <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Email -->
                <div>
                    <label for="email" class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-envelope text-primary-500 mr-1"></i>
                        Email <span class="text-red-500">*</span>
                    </label>
                    <input type="email"
                           name="email"
                           id="email"
                           value="{{ old('email', $user->email) }}"
                           required
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 @error('email') border-red-500 @enderror">
                    @error('email')
                        <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Phone -->
                <div>
                    <label for="phone" class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-phone text-primary-500 mr-1"></i>
                        Téléphone
                    </label>
                    <input type="text"
                           name="phone"
                           id="phone"
                           value="{{ old('phone', $user->phone) }}"
                           placeholder="+229 XX XX XX XX"
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 @error('phone') border-red-500 @enderror">
                    @error('phone')
                        <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Role -->
                <div>
                    <label for="role" class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-user-tag text-primary-500 mr-1"></i>
                        Rôle <span class="text-red-500">*</span>
                    </label>
                    <select name="role"
                            id="role"
                            required
                            class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 @error('role') border-red-500 @enderror">
                        <option value="">Sélectionnez un rôle</option>
                        <option value="client" {{ old('role', $user->role) == 'client' ? 'selected' : '' }}>Client</option>
                        <option value="vendeur" {{ old('role', $user->role) == 'vendeur' ? 'selected' : '' }}>Vendeur</option>
                        <option value="livreur" {{ old('role', $user->role) == 'livreur' ? 'selected' : '' }}>Livreur</option>
                        <option value="admin" {{ old('role', $user->role) == 'admin' ? 'selected' : '' }}>Admin</option>
                    </select>
                    @error('role')
                        <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Gender -->
                <div>
                    <label for="gender" class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-venus-mars text-primary-500 mr-1"></i>
                        Genre
                    </label>
                    <select name="gender"
                            id="gender"
                            class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 @error('gender') border-red-500 @enderror">
                        <option value="">Sélectionnez un genre</option>
                        <option value="male" {{ old('gender', $user->gender) == 'male' ? 'selected' : '' }}>Homme</option>
                        <option value="female" {{ old('gender', $user->gender) == 'female' ? 'selected' : '' }}>Femme</option>
                        <option value="other" {{ old('gender', $user->gender) == 'other' ? 'selected' : '' }}>Autre</option>
                    </select>
                    @error('gender')
                        <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Birth Date -->
                <div>
                    <label for="birth_date" class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-calendar text-primary-500 mr-1"></i>
                        Date de naissance
                    </label>
                    <input type="date"
                           name="birth_date"
                           id="birth_date"
                           value="{{ old('birth_date', $user->birth_date?->displayTz()->format('Y-m-d')) }}"
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 @error('birth_date') border-red-500 @enderror">
                    @error('birth_date')
                        <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Country -->
                <div>
                    <label for="country" class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-globe text-primary-500 mr-1"></i>
                        Pays
                    </label>
                    <select name="country"
                            id="country"
                            class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 @error('country') border-red-500 @enderror">
                        <option value="">Sélectionnez un pays</option>
                        <option value="Afghanistan" {{ old('country', $user->country) == 'Afghanistan' ? 'selected' : '' }}>Afghanistan</option>
                        <option value="Afrique du Sud" {{ old('country', $user->country) == 'Afrique du Sud' ? 'selected' : '' }}>Afrique du Sud</option>
                        <option value="Albanie" {{ old('country', $user->country) == 'Albanie' ? 'selected' : '' }}>Albanie</option>
                        <option value="Algérie" {{ old('country', $user->country) == 'Algérie' ? 'selected' : '' }}>Algérie</option>
                        <option value="Allemagne" {{ old('country', $user->country) == 'Allemagne' ? 'selected' : '' }}>Allemagne</option>
                        <option value="Andorre" {{ old('country', $user->country) == 'Andorre' ? 'selected' : '' }}>Andorre</option>
                        <option value="Angola" {{ old('country', $user->country) == 'Angola' ? 'selected' : '' }}>Angola</option>
                        <option value="Arabie Saoudite" {{ old('country', $user->country) == 'Arabie Saoudite' ? 'selected' : '' }}>Arabie Saoudite</option>
                        <option value="Argentine" {{ old('country', $user->country) == 'Argentine' ? 'selected' : '' }}>Argentine</option>
                        <option value="Arménie" {{ old('country', $user->country) == 'Arménie' ? 'selected' : '' }}>Arménie</option>
                        <option value="Australie" {{ old('country', $user->country) == 'Australie' ? 'selected' : '' }}>Australie</option>
                        <option value="Autriche" {{ old('country', $user->country) == 'Autriche' ? 'selected' : '' }}>Autriche</option>
                        <option value="Azerbaïdjan" {{ old('country', $user->country) == 'Azerbaïdjan' ? 'selected' : '' }}>Azerbaïdjan</option>
                        <option value="Bahamas" {{ old('country', $user->country) == 'Bahamas' ? 'selected' : '' }}>Bahamas</option>
                        <option value="Bahreïn" {{ old('country', $user->country) == 'Bahreïn' ? 'selected' : '' }}>Bahreïn</option>
                        <option value="Bangladesh" {{ old('country', $user->country) == 'Bangladesh' ? 'selected' : '' }}>Bangladesh</option>
                        <option value="Belgique" {{ old('country', $user->country) == 'Belgique' ? 'selected' : '' }}>Belgique</option>
                        <option value="Bénin" {{ old('country', $user->country) == 'Bénin' ? 'selected' : '' }}>Bénin</option>
                        <option value="Bhoutan" {{ old('country', $user->country) == 'Bhoutan' ? 'selected' : '' }}>Bhoutan</option>
                        <option value="Bolivie" {{ old('country', $user->country) == 'Bolivie' ? 'selected' : '' }}>Bolivie</option>
                        <option value="Bosnie-Herzégovine" {{ old('country', $user->country) == 'Bosnie-Herzégovine' ? 'selected' : '' }}>Bosnie-Herzégovine</option>
                        <option value="Botswana" {{ old('country', $user->country) == 'Botswana' ? 'selected' : '' }}>Botswana</option>
                        <option value="Brésil" {{ old('country', $user->country) == 'Brésil' ? 'selected' : '' }}>Brésil</option>
                        <option value="Brunei" {{ old('country', $user->country) == 'Brunei' ? 'selected' : '' }}>Brunei</option>
                        <option value="Bulgarie" {{ old('country', $user->country) == 'Bulgarie' ? 'selected' : '' }}>Bulgarie</option>
                        <option value="Burkina Faso" {{ old('country', $user->country) == 'Burkina Faso' ? 'selected' : '' }}>Burkina Faso</option>
                        <option value="Burundi" {{ old('country', $user->country) == 'Burundi' ? 'selected' : '' }}>Burundi</option>
                        <option value="Cambodge" {{ old('country', $user->country) == 'Cambodge' ? 'selected' : '' }}>Cambodge</option>
                        <option value="Cameroun" {{ old('country', $user->country) == 'Cameroun' ? 'selected' : '' }}>Cameroun</option>
                        <option value="Canada" {{ old('country', $user->country) == 'Canada' ? 'selected' : '' }}>Canada</option>
                        <option value="Cap-Vert" {{ old('country', $user->country) == 'Cap-Vert' ? 'selected' : '' }}>Cap-Vert</option>
                        <option value="Chili" {{ old('country', $user->country) == 'Chili' ? 'selected' : '' }}>Chili</option>
                        <option value="Chine" {{ old('country', $user->country) == 'Chine' ? 'selected' : '' }}>Chine</option>
                        <option value="Chypre" {{ old('country', $user->country) == 'Chypre' ? 'selected' : '' }}>Chypre</option>
                        <option value="Colombie" {{ old('country', $user->country) == 'Colombie' ? 'selected' : '' }}>Colombie</option>
                        <option value="Comores" {{ old('country', $user->country) == 'Comores' ? 'selected' : '' }}>Comores</option>
                        <option value="Congo" {{ old('country', $user->country) == 'Congo' ? 'selected' : '' }}>Congo</option>
                        <option value="Corée du Nord" {{ old('country', $user->country) == 'Corée du Nord' ? 'selected' : '' }}>Corée du Nord</option>
                        <option value="Corée du Sud" {{ old('country', $user->country) == 'Corée du Sud' ? 'selected' : '' }}>Corée du Sud</option>
                        <option value="Costa Rica" {{ old('country', $user->country) == 'Costa Rica' ? 'selected' : '' }}>Costa Rica</option>
                        <option value="Côte d'Ivoire" {{ old('country', $user->country) == "Côte d'Ivoire" ? 'selected' : '' }}>Côte d'Ivoire</option>
                        <option value="Croatie" {{ old('country', $user->country) == 'Croatie' ? 'selected' : '' }}>Croatie</option>
                        <option value="Cuba" {{ old('country', $user->country) == 'Cuba' ? 'selected' : '' }}>Cuba</option>
                        <option value="Danemark" {{ old('country', $user->country) == 'Danemark' ? 'selected' : '' }}>Danemark</option>
                        <option value="Djibouti" {{ old('country', $user->country) == 'Djibouti' ? 'selected' : '' }}>Djibouti</option>
                        <option value="Égypte" {{ old('country', $user->country) == 'Égypte' ? 'selected' : '' }}>Égypte</option>
                        <option value="Émirats arabes unis" {{ old('country', $user->country) == 'Émirats arabes unis' ? 'selected' : '' }}>Émirats arabes unis</option>
                        <option value="Équateur" {{ old('country', $user->country) == 'Équateur' ? 'selected' : '' }}>Équateur</option>
                        <option value="Érythrée" {{ old('country', $user->country) == 'Érythrée' ? 'selected' : '' }}>Érythrée</option>
                        <option value="Espagne" {{ old('country', $user->country) == 'Espagne' ? 'selected' : '' }}>Espagne</option>
                        <option value="Estonie" {{ old('country', $user->country) == 'Estonie' ? 'selected' : '' }}>Estonie</option>
                        <option value="États-Unis" {{ old('country', $user->country) == 'États-Unis' ? 'selected' : '' }}>États-Unis</option>
                        <option value="Éthiopie" {{ old('country', $user->country) == 'Éthiopie' ? 'selected' : '' }}>Éthiopie</option>
                        <option value="Finlande" {{ old('country', $user->country) == 'Finlande' ? 'selected' : '' }}>Finlande</option>
                        <option value="France" {{ old('country', $user->country) == 'France' ? 'selected' : '' }}>France</option>
                        <option value="Gabon" {{ old('country', $user->country) == 'Gabon' ? 'selected' : '' }}>Gabon</option>
                        <option value="Gambie" {{ old('country', $user->country) == 'Gambie' ? 'selected' : '' }}>Gambie</option>
                        <option value="Géorgie" {{ old('country', $user->country) == 'Géorgie' ? 'selected' : '' }}>Géorgie</option>
                        <option value="Ghana" {{ old('country', $user->country) == 'Ghana' ? 'selected' : '' }}>Ghana</option>
                        <option value="Grèce" {{ old('country', $user->country) == 'Grèce' ? 'selected' : '' }}>Grèce</option>
                        <option value="Guatemala" {{ old('country', $user->country) == 'Guatemala' ? 'selected' : '' }}>Guatemala</option>
                        <option value="Guinée" {{ old('country', $user->country) == 'Guinée' ? 'selected' : '' }}>Guinée</option>
                        <option value="Guinée équatoriale" {{ old('country', $user->country) == 'Guinée équatoriale' ? 'selected' : '' }}>Guinée équatoriale</option>
                        <option value="Guinée-Bissau" {{ old('country', $user->country) == 'Guinée-Bissau' ? 'selected' : '' }}>Guinée-Bissau</option>
                        <option value="Guyana" {{ old('country', $user->country) == 'Guyana' ? 'selected' : '' }}>Guyana</option>
                        <option value="Haïti" {{ old('country', $user->country) == 'Haïti' ? 'selected' : '' }}>Haïti</option>
                        <option value="Honduras" {{ old('country', $user->country) == 'Honduras' ? 'selected' : '' }}>Honduras</option>
                        <option value="Hongrie" {{ old('country', $user->country) == 'Hongrie' ? 'selected' : '' }}>Hongrie</option>
                        <option value="Inde" {{ old('country', $user->country) == 'Inde' ? 'selected' : '' }}>Inde</option>
                        <option value="Indonésie" {{ old('country', $user->country) == 'Indonésie' ? 'selected' : '' }}>Indonésie</option>
                        <option value="Irak" {{ old('country', $user->country) == 'Irak' ? 'selected' : '' }}>Irak</option>
                        <option value="Iran" {{ old('country', $user->country) == 'Iran' ? 'selected' : '' }}>Iran</option>
                        <option value="Irlande" {{ old('country', $user->country) == 'Irlande' ? 'selected' : '' }}>Irlande</option>
                        <option value="Islande" {{ old('country', $user->country) == 'Islande' ? 'selected' : '' }}>Islande</option>
                        <option value="Israël" {{ old('country', $user->country) == 'Israël' ? 'selected' : '' }}>Israël</option>
                        <option value="Italie" {{ old('country', $user->country) == 'Italie' ? 'selected' : '' }}>Italie</option>
                        <option value="Jamaïque" {{ old('country', $user->country) == 'Jamaïque' ? 'selected' : '' }}>Jamaïque</option>
                        <option value="Japon" {{ old('country', $user->country) == 'Japon' ? 'selected' : '' }}>Japon</option>
                        <option value="Jordanie" {{ old('country', $user->country) == 'Jordanie' ? 'selected' : '' }}>Jordanie</option>
                        <option value="Kazakhstan" {{ old('country', $user->country) == 'Kazakhstan' ? 'selected' : '' }}>Kazakhstan</option>
                        <option value="Kenya" {{ old('country', $user->country) == 'Kenya' ? 'selected' : '' }}>Kenya</option>
                        <option value="Kirghizistan" {{ old('country', $user->country) == 'Kirghizistan' ? 'selected' : '' }}>Kirghizistan</option>
                        <option value="Koweït" {{ old('country', $user->country) == 'Koweït' ? 'selected' : '' }}>Koweït</option>
                        <option value="Laos" {{ old('country', $user->country) == 'Laos' ? 'selected' : '' }}>Laos</option>
                        <option value="Lesotho" {{ old('country', $user->country) == 'Lesotho' ? 'selected' : '' }}>Lesotho</option>
                        <option value="Lettonie" {{ old('country', $user->country) == 'Lettonie' ? 'selected' : '' }}>Lettonie</option>
                        <option value="Liban" {{ old('country', $user->country) == 'Liban' ? 'selected' : '' }}>Liban</option>
                        <option value="Libéria" {{ old('country', $user->country) == 'Libéria' ? 'selected' : '' }}>Libéria</option>
                        <option value="Libye" {{ old('country', $user->country) == 'Libye' ? 'selected' : '' }}>Libye</option>
                        <option value="Liechtenstein" {{ old('country', $user->country) == 'Liechtenstein' ? 'selected' : '' }}>Liechtenstein</option>
                        <option value="Lituanie" {{ old('country', $user->country) == 'Lituanie' ? 'selected' : '' }}>Lituanie</option>
                        <option value="Luxembourg" {{ old('country', $user->country) == 'Luxembourg' ? 'selected' : '' }}>Luxembourg</option>
                        <option value="Macédoine du Nord" {{ old('country', $user->country) == 'Macédoine du Nord' ? 'selected' : '' }}>Macédoine du Nord</option>
                        <option value="Madagascar" {{ old('country', $user->country) == 'Madagascar' ? 'selected' : '' }}>Madagascar</option>
                        <option value="Malaisie" {{ old('country', $user->country) == 'Malaisie' ? 'selected' : '' }}>Malaisie</option>
                        <option value="Malawi" {{ old('country', $user->country) == 'Malawi' ? 'selected' : '' }}>Malawi</option>
                        <option value="Maldives" {{ old('country', $user->country) == 'Maldives' ? 'selected' : '' }}>Maldives</option>
                        <option value="Mali" {{ old('country', $user->country) == 'Mali' ? 'selected' : '' }}>Mali</option>
                        <option value="Malte" {{ old('country', $user->country) == 'Malte' ? 'selected' : '' }}>Malte</option>
                        <option value="Maroc" {{ old('country', $user->country) == 'Maroc' ? 'selected' : '' }}>Maroc</option>
                        <option value="Maurice" {{ old('country', $user->country) == 'Maurice' ? 'selected' : '' }}>Maurice</option>
                        <option value="Mauritanie" {{ old('country', $user->country) == 'Mauritanie' ? 'selected' : '' }}>Mauritanie</option>
                        <option value="Mexique" {{ old('country', $user->country) == 'Mexique' ? 'selected' : '' }}>Mexique</option>
                        <option value="Moldavie" {{ old('country', $user->country) == 'Moldavie' ? 'selected' : '' }}>Moldavie</option>
                        <option value="Monaco" {{ old('country', $user->country) == 'Monaco' ? 'selected' : '' }}>Monaco</option>
                        <option value="Mongolie" {{ old('country', $user->country) == 'Mongolie' ? 'selected' : '' }}>Mongolie</option>
                        <option value="Monténégro" {{ old('country', $user->country) == 'Monténégro' ? 'selected' : '' }}>Monténégro</option>
                        <option value="Mozambique" {{ old('country', $user->country) == 'Mozambique' ? 'selected' : '' }}>Mozambique</option>
                        <option value="Myanmar" {{ old('country', $user->country) == 'Myanmar' ? 'selected' : '' }}>Myanmar</option>
                        <option value="Namibie" {{ old('country', $user->country) == 'Namibie' ? 'selected' : '' }}>Namibie</option>
                        <option value="Népal" {{ old('country', $user->country) == 'Népal' ? 'selected' : '' }}>Népal</option>
                        <option value="Nicaragua" {{ old('country', $user->country) == 'Nicaragua' ? 'selected' : '' }}>Nicaragua</option>
                        <option value="Niger" {{ old('country', $user->country) == 'Niger' ? 'selected' : '' }}>Niger</option>
                        <option value="Nigeria" {{ old('country', $user->country) == 'Nigeria' ? 'selected' : '' }}>Nigeria</option>
                        <option value="Norvège" {{ old('country', $user->country) == 'Norvège' ? 'selected' : '' }}>Norvège</option>
                        <option value="Nouvelle-Zélande" {{ old('country', $user->country) == 'Nouvelle-Zélande' ? 'selected' : '' }}>Nouvelle-Zélande</option>
                        <option value="Oman" {{ old('country', $user->country) == 'Oman' ? 'selected' : '' }}>Oman</option>
                        <option value="Ouganda" {{ old('country', $user->country) == 'Ouganda' ? 'selected' : '' }}>Ouganda</option>
                        <option value="Ouzbékistan" {{ old('country', $user->country) == 'Ouzbékistan' ? 'selected' : '' }}>Ouzbékistan</option>
                        <option value="Pakistan" {{ old('country', $user->country) == 'Pakistan' ? 'selected' : '' }}>Pakistan</option>
                        <option value="Palestine" {{ old('country', $user->country) == 'Palestine' ? 'selected' : '' }}>Palestine</option>
                        <option value="Panama" {{ old('country', $user->country) == 'Panama' ? 'selected' : '' }}>Panama</option>
                        <option value="Papouasie-Nouvelle-Guinée" {{ old('country', $user->country) == 'Papouasie-Nouvelle-Guinée' ? 'selected' : '' }}>Papouasie-Nouvelle-Guinée</option>
                        <option value="Paraguay" {{ old('country', $user->country) == 'Paraguay' ? 'selected' : '' }}>Paraguay</option>
                        <option value="Pays-Bas" {{ old('country', $user->country) == 'Pays-Bas' ? 'selected' : '' }}>Pays-Bas</option>
                        <option value="Pérou" {{ old('country', $user->country) == 'Pérou' ? 'selected' : '' }}>Pérou</option>
                        <option value="Philippines" {{ old('country', $user->country) == 'Philippines' ? 'selected' : '' }}>Philippines</option>
                        <option value="Pologne" {{ old('country', $user->country) == 'Pologne' ? 'selected' : '' }}>Pologne</option>
                        <option value="Portugal" {{ old('country', $user->country) == 'Portugal' ? 'selected' : '' }}>Portugal</option>
                        <option value="Qatar" {{ old('country', $user->country) == 'Qatar' ? 'selected' : '' }}>Qatar</option>
                        <option value="République centrafricaine" {{ old('country', $user->country) == 'République centrafricaine' ? 'selected' : '' }}>République centrafricaine</option>
                        <option value="République démocratique du Congo" {{ old('country', $user->country) == 'République démocratique du Congo' ? 'selected' : '' }}>République démocratique du Congo</option>
                        <option value="République dominicaine" {{ old('country', $user->country) == 'République dominicaine' ? 'selected' : '' }}>République dominicaine</option>
                        <option value="République tchèque" {{ old('country', $user->country) == 'République tchèque' ? 'selected' : '' }}>République tchèque</option>
                        <option value="Roumanie" {{ old('country', $user->country) == 'Roumanie' ? 'selected' : '' }}>Roumanie</option>
                        <option value="Royaume-Uni" {{ old('country', $user->country) == 'Royaume-Uni' ? 'selected' : '' }}>Royaume-Uni</option>
                        <option value="Russie" {{ old('country', $user->country) == 'Russie' ? 'selected' : '' }}>Russie</option>
                        <option value="Rwanda" {{ old('country', $user->country) == 'Rwanda' ? 'selected' : '' }}>Rwanda</option>
                        <option value="Saint-Marin" {{ old('country', $user->country) == 'Saint-Marin' ? 'selected' : '' }}>Saint-Marin</option>
                        <option value="Salvador" {{ old('country', $user->country) == 'Salvador' ? 'selected' : '' }}>Salvador</option>
                        <option value="Sénégal" {{ old('country', $user->country) == 'Sénégal' ? 'selected' : '' }}>Sénégal</option>
                        <option value="Serbie" {{ old('country', $user->country) == 'Serbie' ? 'selected' : '' }}>Serbie</option>
                        <option value="Seychelles" {{ old('country', $user->country) == 'Seychelles' ? 'selected' : '' }}>Seychelles</option>
                        <option value="Sierra Leone" {{ old('country', $user->country) == 'Sierra Leone' ? 'selected' : '' }}>Sierra Leone</option>
                        <option value="Singapour" {{ old('country', $user->country) == 'Singapour' ? 'selected' : '' }}>Singapour</option>
                        <option value="Slovaquie" {{ old('country', $user->country) == 'Slovaquie' ? 'selected' : '' }}>Slovaquie</option>
                        <option value="Slovénie" {{ old('country', $user->country) == 'Slovénie' ? 'selected' : '' }}>Slovénie</option>
                        <option value="Somalie" {{ old('country', $user->country) == 'Somalie' ? 'selected' : '' }}>Somalie</option>
                        <option value="Soudan" {{ old('country', $user->country) == 'Soudan' ? 'selected' : '' }}>Soudan</option>
                        <option value="Soudan du Sud" {{ old('country', $user->country) == 'Soudan du Sud' ? 'selected' : '' }}>Soudan du Sud</option>
                        <option value="Sri Lanka" {{ old('country', $user->country) == 'Sri Lanka' ? 'selected' : '' }}>Sri Lanka</option>
                        <option value="Suède" {{ old('country', $user->country) == 'Suède' ? 'selected' : '' }}>Suède</option>
                        <option value="Suisse" {{ old('country', $user->country) == 'Suisse' ? 'selected' : '' }}>Suisse</option>
                        <option value="Suriname" {{ old('country', $user->country) == 'Suriname' ? 'selected' : '' }}>Suriname</option>
                        <option value="Syrie" {{ old('country', $user->country) == 'Syrie' ? 'selected' : '' }}>Syrie</option>
                        <option value="Tadjikistan" {{ old('country', $user->country) == 'Tadjikistan' ? 'selected' : '' }}>Tadjikistan</option>
                        <option value="Tanzanie" {{ old('country', $user->country) == 'Tanzanie' ? 'selected' : '' }}>Tanzanie</option>
                        <option value="Tchad" {{ old('country', $user->country) == 'Tchad' ? 'selected' : '' }}>Tchad</option>
                        <option value="Thaïlande" {{ old('country', $user->country) == 'Thaïlande' ? 'selected' : '' }}>Thaïlande</option>
                        <option value="Timor oriental" {{ old('country', $user->country) == 'Timor oriental' ? 'selected' : '' }}>Timor oriental</option>
                        <option value="Togo" {{ old('country', $user->country) == 'Togo' ? 'selected' : '' }}>Togo</option>
                        <option value="Trinité-et-Tobago" {{ old('country', $user->country) == 'Trinité-et-Tobago' ? 'selected' : '' }}>Trinité-et-Tobago</option>
                        <option value="Tunisie" {{ old('country', $user->country) == 'Tunisie' ? 'selected' : '' }}>Tunisie</option>
                        <option value="Turkménistan" {{ old('country', $user->country) == 'Turkménistan' ? 'selected' : '' }}>Turkménistan</option>
                        <option value="Turquie" {{ old('country', $user->country) == 'Turquie' ? 'selected' : '' }}>Turquie</option>
                        <option value="Ukraine" {{ old('country', $user->country) == 'Ukraine' ? 'selected' : '' }}>Ukraine</option>
                        <option value="Uruguay" {{ old('country', $user->country) == 'Uruguay' ? 'selected' : '' }}>Uruguay</option>
                        <option value="Vanuatu" {{ old('country', $user->country) == 'Vanuatu' ? 'selected' : '' }}>Vanuatu</option>
                        <option value="Vatican" {{ old('country', $user->country) == 'Vatican' ? 'selected' : '' }}>Vatican</option>
                        <option value="Venezuela" {{ old('country', $user->country) == 'Venezuela' ? 'selected' : '' }}>Venezuela</option>
                        <option value="Viêt Nam" {{ old('country', $user->country) == 'Viêt Nam' ? 'selected' : '' }}>Viêt Nam</option>
                        <option value="Yémen" {{ old('country', $user->country) == 'Yémen' ? 'selected' : '' }}>Yémen</option>
                        <option value="Zambie" {{ old('country', $user->country) == 'Zambie' ? 'selected' : '' }}>Zambie</option>
                        <option value="Zimbabwe" {{ old('country', $user->country) == 'Zimbabwe' ? 'selected' : '' }}>Zimbabwe</option>
                    </select>
                    @error('country')
                        <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Address with Google Maps -->
            <div class="mt-6">
                @include('admin.partials.google-map', [
                    'id' => 'user-map',
                    'label' => 'Localisation de l\'utilisateur',
                    'latitude' => old('latitude', $user->latitude),
                    'longitude' => old('longitude', $user->longitude),
                    'address' => old('address', $user->address),
                    'zoom' => 13
                ])
            </div>

            <!-- Password Section -->
            <div class="mt-6 p-4 bg-dark-50 rounded-lg border border-dark-200">
                <h3 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-key text-primary-500 mr-2"></i>
                    Changer le mot de passe (optionnel)
                </h3>
                <p class="text-sm text-gray-400 mb-4">Laissez vide pour conserver le mot de passe actuel</p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Password -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-white mb-2">
                            <i class="fas fa-lock text-primary-500 mr-1"></i>
                            Nouveau mot de passe
                        </label>
                        <input type="password"
                               name="password"
                               id="password"
                               class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 @error('password') border-red-500 @enderror">
                        @error('password')
                            <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-gray-400">Minimum 8 caractères</p>
                    </div>

                    <!-- Password Confirmation -->
                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-white mb-2">
                            <i class="fas fa-lock text-primary-500 mr-1"></i>
                            Confirmer le mot de passe
                        </label>
                        <input type="password"
                               name="password_confirmation"
                               id="password_confirmation"
                               class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="mt-8 flex gap-4">
                <button type="submit"
                        class="px-6 py-3 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all duration-200 shadow-lg hover:shadow-xl">
                    <i class="fas fa-save mr-2"></i>
                    Mettre à jour
                </button>
                <a href="{{ route('admin.users.index') }}"
                   class="px-6 py-3 bg-gray-200 text-white rounded-lg hover:bg-gray-700 transition-all">
                    <i class="fas fa-times mr-2"></i>
                    Annuler
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
