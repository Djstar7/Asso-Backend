<?php

namespace App\Http\Middleware;

use App\Models\Product;
use App\Models\Shop;
use App\Support\Translation\ContentLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Écrans où le vendeur gère son propre contenu : on renvoie le texte
 * français saisi (et les traductions à part), jamais la version traduite,
 * pour qu'un formulaire réenregistré n'écrase pas le français.
 */
class ContentSourceLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        return ContentLocale::raw(fn () => $next($request), [Product::class, Shop::class]);
    }
}
