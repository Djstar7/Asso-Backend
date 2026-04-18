# 🤖 Intégration Gemini AI - Analyse de Produits

## 📋 Vue d'ensemble

Ce document décrit l'intégration complète de Google Gemini Vision AI pour l'analyse automatique des images de produits dans l'application ASSO.

### Fonctionnalités

L'utilisateur peut :
1. ✅ Uploader une ou plusieurs images de produit
2. 🤖 Lancer une analyse AI en un clic
3. 📝 Voir les champs du formulaire se remplir automatiquement
4. ✏️ Modifier les suggestions avant soumission
5. 💾 Soumettre le produit normalement

---

## 🏗️ Architecture

### Backend (Laravel)

```
Backend/ASSO/
├── app/
│   ├── Services/
│   │   └── GeminiService.php          # Service d'analyse Gemini
│   └── Http/Controllers/Api/
│       └── AnalyzeProductController.php # Controller API
├── routes/
│   └── api.php                         # Routes API
├── config/
│   └── gemini.php                      # Configuration Gemini
└── .env                                # Variables d'environnement
```

### Frontend (Flutter)

```
Mobile/lib/app/
├── data/providers/
│   └── product_service.dart            # Service API (méthodes ajoutées)
└── modules/addProduct/
    ├── controllers/
    │   └── add_product_controller.dart # Logique d'analyse (modifié)
    └── views/
        └── add_product_view.dart       # UI avec bouton AI (modifié)
```

---

## 🔌 API Endpoints

### 1. Analyser une image de produit

```http
POST /api/v1/products/analyze
Content-Type: multipart/form-data
```

**Request:**
```
image: File (jpeg, png, jpg, gif, webp - max 10MB)
```

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Image analyzed successfully",
  "data": {
    "suggested_data": {
      "name": "iPhone 14 Pro Max",
      "description": "Smartphone Apple dernier modèle avec écran OLED...",
      "condition": "used",
      "type": "article",
      "weight_category": "X-small",
      "category_id": 5,
      "subcategory_id": 12,
      "suggested_category_name": "Électronique",
      "suggested_subcategory_name": "Smartphones"
    },
    "confidence": {
      "name": 0.95,
      "description": 0.88,
      "condition": 0.72,
      "category": 0.90
    },
    "available_categories": [...]
  },
  "meta": {
    "analyzed_at": "2026-04-17T15:30:00Z",
    "original_filename": "product.jpg"
  }
}
```

**Response (422 Validation Error):**
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "image": ["The image field is required."]
  }
}
```

**Response (500 Analysis Error):**
```json
{
  "success": false,
  "message": "An error occurred while analyzing the image",
  "error": "Error details (if debug mode enabled)"
}
```

---

### 2. Vérifier la santé du service Gemini

```http
GET /api/v1/products/analyze/health
```

**Response (200 OK):**
```json
{
  "success": true,
  "service": "Gemini Vision AI",
  "status": "configured",
  "ready": true
}
```

---

### 3. Obtenir les catégories disponibles

```http
GET /api/v1/products/categories
```

