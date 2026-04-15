<?php $__env->startSection('content'); ?>
<div class="p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Gestion des Utilisateurs</h1>
            <p class="text-gray-400 mt-1">Gérez tous les utilisateurs de la plateforme</p>
        </div>
        <a href="<?php echo e(route('admin.users.create')); ?>"
           class="px-6 py-3 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all duration-200 shadow-lg hover:shadow-xl flex items-center gap-2">
            <i class="fas fa-plus"></i>
            Nouvel Utilisateur
        </a>
    </div>

    <?php if(session('success')): ?>
        <div class="mb-6 p-4 bg-green-900/20 border-l-4 border-green-500 rounded-lg">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <p class="text-green-700"><?php echo e(session('success')); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if(session('error')): ?>
        <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded-lg">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                <p class="text-red-400"><?php echo e(session('error')); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filters Section -->
    <div class="bg-dark-100 rounded-xl shadow-lg p-6 mb-6 border border-dark-200">
        <form method="GET" action="<?php echo e(route('admin.users.index')); ?>" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Search -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-search text-primary-500 mr-1"></i>
                        Recherche
                    </label>
                    <input type="text"
                           name="search"
                           value="<?php echo e(request('search')); ?>"
                           placeholder="Nom, email, téléphone..."
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>

                <!-- Role Filter -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-user-tag text-primary-500 mr-1"></i>
                        Rôle
                    </label>
                    <select name="role" class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">Tous les rôles</option>
                        <option value="client" <?php echo e(request('role') == 'client' ? 'selected' : ''); ?>>Client</option>
                        <option value="vendeur" <?php echo e(request('role') == 'vendeur' ? 'selected' : ''); ?>>Vendeur</option>
                        <option value="livreur" <?php echo e(request('role') == 'livreur' ? 'selected' : ''); ?>>Livreur</option>
                    </select>
                </div>

                <!-- Gender Filter -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-venus-mars text-primary-500 mr-1"></i>
                        Genre
                    </label>
                    <select name="gender" class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">Tous les genres</option>
                        <option value="male" <?php echo e(request('gender') == 'male' ? 'selected' : ''); ?>>Homme</option>
                        <option value="female" <?php echo e(request('gender') == 'female' ? 'selected' : ''); ?>>Femme</option>
                        <option value="other" <?php echo e(request('gender') == 'other' ? 'selected' : ''); ?>>Autre</option>
                    </select>
                </div>

                <!-- Country Filter -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-globe text-primary-500 mr-1"></i>
                        Pays
                    </label>
                    <select name="country" class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">Tous les pays</option>
                        <?php $__currentLoopData = [
                            'Afghanistan', 'Afrique du Sud', 'Albanie', 'Algérie', 'Allemagne', 'Andorre', 'Angola', 'Arabie Saoudite',
                            'Argentine', 'Arménie', 'Australie', 'Autriche', 'Azerbaïdjan', 'Bahamas', 'Bahreïn', 'Bangladesh',
                            'Belgique', 'Bénin', 'Bhoutan', 'Bolivie', 'Bosnie-Herzégovine', 'Botswana', 'Brésil', 'Brunei',
                            'Bulgarie', 'Burkina Faso', 'Burundi', 'Cambodge', 'Cameroun', 'Canada', 'Cap-Vert', 'Chili', 'Chine',
                            'Chypre', 'Colombie', 'Comores', 'Congo', 'Corée du Nord', 'Corée du Sud', 'Costa Rica', 'Côte d\'Ivoire',
                            'Croatie', 'Cuba', 'Danemark', 'Djibouti', 'Égypte', 'Émirats arabes unis', 'Équateur', 'Érythrée',
                            'Espagne', 'Estonie', 'États-Unis', 'Éthiopie', 'Finlande', 'France', 'Gabon', 'Gambie', 'Géorgie',
                            'Ghana', 'Grèce', 'Guatemala', 'Guinée', 'Guinée équatoriale', 'Guinée-Bissau', 'Guyana', 'Haïti',
                            'Honduras', 'Hongrie', 'Inde', 'Indonésie', 'Irak', 'Iran', 'Irlande', 'Islande', 'Israël', 'Italie',
                            'Jamaïque', 'Japon', 'Jordanie', 'Kazakhstan', 'Kenya', 'Kirghizistan', 'Koweït', 'Laos', 'Lesotho',
                            'Lettonie', 'Liban', 'Libéria', 'Libye', 'Liechtenstein', 'Lituanie', 'Luxembourg', 'Macédoine du Nord',
                            'Madagascar', 'Malaisie', 'Malawi', 'Maldives', 'Mali', 'Malte', 'Maroc', 'Maurice', 'Mauritanie',
                            'Mexique', 'Moldavie', 'Monaco', 'Mongolie', 'Monténégro', 'Mozambique', 'Myanmar', 'Namibie', 'Népal',
                            'Nicaragua', 'Niger', 'Nigeria', 'Norvège', 'Nouvelle-Zélande', 'Oman', 'Ouganda', 'Ouzbékistan',
                            'Pakistan', 'Palestine', 'Panama', 'Papouasie-Nouvelle-Guinée', 'Paraguay', 'Pays-Bas', 'Pérou',
                            'Philippines', 'Pologne', 'Portugal', 'Qatar', 'République centrafricaine', 'République démocratique du Congo',
                            'République dominicaine', 'République tchèque', 'Roumanie', 'Royaume-Uni', 'Russie', 'Rwanda', 'Saint-Marin',
                            'Salvador', 'Sénégal', 'Serbie', 'Seychelles', 'Sierra Leone', 'Singapour', 'Slovaquie', 'Slovénie',
                            'Somalie', 'Soudan', 'Soudan du Sud', 'Sri Lanka', 'Suède', 'Suisse', 'Suriname', 'Syrie', 'Tadjikistan',
                            'Tanzanie', 'Tchad', 'Thaïlande', 'Timor oriental', 'Togo', 'Trinité-et-Tobago', 'Tunisie', 'Turkménistan',
                            'Turquie', 'Ukraine', 'Uruguay', 'Vanuatu', 'Vatican', 'Venezuela', 'Viêt Nam', 'Yémen', 'Zambie', 'Zimbabwe'
                        ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $country): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($country); ?>" <?php echo e(request('country') == $country ? 'selected' : ''); ?>><?php echo e($country); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                </div>
            </div>

            <!-- Date Range Filter -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-calendar-alt text-primary-500 mr-1"></i>
                        Date de début
                    </label>
                    <input type="date"
                           name="date_from"
                           value="<?php echo e(request('date_from')); ?>"
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        <i class="fas fa-calendar-alt text-primary-500 mr-1"></i>
                        Date de fin
                    </label>
                    <input type="date"
                           name="date_to"
                           value="<?php echo e(request('date_to')); ?>"
                           class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-3">
                <button type="submit"
                        class="px-6 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                    <i class="fas fa-filter mr-2"></i>
                    Filtrer
                </button>
                <a href="<?php echo e(route('admin.users.index')); ?>"
                   class="px-6 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-all">
                    <i class="fas fa-times mr-2"></i>
                    Réinitialiser
                </a>
            </div>
        </form>
    </div>

    <!-- Users Table -->
    <div class="bg-dark-100 rounded-xl shadow-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-dark-200">
                <thead class="bg-dark-50">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-white uppercase tracking-wider">
                            Utilisateur
                        </th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-white uppercase tracking-wider">
                            Contact
                        </th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-white uppercase tracking-wider">
                            Rôle
                        </th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-white uppercase tracking-wider">
                            Code Parrainage
                        </th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-white uppercase tracking-wider">
                            Boutiques
                        </th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-white uppercase tracking-wider">
                            Inscription
                        </th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-white uppercase tracking-wider">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-dark-100 divide-y divide-dark-200">
                    <?php $__empty_1 = true; $__currentLoopData = $users; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $user): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr class="hover:bg-dark-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 flex-shrink-0">
                                        <div class="h-10 w-10 rounded-full bg-gradient-to-br from-orange-400 to-orange-600 flex items-center justify-center text-white font-bold">
                                            <?php echo e(strtoupper(substr($user->first_name, 0, 1))); ?><?php echo e(strtoupper(substr($user->last_name, 0, 1))); ?>

                                        </div>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-white">
                                            <?php echo e($user->name); ?>

                                        </div>
                                        <div class="text-sm text-gray-500 flex items-center gap-1">
                                            <?php if($user->gender == 'male'): ?>
                                                <i class="fas fa-mars text-blue-500"></i>
                                            <?php elseif($user->gender == 'female'): ?>
                                                <i class="fas fa-venus text-pink-500"></i>
                                            <?php else: ?>
                                                <i class="fas fa-venus-mars text-purple-500"></i>
                                            <?php endif; ?>
                                            <?php echo e($user->country); ?>

                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-white">
                                    <i class="fas fa-envelope text-primary-500 mr-1"></i>
                                    <?php echo e($user->email); ?>

                                </div>
                                <?php if($user->phone): ?>
                                    <div class="text-sm text-gray-500">
                                        <i class="fas fa-phone text-primary-500 mr-1"></i>
                                        <?php echo e($user->phone); ?>

                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if($user->role == 'client'): ?>
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-500/20 text-blue-300 border border-blue-500/50">
                                        <i class="fas fa-user mr-1"></i> Client
                                    </span>
                                <?php elseif($user->role == 'vendeur'): ?>
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-500/20 text-green-300 border border-green-500/50">
                                        <i class="fas fa-store mr-1"></i> Vendeur
                                    </span>
                                <?php elseif($user->role == 'livreur'): ?>
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-orange-500/20 text-orange-300 border border-orange-500/50">
                                        <i class="fas fa-truck mr-1"></i> Livreur
                                    </span>
                                <?php else: ?>
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-500/20 text-gray-300 border border-gray-500/50">
                                        <i class="fas fa-question mr-1"></i> Non défini
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if($user->referral_code): ?>
                                    <div class="flex items-center gap-2">
                                        <code class="px-3 py-1 bg-primary-500/20 text-primary-300 rounded border border-primary-500/50 font-mono text-sm">
                                            <?php echo e($user->referral_code); ?>

                                        </code>
                                        <button onclick="copyToClipboard('<?php echo e($user->referral_code); ?>')"
                                                class="text-primary-400 hover:text-primary-300 transition-colors"
                                                title="Copier">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                    <?php if($user->referrals_count > 0): ?>
                                        <div class="text-xs text-gray-400 mt-1">
                                            <i class="fas fa-users text-green-400"></i>
                                            <?php echo e($user->referrals_count); ?> filleul(s)
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-gray-500 text-sm italic">Aucun</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-white">
                                <?php if($user->shops->count() > 0): ?>
                                    <span class="text-primary-600 font-semibold">
                                        <?php echo e($user->shops->count()); ?> boutique(s)
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-400">Aucune</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo e($user->created_at->format('d/m/Y')); ?>

                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end gap-2">
                                    <a href="<?php echo e(route('admin.users.show', $user)); ?>"
                                       class="text-primary-500 hover:text-primary-400 transition-colors"
                                       title="Voir">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="<?php echo e(route('admin.users.edit', $user)); ?>"
                                       class="text-primary-600 hover:text-orange-900 transition-colors"
                                       title="Modifier">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="<?php echo e(route('admin.users.destroy', $user)); ?>"
                                          method="POST"
                                          class="inline"
                                          onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ?');">
                                        <?php echo csrf_field(); ?>
                                        <?php echo method_field('DELETE'); ?>
                                        <button type="submit"
                                                class="text-red-400 hover:text-red-500 transition-colors"
                                                title="Supprimer">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-users text-4xl text-gray-300 mb-3"></i>
                                <p class="text-lg">Aucun utilisateur trouvé</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if($users->hasPages()): ?>
            <div class="bg-dark-50 px-6 py-4 border-t border-dark-200">
                <?php echo e($users->links()); ?>

            </div>
        <?php endif; ?>
    </div>

    <!-- Stats Summary -->
    <div class="mt-6 grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl p-4 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm">Total Utilisateurs</p>
                    <p class="text-2xl font-bold"><?php echo e($users->total()); ?></p>
                </div>
                <i class="fas fa-users text-3xl text-blue-200"></i>
            </div>
        </div>
        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl p-4 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm">Vendeurs</p>
                    <p class="text-2xl font-bold"><?php echo e(\App\Models\User::where('role', 'vendeur')->count()); ?></p>
                </div>
                <i class="fas fa-store text-3xl text-green-200"></i>
            </div>
        </div>
        <div class="bg-gradient-to-br from-primary-500 to-primary-600 rounded-xl p-4 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-orange-100 text-sm">Livreurs</p>
                    <p class="text-2xl font-bold"><?php echo e(\App\Models\User::where('role', 'livreur')->count()); ?></p>
                </div>
                <i class="fas fa-truck text-3xl text-orange-200"></i>
            </div>
        </div>
        <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl p-4 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-purple-100 text-sm">Clients</p>
                    <p class="text-2xl font-bold"><?php echo e(\App\Models\User::where('role', 'client')->count()); ?></p>
                </div>
                <i class="fas fa-user text-3xl text-purple-200"></i>
            </div>
        </div>
    </div>
</div>

<?php $__env->startPush('scripts'); ?>
<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        // Afficher une notification
        const notification = document.createElement('div');
        notification.className = 'fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50 flex items-center gap-2';
        notification.innerHTML = '<i class="fas fa-check-circle"></i> Code copié !';
        document.body.appendChild(notification);

        setTimeout(() => {
            notification.remove();
        }, 2000);
    }).catch(function(err) {
        console.error('Erreur lors de la copie:', err);
    });
}
</script>
<?php $__env->stopPush(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('admin.layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/djstar-service/Documents/Project/Projet Collab/ASSO/Asso-Backend/resources/views/admin/users/index.blade.php ENDPATH**/ ?>