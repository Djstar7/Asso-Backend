# API de Recherche Intelligente - Documentation

## Vue d'ensemble

Cette API de recherche intelligente utilise les capacités avancées de PostgreSQL pour fournir une expérience de recherche supérieure avec :

- ✅ **Support UTF-8 complet** avec gestion des accents
- ✅ **Recherche sémantique** avec synonymes (PC → ordinateur)
- ✅ **Full-text search** avec PostgreSQL tsvector
- ✅ **Similarité trigram** pour gérer les fautes de frappe
- ✅ **Scoring de pertinence** pour trier les résultats
- ✅ **Expansion de requête** intelligente

## Endpoints

### 1. Recherche principale

```http
GET /api/v1/search?q={query}
```

**Paramètres :**
- `q` (requis) : Terme de recherche
- `per_page` (optionnel) : Nombre de résultats par page (défaut: 20, max: 100)
- `category_id` (optionnel) : Filtrer par catégorie
- `subcategory_id` (optionnel) : Filtrer par sous-catégorie
- `type` (optionnel) : `article` ou `service`
- `min_price` (optionnel) : Prix minimum
- `max_price` (optionnel) : Prix maximum

**Exemple de requête :**
```bash
curl "http://localhost:8001/api/v1/search?q=PC&per_page=10"
```

**Exemple de réponse :**
```json
{
  "success": true,
  "query": "PC",
  "expanded_terms": ["pc", "ordinateur", "computer"],
  "products": [
    {
      "id": 1,
      "name": "Ordinateur portable HP",
      "price": 450000,
      "relevance_score": 125.5,
      "primary_image": "http://...",
      "category": {...},
      "shop": {...}
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 10,
    "total": 28,
    "has_more": true
  }
}
```

### 2. Suggestions de recherche (Autocomplete)

```http
GET /api/v1/search/suggestions?q={query}
```

**Paramètres :**
- `q` (requis) : Début du terme de recherche
- `limit` (optionnel) : Nombre de suggestions (défaut: 10, max: 20)

**Exemple de requête :**
```bash
curl "http://localhost:8001/api/v1/search/suggestions?q=ordi"
```

**Exemple de réponse :**
```json
{
  "success": true,
  "query": "ordi",
  "suggestions": [
    "Ordinateur portable HP",
    "Ordinateur de bureau Dell",
    "Ordinateur gaming ASUS"
  ],
  "synonym_suggestions": [
    {
      "term": "ordinateur",
      "synonym": "pc"
    }
  ]
}
```

### 3. Recherches populaires

```http
GET /api/v1/search/popular?limit={limit}
```

**Paramètres :**
- `limit` (optionnel) : Nombre de termes (défaut: 10)

**Exemple de réponse :**
```json
{
  "success": true,
  "popular_searches": [
    "ordinateur",
    "téléphone",
    "vêtement",
    "chaussure"
  ]
}
```

## Exemples de recherche intelligente

### 1. Recherche avec synonymes

**Requête :** `PC`
**Résultats :** Produits contenant "PC", "ordinateur", "computer"

### 2. Recherche insensible aux accents

**Requête :** `telephone`
**Résultats :** Produits contenant "téléphone", "telephone", "smartphone"

### 3. Recherche avec fautes de frappe

**Requête :** `ordiateur` (faute de frappe)
**Résultats :** Produits contenant "ordinateur" grâce à la similarité trigram

### 4. Recherche multilingue

**Requête :** `laptop`
**Résultats :** Produits contenant "laptop", "portable", "ordinateur portable"

## Système de synonymes

### Structure de la table

