/* Fiches des entrées ajoutées au menu : les titres, chemins et accès viennent du serveur. */
const MENU_ARTICLES = {
  asso_orders: {
    lead:'Les commandes des boutiques détenues par ASSO, à traiter par l’équipe.',
    body:()=>steps(['Recherchez la commande par numéro, client ou téléphone.','Ouvrez le dossier dans son étape actuelle et vérifiez les articles et le paiement.','Effectuez l’action proposée pour confirmer, préparer ou suivre la livraison.'])
  },
  asso_products: {
    lead:'La vue du catalogue limitée aux boutiques ASSO.',
    body:()=>`<p>Cette entrée ouvre la même gestion des produits avec le filtre ASSO. Pour les administrateurs, « Tous les produits » donne la vue complète. Les gestionnaires restent limités au périmètre ASSO.</p>${steps(['Recherchez le produit ou choisissez une boutique ASSO.','Ouvrez sa fiche pour consulter ou modifier les informations selon vos droits.','Utilisez les champs FR et EN pour les textes présentés aux clients.'])}`
  },
  freight_partners: {
    lead:'Les partenaires de transport international, leurs points de départ et d’arrivée, leurs tarifs et leurs départs.',
    body:()=>steps(['Ouvrez un partenaire pour consulter sa configuration.','Vérifiez les QG, les trajets et les tarifs correspondant au service proposé.','Renseignez les départs réels et leur disponibilité pour les rendre réservables.'])
  },
  freight_bookings: {
    lead:'Les réservations de transport et le suivi des colis associés aux départs.',
    body:()=>steps(['Filtrez la liste par statut pour retrouver la réservation.','Ouvrez le dossier et contrôlez le départ, le colis, les montants et le suivi.','Enregistrez les notes et les changements d’étape lorsque les justificatifs sont disponibles.'])
  },
  freight_inquiries: {
    lead:'Les demandes de devis de transport maritime depuis la Chine vers d’autres pays.',
    body:()=>steps(['Examinez la destination et les caractéristiques de la demande.','Sélectionnez un départ réel compatible avec le trajet.','Renseignez le fret dans la devise du départ et la date d’expiration du devis, puis transmettez la proposition.'])
  },
  parcel_natures: {
    lead:'Les catégories de colis et les règles d’assurance utilisées pour les envois.',
    body:()=>`<p>Consultez les natures autorisées et les paramètres d’assurance avant de modifier une règle. Les réglages servent aux envois avion et transport international ; vérifiez le service concerné et les valeurs affichées avant l’enregistrement.</p>`
  },
  landlords: {
    lead:'Les demandes de vérification des bailleurs avant publication de leurs logements.',
    body:()=>steps(['Ouvrez une demande en attente.','Comparez le profil avec les pièces d’identité, la preuve de propriété et la vidéo fournie.','Validez si les éléments concordent, ou refusez avec une raison compréhensible par le bailleur.'])
  },
  property_disputes: {
    lead:'Les réclamations liées aux réservations immobilières, distinctes des litiges de commandes de produits.',
    body:()=>steps(['Ouvrez le dossier et examinez le motif, les preuves et la réservation.','Vérifiez le montant concerné et les parties indiquées.','Rédigez une explication, puis choisissez la décision proposée : remboursement ou déblocage selon les éléments du dossier.'])
  },
  property_settings: {
    lead:'Les règles et équipements utilisés par les annonces immobilières.',
    body:()=>`<p>Cette page regroupe les paramètres du service immobilier et la liste des équipements. Contrôlez le libellé et la valeur de chaque réglage, puis enregistrez. Une modification des équipements doit rester cohérente avec les annonces proposées aux clients.</p>`
  },
  announcements: {
    lead:'Les communications générales destinées aux utilisateurs de la plateforme.',
    body:()=>`${steps(['Créez une annonce et rédigez son titre et son message.','Vérifiez les langues et les options proposées dans le formulaire.','Publiez après relecture.'])}<p>Les annonces commerciales sont regroupées dans le résumé quotidien. Publier ne signifie donc pas qu’un push immédiat a été reçu par chaque téléphone.</p>`
  },
  affiliate_tree: {
    lead:'Le réseau de parrainage et les relations entre parrains et filleuls.',
    body:()=>`<p>Utilisez cette vue pour retrouver la place d’un utilisateur dans le réseau et comprendre ses liens de parrainage. La configuration du programme et les commissions disposent de leurs propres entrées dans la même famille du menu.</p>`
  },
  affiliate_commissions: {
    lead:'Les commissions issues du programme d’affiliation.',
    body:()=>`<p>Recherchez le bénéficiaire et vérifiez la commission, son origine et son statut. Cette page concerne les parrains et filleuls ; les commissions des commerciaux sont suivies dans « Commissions des commerciaux ».</p>`
  },
  fcm_tokens: {
    lead:'Les appareils enregistrés pour recevoir les notifications push.',
    body:()=>`${steps(['Recherchez le compte et filtrez les appareils actifs ou inactifs.','Consultez l’appareil, sa plateforme et la date d’utilisation enregistrée.','Pour une absence de notification, comparez ces informations avec le diagnostic de l’application et les autorisations du téléphone.'])}<p>Un appareil enregistré ne prouve pas que le téléphone a affiché le message. Le système distingue l’acceptation par Firebase de la réception et de la lecture.</p>`
  },
  database: {
    lead:'L’outil technique de consultation des tables et d’exécution de requêtes SQL.',
    body:()=>`<p>Ouvrez une table pour consulter sa structure ou utilisez l’éditeur SQL pour une requête maîtrisée. Vérifiez la base et le résultat attendu avant toute exécution. Cette rubrique est réservée aux administrateurs ; elle ne remplace pas les écrans métier de traitement des commandes et des paiements.</p>`
  },
  vault: {
    lead:'Le coffre qui conserve les identifiants et informations d’accès de l’organisation.',
    body:()=>`<p>Recherchez le service concerné avant de créer ou de modifier une entrée. Conservez les informations dans le coffre et utilisez les permissions prévues pour y accéder. Cette rubrique ne doit pas servir à publier des identifiants dans les annonces, messages ou documents clients.</p>`
  },
  otp_bypass: {
    lead:'Les exceptions de connexion autorisées pour certains numéros de téléphone.',
    body:()=>`<p>Recherchez d’abord le numéro pour vérifier si une exception existe. Lors d’un ajout ou d’une modification, renseignez le numéro et la raison. Désactivez une exception qui n’est plus justifiée : ce réglage modifie les contrôles de connexion du numéro concerné.</p>`
  },
  guide: {
    lead:'Le guide suit les mêmes familles et le même ordre que le menu du back-office.',
    body:()=>`<p>Utilisez la recherche ou le sommaire à gauche. Chaque fiche indique son chemin dans le menu et propose un lien vers l’écran correspondant. La rubrique « Découvrir l’interface » explique la recherche du menu, ses familles repliables et les compteurs.</p>`
  }
};
