# Exportation des achats ASSO

Parcours : vendeur au Cameroun → collecte Solex → agence Solex → DHL/FedEx → destinataire à l’étranger.

### Litiges sur un export international

Le Cas A commence par une collecte pour échange : client à l’étranger → DHL/FedEx →
transit et douane au Cameroun → agence Solex → vendeur. Le vendeur paie le transport
international et Solex avant la collecte. Il confirme la réception du produit depuis
son dossier ; cette première réception ne rembourse pas le client. Elle ouvre la
préparation du remplacement. Une fois le remplacement confirmé prêt, le vendeur
finance le nouveau trajet vendeur → Solex → DHL/FedEx → client. Le contrôle client de
48 h commence à la livraison du remplacement.

Un second défaut reste soumis à la décision ASSO. S’il est fondé, aucun deuxième
remplacement : Cas B, retour client → DHL/FedEx → Solex → vendeur. Le Cas B peut aussi
être choisi directement. Le remboursement du montant de l’article/quantité réclamée
sur le Wallet du client intervient dans la transaction de confirmation de réception
par le vendeur. Un statut logistique « remis au vendeur » seul ne rembourse rien ;
les tentatives répétées ne remboursent pas deux fois.

Les devis utilisent le pays et la ville du destinataire figés dans la commande,
le poids des unités réclamées et des tarifs par sens. Dans **DHL/FedEx → Trajets →
Exportation**, renseigner le **tarif retour international FCFA/kg**. Hors mode
provisoire, aucun tarif retour n’est déduit automatiquement du tarif aller. Pour
les données de test explicitement marquées provisoires, le tarif aller sert de
valeur d’essai tant que le retour n’a pas de tarif dédié. Chaque devis affiche les
deux transports, taxes et frais ; une fois payé il reste figé. Les montants TTC
hors commission ASSO sont répartis entre les comptes partenaires Solex et DHL/FedEx
suivant les volets du devis, sans double crédit.

Les livreurs de l’agence Solex ne voient que leur trajet local, après arrivée du
retour en agence ou paiement de l’envoi du remplacement. Ils ne prennent aucune
mission en France. Le dépôt du remplacement à l’agence utilise le code de l’agence,
et la remise au vendeur utilise son code. La livraison DHL au client n’utilise aucun
code ASSO. Les étapes internationales restent enregistrées par l’équipe ASSO sur
confirmation du transporteur ; cette fonctionnalité ne réserve aucun enlèvement
réel auprès de DHL/FedEx sans leur intégration API.

La migration `2026_10_24_180000_normalize_unpaid_international_dispute_collections`
corrige les anciens brouillons d’export créés comme envoi direct de remplacement :
uniquement sans devis, sans paiement initié et encore en attente de paiement.
Leur identifiant est conservé et le trajet est inversé vers le vendeur. Les courses
déjà payées ou commencées ne sont pas réécrites. Le déploiement habituel
`composer deploy:international` inclut cette migration.

### Référence de suivi pour les essais

`EXPORT_TEST_TRACKING_ENABLED=true` active le préremplissage d’une référence interne
`TEST-ASSO-…` dans le formulaire de suivi international. Elle reste stable pour chaque
commande et est enregistrée lors de la confirmation de remise au transporteur si aucun
numéro réel n’est fourni. Consulter le formulaire ne confirme aucune opération.
Un numéro réel déjà enregistré reste prioritaire. Les références de test n’ont jamais
de lien vers le site DHL/FedEx et ne proviennent pas de leur API.

La valeur par défaut est `false` : en production, renseigner un vrai numéro de suivi.
Ce mode désactivé refuse également une référence `TEST-ASSO-…` envoyée manuellement
pour confirmer la remise au transporteur. Après modification du `.env`, rafraîchir le
cache de configuration selon la procédure de déploiement habituelle.

## Installation en production, même sans les imports précédents

Après installation des dépendances, depuis `Asso-Backend`, avec
`EXPORT_TEST_TRACKING_ENABLED=false` dans l’environnement de production :

```sh
composer deploy:carriers
```

Cette commande vide le cache de configuration, applique les migrations puis exécute
`delivery:configure-carriers`. Elle ne charge ni SinoCam, ni des comptes clients de
démonstration, ni des commandes ou paiements. Le seeder dédié est
`Database\Seeders\DhlFedexProductionSeeder`. Il prépare le réseau Solex, les 498 trajets
DHL/FedEx, leurs tarifs retour indépendants, la collecte et les repères d’agences dans
une transaction. Il peut être relancé. Un échec annule son chargement.

