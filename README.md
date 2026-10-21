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

Les intégrations tierces (Stripe, KPay, Gemini, Firebase) sont **laissées vides** dans le
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

## Relais transporteur piloté par ASSO

Les colis transporteur sont suivis dans **Expéditions** et, pour les imports, dans
**Commandes en gros**. Solex et les autres transporteurs n'ont pas d'espace de
connexion agence. Les courses locales `local` gardent leur parcours coursier.

Parcours import : transport international → réception au dépôt ASSO de Douala →
demande d'enlèvement transmise → acceptation → récupération physique par le
partenaire. Hors de Douala : acheminement partenaire puis arrivée à destination.
ASSO enregistre ensuite le départ en livraison et la remise à domicile, ou la
disponibilité et le retrait en agence.

Le contact avec le partenaire est effectué par l'équipe (téléphone, e-mail,
WhatsApp, sur place ou document), puis tracé avec un contact et une référence.
Aucun message externe n'est envoyé automatiquement par ces actions. Les preuves
restent internes ; la note client et les étapes sont notifiées à l'acheteur.

Créer les agences réelles dans **Partenaires logistiques**, puis affecter l'agence
au colis. Le client voit ses coordonnées et présente sa référence de commande au
retrait. Aucun code client n'est demandé au transporteur dans ASSO.

La remise physique ouvre les 48 heures de contrôle. Le bouton client « J'ai reçu
mon colis » accuse ensuite réception sans relancer ce délai ni effectuer un second
règlement du partenaire. Les anciens colis disponibles sans agence doivent recevoir
une agence puis une nouvelle confirmation d'arrivée et de disponibilité.

Au déploiement, exécuter `php artisan migrate` pour les migrations
`2026_10_21_180000_add_agency_pickup` et `2026_10_21_190000_add_shipment_confirmations`.

## Transport international — configuration de la rubrique

La rubrique admin **Transport : partenaires** (`/admin/maritime`) gère les offres
**aériennes et maritimes**. Les anciennes URL et noms de tables sont conservés
pour les réservations existantes. Dans le mobile, les offres des partenaires
aériens sont dans **Avion → Partenaires**, celles par bateau dans **Maritime**.

### Initialisation en production

Le serveur de production doit utiliser `APP_ENV=production`, `APP_DEBUG=false`
et son URL HTTPS réelle dans `APP_URL`. Le filtrage des données de démonstration
s'appuie sur cet environnement. Conserver les identifiants de paiement et de base
de données déjà configurés sur ce serveur ; les fixtures locales n'en fournissent pas.

Depuis le répertoire `Asso-Backend` de la version livrée, appliquer les trois
migrations de cette rubrique, dans cet ordre (les tables maritimes historiques
du projet doivent déjà exister) :

```bash
php artisan migrate --path=database/migrations/2026_10_21_180000_create_partner_freight_rates.php --force
php artisan migrate --path=database/migrations/2026_10_21_180100_add_freight_rate_snapshots_to_maritime_departures.php --force
php artisan migrate --path=database/migrations/2026_10_21_190000_mark_transport_demo_data.php --force
php artisan transport:configure
```

Cette commande crée seulement les éléments manquants : **SinoCam Transit & Fret**
(inactif lors de sa création) et ses trois tarifs Chine → Cameroun. Elle ne crée
ni adresse fictive, ni départ, ni compte utilisateur, ni crédit de portefeuille.
Une relance conserve les prix, conditions et activations modifiés dans l'admin.
Ne pas exécuter le seeder global pour initialiser uniquement cette rubrique.

| Offre | Coût partenaire (FCFA) | Prix client (FCFA) | Délai |
|---|---:|---:|---|
| Maritime général | 330 000/CBM | 450 000/CBM | 45–55 jours |
| Maritime sensible | 390 000/CBM | 500 000/CBM | 45–55 jours |
| Aérien général | 12 000/kg | 20 000/kg | 10–14 jours |

Avant d'activer le partenaire en production, compléter dans sa fiche :

