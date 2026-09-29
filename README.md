<p align="center">
  <img src="./public/logo/Asso.png" width="200" alt="ASSO Logo">
</p>

<h1 align="center">ASSO - Backend API</h1>

<p align="center">
  <strong>API Backend pour la plateforme d'association communautaire</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-12.0-FF2D20?style=flat&logo=laravel" alt="Laravel">
  <img src="https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat&logo=php" alt="PHP">
  <img src="https://img.shields.io/badge/License-MIT-green.svg" alt="License">
</p>

## À propos

ASSO est une plateforme complète de gestion d'association offrant un ensemble de fonctionnalités pour faciliter la vie associative. Cette API backend alimentée par Laravel fournit tous les services nécessaires pour une gestion moderne et efficace.

## Fonctionnalités principales

### Commerce & Échanges
- **Boutique en ligne** - Gestion complète de produits avec catégories et sous-catégories
- **Système d'échange** - Plateforme d'échange entre membres
- **Transactions** - Suivi et gestion des transactions
- **Avis produits** - Système de notation et commentaires

### Communication
- **Messagerie** - Conversations privées entre membres
- **Confessions** - Système de confessions anonymes avec likes et favoris
- **Annonces** - Diffusion d'annonces à la communauté
- **Bannières** - Gestion des bannières promotionnelles

### Gestion des membres
- **Authentification** - Système sécurisé avec Sanctum
- **Profils utilisateurs** - Gestion complète des profils
- **Programme d'affiliation** - Système de parrainage avec commissions
- **Packages** - Gestion des abonnements et packages

### Administration
- **Dashboard** - Tableau de bord administrateur complet
- **Support** - Système de tickets de support
- **Documents** - Gestionnaire de documents avec catégories
- **Coffre-fort** - Stockage sécurisé des credentials
- **Pages légales** - Gestion des CGU, politique de confidentialité, etc.
- **Mode maintenance** - Activation/désactivation du mode maintenance
- **Paramètres** - Configuration centralisée par groupes

### Outils
- **Base de données** - Gestion et sauvegarde
- **Carte interactive** - Visualisation géographique
- **Clics contacts** - Tracking des interactions

## Prérequis

- PHP >= 8.2 (extensions: PDO, pdo_pgsql, Mbstring, OpenSSL, Tokenizer, XML, Ctype, JSON, GD, BCMath, Intl)
- Composer
- Node.js & NPM
- **PostgreSQL >= 14** (recommandé)

> **Pourquoi PostgreSQL ?** La recherche produits s'appuie sur les extensions
> `pg_trgm` et `unaccent` (index GIN trigramme, `tsvector`), et plusieurs migrations
> utilisent des `CHECK constraints` spécifiques. Sur MySQL/SQLite ces migrations sont
> des no-op et la recherche retombe sur de simples `LIKE` : l'app fonctionne, mais en
> mode dégradé. Utilisez PostgreSQL pour être iso-production.

## Installation locale

### Installation automatique (recommandé)

```bash
./setup-local.sh
```

Le script est **idempotent** (relançable sans risque) et enchaîne :

1. vérification des prérequis (PHP, Composer, NPM, PostgreSQL démarré) ;
2. `composer install` ;
3. création du `.env` (depuis `.env.example`) + `php artisan key:generate` ;
4. création de l'arborescence `storage/framework/*` et du lien `public/storage` ;
5. création de la base + extensions `pg_trgm` / `unaccent` ;
6. `php artisan migrate` puis `php artisan db:seed` ;
7. seeders de démo (boutiques, produits, avis, packages) — voir ci-dessous ;
8. `npm install` + `npm run build`.

Pour une base propre façon production (sans boutiques ni produits factices) :

```bash
SEED_DEMO=0 ./setup-local.sh
```

La base ciblée est `asso_local` avec l'utilisateur système courant. Pour en changer :

