#!/usr/bin/env python3
"""Prepare French draft sheets from verified supplier records; no ASSO writes."""
import argparse
import json
import math
import re
import sqlite3
import unicodedata
from collections import Counter
from pathlib import Path
from sourced_catalog_categories import native_classification

ROOT = Path(__file__).resolve().parents[1]
ARCHIVE = ROOT / 'storage/app/private/catalog-imports/sourcing-3000-2026-10-27'
COUNTRIES = {'CN': 'Chine', 'TR': 'Turquie', 'AE': 'Émirats arabes unis (filière Dubaï)'}
UNITS = {'piece': 'pièce', 'pieces': 'pièce', 'unit': 'unité', 'units': 'unité', 'set': 'ensemble', 'sets': 'ensemble', 'pair': 'paire', 'pairs': 'paire', 'box': 'boîte', 'boxes': 'boîte', 'pack': 'lot', 'packs': 'lot', 'carton': 'carton', 'cartons': 'carton', 'bag': 'sachet', 'bags': 'sachet', 'bottle': 'flacon', 'bottles': 'flacon', 'dozen': 'douzaine', 'dozens': 'douzaine', 'roll': 'rouleau', 'rolls': 'rouleau', 'kilogram': 'kg', 'kilograms': 'kg', 'kg': 'kg', 'liter': 'litre', 'liters': 'litre', 'litre': 'litre', 'litres': 'litre', 'meter': 'mètre', 'meters': 'mètre', 'metre': 'mètre', 'metres': 'mètre'}
ATTR_LABELS = {'material': 'Matière annoncée', 'fabric type': 'Tissu', 'feature': 'Caractéristiques annoncées', 'features': 'Caractéristiques annoncées', 'color': 'Couleur', 'colour': 'Couleur', 'size': 'Dimensions / taille', 'sizes': 'Tailles', 'capacity': 'Contenance', 'volume': 'Volume', 'style': 'Style', 'pattern': 'Motif', 'pattern type': 'Type de motif', 'technics': 'Technique', 'gender': 'Public', 'season': 'Saison', 'application': 'Utilisation', 'use': 'Usage', 'model number': 'Modèle fournisseur', 'brand name': 'Marque annoncée', 'place of origin': 'Origine de fabrication déclarée', 'closure type': 'Fermeture', 'weight': 'Poids déclaré', 'collar': 'Col', 'sleeve length': 'Manches', 'function': 'Fonction', 'product name': 'Désignation fournisseur', 'product type': 'Type de produit', 'shape': 'Forme', 'item type': 'Type d’article', 'number of pieces': 'Nombre de pièces annoncé', 'power': 'Puissance annoncée', 'voltage': 'Tension annoncée', 'supply type': 'Type de fourniture', 'age group': 'Tranche d’âge', 'fit type': 'Coupe', 'length': 'Longueur', 'thickness': 'Épaisseur', 'surface handling': 'Finition', 'packaging': 'Conditionnement'}

