<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LegalPage;
use App\Support\Translation\ContentLocale;
use Illuminate\Http\Request;

class LegalPageController extends Controller
{
    /**
     * Afficher la liste des pages légales.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $legalPages = LegalPage::ordered()->get();

        return view('admin.legal-pages.index', compact('legalPages'));
    }

    /**
     * Afficher le formulaire de création d'une page légale.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        return view('admin.legal-pages.create');
    }

    /**
     * Enregistrer une nouvelle page légale.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'slug' => 'nullable|string|unique:legal_pages,slug',
                'content' => 'required|string',
                'is_active' => 'nullable|boolean',
                'order' => 'nullable|integer|min:0',
            ] + ContentLocale::rules(['title' => 'string|max:255', 'content' => 'string']));

            // Générer un slug si non fourni
            if (empty($validated['slug'])) {
                $validated['slug'] = LegalPage::generateSlug($validated['title']);
            }

            $validated['is_active'] = $request->has('is_active');
            $validated['order'] = $validated['order'] ?? 0;

            $legalPage = LegalPage::create($validated);
            $legalPage->syncTranslations($validated['translations'] ?? null);

            return redirect()->route('admin.legal-pages.index')
                ->with('success', 'Page légale créée avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la création: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Afficher le formulaire d'édition d'une page légale.
     *
     * @param LegalPage $legalPage
     * @return \Illuminate\View\View
     */
    public function edit(LegalPage $legalPage)
    {
        return view('admin.legal-pages.edit', compact('legalPage'));
    }

    /**
     * Mettre à jour une page légale.
     *
     * @param Request $request
     * @param LegalPage $legalPage
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, LegalPage $legalPage)
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'slug' => 'required|string|unique:legal_pages,slug,' . $legalPage->id,
                'content' => 'required|string',
                'is_active' => 'nullable|boolean',
                'order' => 'nullable|integer|min:0',
            ] + ContentLocale::rules(['title' => 'string|max:255', 'content' => 'string']));

            $validated['is_active'] = $request->has('is_active');
            $validated['order'] = $validated['order'] ?? $legalPage->order;

            $legalPage->update($validated);
            $legalPage->syncTranslations($validated['translations'] ?? null);

            return redirect()->route('admin.legal-pages.index')
                ->with('success', 'Page légale mise à jour avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la mise à jour: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Supprimer une page légale.
     *
     * @param LegalPage $legalPage
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(LegalPage $legalPage)
    {
        try {
            $legalPage->delete();

            return redirect()->route('admin.legal-pages.index')
                ->with('success', 'Page légale supprimée avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la suppression: ' . $e->getMessage());
        }
    }

    /**
     * Basculer le statut actif/inactif d'une page légale.
     *
     * @param LegalPage $legalPage
     * @return \Illuminate\Http\RedirectResponse
     */
    public function toggle(LegalPage $legalPage)
    {
        try {
            $legalPage->is_active = !$legalPage->is_active;
            $legalPage->save();

            $message = $legalPage->is_active ? 'Page activée' : 'Page désactivée';

            return redirect()->back()
                ->with('success', $message);
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors du basculement: ' . $e->getMessage());
        }
    }
}