1. Les QG réels : ville, adresse précise, téléphone, horaires et activation.
2. Les tarifs des autres routes réellement desservies. Aucun prix Paris → Cameroun
   n'est inventé ou recopié depuis les tarifs chinois.
3. Les dates de départ et capacités réellement confirmées par le partenaire.
   Pour Paris → Cameroun, le champ « Nombre de départs à créer » permet une série
   espacée de 14 jours, à partir de la première date renseignée. Les prochains
   départs ne sont pas inventés automatiquement par une tâche planifiée.
   Chine → Cameroun reste par occasion : saisir chaque date réelle.
4. Le compte de règlement du partenaire et les réglages existants de paiement,
   commission, assurance et dates limites de réservation/dépôt.

Un prix client fixe inclut la marge ASSO (prix client moins coût partenaire) ;
il ne reçoit pas une deuxième commission en pourcentage. Sans prix client fixe,
la commission habituelle s'applique. La conversion existante **1 CBM = 1 000 kg**
est conservée. Les tarifs d'un départ sont figés à sa création ; un départ déjà
réservé ne peut pas changer de route ou de prix.

L'échéance « fret partenaire à l'arrivée » est une condition fournisseur
renseignée pour les routes Chine → Cameroun. Elle ne crée pas un paiement différé
acheteur : l'encaissement à la réservation et le reversement après remise suivent
le circuit ASSO existant, conformément au choix de conserver ce mécanisme.
Pour l'Europe/Amérique/Canada, renseigner les routes et conditions de règlement
au départ en Chine avec leurs prix réels.

Le nom, le logo et les contacts du partenaire ne sont pas exposés dans le catalogue
client. Les adresses, noms de QG, horaires et coordonnées précises ne sont renvoyés
qu'au client disposant d'une réservation payée et non annulée sur le départ.
Les conditions internes et coûts fournisseur ne sont pas affichés au client.

Les services de paiement, webhooks, workers et le cron Laravel existants restent
en place. En particulier, `php artisan schedule:run` doit continuer à être appelé
chaque minute ; `maritime:refund-missed-dropoffs` est déjà planifié pour fermer les
réservations et traiter les colis non déposés. Les migrations seules n'installent
pas ces services. Après déploiement du code, reconstruire les caches de la version
avec `php artisan optimize` et redémarrer les workers existants.

Pour le mobile de production, utiliser le domaine HTTPS de l'API de production,
jamais le `localhost:8001` de développement. Le domaine par défaut du projet est
`https://asso-dashboard.sbs/api` ; on peut le fournir explicitement au build :

```bash
cd ../Asso-Mobile
flutter build appbundle --release --dart-define=API_BASE_URL=https://asso-dashboard.sbs/api
```

### Essais locaux : QG et départs de démonstration

Après les mêmes migrations, dans l'environnement `local` :

```bash
php artisan transport:configure --demo
```

Cette commande ajoute trois QG **[TEST]** (Guangzhou, Paris, Douala), avec adresses
explicitement fictives, puis trois départs Chine → Cameroun (aérien général,
maritime général et sensible). Les dates sont futures et les capacités sont de
100 kg pour l'aérien et 1 000 kg pour le maritime. Le partenaire est activé en local
pour tester le catalogue et les réservations. Aucun montant n'est crédité et
aucune réservation n'est automatiquement payée.

Les QG et départs sont marqués `is_demo`. Une relance ne recrée pas les QG ni les
départs futurs existants et ne remet pas leur capacité restante à zéro. Le QG
Paris est disponible pour les essais, sans création d'un tarif Paris fictif.
L'option `--demo` et le seeder de démonstration sont interdits hors `local/testing`.
En production, même si une base locale était copiée par erreur, ces QG et départs
sont exclus du catalogue public et ne sont pas réservables. Ne pas remplacer leurs
adresses fictives par des adresses réelles pour publier : créer des QG réels.

### Vérification de cette rubrique