```bash
DB_NAME=ma_base DB_USER=mon_user ./setup-local.sh
```

⚠️ Le script **ne crée pas** le `.env` s'il existe déjà, et **ne recrée pas** la base si
elle existe. Adaptez ensuite `DB_DATABASE` / `DB_USERNAME` dans le `.env` si besoin.

### Installation manuelle

```bash
composer install
cp .env.example .env
php artisan key:generate

# Arborescence storage — NON versionnée : sans elle, l'app renvoie
# "Please provide a valid cache path" (HTTP 500) sur toute vue Blade.
mkdir -p storage/framework/{views,cache/data,sessions,testing} storage/logs
php artisan storage:link

createdb asso_local
psql -d asso_local -c "CREATE EXTENSION IF NOT EXISTS pg_trgm; CREATE EXTENSION IF NOT EXISTS unaccent;"

# Puis renseigner dans .env :
#   DB_CONNECTION=pgsql
#   DB_DATABASE=asso_local
#   DB_USERNAME=<votre utilisateur postgres>

php artisan migrate
php artisan db:seed
npm install && npm run build
```

### Services optionnels

| Service  | Usage                       | Activation                                                        |
|----------|-----------------------------|-------------------------------------------------------------------|
| Mailpit  | Capture des mails en local  | `brew services start mailpit`, puis `MAIL_MAILER=smtp` (port 1025) — UI sur http://localhost:8025 |
| Redis    | Cache / queues alternatifs  | `brew services start redis`, puis `CACHE_STORE=redis`              |
| Reverb   | Websockets temps réel       | `php artisan reverb:start` (port 8080)                             |
| Firebase | Notifications push (FCM)    | Déposer le service account dans `storage/app/private/firebase/service-account.json` |
| Asso-Lookup | Fiche produit pré-remplie par scan (sans LLM) | Lancer `../Asso-Lookup` (voir son README), puis `PRODUCT_LOOKUP_URL` et `PRODUCT_LOOKUP_TOKEN` |

Les intégrations tierces (Stripe, KPay, Firebase, Asso-Lookup) sont **laissées vides** dans le
`.env` local : les services concernés dégradent proprement. Renseignez uniquement les clés
de **test/sandbox** si vous devez tester ces parcours.

### Lancement du serveur de développement

```bash
composer run dev
```