Équivalent explicite :

```sh
php artisan config:clear
php artisan migrate --force
php artisan delivery:configure-carriers
```

Le déploiement groupé reste disponible avec `composer deploy:international` ou
`php artisan transport:configure` après les migrations. Son point d’entrée
`InternationalTransportProductionSeeder` appelle le même seeder DHL/FedEx, après
les imports SinoCam. Les deux entrées donnent 249 destinations par transporteur,
200 repères d’agences sourcés et 498 repères provisoires. Les relances conservent les
prix, zones, adresses, coordonnées et activations existants ; le chargement production
ne réactive pas un partenaire ou un cercle Solex volontairement désactivé.
Un partenaire ou une agence Solex désactivés restent désactivés, y compris lors de
la création de trajets manquants. La configuration est enregistrée ; les offres de
commande restent indisponibles tant que les partenaires concernés ne sont pas actifs.

Les tarifs DHL/FedEx sont **provisoires et synthétiques**, conformément au paramétrage
de démarrage demandé ; ils ne proviennent pas des transporteurs. Ils restent
modifiables au kilo dans l’administration. Le tarif retour manquant d’un trajet provisoire
est initialisé une seule fois à partir du tarif aller, puis évolue indépendamment.
Un tarif retour saisi par l’admin n’est jamais écrasé. Un tarif retour absent d’un trajet
non provisoire n’est jamais inventé. Le marqueur `return_pricing_provisional` empêche
d’utiliser une valeur de test si les tarifs du trajet sont ensuite déclarés non provisoires
sans confirmation explicite de son tarif retour dans le formulaire d’administration.
Les deux transporteurs sont actifs à leur
première création. Les repères ne limitent jamais la couverture au voisinage de l’agence.

Toutes les migrations doivent précéder le chargement : export, destinataire/code postal,
références internationales et précision des coordonnées. La commande vérifie le schéma
avant toute écriture. Les trois fichiers JSON de `database/data` (territoires Cameroun,
contours des pays, agences internationales) doivent être livrés avec le code. Aucune
requête externe n’est nécessaire au déploiement ; les scripts de recherche ne sont pas
exécutés sur le serveur. Le seeder global et les fixtures de comptes clients ne sont pas utilisés.

La nouvelle version de l’application mobile doit également être publiée avec ses
assets de traduction et l’URL réelle de l’API : le backend seul ne met pas à jour une
ancienne application installée. Vérifier la carte de commande en sélectionnant France,
Allemagne ou États-Unis, puis une adresse ; un article avec son poids vendeur renseigné
permet de vérifier le prix et le choix des deux transporteurs sans effectuer de paiement.

Une installation complète via `DatabaseSeeder` garde aussi les imports export et agences.
Les seeders individuels restent disponibles pour une intervention ciblée ;
`InternationalExportSeeder` seul suppose qu’une agence Solex active existe déjà.

## Réglages dans l’administration

Partenaires logistiques → DHL Express ou FedEx → Trajets et international → Modifier / Ajouter un trajet.

- Choisir le pays de départ CM et le pays d’arrivée (tous les pays du catalogue sont disponibles). Une ville de départ vide couvre les boutiques du pays ; renseigner une ville pour limiter la collecte. La grille de collecte inclut l’acheminement jusqu’à l’agence Solex choisie.
- Choisir « Poids réel × prix du kilo », renseigner le prix unitaire et éventuellement le poids maximum. Les fractions de kilo sont facturées proportionnellement (2,5 kg × 8 000 FCFA = 20 000 FCFA). Le mode par tranches reste disponible pour les autres contrats. Renseigner ensuite le délai, l’agence et les tarifs de collecte Solex.
- Nommer la zone tarifaire (Europe, Amérique…). Chaque trajet porte sa propre grille, modifiable pays par pays.
- Choisir la couverture nationale ou dessiner la zone de destination sur OpenStreetMap. Le pays et, si configurée, la ville restent vérifiés en plus du polygone. Les zones sont définies par ASSO selon les informations du partenaire ; elles ne sont pas importées automatiquement depuis DHL/FedEx. Les destinations de démonstration utilisent une couverture nationale fictive à ajuster selon les accords de livraison.
- Décocher « Tarifs provisoires » après remplacement des montants d’exemple.

