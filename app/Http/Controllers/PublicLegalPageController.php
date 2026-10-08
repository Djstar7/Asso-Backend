<?php

namespace App\Http\Controllers;

use App\Models\LegalPage;

/**
 * Pages légales (CGU, CGV, confidentialité…) rédigées dans le back-office.
 *
 * Version web publique : lue dans l'application mobile et utilisable comme
 * lien de politique de confidentialité sur les stores. Version JSON : la liste
 * que l'application affiche dans « À propos » et le menu Aide & Support.
 */
class PublicLegalPageController extends Controller
{
    /**
     * GET /legal/{slug}
     */
    public function show(string $slug)
    {
        $page = LegalPage::findActiveBySlug($slug);
        abort_unless($page, 404);

        return view('legal.show', [
            'page' => $page,
            'others' => LegalPage::getAllActive()->where('id', '!=', $page->id),
        ]);
    }

    /**
     * GET /api/v1/legal-pages
     */
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => LegalPage::getAllActive()->map(fn (LegalPage $page) => self::summary($page))->values(),
        ]);
    }

    /**
     * GET /api/v1/legal-pages/{slug}
     */
    public function showJson(string $slug)
    {
        $page = LegalPage::findActiveBySlug($slug);
        if (!$page) {
            return response()->json(['success' => false, 'message' => 'Page introuvable.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => self::summary($page) + ['content' => $page->content],
        ]);
    }

    public static function summary(LegalPage $page): array
    {
        return [
            'slug' => $page->slug,
            'title' => $page->title,
            'url' => route('legal.show', $page->slug),
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
    }
}
