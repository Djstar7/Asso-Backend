# Liens partagés et espace vendeur web : mise en production

Tout est coupé par défaut et se règle dans **Paramètres, onglet Liens**. Déployer le code ne change rien pour les utilisateurs. Chaque étape attend que les essais de la précédente soient réussis.

## Étape 0 : avant de commencer

- [ ] Sauvegarder la base de production.
- [ ] Relever le dernier `versionCode` dans la Play Console. L'application est en `1.2.1+30` : à augmenter si la console a déjà reçu 30 ou plus.
- [ ] Copier l'empreinte **SHA-256 de la clé de signature de l'application** : Play Console, Configuration, Intégrité de l'application, Signature de l'application. Si l'application est aussi installée hors de Google Play, ajouter l'empreinte de cette clé.
- [ ] Vérifier sur developer.apple.com que l'App ID `com.asso.asso` a la capacité **Associated Domains**.
- [ ] Créer ou choisir un compte vendeur de test en production, avec une boutique vérifiée, et noter son numéro d'utilisateur.

## Étape 1 : déployer le backend

```bash
php artisan migrate --force
php artisan optimize:clear
```

Vérifier :

```bash
curl -s https://asso-dashboard.sbs/.well-known/assetlinks.json                # doit répondre []
curl -s https://asso-dashboard.sbs/.well-known/apple-app-site-association     # details vide
curl -sI https://asso-dashboard.sbs/produit/1                                 # 301 vers /p/1
curl -sI https://asso-dashboard.sbs/tailwindcss/tailwindcss-3.4.17.js         # 200
curl -s -o /dev/null -w '%{http_code}\n' https://asso-dashboard.sbs/vendor    # 404 tant que c'est coupé
```

- [ ] Le dossier `public/vendor` n'existe plus sur le serveur. S'il reste, `/vendor` répond 403.
- [ ] L'administration s'affiche correctement : son script Tailwind a changé d'adresse.
- [ ] Ouvrir `/p/{id}` et `/b/{id}` d'un vrai produit et d'une vraie boutique, sur téléphone et sur ordinateur.
- [ ] Coller un lien `/p/{id}` dans WhatsApp : l'aperçu montre l'image, le nom et le prix.

**Retour arrière :** revenir au commit précédent. La migration ne fait qu'ajouter une table.

## Étape 2 : version 1.2.1 en test interne

Publier la version sur **TestFlight** et sur les **tests internes de Google Play**, sans sortie publique. Shorebird ne suffit pas : le manifest et `Info.plist` changent.

## Étape 3 : activer l'association

Dans Paramètres, Liens : coller l'empreinte, cocher « Ouvrir l'application depuis les liens », enregistrer.

- [ ] Vérifier chez Google : `https://digitalassetlinks.googleapis.com/v1/statements:list?source.web.site=https://asso-dashboard.sbs&relation=delegate_permission/common.handle_all_urls`
- [ ] Android : réinstaller la version de test pour relancer la vérification.
- [ ] iPhone : le cache Apple peut prendre jusqu'à plusieurs jours (`https://app-site-association.cdn-apple.com/a/v1/asso-dashboard.sbs`). Réinstaller ensuite la version de test.

Faire sur **Android et iPhone**, avec un lien envoyé depuis WhatsApp :

| Situation | Résultat attendu |
|---|---|
| Application fermée, compte connecté | Splash court, puis la fiche ; le retour mène à l'accueil |
| Application ouverte sur un autre écran | La fiche s'ouvre ; le retour ramène à cet écran |
| Compte invité | La fiche s'ouvre |
| Application réinstallée, sans compte ni pays | Choix du pays, puis la fiche en invité |
| Application fermée, lien `/b/{id}` | La page de la boutique |
| Produit en gros | L'écran du produit en gros |
| Produit retiré | Message « Produit introuvable », puis l'accueil |
| Mode avion | Message réseau avec « Réessayer » |
| Application non installée | Vitrine, puis bouton vers le magasin |
| Vitrine, bouton « Ouvrir dans l'application » | L'application s'ouvre sur la fiche |
| Android : lien depuis WhatsApp, application déjà lancée | Une seule instance de l'application ; le retour reste cohérent |