La commande recalcule l’offre côté serveur. Le poids est la somme poids vendeur × quantités ; un poids manquant bloque l’exportation. Une commande export correspond à une boutique de collecte. La collecte et l’international suivent le régime de TVA du partenaire sélectionné ; la commission ASSO s’applique au total hors taxe. Le détail, la zone et les coordonnées de l’agence sont figés dans la commande.

## Paiement et suivi

Le paiement carte reprend Stripe natif déjà intégré (si activé/configuré), avec conversion dans la devise du rail. Le destinataire et son code postal sont indépendants du compte acheteur. Aucun statut d’expédition n’est disponible avant confirmation du paiement et validation de la commande.

ASSO enregistre les confirmations dans Expéditions : collecte programmée → récupéré chez le vendeur → reçu à Solex → remis au transporteur → transit → dédouanement si nécessaire → arrivé → en livraison → livré. Le numéro DHL/FedEx est obligatoire à la remise au transporteur. Un incident exige une note et reprend au stade opérationnel précédent. La programmation ouvre une mission de collecte auprès des livreurs de l’agence Solex choisie. Elle ne propose aucune mission nationale aux coursiers DHL/FedEx : leur transport international commence après réception du colis à Solex.

La première version utilise un suivi saisi par ASSO et les liens publics des transporteurs. Elle ne réserve pas automatiquement un enlèvement, ne génère pas d’étiquette et ne synchronise pas les API DHL/FedEx. Les frais de douane ne sont pas chiffrés. Le règlement conserve le fonctionnement existant : la part livraison est créditée au compte du partenaire sélectionné ; aucune répartition automatique entre Solex et ce partenaire n’est ajoutée.


## Charger et retirer le catalogue de démonstration

```sh
php artisan delivery:demo-international --dry-run
php artisan delivery:demo-international
# Plus tard, aperçu de la suppression puis suppression :
php artisan delivery:demo-international --remove --dry-run
php artisan delivery:demo-international --remove
```

Le catalogue contient Europe, Afrique, Amériques, Asie/Moyen-Orient et Océanie/autres territoires. Les prix varient selon la zone et le transporteur. Tous les trajets utilisent le poids réel multiplié par `price_per_kg`, sans forfait initial ni arrondi au kilo supérieur. La limite d’essai est 1 000 kg par colis. La collecte Solex reste distincte, puis viennent la TVA éventuelle et la commission ASSO.

Le chargement conserve les trajets déjà personnalisés et ne réinitialise jamais les montants chargés lors d’une relance. Les six anciens exemples non modifiés (France, Allemagne, États-Unis) sont convertis au kilo. Les trajets sont marqués `export_config.demo_dataset = international-carriers-demo-v1` et `provisional_rates = true`. Une sauvegarde du trajet dans l’administration retire ce marquage d’import : le trajet personnalisé est conservé lors du nettoyage.

La suppression ne cible que ce marquage. Elle supprime les trajets inutilisés et désactive ceux référencés par une commande, sans supprimer les commandes ni leurs tarifs figés. Elle conserve les agences Solex, les comptes transporteurs et les autres trajets.

## Carte de commande

L’API `/v1/delivery/coverage` renvoie désormais `international_areas`, les contours ou polygones configurés et les transporteurs actifs de chaque destination. Les destinations nationales sont regroupées par pays pour éviter de dessiner deux fois le même contour DHL/FedEx. Les itinéraires inactifs, partenaires inactifs et collectes sans agence Solex active sont exclus.

Sur l’application, le bouton « DHL / FedEx · destinations » ouvre la recherche de pays. Choisir une destination cadre la carte ; le client sélectionne ensuite son adresse de livraison. Les étiquettes locales sont masquées au zoom mondial pour conserver la lisibilité. La vérification d’un point transmet le pays et la ville issus du géocodage, puis recalcule les offres du panier : voir une zone ne garantit pas l’éligibilité d’un produit ou d’un poids donné.

### Choix du pays et agences sur la carte