**Response (200 OK):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Électronique",
      "name_en": "Electronics",
      "subcategories": [
        {
          "id": 5,
          "name": "Smartphones",
          "name_en": "Smartphones"
        }
      ]
    }
  ]
}
```

---

## ⚙️ Configuration Backend

### 1. Variables d'environnement (.env)

```env
GEMINI_API_KEY=AQ.Ab8RN6ITcTQv1hUjn_Om85K1J2dpwCfPiGCLUGub3vK5hqoPiA
GEMINI_MODEL=gemini-pro
GEMINI_VISION_MODEL=gemini-pro-vision
GEMINI_TIMEOUT=60
GEMINI_MAX_TOKENS=2048
GEMINI_TEMPERATURE=0.7
```

### 2. Installation du package

```bash
cd Backend/ASSO
composer require google-gemini-php/laravel
php artisan config:clear
```

### 3. Vérifier les routes

```bash
php artisan route:list --path=v1/products
```

Vous devriez voir :
- ✅ `POST api/v1/products/analyze`
- ✅ `GET api/v1/products/analyze/health`
- ✅ `GET api/v1/products/categories`

---

## 📱 Utilisation Flutter

### Flow utilisateur

1. **Ouvrir le formulaire d'ajout de produit**
   ```dart
   Get.toNamed('/add-product');
   ```

2. **Uploader une ou plusieurs images**
   - Bouton "Upload Images"
   - Choix : Caméra ou Galerie
   - Les images apparaissent en miniature

3. **Lancer l'analyse AI**
   - Cliquer sur le bouton "Analyser avec l'IA"
   - État du bouton :
     - 🔴 Désactivé si aucune image
     - 🟡 "Analyse en cours..." avec spinner
     - 🟢 "Analyser avec l'IA" prêt

4. **Résultats de l'analyse**
   - Snackbar de succès : "Les informations ont été pré-remplies avec succès"
   - Champs auto-remplis :
     - ✅ Nom du produit
     - ✅ Description
     - ✅ Type (article/service)
     - ✅ Catégorie et sous-catégorie
     - ✅ Poids (weight_category)

5. **Validation et édition**
   - L'utilisateur peut modifier les champs pré-remplis
   - Remplir les champs manquants (prix, stock, etc.)
   - Soumettre normalement

---

## 🎨 Interface utilisateur

### Bouton d'analyse

Le bouton s'adapte automatiquement :

| État | Apparence | Action |
|------|-----------|--------|
| **Sans image** | Grisé + "Ajoutez une image pour analyser" | Désactivé |
| **Avec image** | Bleu + "Analyser avec l'IA" + icône ✨ | Cliquable |
| **En cours** | Bleu + "Analyse en cours..." + spinner | Désactivé |

### Snackbars

**Succès :**
```
✨ Analyse terminée
Les informations ont été pré-remplies avec succès
```

**Erreur - Pas d'image :**
```
⚠️ Aucune image
Veuillez ajouter au moins une image avant l'analyse
```

**Erreur - Analyse échouée :**
```
❌ Erreur d'analyse
Impossible d'analyser l'image: [détails]
```

---

## 🧠 Intelligence Artificielle

### Prompt Gemini

Le service envoie ce prompt à Gemini :

```
Vous êtes un assistant d'analyse de produits pour une marketplace en Afrique (Bénin/Gabon).
Analysez cette image de produit et extrayez les informations suivantes en JSON.

Champs à extraire :
1. name - Nom du produit (concis, max 100 caractères) en français
2. description - Description détaillée (200-500 caractères) en français
3. condition - État du produit (new, used, refurbished)
4. type - Classification (article, service)
5. category - Catégorie principale en français
6. subcategory - Sous-catégorie en français
7. weight_category - Catégorie de poids pour livraison
8. confidence - Scores de confiance (0.0 à 1.0)

IMPORTANT : Retournez UNIQUEMENT du JSON valide, pas de markdown.
```

### Mapping automatique des catégories

Le `GeminiService` mappe automatiquement les suggestions de catégories avec votre base de données :

```php
// Recherche case-insensitive avec ILIKE
Category::query()
    ->where(function ($query) use ($suggestedCategory) {
        $query->where('name', 'ILIKE', "%{$suggestedCategory}%")
            ->orWhere('name_en', 'ILIKE', "%{$suggestedCategory}%");
    })
    ->first();
```

Si aucune correspondance exacte :
- ✅ Retourne `suggested_category_name` pour affichage
- ✅ `category_id` = null
- ℹ️ L'utilisateur doit sélectionner manuellement

---

## 🔒 Sécurité

### Validation des images

**Backend (Laravel):**
```php
'image' => 'required|file|image|mimes:jpeg,png,jpg,gif,webp|max:10240'
```

**Taille max :** 10 MB
**Formats acceptés :** JPEG, PNG, JPG, GIF, WebP

### Protection de la clé API

✅ **Bonne pratique :**
- La clé Gemini est dans `.env` (backend)
- Jamais exposée au client Flutter
- Toutes les requêtes passent par l'API Laravel

❌ **À éviter :**
- Ne jamais mettre la clé dans le code Flutter
- Ne jamais commit la clé dans Git

### Gestion des erreurs

Toutes les erreurs Gemini sont loggées :
```php
Log::error('Gemini Analysis Error:', [
    'message' => $e->getMessage(),
    'trace' => $e->getTraceAsString(),
]);
```

Check logs :
```bash
tail -f storage/logs/laravel.log
```

---

## 🧪 Tests

### Test manuel - Backend

1. **Tester l'endpoint avec curl :**

```bash
curl -X POST http://10.193.76.109:8001/api/v1/products/analyze \
  -F "image=@/path/to/test-image.jpg" \
  -H "Accept: application/json"