# Literal vocabulary conversion only. Original titles/attributes remain in the
# source record; no generative dimensions, certifications, models or performance.
PHRASES = {
    'stainless steel': 'acier inoxydable', 'double wall': 'double paroi', 'water bottle': 'gourde', 'vacuum flask': 'bouteille isotherme', 'bath towel': 'serviette de bain', 'beach towel': 'serviette de plage', 'hand towel': 'serviette pour les mains', 'tea towel': 'torchon', 'home textile': 'linge de maison', 'bed sheet': 'drap', 'bedding set': 'parure de lit', 'duvet cover': 'housse de couette', 'pillow case': 'taie d’oreiller', 'pillow cover': 'housse de coussin', 'throw pillow': 'coussin décoratif', 'dining table': 'table à manger', 'coffee table': 'table basse', 'shoe rack': 'rangement à chaussures', 'storage rack': 'étagère de rangement', 'storage box': 'boîte de rangement', 'storage bag': 'sac de rangement', 'makeup bag': 'trousse de maquillage', 'cosmetic bag': 'trousse de toilette', 'travel bag': 'sac de voyage', 'shoulder bag': 'sac à bandoulière', 'crossbody bag': 'sac porté croisé', 'tote bag': 'sac cabas', 'shopping bag': 'sac de courses', 'back pack': 'sac à dos', 'mobile phone': 'téléphone portable', 'phone case': 'coque de téléphone', 'phone holder': 'support de téléphone', 'screen protector': 'protection d’écran', 'wireless charger': 'chargeur sans fil', 'power bank': 'batterie externe', 'charging cable': 'câble de recharge', 'usb cable': 'câble USB', 'bluetooth speaker': 'enceinte Bluetooth', 'smart watch': 'montre connectée', 'makeup brush': 'pinceau de maquillage', 'hair brush': 'brosse à cheveux', 'hair clip': 'pince à cheveux', 'lip gloss': 'brillant à lèvres', 'eau de parfum': 'eau de parfum', 'eau de toilette': 'eau de toilette', 'perfume oil': 'huile parfumée', 'long lasting': 'tenue annoncée longue', 'body lotion': 'lait pour le corps', 'body wash': 'gel douche', 'face cream': 'crème pour le visage', 'shower gel': 'gel douche', 'skin care': 'soin de la peau', 'hair care': 'soin des cheveux', 'hair dryer': 'sèche-cheveux', 'hair straightener': 'lisseur à cheveux', 't shirt': 'T-shirt', 't-shirt': 'T-shirt', 't shirts': 'T-shirts', 't-shirts': 'T-shirts', 'polo shirt': 'polo', 'dress shirt': 'chemise habillée', 'button up': 'boutonné', 'long sleeve': 'manches longues', 'short sleeve': 'manches courtes', 'sleeveless': 'sans manches', 'round neck': 'col rond', 'v neck': 'col V', 'v-neck': 'col V', 'slim fit': 'coupe ajustée', 'loose fit': 'coupe ample', 'regular fit': 'coupe droite', 'high waist': 'taille haute', 'wide leg': 'jambe large', 'straight leg': 'jambe droite', 'solid color': 'uni', 'solid colour': 'uni', 'plus size': 'grandes tailles', 'baby clothes': 'vêtements bébé', 'baby clothing': 'vêtements bébé', 'kids clothing': 'vêtements enfants', 'children clothing': 'vêtements enfants', 'women clothing': 'vêtements femme', 'men clothing': 'vêtements homme', 'women dress': 'robe femme', 'ladies dress': 'robe femme', 'men shirt': 'chemise homme', 'mens shirt': 'chemise homme', 'men shoes': 'chaussures homme', 'women shoes': 'chaussures femme', 'running shoes': 'chaussures de course', 'sports shoes': 'chaussures de sport', 'casual shoes': 'chaussures décontractées', 'high heels': 'talons hauts', 'flat shoes': 'chaussures plates', 'flip flops': 'tongs', 'kitchen utensils': 'ustensiles de cuisine', 'kitchen tools': 'accessoires de cuisine', 'cutting board': 'planche à découper', 'frying pan': 'poêle', 'sauce pan': 'casserole', 'non stick': 'antiadhésif', 'non-stick': 'antiadhésif', 'food container': 'boîte alimentaire', 'lunch box': 'boîte repas', 'coffee mug': 'mug', 'drinking glass': 'verre', 'wall clock': 'horloge murale', 'wall art': 'décoration murale', 'table lamp': 'lampe de table', 'floor lamp': 'lampadaire', 'ceiling light': 'plafonnier', 'led light': 'éclairage LED', 'string lights': 'guirlande lumineuse', 'solar light': 'lampe solaire', 'outdoor furniture': 'mobilier extérieur', 'garden tools': 'outils de jardin', 'yoga mat': 'tapis de yoga', 'resistance band': 'bande de résistance', 'jump rope': 'corde à sauter', 'sleeping bag': 'sac de couchage', 'camping tent': 'tente de camping', 'water proof': 'résistant à l’eau', 'quick dry': 'séchage rapide', 'quick-dry': 'séchage rapide', 'easy to clean': 'nettoyage facile', 'eco friendly': 'présenté comme écologique', 'eco-friendly': 'présenté comme écologique', 'high quality': '', 'top quality': '', 'best quality': '', 'best price': '', 'hot sale': '', 'hot selling': '', 'new arrival': '', 'factory direct': '', 'free shipping': '', 'private label': '', 'custom logo': '', 'customized logo': '', 'oem odm': '', 'oem/odm': '', '100 cotton': '100 % coton',
}
WORDS = {
    'women': 'femme', 'womens': 'femme', "women's": 'femme', 'ladies': 'femme', 'female': 'femme', 'men': 'homme', 'mens': 'homme', "men's": 'homme', 'male': 'homme', 'unisex': 'mixte', 'kids': 'enfant', 'children': 'enfant', 'child': 'enfant', 'baby': 'bébé', 'babies': 'bébé', 'girls': 'fille', 'girl': 'fille', 'boys': 'garçon', 'boy': 'garçon', 'adult': 'adulte', 'cotton': 'coton', 'linen': 'lin', 'polyester': 'polyester', 'silk': 'soie', 'satin': 'satin', 'denim': 'denim', 'wool': 'laine', 'leather': 'cuir', 'synthetic': 'synthétique', 'silicone': 'silicone', 'plastic': 'plastique', 'wooden': 'bois', 'wood': 'bois', 'glass': 'verre', 'ceramic': 'céramique', 'metal': 'métal', 'aluminum': 'aluminium', 'aluminium': 'aluminium', 'bamboo': 'bambou', 'steel': 'acier', 'velvet': 'velours', 'lace': 'dentelle', 'chiffon': 'mousseline', 'knitted': 'maille', 'woven': 'tissé', 'embroidered': 'brodé', 'printed': 'imprimé', 'striped': 'rayé', 'plaid': 'carreaux', 'floral': 'fleuri', 'plain': 'uni', 'solid': 'uni', 'breathable': 'respirant', 'absorbent': 'absorbant', 'lightweight': 'léger', 'soft': 'doux', 'stretch': 'extensible', 'elastic': 'élastique', 'foldable': 'pliable', 'reusable': 'réutilisable', 'portable': 'portable', 'insulated': 'isotherme', 'thermal': 'thermique', 'waterproof': 'résistant à l’eau', 'washable': 'lavable', 'dress': 'robe', 'dresses': 'robes', 'shirt': 'chemise', 'shirts': 'chemises', 'blouse': 'chemisier', 'blouses': 'chemisiers', 'pants': 'pantalon', 'trousers': 'pantalon', 'jeans': 'jean', 'shorts': 'short', 'skirt': 'jupe', 'skirts': 'jupes', 'jacket': 'veste', 'jackets': 'vestes', 'coat': 'manteau', 'coats': 'manteaux', 'hoodie': 'sweat à capuche', 'hoodies': 'sweats à capuche', 'sweatshirt': 'sweat', 'sweater': 'pull', 'sweaters': 'pulls', 'pullover': 'pull', 'cardigan': 'gilet', 'tracksuit': 'survêtement', 'tracksuits': 'survêtements', 'suit': 'costume', 'suits': 'costumes', 'blazer': 'blazer', 'pajamas': 'pyjama', 'pyjamas': 'pyjama', 'sleepwear': 'vêtement de nuit', 'nightwear': 'vêtement de nuit', 'lingerie': 'lingerie', 'underwear': 'sous-vêtements', 'bra': 'soutien-gorge', 'panties': 'culotte', 'boxers': 'boxers', 'socks': 'chaussettes', 'sock': 'chaussette', 'scarf': 'écharpe', 'scarves': 'écharpes', 'shawl': 'châle', 'cap': 'casquette', 'hat': 'chapeau', 'belt': 'ceinture', 'gloves': 'gants', 'robe': 'peignoir', 'bathrobe': 'peignoir', 'bathrobes': 'peignoirs', 'towel': 'serviette', 'towels': 'serviettes', 'blanket': 'couverture', 'blankets': 'couvertures', 'curtain': 'rideau', 'curtains': 'rideaux', 'carpet': 'tapis', 'rug': 'tapis', 'bedsheet': 'drap', 'bedsheets': 'draps', 'pillow': 'oreiller', 'cushion': 'coussin', 'sofa': 'canapé', 'chair': 'chaise', 'chairs': 'chaises', 'table': 'table', 'desk': 'bureau', 'shelf': 'étagère', 'cabinet': 'meuble de rangement', 'mirror': 'miroir', 'vase': 'vase', 'basket': 'panier', 'mat': 'tapis', 'lamp': 'lampe', 'light': 'éclairage', 'lights': 'luminaires', 'bulb': 'ampoule', 'bulbs': 'ampoules', 'bottle': 'bouteille', 'bottles': 'bouteilles', 'cup': 'tasse', 'cups': 'tasses', 'mug': 'mug', 'mugs': 'mugs', 'tumbler': 'gobelet', 'flask': 'bouteille isotherme', 'bowl': 'bol', 'bowls': 'bols', 'plate': 'assiette', 'plates': 'assiettes', 'spoon': 'cuillère', 'fork': 'fourchette', 'knife': 'couteau', 'cutlery': 'couverts', 'cookware': 'batterie de cuisine', 'pot': 'marmite', 'pan': 'poêle', 'kettle': 'bouilloire', 'container': 'boîte', 'organizer': 'organisateur', 'storage': 'rangement', 'hook': 'crochet', 'hooks': 'crochets', 'bag': 'sac', 'bags': 'sacs', 'handbag': 'sac à main', 'handbags': 'sacs à main', 'backpack': 'sac à dos', 'backpacks': 'sacs à dos', 'wallet': 'portefeuille', 'wallets': 'portefeuilles', 'purse': 'sac à main', 'pouch': 'pochette', 'luggage': 'bagage', 'suitcase': 'valise', 'suitcases': 'valises', 'shoes': 'chaussures', 'shoe': 'chaussure', 'sneakers': 'baskets', 'sneaker': 'basket', 'sandals': 'sandales', 'sandal': 'sandale', 'slippers': 'chaussons', 'boots': 'bottes', 'heels': 'talons', 'loafers': 'mocassins', 'watch': 'montre', 'watches': 'montres', 'bracelet': 'bracelet', 'necklace': 'collier', 'earrings': 'boucles d’oreilles', 'ring': 'bague', 'jewelry': 'bijoux', 'jewellery': 'bijoux', 'perfume': 'parfum', 'perfumes': 'parfums', 'fragrance': 'parfum', 'fragrances': 'parfums', 'deodorant': 'déodorant', 'attar': 'attar', 'spray': 'vaporisateur', 'oil': 'huile', 'musk': 'musc', 'vanilla': 'vanille', 'amber': 'ambre', 'woody': 'boisé', 'flower': 'fleur', 'rose': 'rose', 'shampoo': 'shampoing', 'conditioner': 'après-shampoing', 'serum': 'sérum', 'cream': 'crème', 'lotion': 'lotion', 'soap': 'savon', 'cleanser': 'nettoyant', 'moisturizing': 'hydratant', 'lipstick': 'rouge à lèvres', 'mascara': 'mascara', 'eyeliner': 'eyeliner', 'eyeshadow': 'ombre à paupières', 'foundation': 'fond de teint', 'concealer': 'correcteur', 'blush': 'blush', 'brush': 'brosse', 'brushes': 'brosses', 'sponge': 'éponge', 'sunglasses': 'lunettes de soleil', 'glasses': 'lunettes', 'charger': 'chargeur', 'charging': 'recharge', 'cable': 'câble', 'cables': 'câbles', 'wireless': 'sans fil', 'earbuds': 'écouteurs', 'earphones': 'écouteurs', 'headphones': 'casque audio', 'headset': 'casque audio', 'speaker': 'enceinte', 'speakers': 'enceintes', 'smartphone': 'smartphone', 'phone': 'téléphone', 'tablet': 'tablette', 'laptop': 'ordinateur portable', 'computer': 'ordinateur', 'keyboard': 'clavier', 'mouse': 'souris', 'monitor': 'écran', 'camera': 'caméra', 'projector': 'projecteur', 'remote': 'télécommande', 'battery': 'batterie', 'electric': 'électrique', 'electrical': 'électrique', 'electronic': 'électronique', 'digital': 'numérique', 'rechargeable': 'rechargeable', 'refrigerator': 'réfrigérateur', 'freezer': 'congélateur', 'blender': 'mixeur', 'mixer': 'mixeur', 'juicer': 'extracteur de jus', 'toaster': 'grille-pain', 'grinder': 'moulin', 'iron': 'fer', 'vacuum': 'aspiration', 'cleaner': 'nettoyant', 'fan': 'ventilateur', 'heater': 'chauffage', 'notebook': 'carnet', 'notebooks': 'carnets', 'pen': 'stylo', 'pens': 'stylos', 'pencil': 'crayon', 'pencils': 'crayons', 'paper': 'papier', 'stationery': 'papeterie', 'office': 'bureau', 'school': 'école', 'toy': 'jouet', 'toys': 'jouets', 'puzzle': 'puzzle', 'doll': 'poupée', 'dolls': 'poupées', 'ball': 'ballon', 'fitness': 'fitness', 'gym': 'salle de sport', 'sport': 'sport', 'sports': 'sport', 'yoga': 'yoga', 'camping': 'camping', 'outdoor': 'extérieur', 'indoor': 'intérieur', 'travel': 'voyage', 'beach': 'plage', 'garden': 'jardin', 'kitchen': 'cuisine', 'bathroom': 'salle de bain', 'bedroom': 'chambre', 'home': 'maison', 'hotel': 'hôtel', 'restaurant': 'restaurant', 'coffee': 'café', 'tea': 'thé', 'dates': 'dattes', 'chocolate': 'chocolat', 'candy': 'bonbon', 'candies': 'bonbons', 'nuts': 'fruits à coque', 'snacks': 'collations', 'dried': 'séché', 'roasted': 'torréfié', 'instant': 'instantané', 'ground': 'moulu', 'powder': 'poudre', 'beans': 'grains', 'black': 'noir', 'white': 'blanc', 'red': 'rouge', 'blue': 'bleu', 'green': 'vert', 'yellow': 'jaune', 'pink': 'rose', 'purple': 'violet', 'brown': 'marron', 'grey': 'gris', 'gray': 'gris', 'beige': 'beige', 'gold': 'doré', 'silver': 'argenté', 'orange': 'orange', 'navy': 'bleu marine', 'multicolor': 'multicolore', 'transparent': 'transparent', 'round': 'rond', 'square': 'carré', 'rectangular': 'rectangulaire', 'large': 'grand', 'small': 'petit', 'mini': 'mini', 'long': 'long', 'short': 'court', 'thick': 'épais', 'thin': 'fin', 'adjustable': 'réglable', 'zipper': 'fermeture zippée', 'zip': 'zip', 'button': 'bouton', 'buttons': 'boutons', 'hooded': 'à capuche', 'hood': 'capuche', 'pocket': 'poche', 'pockets': 'poches', 'handle': 'poignée', 'handles': 'poignées', 'with': 'avec', 'without': 'sans', 'for': 'pour', 'and': 'et', 'or': 'ou', 'of': 'de', 'in': 'en', 'from': 'de', 'made': 'fabriqué', 'turkey': 'Turquie', 'turkish': 'turc', 'china': 'Chine', 'chinese': 'chinois', 'dubai': 'Dubaï', 'arabic': 'arabe', 'arabian': 'oriental', 'spring': 'printemps', 'summer': 'été', 'autumn': 'automne', 'winter': 'hiver', 'casual': 'décontracté', 'elegant': 'élégant', 'fashion': '', 'fashionable': '', 'stylish': '', 'luxury': '', 'premium': '', 'wholesale': '', 'manufacturer': '', 'manufacturers': '', 'supplier': '', 'suppliers': '', 'factory': '', 'custom': '', 'customized': '', 'customizable': '', 'customization': '', 'logo': '', 'oem': '', 'odm': '', 'new': '', 'hot': '', 'sale': '', 'selling': '', 'best': '', 'quality': '', 'product': '', 'products': '', 'design': '', 'designs': '', 'style': '', 'styles': '', 'cheap': '', 'price': '', 'prices': '', 'top': '', 'latest': '', 'trending': '', 'popular': '', '2024': '', '2025': '', '2026': '', 'supply': '', 'professional': 'professionnel', 'commercial': 'professionnel', 'set': 'ensemble', 'sets': 'ensembles', 'pack': 'lot', 'packs': 'lots', 'piece': 'pièce', 'pieces': 'pièces', 'pcs': 'pièces', 'pair': 'paire', 'pairs': 'paires',
}