Les 249 destinations de démonstration sont configurées en couverture nationale pour
les deux transporteurs. Le choix d’un pays sélectionne son contexte international,
affiche la couverture et demande ensuite l’adresse du destinataire (recherche ou tap
sur la carte). La validation reste désactivée tant que seul le pays a été choisi :
un centre de pays ou une agence de référence ne doit pas devenir une adresse client.
Les marqueurs d’agence ne définissent pas un rayon de couverture.

Quand le pays est couvert mais que le devis est indisponible (par exemple poids vendeur
manquant), la carte conserve l’indication de couverture et affiche séparément la cause.
Les 200 repères sourcés et les repères provisoires sont documentés dans `database/data/README.md`.

### Retrouver une commande et consulter son suivi dans le dashboard

Le menu **Commandes internationales** ouvre `/admin/shipments?scope=export&status=all`.
Il liste les exports sans filtrer sur l’origine des produits, le gros/détail ou le
statut de paiement. Une commande de boutique avec un produit importé peut donc être
retrouvée ici même si elle n’appartient ni aux commandes locales ni aux commandes en gros.
La recherche accepte le numéro de commande ou de suivi, sans distinction de casse.
Le paiement est affiché séparément de la dernière étape logistique ; les filtres
permettent de retrouver collecte Solex, remise au transporteur, transit, douane ou livraison.

**Voir le suivi** ouvre l’historique daté. La validation du vendeur d’une commande payée
programme automatiquement la collecte Solex dans la même transaction, avec un événement
`export_pickup_requested` attribué au système. La consultation ne change aucun statut.
La programmation ne confirme ni la récupération chez le vendeur ni le dépôt à l’agence.
Les nouvelles confirmations saisies par les opérateurs ASSO
alimentent ensuite le suivi client ; aucune progression DHL/FedEx n’est inventée ou
importée automatiquement par cette version.


### Collecte internationale et livreurs de l’agence Solex

La migration `2026_10_24_150000_add_export_collection_dispatch` fait partie du déploiement
`composer deploy:international`. Elle ajoute le rattachement à une agence pour les codes
et synchronisations de livreurs, et l’identification/la clôture de la collecte dans la commande.

Dans **Partenaires logistiques → SOLEX → Partenaire et paiements**, générer un code en
choisissant l’agence : les nouveaux livreurs synchronisés héritent de cette agence.
Pour les livreurs déjà synchronisés, ouvrir **Rattacher les livreurs existants à une agence**,
choisir leur agence, puis enregistrer. Aucun rattachement n’est déduit automatiquement
d’une ville ou d’un ancien code partenaire, car une société peut avoir plusieurs agences.
L’écran d’expédition indique le nombre de livreurs rattachés.

Après confirmation du paiement et du vendeur, l’étape `export_pickup_requested` est enregistrée
automatiquement et ouvre la mission après validation de la transaction, sans formulaire admin.
Une seconde validation du vendeur ne programme ni ne notifie une seconde fois la collecte.
Tous les livreurs actifs non bannis rattachés
à cette agence sont notifiés, même hors ligne (notification enregistrée dans l’historique
avant tentative de push). L’offre reste durablement visible aux livreurs en ligne de
cette agence dans les demandes et leur dashboard. Le premier qui accepte est affecté ;
un verrou protège contre une deuxième attribution. Les autres agences, les livreurs sans
agence et les coursiers DHL/FedEx ne reçoivent pas cette collecte.

Le coursier Solex assure uniquement le trajet vendeur → agence : l’API acheteur le
présente sous `collection_courier`, jamais comme `delivery_person`. Le code de dépôt
est communiqué par l’agence au coursier Solex. Le destinataire international ne reçoit
pas de code de remise ASSO à transmettre à DHL/FedEx. Après le dépôt, le transporteur
gère l’acheminement et la livraison finale ; ASSO conserve le suivi et les confirmations
enregistrées par les opérateurs. Le statut « en cours de livraison » nomme le transporteur.
La synchronisation d’un coursier à DHL/FedEx dans ASSO ne lui attribue pas ces exports.

Le coursier récupère le colis chez le vendeur (`export_collected`), puis le remet à
l’agence Solex. Le réceptionnaire Solex lui communique le code de dépôt visible côté
administration : la validation de ce code enregistre `export_at_solex` et clôture sa
mission. Elle libère sa capacité de courses, sans marquer la commande comme livrée
au destinataire, sans paiement supplémentaire ni nouveau crédit de portefeuille.
La part de transport reste régie par le règlement existant ; aucune répartition
financière automatique Solex/DHL/FedEx n’est ajoutée.