```

2. **Tester le health check :**

```bash
curl http://10.193.76.109:8001/api/v1/products/analyze/health
```

Expected:
```json
{
  "success": true,
  "service": "Gemini Vision AI",
  "status": "configured",
  "ready": true
}
```

### Test manuel - Flutter

1. Lancer l'app Flutter
2. Se connecter en tant que vendeur
3. Aller dans "Ajouter un produit"
4. Upload une image de test (ex: photo d'un téléphone)
5. Cliquer sur "Analyser avec l'IA"
6. Vérifier que les champs se remplissent
7. Modifier si nécessaire
8. Soumettre le produit

### Images de test recommandées

- 📱 Smartphone (haute confiance)
- 👕 Vêtements (confiance moyenne)
- 🪑 Meubles (confiance moyenne)
- 🍎 Fruits (haute confiance)
- 📚 Livres (haute confiance)

---

## 🐛 Dépannage

### Problème : "Gemini API Error"

**Causes possibles :**
1. Clé API invalide ou expirée
2. Quota dépassé
3. Problème réseau

**Solutions :**
```bash
# Vérifier la clé API
php artisan tinker
>>> config('gemini.api_key')

# Tester la connexion
curl https://generativelanguage.googleapis.com/v1/models \
  -H "x-goog-api-key: YOUR_API_KEY"
```

### Problème : "Routes not found"

**Solution :**
```bash
php artisan route:clear
php artisan config:clear
php artisan cache:clear
php artisan route:list --path=v1/products
```

### Problème : "Image too large"

**Solution côté Flutter :**
- Réduire `imageQuality` dans ImagePicker (actuellement 85%)
- Compresser l'image avant upload

```dart
final XFile? image = await _picker.pickImage(
  source: ImageSource.camera,
  imageQuality: 70,  // Réduire de 85 à 70
  maxWidth: 1920,
  maxHeight: 1080,
);
```

### Problème : Catégories non matchées

**Check la base de données :**
```sql
SELECT id, name, name_en FROM categories;
SELECT id, category_id, name, name_en FROM subcategories;
```

**Ajuster le prompt Gemini :**
- Modifier `GeminiService::buildAnalysisPrompt()`
- Ajouter des exemples de catégories existantes

---

## 📊 Logs et Monitoring

### Logs Laravel

```bash
# Suivre les logs en temps réel
tail -f storage/logs/laravel.log

# Filtrer les logs Gemini
grep "Gemini" storage/logs/laravel.log
```

### Logs Flutter (Debug)

Les logs du controller affichent :
```
🤖 Starting Gemini AI analysis...
   └─ Analyzing image: /path/to/image.jpg
✅ Analysis completed successfully!
   └─ Suggested name: iPhone 14
   └─ Category ID: 5
📝 Applying analysis results to form...
   └─ Name applied: iPhone 14
   └─ Description applied
   └─ Type applied: article
   └─ Category/Subcategory applied: Smartphones
   └─ Weight category applied: X-small
✅ Analysis results applied successfully!
```

---

## 💡 Améliorations futures

### Court terme
- [ ] Ajouter un bouton "Réanalyser" si l'utilisateur n'est pas satisfait
- [ ] Afficher les scores de confiance dans l'UI
- [ ] Permettre l'analyse de plusieurs images (agrégation)
- [ ] Ajouter un historique des analyses

### Moyen terme
- [ ] Caching des résultats d'analyse (éviter reanalyse de la même image)
- [ ] Extraction du prix depuis l'image (si affiché)
- [ ] Détection automatique de la condition (neuf vs usagé) via qualité
- [ ] Support multi-langues (FR/EN)

### Long terme
- [ ] Fine-tuning du modèle pour les produits africains
- [ ] Détection de produits contrefaits
- [ ] Génération automatique de tags
- [ ] Suggestion de prix basée sur le marché

---

## 📚 Références

### Documentation officielle
- [Google Gemini API](https://ai.google.dev/docs)
- [google-gemini-php/laravel](https://github.com/google-gemini-php/laravel)
- [Laravel File Storage](https://laravel.com/docs/11.x/filesystem)
- [Flutter ImagePicker](https://pub.dev/packages/image_picker)

### Modèles Gemini utilisés
- **gemini-pro-vision** : Analyse d'images + génération de texte
- **Format supporté** : JPEG, PNG, WebP, HEIC, HEIF

---

## 👥 Support

En cas de problème :

1. **Vérifier cette documentation**
2. **Consulter les logs** (`storage/logs/laravel.log`)
3. **Tester avec l'endpoint health** (`/api/v1/products/analyze/health`)
4. **Vérifier la clé API Gemini** (expiration, quota)

---

**Version :** 1.0.0
**Date :** 17 Avril 2026
**Auteur :** Claude Code
**Projet :** ASSO Marketplace
