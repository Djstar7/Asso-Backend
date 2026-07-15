<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportCountry;

class ImportCountryController extends Controller
{
    /**
     * Liste des pays d'origine des produits importés (Chine, Turquie, Dubaï…).
     * Public — utilisé par la section "Produits importés" et le formulaire produit.
     *
     * GET /v1/import-countries
     */
    public function index()
    {
        $countries = ImportCountry::activeOrdered()
            ->get(['code', 'name', 'flag'])
            ->map(fn ($c) => [
                'code' => $c->code,
                'name' => $c->name,
                'flag' => $c->flag,
            ]);

        return response()->json([
            'success' => true,
            'countries' => $countries,
        ]);
    }
}