```sql
CREATE TABLE search_synonyms (
    id BIGSERIAL PRIMARY KEY,
    term VARCHAR(255),      -- Terme recherché
    synonym VARCHAR(255),   -- Synonyme
    weight INT DEFAULT 1,   -- Poids pour le ranking
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Ajouter des synonymes

```sql
INSERT INTO search_synonyms (term, synonym, weight, is_active)
VALUES ('laptop', 'ordinateur portable', 9, true);
```

### Exemples de synonymes inclus

| Terme | Synonyme | Poids |
|-------|----------|-------|
| pc | ordinateur | 10 |
| pc | computer | 8 |
| portable | laptop | 10 |
| smartphone | téléphone | 9 |
| smartphone | mobile | 9 |
| voiture | auto | 8 |
| vélo | bicyclette | 8 |

Plus de 70 synonymes sont déjà configurés dans différentes catégories :
- Informatique & Électronique
- Vêtements & Mode
- Maison & Jardin
- Véhicules
- Alimentation
- Sports & Loisirs

## Scoring de pertinence

L'algorithme de scoring utilise plusieurs critères pondérés :

1. **Full-text search** (poids: 100) - Recherche dans le vecteur de recherche
2. **Similarité trigram** (poids: 50) - Correspondance floue sur le nom
3. **Correspondance exacte** (poids: 75) - Nom exact du produit
4. **Correspondance partielle** (poids: 25) - Nom contient le terme
5. **Correspondance description** (poids: 10) - Description contient le terme

Les résultats sont triés par score de pertinence décroissant.

## Architecture technique

### Extensions PostgreSQL utilisées

```sql
-- Recherche par similarité (trigram)
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Suppression des accents
CREATE EXTENSION IF NOT EXISTS unaccent;
```

### Index créés

```sql
-- Index trigram sur le nom
CREATE INDEX products_name_trgm_idx
ON products USING gin (f_unaccent(name) gin_trgm_ops);

-- Index trigram sur la description
CREATE INDEX products_description_trgm_idx
ON products USING gin (f_unaccent(description) gin_trgm_ops);

-- Index full-text search
CREATE INDEX products_search_vector_idx
ON products USING gin (search_vector);
```

### Fonction d'auto-mise à jour

Un trigger PostgreSQL met automatiquement à jour le vecteur de recherche à chaque modification de produit :

```sql
CREATE TRIGGER products_search_vector_trigger
BEFORE INSERT OR UPDATE ON products
FOR EACH ROW
EXECUTE FUNCTION products_search_vector_update();
```

## Performance

- Les index GIN permettent des recherches ultra-rapides même sur des millions de produits
- La recherche trigram gère les fautes de frappe avec une performance optimale
- Le système de synonymes permet d'élargir intelligemment les requêtes
- Le scoring de pertinence assure que les meilleurs résultats apparaissent en premier

## Migration depuis l'ancienne API

L'ancienne méthode de recherche avec `LIKE` et `REPLACE` manuels :

```php
// ❌ Ancienne méthode (inefficace)
->where('name', 'LIKE', "%{$search}%")
->orWhereRaw('LOWER(REPLACE(...)) LIKE ?', [...])
```

Nouvelle méthode avec recherche intelligente :

```php
// ✅ Nouvelle méthode (efficace et intelligente)
->whereRaw("search_vector @@ to_tsquery('french', ?)", [$tsQuery])
->orWhereRaw("similarity(f_unaccent(name), f_unaccent(?)) > 0.3", [$term])
```

## Notes importantes

1. Les synonymes sont bidirectionnels : si "PC" → "ordinateur", alors "ordinateur" → "PC"
2. La recherche est insensible à la casse et aux accents
3. Les fautes de frappe sont gérées automatiquement avec une similarité > 30%
4. Les produits inactifs et les boutiques inactives sont exclus des résultats

## Support et maintenance

Pour ajouter de nouveaux synonymes :

```php
use App\Models\SearchSynonym;

SearchSynonym::create([
    'term' => 'nouveau_terme',
    'synonym' => 'synonyme',
    'weight' => 8,
    'is_active' => true,
]);
```

Pour désactiver temporairement un synonyme :

```sql
UPDATE search_synonyms
SET is_active = false
WHERE term = 'terme' AND synonym = 'synonyme';
```