**Retour arrière :** décocher l'association. Android suit vite ; sur iPhone, le cache Apple peut garder l'ancien fichier plusieurs jours.

## Étape 4 : sortie publique

Publier la version 1.2.1 sur les deux magasins.

**Limite connue, version iPhone 1.1.9 :** une fois l'association activée, un lien `/p` ou `/b` ouvre l'ancienne application sur l'accueil au lieu de la fiche, jusqu'à la mise à jour. Les versions Android déjà installées ne sont pas concernées : elles continuent d'ouvrir la vitrine. Pour réduire ce cas, activer le bouton de partage (étape 5) seulement quand la version 1.2.1 est largement installée.

## Étape 5 : activer le partage

Cocher « Afficher le bouton de partage dans l'application ». L'effet arrive au prochain démarrage de l'application.

- [ ] Partager un produit, un produit en gros et une boutique vers un téléphone avec l'application et vers un autre sans l'application.

**Retour arrière :** décocher.

## Étape 6 : espace vendeur web, compte de test seulement

Compiler depuis `Asso-Mobile` puis déposer le résultat sur le serveur. Le dossier n'est pas dans Git :

```bash
scripts/build_vendor_web.sh /tmp/vendor-web
rsync -a --delete /tmp/vendor-web/ serveur:/chemin/du/projet/public/vendor-web/
```

Sur le serveur web :

- [ ] Le fichier JavaScript fait environ 4,7 Mo, soit 1,4 Mo une fois compressé : activer gzip ou brotli pour `application/javascript` et `application/wasm` (aucune compression n'a été observée sur les fichiers statiques actuels).
- [ ] Vérifier que `.wasm` est servi en `application/wasm`.

Dans Paramètres, Liens : renseigner le numéro du compte de test, cocher « Activer l'espace vendeur web ».

| Essai | Résultat attendu |
|---|---|
| Ouvrir `asso-dashboard.sbs/vendor` sur ordinateur | Un QR s'affiche |
| Application, tableau de bord vendeur, Espace vendeur sur ordinateur, scanner | L'ordinateur indique « QR scanné », le téléphone demande confirmation |
| Refuser | L'ordinateur l'indique et propose un nouveau QR |
| Attendre 2 minutes sans scanner | Le QR expire et un nouveau est proposé |
| Accepter | Le tableau de bord s'ouvre et une notification arrive sur le téléphone |
| Recharger la page | La session reste ouverte |
| Modifier un produit, y ajouter une photo, traiter une commande, modifier la boutique | Tout s'enregistre |
| Cliquer sur « Voir les forfaits » | Message « Disponible dans l'application » |
| « Quitter » | Retour au QR |
| Téléphone, Déconnecter ce navigateur | L'ordinateur revient au QR à l'action suivante |
| Compte autre que le compte de test, sur téléphone | L'entrée n'apparaît pas dans le tableau de bord |

**Retour arrière :** décocher « Activer l'espace vendeur web ». Les sessions ouvertes sont refusées dès la requête suivante.

## Étape 7 : ouverture à tous les vendeurs

Vider la liste des comptes de test.

## Ce que le jeton web ne peut pas faire

La liste blanche est dans `config/web_vendor.php`. Toute route absente est refusée, y compris les routes ajoutées plus tard. Sont exclus : wallet, retraits, paiements, forfaits, achat de boosts, litiges, modification du compte, autorisation d'un autre navigateur.

Pour ajouter une route à l'espace web, il faut l'inscrire dans cette liste.

## Mettre à jour l'espace web

Relancer le script et redéposer le dossier. `/vendor` n'est jamais gardé en cache : la nouvelle version est prise au prochain chargement.
