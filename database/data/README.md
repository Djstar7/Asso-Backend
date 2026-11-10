# Référentiel territorial de livraison — Cameroun

`cameroon-delivery-territories.json` est une extraction hors ligne du jeu
INC/OCHA COD-AB publié sur HDX, téléchargé le 21 octobre 2026 :

- https://data.humdata.org/dataset/cod-ab-cmr
- La ressource XLSX exacte est indiquée dans `resource_url` du JSON.
- 10 régions, 58 départements, 360 arrondissements ; codes administratifs
  conservés, rattachements explicites. Une traduction anglaise absente reprend
  le nom source, sans traduction inventée.
- Attribution : Institut National de Cartographie / OCHA, licence de distribution HDX CC BY-IGO.

Les centres des arrondissements sont les **centroïdes du référentiel**, pas des
adresses d'agences SOLEX. Ce sont des centres de couverture provisoires :
l'administrateur les déplace et définit le rayon avant activation. Les cercles
commerciaux ne représentent pas des limites administratives officielles.

Les chefs-lieux sont rapprochés par nom de département, les codes de la feuille
des capitales ayant un ancien format. Les variantes Kadey/Kadei sont normalisées.
Les adresses des relais SOLEX générés suivent le format `Ville-Département`
(par exemple `Douala-Wouri`),
sans inventer une rue ou un bâtiment. Le point GPS reste un repère provisoire ;
une adresse de dépôt précise peut être renseignée ensuite dans l'administration.
Les rattachements ambigus (Océan, Mvila, Logone-et-Chari) retiennent respectivement
Kribi, Ebolowa, Kousseri. Ntui est retenu pour le Mbam-et-Kim ; Monatélé pour la
Lékié ; Menji pour le Lebialem ; Mundemba pour le Ndian. Lorsque le point du
chef-lieu manque, le centroïde de l'arrondissement correspondant est utilisé
(Fontem pour Menji). Ces points restent provisoires.

Contrôle des chefs-lieux :
https://minat.gov.cm/presentation/carte-des-regions/centre/ et
https://minat.gov.cm/presentation/carte-des-regions/sud-ouest/.

La seed ne télécharge rien : une panne réseau ne modifie pas le référentiel.
Toute mise à jour de cette extraction doit être revue avec ses nombres,
rattachements et codes, puis testée avant synchronisation.

## Frontières de la carte de livraison internationale

`delivery-country-boundaries.json` contient les contours simplifiés Natural Earth à l’échelle 1:50m (admin-0 map units), dans le domaine public. Ils servent de fond aux couvertures nationales configurées dans ASSO ; ils ne constituent pas une carte officielle de desserte DHL/FedEx. Les petites destinations sans contour à cette échelle restent accessibles par un point de navigation. La couverture personnalisée par polygone conserve le tracé de l’administrateur.

Source : https://github.com/nvkelso/natural-earth-vector/blob/master/geojson/ne_50m_admin_0_map_units.geojson
Conditions : https://www.naturalearthdata.com/about/terms-of-use/

Reconstruction (Python standard, sans dépendance) :

```sh
python scripts/build_delivery_country_boundaries.py /chemin/ne_50m_admin_0_map_units.geojson
```

Le fichier embarqué conserve l’empreinte SHA-256 de la source. Le géocodeur d’adresse fournit en priorité le pays ; sans réponse, les contours permettent de reconnaître le pays du point (avec une tolérance côtière de 5 km pour les îlots omis à cette échelle).

## Repères des agences internationales

`international-carrier-agencies.json` : collecte du 24 octobre 2026 dans les annuaires officiels
FedEx (`https://local.fedex.com/en`) et DHL Express (États-Unis, Canada et France).
Le lot contient 200 points dans 126 pays : 185 FedEx et 15 DHL Express.
Chaque entrée conserve son adresse, sa page source et sa date de consultation.
Les coordonnées publiées ont été rapprochées du pays : cinq points incohérents ont été écartés.
Pour Paris la Boétie (DHL) et Berlin (FedEx), l’adresse officielle a été géocodée dans
OpenStreetMap/Nominatim à l’échelle de la rue : `coordinate_precision: street` et lien
OSM conservé dans le JSON ; l’application indique que la position est approximative.
Attribution de ces coordonnées : © OpenStreetMap contributors, ODbL,
https://www.openstreetmap.org/copyright.

Les annuaires publics ne permettent pas de confirmer une agence dans chaque pays.
Le seeder crée donc aussi un repère **provisoire** par transporteur et pays (498),
placé au centre cartographique, sans inventer une adresse d’agence. Sur la carte,
les agences réelles sont utilisées en priorité pour chaque transporteur ; les repères
provisoires sont gris et explicitement signalés. Aucun de ces repères ne restreint la
couverture nationale ou ne devient automatiquement une agence de retrait de commande.
La présence dans l’annuaire ne confirme pas les conditions contractuelles d’export au départ du Cameroun.

Chargement hors ligne, après migrations et `InternationalExportSeeder` :

