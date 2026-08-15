# Notes de déploiement — unification `dev` (Diaspo upstream + KPay/escrow/devises)

Ce document liste les étapes **manuelles** à faire au déploiement de `dev`, suite à la
fusion `integration/unify-dev` (features upstream) et aux features KPay/escrow/multi-devises.

## 1. Bascule des données Diaspo (schéma 14/04 → schéma unifié 27/04)

Les tables `diaspo_offers` / `diaspo_bookings` ont deux schémas divergents. Le schéma
**upstream (27/04)** a été retenu (statuts, softDeletes, index).

**Automatique** : les migrations `2026_04_27_000001/000002` détectent une base déjà déployée
avec l'ancien schéma et **renomment** les anciennes tables en
`diaspo_offers_legacy_preunify` / `diaspo_bookings_legacy_preunify` avant de créer les
nouvelles (aucune donnée n'est supprimée). Sur une base fraîche, c'est un no-op.

**À décider par l'équipe APRÈS `php artisan migrate`** :
- Si les tables `*_legacy_preunify` **contiennent des données Diaspo réelles** à conserver :
  écrire un script de reprise (mapping des `status` : `active`→`approved`, etc. ; devise
  `XAF`→cible ; précisions décimales). Les nouveaux enums :
  - offer.status ∈ `pending|approved|rejected|expired|completed`
  - booking.status ∈ `pending|paid|confirmed|cancelled|completed`
  - booking.payment_status ∈ `pending|completed|refunded`
- Si elles sont **vides / de test** : les supprimer (`DROP TABLE diaspo_offers_legacy_preunify`,
  idem bookings).

## 2. Commandes payées KPay direct « héritées »

Après migration, lancer le diagnostic/réparation des commandes `kpay_direct` créées avant
le fix du règlement unifié :

```bash
php artisan kpay:diagnose-legacy-orders          # rapport (lecture seule)
php artisan kpay:diagnose-legacy-orders --repair # répare le cas sûr (confirmation demandée)
```

## 3. Recherche PostgreSQL

La migration `enable_postgresql_search_extensions` ne s'exécute que sur PostgreSQL
(no-op sur SQLite/MySQL). Aucune action requise ; sur pgsql, elle crée les extensions
`pg_trgm`/`unaccent` (droits superuser éventuellement nécessaires).

## 4. QA fonctionnelle Diaspo (résiduel — à faire avec l'équipe)

La fusion a fait **cohabiter deux implémentations Diaspo** : persistance/API upstream
(`DiaspoOfferController`/`DiaspoBookingController`, préfixe `/v1/diaspo`) qui **gagnent** le
routage, + le paiement KPay d'origin (`DiaspoController::bookingPaymentStatus`/
`confirmBookingPayment`/`failBookingPayment`, seul endpoint origin encore vivant, utilisé
par le mobile pour le polling de paiement).

- **Corrigé** : le chemin de paiement vivant (polling + webhook) écrit désormais des
  valeurs d'enum valides sous le schéma unifié (`payment_status` `completed`, plus de
  `paid`/`failed`).
- **À valider fonctionnellement** : le cycle de vie complet d'une réservation Diaspo
  (création upstream → paiement KPay → confirmation → livraison), car les autres méthodes
  d'origin (`confirmReceipt`/`sellerConfirmCode` avec statut `in_transit` absent du nouvel
  enum, `cancelBooking`) sont **shadowées/mortes** (routes upstream prioritaires). À terme,
  **consolider sur UNE seule implémentation Diaspo** pour retirer ce code mort.