PHRASES.update({'cast iron': 'fonte', 'non slip': 'antidérapant', 'non-slip': 'antidérapant', 'food grade': 'qualité alimentaire annoncée', 'crew neck': 'col rond', 'knee length': 'longueur genou', 'full sleeves': 'manches longues', 'living room': 'salon', 'memory foam': 'mousse à mémoire de forme', 'seat cushion': 'coussin d’assise', 'die cast': 'moulé', 'kitchen aid': 'accessoire de cuisine', 'wall mounted': 'fixation murale', 'multi purpose': 'polyvalent', 'multi-purpose': 'polyvalent', 'body underwear': 'body', 'long lasting': 'tenue annoncée longue', 'double sided': 'double face', 'side pocket': 'poche latérale', 'all season': 'toutes saisons', 'long sleeves': 'manches longues', 'short sleeves': 'manches courtes', 'ready to ship': '', 'in stock': '', 'top selling': '', 'high grade': '', 'low price': '', 'best selling': ''})
WORDS.update({'clothing': 'vêtement', 'clothes': 'vêtements', 'beauty': 'beauté', 'kitchenware': 'ustensiles de cuisine', 'packaging': 'emballage', 'modern': 'moderne', 'accessories': 'accessoires', 'accessory': 'accessoire', 'plush': 'peluche', 'household': 'ménager', 'decor': 'décoration', 'decoration': 'décoration', 'decorative': 'décoratif', 'use': 'usage', 'modest': 'sobre', 'tool': 'outil', 'tools': 'outils', 'furniture': 'mobilier', 'comfortable': 'confortable', 'comfort': 'confort', 'fast': 'rapide', 'fabric': 'tissu', 'food': 'alimentaire', 'durable': 'durabilité annoncée', 'wall': 'mural', 'stand': 'support', 'daily': 'quotidien', 'print': 'imprimé', 'length': 'longueur', 'wear': 'vêtement', 'clear': 'transparent', 'room': 'pièce', 'frame': 'cadre', 'neck': 'col', 'size': 'taille', 'sizes': 'tailles', 'face': 'visage', 'pattern': 'motif', 'cooking': 'cuisson', 'polarized': 'polarisé', 'cycling': 'cyclisme', 'classic': 'classique', 'holder': 'support', 'kit': 'kit', 'magnetic': 'magnétique', 'mobile': 'mobile', 'car': 'voiture', 'lens': 'lentille', 'body': 'corps', 'cosmetic': 'cosmétique', 'cosmetics': 'cosmétiques', 'hand': 'main', 'viscose': 'viscose', 'type': 'type', 'drying': 'séchage', 'genuine': '', 'authentic': '', 'original': '', 'knitwear': 'maille', 'muslim': 'musulman', 'wedding': 'mariage', 'gift': 'cadeau', 'floor': 'sol', 'cute': '', 'screen': 'écran', 'power': 'alimentation', 'candle': 'bougie', 'candles': 'bougies', 'full': 'complet', 'collar': 'col', 'material': 'matière', 'coating': 'revêtement', 'flat': 'plat', 'cover': 'housse', 'resealable': 'refermable', 'stuffed': 'rembourré', 'low': '', 'single': 'simple', 'lid': 'couvercle', 'lids': 'couvercles', 'ready': '', 'inch': 'pouce', 'islamic': 'islamique', 'traditional': 'traditionnel', 'model': 'modèle', 'printing': 'impression', 'base': 'base', 'super': '', 'free': '', 'makeup': 'maquillage', 'care': 'soin', 'brand': 'marque', 'running': 'course', 'color': 'couleur', 'colors': 'couleurs', 'soup': 'soupe', 'pots': 'marmites', 'pans': 'poêles', 'box': 'boîte', 'boxes': 'boîtes', 'capacity': 'capacité', 'bottom': 'fond', 'bath': 'bain', 'case': 'étui', 'display': 'affichage', 'card': 'carte', 'cleaning': 'nettoyage', 'shopping': 'courses', 'handmade': 'fabrication manuelle annoncée', 'gaming': 'jeu vidéo', 'waist': 'taille', 'control': 'commande', 'seal': 'joint', 'dual': 'double', 'sleeves': 'manches', 'shape': 'forme', 'gas': 'gaz', 'quick': 'rapide', 'resistant': 'résistant', 'carbon': 'carbone', 'layer': 'couche', 'microfiber': 'microfibre', 'universal': 'universel', 'christmas': 'Noël', 'crystal': 'cristal', 'resin': 'résine', 'years': 'ans', 'cooling': 'refroidissement', 'cord': 'cordon', 'uniform': 'uniforme', 'garment': 'vêtement', 'side': 'latéral', 'windproof': 'coupe-vent', 'plug': 'prise', 'facial': 'visage', 'eye': 'œil', 'fit': 'coupe', 'line': 'ligne', 'trendy': '', 'enamel': 'émaillé', 'rectangle': 'rectangle', 'panel': 'panneau', 'tray': 'plateau', 'heat': 'chaleur', 'flexible': 'flexible', 'bulk': '', 'business': 'professionnel', 'desktop': 'de bureau', 'matte': 'mat', 'cooler': 'rafraîchisseur', 'protector': 'protection', 'wide': 'large', 'adhesive': 'adhésif', 'disposable': 'jetable', 'fishing': 'pêche', 'eyewear': 'lunettes', 'driving': 'conduite', 'walking': 'marche', 'eyelash': 'cils', 'warmer': 'réchauffeur', 'baking': 'pâtisserie', 'funnel': 'entonnoir', 'oven': 'four', 'stove': 'cuisinière', 'enamelled': 'émaillé', 'silky': 'soyeux', 'fluffy': 'duveteux', 'wicker': 'osier', 'knitting': 'tricot', 'zippered': 'zippé', 'everyday': 'quotidien', 'holiday': 'vacances', 'relaxed': 'décontracté', 'fitted': 'ajusté'})