```bash
php artisan test tests/Feature/TransportConfigurationTest.php tests/Feature/PartnerFreightRatesTest.php tests/Feature/MaritimeBookingTest.php tests/Feature/MaritimeInsuranceClaimsTest.php
```

Côté Flutter :

```bash
flutter test test/diaspo_air_partner_navigation_test.dart test/partner_freight_visibility_test.dart test/maritime_search_test.dart test/maritime_insurance_test.dart
```

### Emails des actions en attente pour l'administration

`admin:notify-actions` prépare un récapitulatif par membre du back-office. Les
permissions de section et le périmètre boutiques ASSO sont vérifiés pour chaque
destinataire ; les comptes techniques et les clients/vendeurs ne reçoivent pas
ces emails. Les liens conduisent aux dossiers, derrière la connexion et les
contrôles d'accès habituels, sans donner de permission supplémentaire.

Le récapitulatif couvre les annonces immobilières en revue, les bailleurs à
vérifier, les litiges immobiliers, les boutiques/vendeurs et déplacements de
boutiques à vérifier, les commandes payées encore à traiter (ASSO, gros, local,
acompte), les litiges et leurs courses à payer par ASSO, les tickets/messages de
support, les identités DIASPO et les envois avion/maritime encore en cours.
Un vendeur est signalé par sa boutique en attente de vérification : il n'existe
pas de circuit d'approbation distinct pour un simple compte vendeur.
Les paiements non aboutis et les commandes livrées/annulées ne sont pas des tâches
de cette liste. Les notifications immédiates déjà présentes restent actives.

Au déploiement :

```bash
php artisan migrate --force
php artisan admin:notify-actions --dry-run
```

Configurer un vrai transport dans `MAIL_MAILER` et les paramètres SMTP associés,
ainsi qu'un `APP_URL` public correct pour les liens. Les transports `log` et
`array` sont refusés par la commande d'envoi : écrire dans un journal ne signifie
pas que le destinataire a reçu un email. La commande de diagnostic ci-dessus ne
transmet aucun email.

L'ordonnanceur Laravel doit fonctionner en production (cron existant :
`* * * * * cd /chemin/Asso-Backend && php artisan schedule:run >> /dev/null 2>&1`).
Il vérifie les tâches toutes les cinq minutes. Un changement de dossier/étape
entraîne un nouveau récapitulatif au prochain passage. Sans changement, une
relance est envoyée après `ADMIN_ACTION_REMINDER_HOURS` (24 heures par défaut).
Les tâches résolues sortent du récapitulatif. L'état du dernier envoi est conservé
en base ; les échecs SMTP sont journalisés et réessayés au prochain passage,
sans bloquer l'opération métier du client. Un verrou empêche les passages
concurrents. `ADMIN_ACTION_EMAILS_ENABLED=false` désactive ces récapitulatifs.
Aucun worker de queue supplémentaire n'est requis pour cette commande.


### Répartition des paiements immobiliers

Le prix affiché multiplié par le nombre de mois d’avance revient entièrement
au bailleur (ou au cabinet bénéficiaire). La part ASSO et la commission du
cabinet sont ajoutées à cette avance, jamais déduites. Les taux de ces frais
restent appliqués au prix de référence enregistré (`net_price`). Par exemple,
45 000 FCFA de référence, un loyer affiché de 49 500 FCFA et trois mois donnent
148 500 FCFA au bailleur, 13 500 FCFA à ASSO, 6 750 FCFA au cabinet, soit
168 750 FCFA débités au locataire. Les acomptes terrain suivent la même règle.

Les montants sont figés lors de la réservation et utilisés pour le paiement,
le blocage, le déblocage, les litiges et les justificatifs. Les réservations
antérieures conservent leurs montants réellement acceptés et encaissés ; aucune
réécriture de solde ni facturation rétroactive n’est faite. Un ancien devis
envoyé par un client récent est rejeté avant paiement si le total a changé.

### SOLEX — réseau territorial national