La suite (remise DHL/FedEx, transit, douane, livraison) reste suivie par ASSO. Le coursier
Solex ne peut pas enregistrer ces étapes. Les reprises par `carriers:dispatch-pending`
(commande déjà planifiée toutes les cinq minutes) incluent les collectes programmées
non encore ouvertes. Elles ne recréent pas une mission déjà ouverte et ne relancent
pas une collecte déjà récupérée ou déposée à Solex.

### Prix et distance de la collecte vendeur → Solex

Le montant affiché sur la mission de collecte correspond au premier volet du prix
accepté à la commande (`legs[0].price`) ; il ne reste plus forcé à zéro. Le deuxième
volet reste celui du transport DHL/FedEx. Les détails de prix de l’application et
la fiche d’expédition affichent ces deux montants séparément, en plus du total.
Cet affichage ne crée pas de crédit de portefeuille supplémentaire : la répartition
financière entre partenaires reste celle décrite plus haut.

Les nouvelles commandes figent le GPS du vendeur, celui de l’agence, la distance et
les deux montants. Un changement ultérieur de GPS ou de tarif ne recalcule pas une
commande déjà acceptée. Pour les anciennes commandes sans GPS figé, l’écran utilise
les points actuellement connus pour afficher une estimation, sans modifier le prix payé.

`SolexNetworkSeeder` renseigne les coordonnées de centre-ville du référentiel territorial
pour les nouveaux relais provisoires et complète les anciens relais dont le GPS manque
et dont l’adresse indique encore « point exact à confirmer ». Les coordonnées déjà
renseignées et les agences personnalisées sont conservées. Ces points portent
`coordinate_precision = city_center` et restent provisoires : ils se corrigent dans
**Agences de retrait**, avec latitude/longitude et précision des coordonnées.
Les distances affichées sont géographiques à vol d’oiseau, pas des distances routières.
Le GPS de la boutique doit également correspondre à sa véritable localisation.

Dans la rubrique Solex de collecte (ou la grille de repli du trajet), **Supplément FCFA/km** permet d’ajouter une composante
à la distance au tarif de collecte au poids. Par défaut elle vaut zéro pour conserver
les tarifs déjà configurés. Si elle est renseignée :

```
collecte = tarif de collecte au poids + arrondi(distance_km × supplément FCFA/km)
```

Avec un supplément positif, les GPS vendeur et agence sont nécessaires au devis.
Le calcul n’utilise pas une distance inventée lorsque des coordonnées manquent.
Le transport international reste facturé selon sa propre grille au kilo.

### Tarif Solex central et récapitulatif avant paiement

La rubrique **SOLEX → Tarifs de collecte Solex** permet de régler, pour chaque agence,
le forfait de collecte au poids, le poids inclus, le supplément au kilo et le supplément
au kilomètre. Ce tarif est prioritaire pour les futurs devis DHL/FedEx qui partent de
cette agence. Les montants de collecte conservés sur chaque trajet international
servent uniquement de repli lorsque l’agence n’a pas de tarif central.

`SolexCollectionPricingSeeder`, inclus dans l’initialisation de production et globale,
reprend le tarif existant d’une agence uniquement lorsque tous ses trajets export ont
la même grille de collecte. Les tarifs d’agence déjà personnalisés sont conservés ;
les cas où plusieurs grilles diffèrent restent à configurer explicitement. La migration
`2026_10_24_170000_add_solex_collection_pricing` précède ce chargement via le déploiement groupé.

Les prix visibles avant paiement suivent cette égalité : collecte Solex + transport
DHL/FedEx + TVA éventuelle + frais de service ASSO = total livraison. Ce détail est
visible sur les cartes des transporteurs et dans le récapitulatif de commande.
Le prix des articles est ajouté ensuite pour former le total à payer.

Tous les poids du formulaire produit sont en kilogrammes : 1 correspond à 1 kg,
0,001 correspond à 1 gramme. Deux unités de 1 kg donnent 2 kg dans le devis.
L’affichage conserve trois décimales utiles afin qu’un petit poids positif ne soit
plus présenté comme « 0,00 kg ». Les anciennes commandes et leurs prix figés ne sont
pas réécrits après une correction du poids d’un produit.