def translate_title(text, brands=()):
    # Protect source product/brand identifiers by only replacing whole words.
    text = text.replace('’', "'").replace('–', '-').replace('—', '-')
    text = re.sub(r'\b(?:women|men|kid|children|girl|boy)\s*\'s\b', lambda m: m[0].replace("'s", ''), text, flags=re.I)
    protected = []
    for brand in brands:
        if not brand or len(brand) < 3:
            continue
        def keep_brand(match):
            protected.append(match[0])
            return '\ue000' + str(len(protected) - 1) + '\ue001'
        text = re.sub(r'(?<!\w)' + re.escape(brand) + r'(?!\w)', keep_brand, text, flags=re.I)
    for key, value in sorted(PHRASES.items(), key=lambda item: len(item[0]), reverse=True):
        def replace_phrase(match):
            protected.append(value)
            return '\ue000' + str(len(protected) - 1) + '\ue001'
        text = re.sub(r'(?<!\w)' + re.escape(key) + r'(?!\w)', replace_phrase, text, flags=re.I)
    # Avoid recursively translating French output from phrase conversion: convert
    # words once, leaving words absent from the literal English dictionary intact.
    text = re.sub(r"[A-Za-z]+(?:'[A-Za-z]+)?|\b20(?:24|25|26)\b", lambda m: WORDS.get(m[0].lower(), m[0]), text)
    for index, value in enumerate(protected):
        text = text.replace('\ue000' + str(index) + '\ue001', value)
    text = re.sub(r'\s+', ' ', text)
    text = re.sub(r'\s*([|/,;])\s*', r' \1 ', text).strip(' |/,;-')
    text = re.sub(r'(?:\s*\|\s*)+', ' — ', text)
    text = re.sub(r'\b(\w+)(\s+\1\b)+', r'\1', text, flags=re.I)
    text = re.sub(r'\s+', ' ', text).strip()
    return text[:1].upper() + text[1:]


