<?php

namespace App\Support;

use App\Models\Division;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Périmètre de l'espace « Ma rubrique » : toutes les requêtes de cet espace
 * passent par ici, jamais par les pages de l'admin.
 *
 * - Un chef ne voit que les rubriques où il est en poste aujourd'hui ;
 *   l'admin peut ouvrir n'importe quelle rubrique (aperçu de l'espace).
 * - Les ventes sont filtrées LIGNE PAR LIGNE sur la catégorie figée à la
 *   commande (order_items.category_id) : dans une commande mêlant plusieurs
 *   rubriques, le chef ne voit que ses lignes, jamais les autres ni le total.
 */
class DivisionScope
{
    /** Rubriques ouvertes au compte. */
    public static function divisionsFor(User $user): Collection
    {
        if ($user->isAdmin()) {
            return Division::active()->orderBy('name')->get();
        }

        return Division::active()
            ->whereHas('heads', fn ($q) => $q->current()->where('user_id', $user->id))
            ->orderBy('name')
            ->get();
    }

    /** Rubrique demandée (?division=) parmi celles du compte, sinon la première ; null si aucune. */
    public static function current(Request $request): ?Division
    {
        $divisions = static::divisionsFor($request->user());
        $wanted = $request->integer('division');

        return ($wanted ? $divisions->firstWhere('id', $wanted) : null) ?? $divisions->first();
    }

    /** Comme current(), mais 404 si le compte n'a aucune rubrique ouverte. */
    public static function require(Request $request): Division
    {
        $division = static::current($request);
        abort_unless($division, 404);

        return $division;
    }

    /** Lignes de commande de la rubrique (catégorie figée à la commande). */
    public static function items(Division $division): Builder
    {
        return OrderItem::query()->whereIn('order_items.category_id', static::categoryIds($division));
    }

    /** Commandes ayant au moins une ligne de la rubrique. */
    public static function orders(Division $division): Builder
    {
        $ids = static::categoryIds($division);

        return Order::withoutGlobalScopes()->whereHas('items', fn ($q) => $q->whereIn('category_id', $ids));
    }

    /** Produits de la rubrique, tous vendeurs confondus, archivés exclus. */
    public static function products(Division $division): Builder
    {
        return Product::withoutGlobalScopes([AssoScope::NAME, AssoScope::OWN_PRODUCTS])
            ->whereIn('products.category_id', static::categoryIds($division));
    }

    /** Une commande donnée relève-t-elle de la rubrique ? */
    public static function coversOrder(Division $division, Order $order): bool
    {
        return OrderItem::where('order_id', $order->id)->whereIn('category_id', static::categoryIds($division))->exists();
    }

    public static function categoryIds(Division $division): array
    {
        // Liste vide : aucune ligne ne correspond (whereIn([]) renvoie zéro ligne).
        return $division->categoryIds();
    }
}