Cette commande lance simultanément:
- Serveur Laravel (http://localhost:8000)
- Worker de queue
- Logs en temps réel (Pail)
- Vite dev server pour les assets

Alternative minimale : `php artisan serve`.

### Comptes de démonstration

Créés par les seeders, tous avec le mot de passe `password` :

| Rôle    | Email                       |
|---------|-----------------------------|
| Admin   | `admin@asso.com`            |
| Vendeur | `amina.kossou@vendeur.com`  |
| Client  | `marie.ahossou@client.com`  |
| Livreur | `moussa.traore@livreur.com` |

- Back-office : http://localhost:8000/admin/login
- API : `POST /api/v1/auth/login-email` (renvoie un token Sanctum)

### Données de démo

Le `DatabaseSeeder` par défaut ne crée **ni boutiques ni produits** (en production, ils
sont créés par de vrais utilisateurs). `setup-local.sh` lance en plus, pour le confort du
développement local, les seeders suivants — reproductibles à la main :

```bash
php artisan db:seed --class=ProductSeeder        # 3 boutiques + 19 produits/services
php artisan db:seed --class=PackageSeeder        # 9 packages (stockage, boost, certification)
php artisan db:seed --class=ProductReviewsSeeder # ~171 avis notés
php artisan db:seed --class=OrderTestSeeder      # client.test@asso.com (wallet 500 000 FCFA) + produits
```

Résultat : 20 utilisateurs, 3 boutiques, 61 produits, 171 avis, 9 packages.

⚠️ **Ces seeders ne sont pas idempotents** : `products.slug` porte une contrainte unique,
les relancer sur une base déjà peuplée échoue en `UniqueConstraintViolationException`.
`setup-local.sh` les saute automatiquement si des produits existent déjà. Pour repartir de
zéro : `php artisan migrate:fresh --seed` puis relancer le script.

**Aucun seeder ne crée de commandes** (`orders` reste vide) : `OrderTestSeeder` prépare
seulement le client et les produits, les commandes se passent via l'app ou l'API.

#### Seeders non branchés

`ExtendedProductsSeeder` et `MultipleProductsSeeder` ciblent des IDs **codés en dur**
(`user_id = 21`, `shop_id = 10`) qui n'existent pas sur une base fraîche : ils échouent en
violation de clé étrangère. Ils téléchargent en outre 79 images depuis `images.unsplash.com`
(lent, nécessite le réseau). À corriger avant usage.

## Utilisation

### API Endpoints

#### Settings (Public)
```
GET /api/settings - Liste tous les paramètres
GET /api/settings/group/{group} - Paramètres par groupe
GET /api/settings/{key} - Paramètre spécifique
```

#### Confessions (Authentifié)
```
GET    /api/v1/confessions - Liste des confessions
POST   /api/v1/confessions - Créer une confession
GET    /api/v1/confessions/{id} - Détails d'une confession
PUT    /api/v1/confessions/{id} - Modifier une confession
DELETE /api/v1/confessions/{id} - Supprimer une confession
POST   /api/v1/confessions/{id}/favorite - Ajouter/retirer des favoris
POST   /api/v1/confessions/{id}/like - Liker une confession
POST   /api/v1/confessions/{id}/reveal-identity - Révéler son identité
```

### Scripts disponibles

```bash
# Installation complète
composer run setup

# Développement (tous les services)
composer run dev

# Tests
composer run test

# Formattage du code
./vendor/bin/pint
```

## Structure du projet

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Admin/          # Contrôleurs admin
│   │   └── Api/            # Contrôleurs API
│   └── Middleware/         # Middlewares personnalisés
├── Models/                 # Modèles Eloquent
└── Helpers/               # Fonctions helper

database/
├── migrations/            # Migrations de base de données
└── seeders/              # Seeders

routes/
├── api.php               # Routes API
├── web.php               # Routes web
└── console.php           # Commandes Artisan

public/
└── logo/                 # Assets logo
```

## Sécurité

- Authentification via Laravel Sanctum
- Middleware de vérification du mode maintenance
- Validation des entrées utilisateur
- Protection CSRF
- Hashage sécurisé des mots de passe
- Stockage sécurisé des credentials

## Tests

```bash
composer run test          # ou : php artisan test
php artisan test --filter=NomDuTest
```

Les tests tournent sur **SQLite en mémoire** (voir `phpunit.xml`), indépendamment du
`.env` : aucune configuration supplémentaire n'est requise, et la base de dev n'est
jamais touchée. Les migrations spécifiques à PostgreSQL y sont des no-op.

> État au 19/09/2026 : **182 tests passent, 6 échouent** (`StripePaymentSettingsTest` ×2,
> `DiaspoBookingLifecycleTest` ×3, `DiaspoUnverifiedPublicationTest` ×1). Ces échecs sont
> **préexistants** et sans rapport avec l'installation locale — voir `DEPLOY_NOTES_UNIFY.md`
> §4 sur la cohabitation des deux implémentations Diaspo.

## Contribution

Les contributions sont les bienvenues! Veuillez suivre ces étapes:

1. Fork le projet
2. Créer une branche pour votre fonctionnalité (`git checkout -b feature/AmazingFeature`)
3. Commit vos changements (`git commit -m 'Add some AmazingFeature'`)
4. Push vers la branche (`git push origin feature/AmazingFeature`)
5. Ouvrir une Pull Request

## Licence

Ce projet est sous licence MIT. Voir le fichier `LICENSE` pour plus de détails.