def classify(title, query):
    t = title.lower()
    woman = bool(re.search(r'\b(women|woman|womens|ladies|lady|female|girl|girls)\b', t))
    child = bool(re.search(r'\b(kids|children|child|boys|girls|toddler)\b', t))
    if re.search(r'\b(machinery|making machine|making machinery|production line|production machine|injection molding|injection moulding|extrusion machine|extruder|pulp molding|paper mill|industrial plant|blown film|rotary drilling|hydraulic crane|slitting|slitter|slotting|grooving|rewinder|autoclave|dyeing machine|fixing technology|stationery factory|stationery making|fastener machine)\b', t):
        return None
    if re.search(r'\b(polybutene|petrochemical|titanium dioxide|oleic acid|transmission oil|brake fluid|antifreeze|hydraulic|drilling rig|mobile crane|resin granules|resin premium extrusion)\b', t):
        return None
    if re.search(r'\b(shoe last|shoe mold|shoe mould|injection mold|bag making machine|manufacturing machine)\b', t):
        return None
    if re.search(r'\b(screw|screws|bolt|bolts|fastener|fasteners|nut and bolt|hinge|hinges)\b', t):
        return 3, 20, 'Quincaillerie'
    if re.search(r'\b(knife sharpener|knife sharpening)\b', t):
        return 3, 17, 'Cuisine et table'
    if re.search(r'\b(candle making wax|soy wax flakes|wax for candle making)\b', t):
        return 29, 163, 'Loisirs créatifs'
    if re.search(r'\b(candle|candles|wax burner|fragrance warmer|reed diffuser)\b', t):
        return 3, 16, 'Parfum d’intérieur et décoration'
    if re.search(r'\b(shoe dryer|boot dryer)\b', t):
        return 14, 86, 'Petit électroménager'
    if re.search(r'\b(calculator|pencil sharpener|stapler|label printer)\b', t) or ('sharpener' in t and re.search(r'\b(stationery|stationary|school)\b', t)):
        return 11, 69, 'Matériel de bureau'
    if re.search(r'\b(e-scooter|electric scooter)\b', t):
        return 9, 57, 'Mobilité électrique'
    if re.search(r'\b(gaming console|game console|playstation)\b', t):
        return 6, 40, 'Jeux vidéo'
    if re.search(r'\b(arduino|circuit board|pcb|electronic component|electronic compass|power station|phone protector|tempered glass protector|case.*mobile phone|case.*phones|phone.*case)\b', t):
        return 1, 5, 'Accessoire électronique'
    if re.search(r'perfume|parfum|fragrance|attar|bakhoor|bukhoor|deodorant|oudh', t):
        return 5, 31, 'Parfumerie'
    if re.search(r'shampoo|conditioner|hair (?:dryer|straightener|brush|clip)|wig\b|hair extension', t):
        return 5, 32, 'Coiffure'
    if re.search(r'makeup|make up|mascara|eyeshadow|lipstick|eyeliner|concealer|lip gloss|blush\b', t) and not re.search(r'\b(bag|case|pouch)\b', t):
        return 5, 30, 'Maquillage et accessoires'
    if re.search(r'skincare|skin care|face cream|face serum|moisturi|sunscreen|body lotion|facial', t):
        return 5, 29, 'Soin de la peau'
    if re.search(r'soap\b|toothbrush|toothpaste|shower gel|body wash|sanitary', t):
        return 5, 34, 'Hygiène'
    if re.search(r'\b(diaper|diapers|nappy|nappies)\b', t):
        return 12, 76, 'Hygiène bébé'
    if re.search(r'\b(stroller|pushchair|baby carriage|car seat)\b', t):
        return 12, 75, 'Puériculture'
    if re.search(r'\b(baby|newborn|infant)\b', t) and re.search(r'cloth|romper|bodysuit|dress|pajama|jumpsuit|shirt|pants|outfit', t):
        return 12, 73, 'Vêtement bébé'
    if re.search(r'\b(smartwatch|smart watch|fitness watch)\b', t):
        return 1, 6, 'Montre connectée'
    if re.search(r'earbuds|earphones|headphones|headset', t):
        return 1, 7, 'Audio personnel'
    if re.search(r'\b(laptop|notebook computer|desktop|computer|keyboard|mouse|monitor)\b', t):
        return 1, 1, 'Informatique'
    if re.search(r'phone case|phone cover|screen protect|charger|charging|power bank|usb|phone holder|phone stand|adapter', t):
        return 1, 5, 'Accessoire électronique'
    if re.search(r'\b(iphone|smartphone|smartphones|tablet|mobile phone|cell phone|ipad)\b', t):
        return 1, 2, 'Téléphone ou tablette'
    if re.search(r'\b(camera|cameras|webcam|camcorder)\b', t):
        return 1, 4, 'Photo et vidéo'
    if re.search(r'\b(television|tv|speaker|speakers|projector|soundbar|amplifier)\b', t):
        return 1, 3, 'Télévision et audio'
    if re.search(r'\b(refrigerator|fridge|freezer|washing machine|dishwasher|oven|cooker)\b', t):
        return 14, 85, 'Gros électroménager'
    if re.search(r'\b(coffee machine|coffee maker|espresso machine)\b', t):
        return 14, 89, 'Machine à café'
    if re.search(r'\b(electric.*grinder|electric.*iron|iron.*electric|popcorn maker)\b', t):
        return 14, 86, 'Petit électroménager'
    if re.search(r'\b(vacuum cleaner|steam mop|robot vacuum)\b', t):
        return 14, 87, 'Entretien de la maison'
    if re.search(r'\b(fan|heater|air conditioner|air cooler)\b', t) and 'makeup' not in t:
        return 14, 88, 'Ventilation et chauffage'
    if re.search(r'\b(blender|juicer|mixer|electric kettle|air fryer|toaster|sandwich maker|electric iron)\b', t):
        return 14, 86, 'Petit électroménager'
    if re.search(r'\b(suitcase|suitcases|luggage|trolley bag)\b', t):
        return 30, 168, 'Valise et bagage'
    if re.search(r'\b(backpack|backpacks|rucksack)\b', t):
        return 30, 169, 'Sac à dos'
    if re.search(r'\b(travel bag|duffel|duffle|weekender)\b', t):
        return 30, 170, 'Sac de voyage'
    if re.search(r'cosmetic bag|makeup bag|toiletry|wash bag|travel organizer', t):
        return 30, 172, 'Trousse et rangement de voyage'
    if re.search(r'\b(bag|bags|handbag|handbags|purse|wallet|wallets|pouch|tote)\b', t):
        return 2, 12, 'Sac et maroquinerie'
    if re.search(r'\b(shoe|shoes|sneaker|sneakers|sandal|sandals|slipper|slippers|boots|heels|loafers|footwear)\b', t):
        return 2, 11, 'Chaussures'
    if re.search(r'\b(watch|watches|bracelet|necklace|earrings|jewelry|jewellery|ring)\b', t):
        return 2, 13, 'Bijoux et montres'
    if re.search(r'\b(scarf|scarves|shawl|belt|hat|hats|cap|caps|sunglasses|hijab|gloves)\b', t):
        return 2, 14, 'Accessoire de mode'
    if re.search(r'\b(towel|towels|bathrobe|bathrobes|bedding|bedsheet|bedsheets|bed sheet|duvet|pillow|pillowcase|curtain|blanket|quilt|fouta|peshtemal|peskir|tablecloth|chair cover|table cloth)\b', t):
        return 3, 18, 'Linge de maison'
    if re.search(r'\b(sofa|chair|chairs|furniture|table|desk|cabinet|wardrobe|bed|dresser|stool|shelf|shelves|bookshelf)\b', t) and not re.search(r'tableware|table cloth|tablecloth', t):
        return 3, 15, 'Mobilier'
    if re.search(r'\b(lamp|lamps|light|lights|lighting|bulb|bulbs|chandelier|lantern)\b', t):
        return 3, 21, 'Éclairage'
    if re.search(r'\b(garden|gardening|planter|flower pot|watering|pruning)\b', t):
        return 3, 19, 'Jardin'
    if re.search(r'\b(drill|hammer|screwdriver|wrench|pliers|tool kit|tool set|measuring tape)\b', t):
        return 3, 20, 'Outils'
    if re.search(r'\b(kitchen|kitchenware|cookware|cutlery|spoon|fork|knife|bowl|bowls|plate|plates|cup|cups|mug|tumbler|bottle|bottles|flask|kettle|frying|saucepan|pot|pots|pan|pans|ladle|dinner set|bakeware|lunch|food container|dinnerware|tableware)\b', t):
        return 3, 17, 'Cuisine et table'
    if re.search(r'\b(candle|vase|mirror|decor|decoration|carpet|rug|clock|basket|ornament|bathroom|storage|organizer|mat|doormat|cleaning|mop|broom|brush)\b', t):
        return 3, 16, 'Maison et décoration'
    if re.search(r'\b(notebook|paper|pen|pens|pencil|pencils|stationery|folder|stapler|eraser|ruler|marker|sticker)\b', t):
        return 11, 67, 'Papeterie'
    if re.search(r'\b(toy|toys|puzzle|doll|dolls|teddy|plush|building blocks|educational game)\b', t):
        return 7, 48, 'Jouets'
    if re.search(r'\b(yoga|fitness|dumbbell|gym|resistance band|jump rope|pilates)\b', t):
        return 4, 22, 'Fitness'
    if re.search(r'\b(camping|tent|sleeping bag|hiking|picnic)\b', t):
        return 4, 26, 'Camping et randonnée'
    if re.search(r'\b(ball|football|basketball|volleyball|tennis|sport|sports|badminton|racket)\b', t) and not re.search(r'cloth|wear|shirt|pants|shorts|tracksuit|hoodie', t):
        return 4, 28, 'Équipement sportif'
    if re.search(r'\b(coffee|tea|espresso|cappuccino|matcha)\b', t):
        return 8, 53, 'Café et thé'
    if re.search(r'\b(dates|rice|pasta|flour|spice|honey|olive oil|sunflower oil|nuts|cashew|almond|pistachio)\b', t):
        return 8, 49, 'Épicerie'
    if re.search(r'\b(chocolate|candy|candies|snack|biscuit|cookies|wafer)\b', t):
        return 8, 50, 'Snacks et confiserie'
    if re.search(r'\b(dress|dresses|shirt|shirts|t-shirt|tee|polo|pants|trousers|jeans|shorts|skirt|jacket|coat|hoodie|sweater|cardigan|suit|blazer|pajamas|pyjamas|sleepwear|underwear|lingerie|bra|socks|tracksuit|abaya|kaftan|clothing|garment|apparel|sportswear|outfit|jumpsuit|romper|leggings|bodysuit|tunic)\b', t):
        return 2, (10 if child else 9 if woman or re.search(r'\b(abaya|kaftan|lingerie|bra|dress|skirt)\b', t) else 8), 'Vêtement'
    return None


