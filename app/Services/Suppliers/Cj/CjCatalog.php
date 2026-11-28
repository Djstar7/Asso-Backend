<?php

namespace App\Services\Suppliers\Cj;

use App\Models\Supplier;
use App\Models\SupplierCategory;

/** Lecture du catalogue CJ : catégories, recherche, fiche détaillée, transport. */
class CjCatalog
{
    public const PAGE_SIZE_MAX = 100;

    public function __construct(private Supplier $supplier, private CjClient $client) {}

    /**
     * Récupère l'arbre des catégories CJ et enregistre les catégories de 3e
     * niveau (les seules qui portent un identifiant et les produits). Les
     * correspondances ASSO déjà faites sont conservées.
     *
     * @return int nombre de catégories CJ enregistrées
     */
    public function syncCategories(): int
    {
        $tree = $this->client->get('product/getCategory');
        $count = 0;
        $now = now();

        foreach ((array) $tree as $first) {
            $firstName = trim((string) ($first['categoryFirstName'] ?? ''));
            foreach ((array) ($first['categoryFirstList'] ?? []) as $second) {
                $secondName = trim((string) ($second['categorySecondName'] ?? ''));
                foreach ((array) ($second['categorySecondList'] ?? []) as $third) {
                    $id = trim((string) ($third['categoryId'] ?? ''));
                    $name = trim((string) ($third['categoryName'] ?? ''));
                    if ($id === '' || $name === '') {
                        continue;
                    }
                    SupplierCategory::updateOrCreate(
                        ['supplier_id' => $this->supplier->id, 'external_id' => $id],
                        [
                            'name' => mb_substr($name, 0, 255),
                            'path' => mb_substr(implode(' > ', array_filter([$firstName, $secondName, $name])), 0, 600),
                            'synced_at' => $now,
                        ]
                    );
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Recherche dans le catalogue CJ (Product List V2). CJ plafonne à 6000
     * résultats par recherche : affiner par catégorie ou par prix au-delà.
     *
     * @return array{total: int, pages: int, page: int, items: list<array>}
     */
    public function search(array $filters, int $page = 1, int $size = 50): array
    {
        $query = array_filter([
            'keyWord' => $filters['keyword'] ?? null,
            'categoryId' => $filters['category'] ?? null,
            'startSellPrice' => $filters['min_price'] ?? null,
            'endSellPrice' => $filters['max_price'] ?? null,
            'countryCode' => $filters['country'] ?? null,
            'page' => max(1, min(1000, $page)),
            'size' => max(1, min(self::PAGE_SIZE_MAX, $size)),
            'orderBy' => $filters['order_by'] ?? null,
            'sort' => $filters['sort'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        $query['features'] = 'enable_category';

        $data = (array) $this->client->get('product/listV2', $query);
        $items = [];
        foreach ((array) ($data['content'] ?? []) as $group) {
            foreach ((array) ($group['productList'] ?? []) as $product) {
                if (empty($product['id'])) {
                    continue;
                }
                $items[] = [
                    'id' => (string) $product['id'],
                    'name' => (string) ($product['nameEn'] ?? ''),
                    'sku' => $product['sku'] ?? null,
                    'image' => $product['bigImage'] ?? null,
                    'price' => $product['nowPrice'] ?? $product['sellPrice'] ?? null,
                    'category_id' => $product['categoryId'] ?? null,
                    'category' => $product['threeCategoryName'] ?? null,
                    'inventory' => isset($product['warehouseInventoryNum']) ? (int) $product['warehouseInventoryNum'] : null,
                    'delivery_cycle' => $product['deliveryCycle'] ?? null,
                ];
            }
        }

        return [
            'total' => (int) ($data['totalRecords'] ?? count($items)),
            'pages' => (int) ($data['totalPages'] ?? 1),
            'page' => (int) ($data['pageNumber'] ?? $page),
            'items' => $items,
        ];
    }

    /** Fiche détaillée avec toutes ses déclinaisons et leurs stocks. */
    public function product(string $pid): array
    {
        // Sans « enable_inventory », CJ renvoie inventories = null : stock lu à 0 partout.
        return (array) $this->client->get('product/query', ['pid' => $pid, 'features' => 'enable_inventory']);
    }

    /**
     * Lignes de transport CJ disponibles pour une déclinaison, de la moins chère
     * à la plus chère.
     *
     * @return list<array{name: string, price_usd: float, aging: ?string}>
     */
    public function freight(string $vid, int $quantity = 1): array
    {
        return $this->freightFor([['quantity' => $quantity, 'vid' => $vid]]);
    }

    /**
     * Lignes de transport pour un colis de plusieurs déclinaisons (une commande).
     *
     * @param  list<array{vid: string, quantity: int}>  $products
     * @return list<array{name: string, price_usd: float, aging: ?string}>
     */
    public function freightFor(array $products): array
    {
        $data = $this->client->post('logistic/freightCalculate', [
            'startCountryCode' => (string) $this->supplier->setting('from_country_code', 'CN'),
            'endCountryCode' => (string) $this->supplier->setting('to_country_code', 'CM'),
            'products' => array_values($products),
        ]);

        $lines = [];
        foreach ((array) $data as $line) {
            if (empty($line['logisticName']) || ! is_numeric($line['logisticPrice'] ?? null)) {
                continue;
            }
            $lines[] = [
                'name' => (string) $line['logisticName'],
                'price_usd' => (float) $line['logisticPrice'],
                'aging' => isset($line['logisticAging']) ? (string) $line['logisticAging'] : null,
            ];
        }
        usort($lines, fn ($a, $b) => $a['price_usd'] <=> $b['price_usd']);

        return $lines;
    }
}
