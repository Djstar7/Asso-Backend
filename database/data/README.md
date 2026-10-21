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
