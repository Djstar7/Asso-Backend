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