def norm(text):
    text = unicodedata.normalize('NFKD', text).encode('ascii', 'ignore').decode().lower()
    return re.sub('[^a-z0-9]', '', text)


def build(target, batch_size):
    initial = json.loads((ROOT / 'database/data/wholesale-catalog-2026-10-27.json').read_text())
    initial_counts = Counter(p['product']['origin_country'] for p in initial['products'])
    initial_ids = {p['source']['id'] for p in initial['products']}
    rates = json.loads((ARCHIVE / 'rates.json').read_text())
    publication_path = ARCHIVE / 'publication-mode.json'
    public = publication_path.exists() and json.loads(publication_path.read_text()).get('visible', False)
    con = sqlite3.connect(ARCHIVE / 'queue.sqlite')
    rows = con.execute('SELECT record FROM candidates WHERE status="ready" ORDER BY rowid').fetchall()
    frozen_path = ARCHIVE / 'frozen-selection.json'
    frozen = json.loads(frozen_path.read_text()) if frozen_path.exists() else None
    seen_titles = set()
    seen_models = set()
    used_names = set()
    selected = []
    counts = Counter(initial_counts)
    rejected = Counter()
    for raw, in rows:
        record = json.loads(raw)
        if frozen is not None and record['id'] not in frozen:
            continue
        country = record['country']
        if counts[country] >= target or record['id'] in initial_ids:
            continue
        classification = native_classification(record) or classify(record['title'], record['query'])
        if not classification:
            rejected['unclassified'] += 1
            continue
        if any(t['unit'].lower().strip() not in UNITS for t in record['tiers']):
            rejected['unsupported_source_unit'] += 1
            continue
        if any(math.ceil(t['price'] * rates['USD'] / 50) * 50 > 99999999.99 for t in record['tiers']):
            rejected['price_requires_manual_review'] += 1
            continue
        title_key = norm(record['title'])
        if title_key in seen_titles:
            rejected['duplicate_title'] += 1
            continue
        model = record.get('model') or ''
        model_key = norm(record['supplier']) + ':' + norm(model)
        if len(norm(model)) > 4 and norm(model) not in ['custom', 'customized', 'customlogo', 'oemodm']:
            if model_key in seen_models:
                rejected['duplicate_supplier_model'] += 1
                continue
            seen_models.add(model_key)
        brands = ['Green Lion', 'Al Rehab', 'Al Haramain', 'New Balance', 'The North Face', 'Bath & Body Works']
        brands.extend(str(a.get('attrValue', '')) for a in record['attributes'] if a.get('attrName', '').lower() == 'brand name' and str(a.get('attrValue', '')).lower() not in ['custom', 'oem', 'odm', 'unbranded', 'no brand', 'generic'])
        title_for_name = record['title']
        if classification[0] == 3 and classification[1] == 17:
            title_for_name = re.sub(r'\bsuit\b', 'set', title_for_name, flags=re.I)
        name = frozen[record['id']] if frozen is not None else translate_title(title_for_name, brands)
        if len(name) > 235:
            name = name[:232].rsplit(' ', 1)[0].rstrip(' ,/|-') + '…'
        if len(name) < 5:
            rejected['empty_name'] += 1
            continue
        if norm(name) in used_names:
            # Do not create numbered clones to satisfy the quota.
            rejected['duplicate_translated_name'] += 1
            continue
        used_names.add(norm(name))
        seen_titles.add(title_key)
        cat, sub, label = classification
        weight = record['weight_kg']
        origin = record['declared_origin'] or 'Non précisée dans les données publiques consultées.'
        characteristics = [f"Famille : {label}.", f"Fournisseur référencé : {record['supplier']}.", f"Pays du fournisseur / filière d’approvisionnement : {COUNTRIES[country]}.", f"Origine de fabrication déclarée : {origin}"]
        used_attrs = set()
        for attr in record['attributes']:
            k = str(attr.get('attrName', attr.get('name', ''))).strip()
            value = str(attr.get('attrValue', attr.get('value', ''))).strip()
            if not k or not value or k.lower() in ['place of origin', 'product name', 'available quantity']:
                continue
            if (k.lower(), value.lower()) in used_attrs:
                continue
            used_attrs.add((k.lower(), value.lower()))
            # Preserve exact technical values; label French, value verbatim.
            characteristics.append(f"{ATTR_LABELS.get(k.lower(), k)} : {value}.")
            if len(characteristics) >= 19:
                break
        characteristics.append(f"Poids logistique communiqué : {weight:g} kg par unité de vente." if weight else 'Poids emballé non communiqué : à confirmer avant cotation du transport.')
        characteristics.append('Photos du fournisseur correspondant à cette annonce ; coloris, dimensions et conditionnement à confirmer avant commande.')
        unit = UNITS[record['tiers'][0]['unit'].lower().strip()]
        if unit not in ['pièce', 'unité', 'paire', 'kg', 'litre', 'mètre']:
            characteristics.append(f"Unité de vente annoncée : {unit}. Le nombre d’articles par {unit} n’est pas présumé ; à confirmer sur devis.")
        tiers = []
        for index, t in enumerate(record['tiers'], 1):
            unit_fr = UNITS[t['unit'].lower().strip()]
            amount = int(math.ceil(t['price'] * rates['USD'] / 50) * 50)
            tiers.append({'label': f"Dès {t['min']:,} {unit_fr}(s)".replace(',', ' '), 'unit_price': amount, 'currency': 'XAF', 'min_quantity': t['min'], 'pack_size': 1 if unit_fr in ['pièce', 'unité'] else None, 'weight_kg': weight, 'sort_order': index, 'is_active': True, 'source_price': t['price']})
        commercial = ['Fiche en préparation : prix indicatifs d’approvisionnement, sans disponibilité ASSO confirmée.', 'Transport international, droits, taxes et livraison locale non inclus. Le prix de vente ASSO doit être validé avant activation.']
        for t, source_t in zip(tiers, record['tiers']):
            unit_fr = UNITS[source_t['unit'].lower().strip()]
            commercial.append(f"• Dès {t['min_quantity']:,} {unit_fr}(s) : {t['unit_price']:,} FCFA par {unit_fr} (source : {source_t['price']:g} USD).".replace(',', ' '))
        if any(t.get('range_reference') for t in record['tiers']):
            commercial.append('Prix de référence pris à la borne haute de la fourchette publique ; il dépend des options et du conditionnement.')
        commercial.extend(['Matières, options et performances : déclarations du fournisseur, à confirmer sur le devis retenu.', f"Page source : {record['url']}", f"Source consultée le {record['consulted_at'][:10]}. Conversion ASSO de référence : 1 USD = {rates['USD']:.4f} XAF ; arrondi au multiple supérieur de 50 FCFA."])
        product = {'name': name, 'origin_country': country, 'category_id': cat, 'subcategory_id': sub, 'description': f"{name}.\n\n{label} proposé par {record['supplier']}, fournisseur référencé en {COUNTRIES[country]}. La galerie présente le modèle de l’annonce source. Les caractéristiques disponibles sont reprises ci-dessous pour préparer une commande d’import après confirmation du fournisseur.", 'characteristics': '\n'.join(characteristics), 'commercial_information': '\n'.join(commercial), 'price': tiers[0]['unit_price'], 'currency': 'XAF', 'price_type': 'fixed', 'type': 'article', 'is_wholesale': True, 'is_local_made': False, 'min_order_quantity': tiers[0]['min_quantity'], 'stock': 0, 'weight': weight, 'weight_category': 'X-small', 'status': 'inactive', 'tier_mix_variants': False, 'sizes': [], 'client_reference': 'sourced-alibaba-' + record['id']}
        source = {'platform': 'Alibaba', 'id': record['id'], 'url': record['url'], 'listing_url': record['listing_url'], 'supplier': record['supplier'], 'supplier_country': country, 'declared_origin': record['declared_origin'], 'original_title': record['title'], 'currency': 'USD', 'exchange_rate_xaf': rates['USD'], 'consulted_at': record['consulted_at'], 'detail_available': record['detail_available'], 'unit': record['tiers'][0]['unit'], 'price_type': 'wholesale_upper_bound' if any(t.get('range_reference') for t in record['tiers']) else 'wholesale'}
        product['description'] = f"{label}.\n\nCe modèle est proposé par {record['supplier']}, fournisseur référencé en {COUNTRIES[country]}. Les photos présentent la référence disponible dans son catalogue. Les matières, dimensions et options communiquées figurent dans les caractéristiques ci-dessous. Le prix final, le conditionnement et la disponibilité doivent être confirmés par ASSO avant commande."
        facts = []
        value_fr = {'100% cotton': '100 % coton', 'cotton': 'coton', 'stainless steel': 'acier inoxydable', 'plastic': 'plastique', 'wood': 'bois', 'bamboo': 'bambou', 'linen': 'lin', 'silicone': 'silicone', 'zinc alloy': 'alliage de zinc', '100% polyester': '100 % polyester', 'black': 'noir', 'white': 'blanc', 'blue': 'bleu', 'red': 'rouge', 'green': 'vert'}
        for attribute in record['attributes']:
            key = str(attribute.get('attrName', attribute.get('name', ''))).strip().lower()
            value = str(attribute.get('attrValue', attribute.get('value', ''))).strip()
            if key not in ['material', 'size', 'capacity', 'model number', 'color', 'fabric type'] or not value or len(value) > 120 or re.search(r'custom|oem|not available', value, re.I):
                continue
            fact = f"{ATTR_LABELS.get(key, key)} : {value_fr.get(value.lower(), value)}"
            if fact not in facts:
                facts.append(fact)
            if len(facts) == 4:
                break
        if facts:
            product['description'] = f"{label}.\n\nCaractéristiques annoncées par le fournisseur :\n" + '\n'.join('• ' + fact for fact in facts) + f"\n\nModèle proposé par {record['supplier']}, fournisseur référencé en {COUNTRIES[country]}. Les photos présentent la référence de son catalogue. Le prix final, les options et la disponibilité sont à confirmer par ASSO avant commande."
        product['status'] = 'active' if public else 'inactive'
        product['requires_quote'] = False
        product['commercial_information'] = product['commercial_information'].replace('Fiche en préparation :', 'Référence sur devis :').replace('avant activation.', 'avant commande.')
        source['category_path'] = record.get('category_path', [])
        selected.append({'key': 'alibaba-' + record['id'], 'source': source, 'product': product, 'tiers': tiers, 'images': record['images']})
        counts[country] += 1
    paths = []
    for index in range(0, len(selected), batch_size):
        entries = selected[index:index + batch_size]
        manifest = {'batch': ARCHIVE.name, 'image_policy': {'minimum_short_side': 400, 'minimum_long_side': 700}, 'publication_policy': 'Inactive drafts. Unknown weights remain null. Existing products are never updated. Supplier country is the import procurement route, not an invented country of manufacture.', 'products': entries}
        path = ARCHIVE / 'manifests' / f'catalog-{index // batch_size + 1:03}.json'
        path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')
        paths.append(str(path))
    report = {'target_per_country': target, 'initial_sourced_products': dict(initial_counts), 'selected_new': len(selected), 'total_sourced_by_country': dict(counts), 'curation_exclusions': dict(rejected), 'photos': sum(len(p['images']) for p in selected), 'detail_records': sum(p['source']['detail_available'] for p in selected), 'known_shipping_weight': sum(p['product']['weight'] is not None for p in selected), 'manifests': paths}
    (ARCHIVE / 'selection-report.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--target', type=int, default=1000)
    parser.add_argument('--batch-size', type=int, default=100)
    args = parser.parse_args()
    build(args.target, args.batch_size)