Le réseau utilise le référentiel hors ligne INC/OCHA décrit dans
`database/data/README.md` : 10 régions, 58 départements, 360 arrondissements.
Chaque arrondissement est un cercle de collecte/distribution (centre GPS et
rayon en kilomètres à vol d'oiseau), sans quartier obligatoire. Les centroïdes
source et le rayon initial de 10 km sont provisoires et réglables séparément.

```bash
php artisan migrate --force
php artisan solex:sync --dry-run
php artisan solex:sync
```

`SolexNetworkSeeder` est aussi appelée par `DatabaseSeeder` et par les anciens
points d'entrée `DataSolexSeeder` et `DataSolexVillesSeeder`. Elle prépare
58 grilles départementales, 360 cercles, 45 liaisons nationales aller-retour
et 158 liaisons entre départements d'une même région. Chaque exécution active
le partenaire, le mode territorial, les 58 départements, les 360 cercles et
les 203 liaisons, y compris les données déjà présentes. Les centres, rayons
et tarifs personnalisés sont conservés. `--dry-run` ne modifie rien ;
`--activate` reste accepté comme alias de compatibilité. Les anciennes
grilles et les commandes demeurent en place dans leur périmètre historique distinct.

Dans **Partenaires de livraison → SOLEX → Régions et arrondissements**, les
10 régions et leurs 58 départements sont visibles ensemble. Déplier un
département affiche ses arrondissements ; son lien de configuration charge
uniquement sa grille et sa carte. Les anciennes grilles par ville ne sont
plus affichées pour les partenaires disposant du réseau territorial.
Les **Liaisons interurbaines** sont filtrables et paginées par 15 ; un seul
formulaire est chargé à la demande. Les **Agences de retrait** sont paginées
par 10 et les coordonnées du partenaire sont dans **Partenaire et paiements**.
Choisir un département, placer les centres sur la carte, régler les rayons et valider les
zones. Renseigner les tarifs et délais des véhicules ainsi que la zone du
relais départemental. Valider les liaisons nécessaires. Pour le retrait,
compléter et activer une agence réelle (adresse et téléphone). Le mode
territorial est réactivé à chaque seed. Les offres nationales de ce partenaire
utilisent alors exclusivement ce réseau ; les anciennes zones ne peuvent pas
contourner un cercle ou une liaison désactivée. Les autres transporteurs et
les trajets internationaux historiques continuent à fonctionner.

La collecte en boutique (entrepôt ASSO pour l'import) est incluse dans les
options **retrait en agence** et **livraison à domicile**. Le devis additionne
les étapes utiles, puis calcule une seule commission ASSO sur ce total et la
TVA selon les réglages existants. Une livraison locale directe utilise une
seule étape. Les liaisons non actives, poids non admis et tarifs manquants ne
produisent pas d'offre. Un panier ayant plusieurs boutiques de collecte doit
être réparti en commandes par boutique ; les produits importés ont le même
point de collecte à l'entrepôt ASSO.

En chevauchement, le devis conserve le parcours tarifé le moins cher par
partenaire et option ; à prix égal, priorité configurée, distance
au centre puis identifiants stables. La limite du cercle est incluse et le
contrôle utilise la distance non arrondie. Hors cercle à destination, un
retrait peut rester disponible si la ville demandée correspond à une agence
confirmée. Sans coordonnées de collecte couvertes, aucune offre territoriale.

La commande recalcule le devis et fige les étapes, centres, rayons, montants
et l'agence choisie. Les modifications ultérieures ne changent pas les
paiements déjà acceptés. Les agences à compléter restent inactives tant que
leurs coordonnées réelles ne sont pas renseignées.

La validation d'emplacement vendeur (`delivery/check-availability`) utilise
les mêmes cercles et rayons individuels que la carte acheteur. La couverture
avec panier distingue `geographically_served` (position dans une zone active)
de `quote_available` (trajet chiffrable pour ces articles). Si la boutique ou
l'entrepôt n'a pas de coordonnées de collecte, `quote_reason` vaut
`origin_not_located` : le client conserve sa position choisie et le serveur
ne lui annonce plus à tort que son adresse n'est pas couverte.
