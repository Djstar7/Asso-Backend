<?php $__env->startSection('content'); ?>
<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center gap-3 mb-2">
            <a href="<?php echo e(route('admin.users.index')); ?>"
               class="text-gray-400 hover:text-primary-600 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h1 class="text-2xl font-bold text-white">Modifier l'Utilisateur</h1>
        </div>
        <p class="text-gray-400 ml-10">Modifiez les informations de <?php echo e($user->name); ?></p>
    </div>

    <!-- Form -->
    <div class="bg-dark-100 rounded-xl shadow-lg p-6">
        <form action="<?php echo e(route('admin.users.update', $user)); ?>" method="POST">
            <?php echo csrf_field(); ?>
            <?php echo method_field('PUT'); ?>

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
                           value="<?php echo e(old('first_name', $user->first_name)); ?>"
                           required
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 <?php $__errorArgs = ['first_name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> border-red-500 <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>">
                    <?php $__errorArgs = ['first_name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                        <p class="mt-1 text-sm text-red-400"><?php echo e($message); ?></p>
                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
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
                           value="<?php echo e(old('last_name', $user->last_name)); ?>"
                           required
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 <?php $__errorArgs = ['last_name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> border-red-500 <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>">
                    <?php $__errorArgs = ['last_name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                        <p class="mt-1 text-sm text-red-400"><?php echo e($message); ?></p>
                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
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
                           value="<?php echo e(old('email', $user->email)); ?>"
                           required
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 <?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> border-red-500 <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>">
                    <?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                        <p class="mt-1 text-sm text-red-400"><?php echo e($message); ?></p>
                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
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
                           value="<?php echo e(old('phone', $user->phone)); ?>"
                           placeholder="+229 XX XX XX XX"
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 <?php $__errorArgs = ['phone'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> border-red-500 <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>">
                    <?php $__errorArgs = ['phone'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                        <p class="mt-1 text-sm text-red-400"><?php echo e($message); ?></p>
                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
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
                            class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 <?php $__errorArgs = ['role'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> border-red-500 <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>">
                        <option value="">Sélectionnez un rôle</option>
                        <option value="client" <?php echo e(old('role', $user->role) == 'client' ? 'selected' : ''); ?>>Client</option>
                        <option value="vendeur" <?php echo e(old('role', $user->role) == 'vendeur' ? 'selected' : ''); ?>>Vendeur</option>
                        <option value="livreur" <?php echo e(old('role', $user->role) == 'livreur' ? 'selected' : ''); ?>>Livreur</option>
                        <option value="admin" <?php echo e(old('role', $user->role) == 'admin' ? 'selected' : ''); ?>>Admin</option>
                    </select>
                    <?php $__errorArgs = ['role'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                        <p class="mt-1 text-sm text-red-400"><?php echo e($message); ?></p>
                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>

                <!-- Gender -->
                <div>
                    <label for="gender" class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-venus-mars text-primary-500 mr-1"></i>
                        Genre
                    </label>
                    <select name="gender"
                            id="gender"
                            class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 <?php $__errorArgs = ['gender'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> border-red-500 <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>">
                        <option value="">Sélectionnez un genre</option>
                        <option value="male" <?php echo e(old('gender', $user->gender) == 'male' ? 'selected' : ''); ?>>Homme</option>
                        <option value="female" <?php echo e(old('gender', $user->gender) == 'female' ? 'selected' : ''); ?>>Femme</option>
                        <option value="other" <?php echo e(old('gender', $user->gender) == 'other' ? 'selected' : ''); ?>>Autre</option>
                    </select>
                    <?php $__errorArgs = ['gender'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                        <p class="mt-1 text-sm text-red-400"><?php echo e($message); ?></p>
                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
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
                           value="<?php echo e(old('birth_date', $user->birth_date?->format('Y-m-d'))); ?>"
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 <?php $__errorArgs = ['birth_date'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> border-red-500 <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>">
                    <?php $__errorArgs = ['birth_date'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                        <p class="mt-1 text-sm text-red-400"><?php echo e($message); ?></p>
                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>

                <!-- Country -->
                <div>
                    <label for="country" class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-globe text-primary-500 mr-1"></i>
                        Pays
                    </label>
                    <select name="country"
                            id="country"
                            class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 <?php $__errorArgs = ['country'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> border-red-500 <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>">
                        <option value="">Sélectionnez un pays</option>
                        <option value="Afghanistan" <?php echo e(old('country', $user->country) == 'Afghanistan' ? 'selected' : ''); ?>>Afghanistan</option>
                        <option value="Afrique du Sud" <?php echo e(old('country', $user->country) == 'Afrique du Sud' ? 'selected' : ''); ?>>Afrique du Sud</option>
                        <option value="Albanie" <?php echo e(old('country', $user->country) == 'Albanie' ? 'selected' : ''); ?>>Albanie</option>
                        <option value="Algérie" <?php echo e(old('country', $user->country) == 'Algérie' ? 'selected' : ''); ?>>Algérie</option>
                        <option value="Allemagne" <?php echo e(old('country', $user->country) == 'Allemagne' ? 'selected' : ''); ?>>Allemagne</option>
                        <option value="Andorre" <?php echo e(old('country', $user->country) == 'Andorre' ? 'selected' : ''); ?>>Andorre</option>
                        <option value="Angola" <?php echo e(old('country', $user->country) == 'Angola' ? 'selected' : ''); ?>>Angola</option>
                        <option value="Arabie Saoudite" <?php echo e(old('country', $user->country) == 'Arabie Saoudite' ? 'selected' : ''); ?>>Arabie Saoudite</option>
                        <option value="Argentine" <?php echo e(old('country', $user->country) == 'Argentine' ? 'selected' : ''); ?>>Argentine</option>
                        <option value="Arménie" <?php echo e(old('country', $user->country) == 'Arménie' ? 'selected' : ''); ?>>Arménie</option>
                        <option value="Australie" <?php echo e(old('country', $user->country) == 'Australie' ? 'selected' : ''); ?>>Australie</option>
                        <option value="Autriche" <?php echo e(old('country', $user->country) == 'Autriche' ? 'selected' : ''); ?>>Autriche</option>
                        <option value="Azerbaïdjan" <?php echo e(old('country', $user->country) == 'Azerbaïdjan' ? 'selected' : ''); ?>>Azerbaïdjan</option>
                        <option value="Bahamas" <?php echo e(old('country', $user->country) == 'Bahamas' ? 'selected' : ''); ?>>Bahamas</option>
                        <option value="Bahreïn" <?php echo e(old('country', $user->country) == 'Bahreïn' ? 'selected' : ''); ?>>Bahreïn</option>
                        <option value="Bangladesh" <?php echo e(old('country', $user->country) == 'Bangladesh' ? 'selected' : ''); ?>>Bangladesh</option>
                        <option value="Belgique" <?php echo e(old('country', $user->country) == 'Belgique' ? 'selected' : ''); ?>>Belgique</option>
                        <option value="Bénin" <?php echo e(old('country', $user->country) == 'Bénin' ? 'selected' : ''); ?>>Bénin</option>
                        <option value="Bhoutan" <?php echo e(old('country', $user->country) == 'Bhoutan' ? 'selected' : ''); ?>>Bhoutan</option>
                        <option value="Bolivie" <?php echo e(old('country', $user->country) == 'Bolivie' ? 'selected' : ''); ?>>Bolivie</option>
                        <option value="Bosnie-Herzégovine" <?php echo e(old('country', $user->country) == 'Bosnie-Herzégovine' ? 'selected' : ''); ?>>Bosnie-Herzégovine</option>
                        <option value="Botswana" <?php echo e(old('country', $user->country) == 'Botswana' ? 'selected' : ''); ?>>Botswana</option>
                        <option value="Brésil" <?php echo e(old('country', $user->country) == 'Brésil' ? 'selected' : ''); ?>>Brésil</option>
                        <option value="Brunei" <?php echo e(old('country', $user->country) == 'Brunei' ? 'selected' : ''); ?>>Brunei</option>
                        <option value="Bulgarie" <?php echo e(old('country', $user->country) == 'Bulgarie' ? 'selected' : ''); ?>>Bulgarie</option>
                        <option value="Burkina Faso" <?php echo e(old('country', $user->country) == 'Burkina Faso' ? 'selected' : ''); ?>>Burkina Faso</option>
                        <option value="Burundi" <?php echo e(old('country', $user->country) == 'Burundi' ? 'selected' : ''); ?>>Burundi</option>
                        <option value="Cambodge" <?php echo e(old('country', $user->country) == 'Cambodge' ? 'selected' : ''); ?>>Cambodge</option>
                        <option value="Cameroun" <?php echo e(old('country', $user->country) == 'Cameroun' ? 'selected' : ''); ?>>Cameroun</option>
                        <option value="Canada" <?php echo e(old('country', $user->country) == 'Canada' ? 'selected' : ''); ?>>Canada</option>
                        <option value="Cap-Vert" <?php echo e(old('country', $user->country) == 'Cap-Vert' ? 'selected' : ''); ?>>Cap-Vert</option>
                        <option value="Chili" <?php echo e(old('country', $user->country) == 'Chili' ? 'selected' : ''); ?>>Chili</option>
                        <option value="Chine" <?php echo e(old('country', $user->country) == 'Chine' ? 'selected' : ''); ?>>Chine</option>
                        <option value="Chypre" <?php echo e(old('country', $user->country) == 'Chypre' ? 'selected' : ''); ?>>Chypre</option>
                        <option value="Colombie" <?php echo e(old('country', $user->country) == 'Colombie' ? 'selected' : ''); ?>>Colombie</option>
                        <option value="Comores" <?php echo e(old('country', $user->country) == 'Comores' ? 'selected' : ''); ?>>Comores</option>
                        <option value="Congo" <?php echo e(old('country', $user->country) == 'Congo' ? 'selected' : ''); ?>>Congo</option>
                        <option value="Corée du Nord" <?php echo e(old('country', $user->country) == 'Corée du Nord' ? 'selected' : ''); ?>>Corée du Nord</option>
                        <option value="Corée du Sud" <?php echo e(old('country', $user->country) == 'Corée du Sud' ? 'selected' : ''); ?>>Corée du Sud</option>
                        <option value="Costa Rica" <?php echo e(old('country', $user->country) == 'Costa Rica' ? 'selected' : ''); ?>>Costa Rica</option>
                        <option value="Côte d'Ivoire" <?php echo e(old('country', $user->country) == "Côte d'Ivoire" ? 'selected' : ''); ?>>Côte d'Ivoire</option>
                        <option value="Croatie" <?php echo e(old('country', $user->country) == 'Croatie' ? 'selected' : ''); ?>>Croatie</option>
                        <option value="Cuba" <?php echo e(old('country', $user->country) == 'Cuba' ? 'selected' : ''); ?>>Cuba</option>
                        <option value="Danemark" <?php echo e(old('country', $user->country) == 'Danemark' ? 'selected' : ''); ?>>Danemark</option>
                        <option value="Djibouti" <?php echo e(old('country', $user->country) == 'Djibouti' ? 'selected' : ''); ?>>Djibouti</option>
                        <option value="Égypte" <?php echo e(old('country', $user->country) == 'Égypte' ? 'selected' : ''); ?>>Égypte</option>
                        <option value="Émirats arabes unis" <?php echo e(old('country', $user->country) == 'Émirats arabes unis' ? 'selected' : ''); ?>>Émirats arabes unis</option>
                        <option value="Équateur" <?php echo e(old('country', $user->country) == 'Équateur' ? 'selected' : ''); ?>>Équateur</option>
                        <option value="Érythrée" <?php echo e(old('country', $user->country) == 'Érythrée' ? 'selected' : ''); ?>>Érythrée</option>
                        <option value="Espagne" <?php echo e(old('country', $user->country) == 'Espagne' ? 'selected' : ''); ?>>Espagne</option>
                        <option value="Estonie" <?php echo e(old('country', $user->country) == 'Estonie' ? 'selected' : ''); ?>>Estonie</option>
                        <option value="États-Unis" <?php echo e(old('country', $user->country) == 'États-Unis' ? 'selected' : ''); ?>>États-Unis</option>
                        <option value="Éthiopie" <?php echo e(old('country', $user->country) == 'Éthiopie' ? 'selected' : ''); ?>>Éthiopie</option>
                        <option value="Finlande" <?php echo e(old('country', $user->country) == 'Finlande' ? 'selected' : ''); ?>>Finlande</option>
                        <option value="France" <?php echo e(old('country', $user->country) == 'France' ? 'selected' : ''); ?>>France</option>
                        <option value="Gabon" <?php echo e(old('country', $user->country) == 'Gabon' ? 'selected' : ''); ?>>Gabon</option>
                        <option value="Gambie" <?php echo e(old('country', $user->country) == 'Gambie' ? 'selected' : ''); ?>>Gambie</option>
                        <option value="Géorgie" <?php echo e(old('country', $user->country) == 'Géorgie' ? 'selected' : ''); ?>>Géorgie</option>
                        <option value="Ghana" <?php echo e(old('country', $user->country) == 'Ghana' ? 'selected' : ''); ?>>Ghana</option>
                        <option value="Grèce" <?php echo e(old('country', $user->country) == 'Grèce' ? 'selected' : ''); ?>>Grèce</option>
                        <option value="Guatemala" <?php echo e(old('country', $user->country) == 'Guatemala' ? 'selected' : ''); ?>>Guatemala</option>
                        <option value="Guinée" <?php echo e(old('country', $user->country) == 'Guinée' ? 'selected' : ''); ?>>Guinée</option>
                        <option value="Guinée équatoriale" <?php echo e(old('country', $user->country) == 'Guinée équatoriale' ? 'selected' : ''); ?>>Guinée équatoriale</option>
                        <option value="Guinée-Bissau" <?php echo e(old('country', $user->country) == 'Guinée-Bissau' ? 'selected' : ''); ?>>Guinée-Bissau</option>
                        <option value="Guyana" <?php echo e(old('country', $user->country) == 'Guyana' ? 'selected' : ''); ?>>Guyana</option>
                        <option value="Haïti" <?php echo e(old('country', $user->country) == 'Haïti' ? 'selected' : ''); ?>>Haïti</option>
                        <option value="Honduras" <?php echo e(old('country', $user->country) == 'Honduras' ? 'selected' : ''); ?>>Honduras</option>
                        <option value="Hongrie" <?php echo e(old('country', $user->country) == 'Hongrie' ? 'selected' : ''); ?>>Hongrie</option>
                        <option value="Inde" <?php echo e(old('country', $user->country) == 'Inde' ? 'selected' : ''); ?>>Inde</option>
                        <option value="Indonésie" <?php echo e(old('country', $user->country) == 'Indonésie' ? 'selected' : ''); ?>>Indonésie</option>
                        <option value="Irak" <?php echo e(old('country', $user->country) == 'Irak' ? 'selected' : ''); ?>>Irak</option>
                        <option value="Iran" <?php echo e(old('country', $user->country) == 'Iran' ? 'selected' : ''); ?>>Iran</option>
                        <option value="Irlande" <?php echo e(old('country', $user->country) == 'Irlande' ? 'selected' : ''); ?>>Irlande</option>
                        <option value="Islande" <?php echo e(old('country', $user->country) == 'Islande' ? 'selected' : ''); ?>>Islande</option>
                        <option value="Israël" <?php echo e(old('country', $user->country) == 'Israël' ? 'selected' : ''); ?>>Israël</option>
                        <option value="Italie" <?php echo e(old('country', $user->country) == 'Italie' ? 'selected' : ''); ?>>Italie</option>
                        <option value="Jamaïque" <?php echo e(old('country', $user->country) == 'Jamaïque' ? 'selected' : ''); ?>>Jamaïque</option>
                        <option value="Japon" <?php echo e(old('country', $user->country) == 'Japon' ? 'selected' : ''); ?>>Japon</option>
                        <option value="Jordanie" <?php echo e(old('country', $user->country) == 'Jordanie' ? 'selected' : ''); ?>>Jordanie</option>
                        <option value="Kazakhstan" <?php echo e(old('country', $user->country) == 'Kazakhstan' ? 'selected' : ''); ?>>Kazakhstan</option>
                        <option value="Kenya" <?php echo e(old('country', $user->country) == 'Kenya' ? 'selected' : ''); ?>>Kenya</option>
                        <option value="Kirghizistan" <?php echo e(old('country', $user->country) == 'Kirghizistan' ? 'selected' : ''); ?>>Kirghizistan</option>
                        <option value="Koweït" <?php echo e(old('country', $user->country) == 'Koweït' ? 'selected' : ''); ?>>Koweït</option>
                        <option value="Laos" <?php echo e(old('country', $user->country) == 'Laos' ? 'selected' : ''); ?>>Laos</option>
                        <option value="Lesotho" <?php echo e(old('country', $user->country) == 'Lesotho' ? 'selected' : ''); ?>>Lesotho</option>
                        <option value="Lettonie" <?php echo e(old('country', $user->country) == 'Lettonie' ? 'selected' : ''); ?>>Lettonie</option>
                        <option value="Liban" <?php echo e(old('country', $user->country) == 'Liban' ? 'selected' : ''); ?>>Liban</option>
                        <option value="Libéria" <?php echo e(old('country', $user->country) == 'Libéria' ? 'selected' : ''); ?>>Libéria</option>
                        <option value="Libye" <?php echo e(old('country', $user->country) == 'Libye' ? 'selected' : ''); ?>>Libye</option>
                        <option value="Liechtenstein" <?php echo e(old('country', $user->country) == 'Liechtenstein' ? 'selected' : ''); ?>>Liechtenstein</option>
                        <option value="Lituanie" <?php echo e(old('country', $user->country) == 'Lituanie' ? 'selected' : ''); ?>>Lituanie</option>
                        <option value="Luxembourg" <?php echo e(old('country', $user->country) == 'Luxembourg' ? 'selected' : ''); ?>>Luxembourg</option>
                        <option value="Macédoine du Nord" <?php echo e(old('country', $user->country) == 'Macédoine du Nord' ? 'selected' : ''); ?>>Macédoine du Nord</option>
                        <option value="Madagascar" <?php echo e(old('country', $user->country) == 'Madagascar' ? 'selected' : ''); ?>>Madagascar</option>
                        <option value="Malaisie" <?php echo e(old('country', $user->country) == 'Malaisie' ? 'selected' : ''); ?>>Malaisie</option>
                        <option value="Malawi" <?php echo e(old('country', $user->country) == 'Malawi' ? 'selected' : ''); ?>>Malawi</option>
                        <option value="Maldives" <?php echo e(old('country', $user->country) == 'Maldives' ? 'selected' : ''); ?>>Maldives</option>
                        <option value="Mali" <?php echo e(old('country', $user->country) == 'Mali' ? 'selected' : ''); ?>>Mali</option>
                        <option value="Malte" <?php echo e(old('country', $user->country) == 'Malte' ? 'selected' : ''); ?>>Malte</option>
                        <option value="Maroc" <?php echo e(old('country', $user->country) == 'Maroc' ? 'selected' : ''); ?>>Maroc</option>
                        <option value="Maurice" <?php echo e(old('country', $user->country) == 'Maurice' ? 'selected' : ''); ?>>Maurice</option>
                        <option value="Mauritanie" <?php echo e(old('country', $user->country) == 'Mauritanie' ? 'selected' : ''); ?>>Mauritanie</option>
                        <option value="Mexique" <?php echo e(old('country', $user->country) == 'Mexique' ? 'selected' : ''); ?>>Mexique</option>
                        <option value="Moldavie" <?php echo e(old('country', $user->country) == 'Moldavie' ? 'selected' : ''); ?>>Moldavie</option>
                        <option value="Monaco" <?php echo e(old('country', $user->country) == 'Monaco' ? 'selected' : ''); ?>>Monaco</option>
                        <option value="Mongolie" <?php echo e(old('country', $user->country) == 'Mongolie' ? 'selected' : ''); ?>>Mongolie</option>
                        <option value="Monténégro" <?php echo e(old('country', $user->country) == 'Monténégro' ? 'selected' : ''); ?>>Monténégro</option>
                        <option value="Mozambique" <?php echo e(old('country', $user->country) == 'Mozambique' ? 'selected' : ''); ?>>Mozambique</option>
                        <option value="Myanmar" <?php echo e(old('country', $user->country) == 'Myanmar' ? 'selected' : ''); ?>>Myanmar</option>
                        <option value="Namibie" <?php echo e(old('country', $user->country) == 'Namibie' ? 'selected' : ''); ?>>Namibie</option>
                        <option value="Népal" <?php echo e(old('country', $user->country) == 'Népal' ? 'selected' : ''); ?>>Népal</option>
                        <option value="Nicaragua" <?php echo e(old('country', $user->country) == 'Nicaragua' ? 'selected' : ''); ?>>Nicaragua</option>
                        <option value="Niger" <?php echo e(old('country', $user->country) == 'Niger' ? 'selected' : ''); ?>>Niger</option>
                        <option value="Nigeria" <?php echo e(old('country', $user->country) == 'Nigeria' ? 'selected' : ''); ?>>Nigeria</option>
                        <option value="Norvège" <?php echo e(old('country', $user->country) == 'Norvège' ? 'selected' : ''); ?>>Norvège</option>
                        <option value="Nouvelle-Zélande" <?php echo e(old('country', $user->country) == 'Nouvelle-Zélande' ? 'selected' : ''); ?>>Nouvelle-Zélande</option>
                        <option value="Oman" <?php echo e(old('country', $user->country) == 'Oman' ? 'selected' : ''); ?>>Oman</option>
                        <option value="Ouganda" <?php echo e(old('country', $user->country) == 'Ouganda' ? 'selected' : ''); ?>>Ouganda</option>
                        <option value="Ouzbékistan" <?php echo e(old('country', $user->country) == 'Ouzbékistan' ? 'selected' : ''); ?>>Ouzbékistan</option>
                        <option value="Pakistan" <?php echo e(old('country', $user->country) == 'Pakistan' ? 'selected' : ''); ?>>Pakistan</option>
                        <option value="Palestine" <?php echo e(old('country', $user->country) == 'Palestine' ? 'selected' : ''); ?>>Palestine</option>
                        <option value="Panama" <?php echo e(old('country', $user->country) == 'Panama' ? 'selected' : ''); ?>>Panama</option>
                        <option value="Papouasie-Nouvelle-Guinée" <?php echo e(old('country', $user->country) == 'Papouasie-Nouvelle-Guinée' ? 'selected' : ''); ?>>Papouasie-Nouvelle-Guinée</option>
                        <option value="Paraguay" <?php echo e(old('country', $user->country) == 'Paraguay' ? 'selected' : ''); ?>>Paraguay</option>
                        <option value="Pays-Bas" <?php echo e(old('country', $user->country) == 'Pays-Bas' ? 'selected' : ''); ?>>Pays-Bas</option>
                        <option value="Pérou" <?php echo e(old('country', $user->country) == 'Pérou' ? 'selected' : ''); ?>>Pérou</option>
                        <option value="Philippines" <?php echo e(old('country', $user->country) == 'Philippines' ? 'selected' : ''); ?>>Philippines</option>
                        <option value="Pologne" <?php echo e(old('country', $user->country) == 'Pologne' ? 'selected' : ''); ?>>Pologne</option>
                        <option value="Portugal" <?php echo e(old('country', $user->country) == 'Portugal' ? 'selected' : ''); ?>>Portugal</option>
                        <option value="Qatar" <?php echo e(old('country', $user->country) == 'Qatar' ? 'selected' : ''); ?>>Qatar</option>
                        <option value="République centrafricaine" <?php echo e(old('country', $user->country) == 'République centrafricaine' ? 'selected' : ''); ?>>République centrafricaine</option>
                        <option value="République démocratique du Congo" <?php echo e(old('country', $user->country) == 'République démocratique du Congo' ? 'selected' : ''); ?>>République démocratique du Congo</option>
                        <option value="République dominicaine" <?php echo e(old('country', $user->country) == 'République dominicaine' ? 'selected' : ''); ?>>République dominicaine</option>
                        <option value="République tchèque" <?php echo e(old('country', $user->country) == 'République tchèque' ? 'selected' : ''); ?>>République tchèque</option>
                        <option value="Roumanie" <?php echo e(old('country', $user->country) == 'Roumanie' ? 'selected' : ''); ?>>Roumanie</option>
                        <option value="Royaume-Uni" <?php echo e(old('country', $user->country) == 'Royaume-Uni' ? 'selected' : ''); ?>>Royaume-Uni</option>
                        <option value="Russie" <?php echo e(old('country', $user->country) == 'Russie' ? 'selected' : ''); ?>>Russie</option>
                        <option value="Rwanda" <?php echo e(old('country', $user->country) == 'Rwanda' ? 'selected' : ''); ?>>Rwanda</option>
                        <option value="Saint-Marin" <?php echo e(old('country', $user->country) == 'Saint-Marin' ? 'selected' : ''); ?>>Saint-Marin</option>
                        <option value="Salvador" <?php echo e(old('country', $user->country) == 'Salvador' ? 'selected' : ''); ?>>Salvador</option>
                        <option value="Sénégal" <?php echo e(old('country', $user->country) == 'Sénégal' ? 'selected' : ''); ?>>Sénégal</option>
                        <option value="Serbie" <?php echo e(old('country', $user->country) == 'Serbie' ? 'selected' : ''); ?>>Serbie</option>
                        <option value="Seychelles" <?php echo e(old('country', $user->country) == 'Seychelles' ? 'selected' : ''); ?>>Seychelles</option>
                        <option value="Sierra Leone" <?php echo e(old('country', $user->country) == 'Sierra Leone' ? 'selected' : ''); ?>>Sierra Leone</option>
                        <option value="Singapour" <?php echo e(old('country', $user->country) == 'Singapour' ? 'selected' : ''); ?>>Singapour</option>
                        <option value="Slovaquie" <?php echo e(old('country', $user->country) == 'Slovaquie' ? 'selected' : ''); ?>>Slovaquie</option>
                        <option value="Slovénie" <?php echo e(old('country', $user->country) == 'Slovénie' ? 'selected' : ''); ?>>Slovénie</option>
                        <option value="Somalie" <?php echo e(old('country', $user->country) == 'Somalie' ? 'selected' : ''); ?>>Somalie</option>
                        <option value="Soudan" <?php echo e(old('country', $user->country) == 'Soudan' ? 'selected' : ''); ?>>Soudan</option>
                        <option value="Soudan du Sud" <?php echo e(old('country', $user->country) == 'Soudan du Sud' ? 'selected' : ''); ?>>Soudan du Sud</option>
                        <option value="Sri Lanka" <?php echo e(old('country', $user->country) == 'Sri Lanka' ? 'selected' : ''); ?>>Sri Lanka</option>
                        <option value="Suède" <?php echo e(old('country', $user->country) == 'Suède' ? 'selected' : ''); ?>>Suède</option>
                        <option value="Suisse" <?php echo e(old('country', $user->country) == 'Suisse' ? 'selected' : ''); ?>>Suisse</option>
                        <option value="Suriname" <?php echo e(old('country', $user->country) == 'Suriname' ? 'selected' : ''); ?>>Suriname</option>
                        <option value="Syrie" <?php echo e(old('country', $user->country) == 'Syrie' ? 'selected' : ''); ?>>Syrie</option>
                        <option value="Tadjikistan" <?php echo e(old('country', $user->country) == 'Tadjikistan' ? 'selected' : ''); ?>>Tadjikistan</option>
                        <option value="Tanzanie" <?php echo e(old('country', $user->country) == 'Tanzanie' ? 'selected' : ''); ?>>Tanzanie</option>
                        <option value="Tchad" <?php echo e(old('country', $user->country) == 'Tchad' ? 'selected' : ''); ?>>Tchad</option>
                        <option value="Thaïlande" <?php echo e(old('country', $user->country) == 'Thaïlande' ? 'selected' : ''); ?>>Thaïlande</option>
                        <option value="Timor oriental" <?php echo e(old('country', $user->country) == 'Timor oriental' ? 'selected' : ''); ?>>Timor oriental</option>
                        <option value="Togo" <?php echo e(old('country', $user->country) == 'Togo' ? 'selected' : ''); ?>>Togo</option>
                        <option value="Trinité-et-Tobago" <?php echo e(old('country', $user->country) == 'Trinité-et-Tobago' ? 'selected' : ''); ?>>Trinité-et-Tobago</option>
                        <option value="Tunisie" <?php echo e(old('country', $user->country) == 'Tunisie' ? 'selected' : ''); ?>>Tunisie</option>
                        <option value="Turkménistan" <?php echo e(old('country', $user->country) == 'Turkménistan' ? 'selected' : ''); ?>>Turkménistan</option>
                        <option value="Turquie" <?php echo e(old('country', $user->country) == 'Turquie' ? 'selected' : ''); ?>>Turquie</option>
                        <option value="Ukraine" <?php echo e(old('country', $user->country) == 'Ukraine' ? 'selected' : ''); ?>>Ukraine</option>
                        <option value="Uruguay" <?php echo e(old('country', $user->country) == 'Uruguay' ? 'selected' : ''); ?>>Uruguay</option>
                        <option value="Vanuatu" <?php echo e(old('country', $user->country) == 'Vanuatu' ? 'selected' : ''); ?>>Vanuatu</option>
                        <option value="Vatican" <?php echo e(old('country', $user->country) == 'Vatican' ? 'selected' : ''); ?>>Vatican</option>
                        <option value="Venezuela" <?php echo e(old('country', $user->country) == 'Venezuela' ? 'selected' : ''); ?>>Venezuela</option>
                        <option value="Viêt Nam" <?php echo e(old('country', $user->country) == 'Viêt Nam' ? 'selected' : ''); ?>>Viêt Nam</option>
                        <option value="Yémen" <?php echo e(old('country', $user->country) == 'Yémen' ? 'selected' : ''); ?>>Yémen</option>
                        <option value="Zambie" <?php echo e(old('country', $user->country) == 'Zambie' ? 'selected' : ''); ?>>Zambie</option>
                        <option value="Zimbabwe" <?php echo e(old('country', $user->country) == 'Zimbabwe' ? 'selected' : ''); ?>>Zimbabwe</option>
                    </select>
                    <?php $__errorArgs = ['country'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                        <p class="mt-1 text-sm text-red-400"><?php echo e($message); ?></p>
                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>
            </div>

            <!-- Address with Google Maps -->
            <div class="mt-6">
                <?php echo $__env->make('admin.partials.google-map', [
                    'id' => 'user-map',
                    'label' => 'Localisation de l\'utilisateur',
                    'latitude' => old('latitude', $user->latitude),
                    'longitude' => old('longitude', $user->longitude),
                    'address' => old('address', $user->address),
                    'zoom' => 13
                ], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
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
                               class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 <?php $__errorArgs = ['password'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> border-red-500 <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>">
                        <?php $__errorArgs = ['password'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                            <p class="mt-1 text-sm text-red-400"><?php echo e($message); ?></p>
                        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
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
                <a href="<?php echo e(route('admin.users.index')); ?>"
                   class="px-6 py-3 bg-gray-200 text-white rounded-lg hover:bg-gray-700 transition-all">
                    <i class="fas fa-times mr-2"></i>
                    Annuler
                </a>
            </div>
        </form>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/djstar-service/Documents/Project/Projet Collab/ASSO/Asso-Backend/resources/views/admin/users/edit.blade.php ENDPATH**/ ?>