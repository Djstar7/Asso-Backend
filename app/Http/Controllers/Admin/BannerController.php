<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Support\Translation\ContentLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BannerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $banners = Banner::ordered()->paginate(10);

        return view('admin.banners.index', compact('banners'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('admin.banners.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'link' => 'nullable|url',
            'position' => 'required|integer|min:0',
            'is_active' => 'nullable|boolean',
        ] + ContentLocale::rules(['title' => 'string|max:255', 'description' => 'string']));

        try {
            // Upload de l'image
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('banners', 'public');
                $validated['image_path'] = $imagePath;
            }

            // Gestion du statut actif
            $validated['is_active'] = $request->has('is_active') ? true : false;

            $banner = Banner::create($validated);
            $banner->syncTranslations($validated['translations'] ?? null);

            return redirect()->route('admin.banners.index')
                ->with('success', 'Bannière créée avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la création: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Banner $banner)
    {
        return view('admin.banners.show', compact('banner'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Banner $banner)
    {
        return view('admin.banners.edit', compact('banner'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Banner $banner)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'link' => 'nullable|url',
            'position' => 'required|integer|min:0',
            'is_active' => 'nullable|boolean',
        ] + ContentLocale::rules(['title' => 'string|max:255', 'description' => 'string']));

        try {
            // Upload de la nouvelle image si fournie
            if ($request->hasFile('image')) {
                // Supprimer l'ancienne image
                if ($banner->image_path) {
                    Storage::disk('public')->delete($banner->image_path);
                }

                $imagePath = $request->file('image')->store('banners', 'public');
                $validated['image_path'] = $imagePath;
            }

            // Gestion du statut actif
            $validated['is_active'] = $request->has('is_active') ? true : false;

            $banner->update($validated);
            $banner->syncTranslations($validated['translations'] ?? null);

            return redirect()->route('admin.banners.index')
                ->with('success', 'Bannière mise à jour avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la mise à jour: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Banner $banner)
    {
        try {
            // Supprimer l'image
            if ($banner->image_path) {
                Storage::disk('public')->delete($banner->image_path);
            }

            $banner->delete();

            return redirect()->route('admin.banners.index')
                ->with('success', 'Bannière supprimée avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la suppression: ' . $e->getMessage());
        }
    }

    /**
     * Toggle the active status of a banner.
     */
    public function toggleStatus(Banner $banner)
    {
        try {
            $banner->update(['is_active' => !$banner->is_active]);

            return redirect()->route('admin.banners.index')
                ->with('success', 'Statut mis à jour avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la mise à jour du statut: ' . $e->getMessage());
        }
    }
}