```sh
php artisan db:seed --class=InternationalCarrierAgenciesSeeder
```

Le chargement est répétable et préserve les modifications administratives. Les points
sont modifiables dans les agences des partenaires (pays, coordonnées, source, statut provisoire).
Les jeux sont identifiés par `source_dataset` (`official-carrier-directories-v1` et
`international-reference-points-v1`) pour permettre un retrait ciblé ultérieur.

Scripts de collecte : `research_international_carrier_agencies.py` et
`research_dhl_agencies.py` ; contrôle territorial :
`php scripts/validate_international_carrier_agencies.php`.
Le lot embarqué comprend aussi les deux adresses européennes vérifiées individuellement ;
les protections et limites des annuaires peuvent rendre une nouvelle collecte partielle.
La seed ne contacte jamais les annuaires.

## Catalogue import du 27 octobre 2026

`wholesale-catalog-2026-10-27.json` contient 10 références complémentaires :
4 Chine, 3 Turquie et 3 filière Dubaï/Émirats, avec 29 photos sélectionnées
dans les galeries Alibaba et Amazon.ae. Les liens sources, prix d'origine,
taux ASSO utilisés, paliers, dates et empreintes des photos sont conservés.
Les produits Amazon portent un prix de détail de référence, sans remise de gros
inventée. Les prix XAF sont indicatifs, arrondis au multiple de 50 supérieur,
hors transport, droits et taxes ; le stock reste à zéro jusqu'à confirmation.
Les anciens manifestes conservent le champ historique `requires_quote`, mais
l’import et les commandes ne demandent plus de confirmation ASSO. Le stock à
zéro bloque les commandes locales ; le parcours de gros utilise ses propres règles.
Les poids estimatifs et les poids nets sans emballage sont signalés dans les fiches.

Prévisualisation puis application, uniquement avec les images déjà vérifiées
présentes dans `storage/app/private/catalog-imports/sourcing-2026-10-27/images/` :

```sh
php scripts/import_sourced_wholesale_catalog.php
php scripts/import_sourced_wholesale_catalog.php --apply
```

Le script ne télécharge rien et ne modifie aucune fiche existante. Une référence
stable par annonce source empêche les doublons lors des relances. Chaque application
sauvegarde les tables du catalogue dans l'archive privée et compare chaque ligne
préexistante avant validation de la transaction. Les fiches créées appartiennent à
l'entrepôt ASSO configuré ; les photos sont copiées localement sans écrasement.
L'archive privée conserve aussi les pages sources et les rapports de vérification.

Le lot complet comporte **3 000 références, 1 000 par pays**, dont les dix du
premier lot, avec **8 749 photos** et **6 746 paliers de prix**. Les 401 produits
présents avant cette opération sont conservés. Les fiches possèdent une catégorie
ASSO, une sous-catégorie lorsque le classement le permet, une description et les
caractéristiques déclarées par le fournisseur. La hiérarchie de catégorie réelle
du fournisseur est conservée et utilisée pour classer 2 989 des 2 990 références
du second lot ; la référence restante utilise les données de liste disponibles.
Un âge, un poids, un conditionnement ou une disponibilité inconnus ne sont pas inventés.

Les sources, images, manifestes par cent fiches, sauvegardes et contrôles sont dans
`storage/app/private/catalog-imports/sourcing-3000-2026-10-27/`.
L'index versionné `wholesale-catalog-3000-2026-10-27-index.json` identifie les sources.
Collecte reprenable : `source_wholesale_catalog.py` ; préparation et classement :
`build_sourced_wholesale_manifests.py` et `sourced_catalog_categories.py`.
La sélection finale est figée dans l'archive pour empêcher l'ajout de variantes
artificielles lors d'une relance. Application d'un manifeste déjà vérifié :

```sh
php scripts/import_sourced_wholesale_catalog.php --manifest=/chemin/du/manifeste.json --apply
php scripts/validate_sourced_wholesale_catalog.php
python3 -m unittest discover -s scripts -p test_catalog_sourcing.py
```

API utilisée par le frontend :

```text
GET /api/v1/import/CN/products?page=1&per_page=20
GET /api/v1/import/TR/products?page=1&per_page=20
GET /api/v1/import/AE/products?page=1&per_page=20
GET /api/v1/import/products/{id}
```

Les listes acceptent `category_id` et `subcategory_id`. Chaque fiche expose la
catégorie et la sous-catégorie. Les champs historiques `requires_quote` et
`price_is_indicative` valent désormais `false`, et le libellé de disponibilité est vide. Le catalogue local et les routes locales récents/proximité
conservent leur périmètre. Les données existantes d'import restent dans les listes :
le contrôle en ligne compte donc 1 004 références Chine, 1 230 Turquie et 1 001 Dubaï.
Les 67 pages ont été parcourues sans doublon, avec les 3 000 nouvelles références.

Le gestionnaire peut mettre à jour le prix et les informations d’approvisionnement
dans l’édition du produit.
