<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Credential;
use App\Models\CredentialCategory;
use Illuminate\Http\Request;

class VaultController extends Controller
{
    public function index(Request $request)
    {
        $query = Credential::with('category');

        if ($request->filled('category')) {
            $query->where('category_id', $request->category);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                    ->orWhere('username', 'like', '%'.$request->search.'%')
                    ->orWhere('url', 'like', '%'.$request->search.'%');
            });
        }

        $credentials = $query->latest()->get();
        $categories = CredentialCategory::active()->ordered()->get();

        $stats = [
            'total' => Credential::count(),
            'favorites' => Credential::favorites()->count(),
            'categories' => CredentialCategory::active()->count(),
        ];

        return view('admin.vault.index', compact('credentials', 'categories', 'stats'));
    }

    public function create()
    {
        $categories = CredentialCategory::active()->ordered()->get();

        return view('admin.vault.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string',
            'category_id' => 'nullable|exists:credential_categories,id',
            'username' => 'nullable|string',
            'password' => 'required|string',
            'url' => 'nullable|url',
            'notes' => 'nullable|string',
        ]);

        try {
            Credential::create($validated);

            return redirect()->route('admin.vault.index')
                ->with('success', 'Credential sauvegardé avec succès');
        } catch (\Exception $e) {
            report($e);

            return redirect()->back()
                ->with('error', 'L’action n’a pas pu être enregistrée. Réessayez plus tard.')
                ->withInput($request->except('password'));
        }
    }

    public function edit(Credential $vault)
    {
        $categories = CredentialCategory::active()->ordered()->get();

        return view('admin.vault.edit', compact('vault', 'categories'));
    }

    public function show(Credential $vault)
    {
        return redirect()->route('admin.vault.edit', $vault);
    }

    public function update(Request $request, Credential $vault)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string',
            'category_id' => 'nullable|exists:credential_categories,id',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
            'url' => 'nullable|url',
            'notes' => 'nullable|string',
        ]);

        try {
            if (empty($validated['password'])) {
                unset($validated['password']);
            }

            $vault->update($validated);

            return redirect()->route('admin.vault.index')
                ->with('success', 'Credential mis à jour');
        } catch (\Exception $e) {
            report($e);

            return redirect()->back()
                ->with('error', 'L’action n’a pas pu être enregistrée. Réessayez plus tard.')
                ->withInput($request->except('password'));
        }
    }

    public function destroy(Credential $vault)
    {
        try {
            $vault->delete();

            return redirect()->route('admin.vault.index')
                ->with('success', 'Credential supprimé');
        } catch (\Exception $e) {
            report($e);

            return redirect()->back()
                ->with('error', 'L’action n’a pas pu être enregistrée. Réessayez plus tard.');
        }
    }

    public function reveal(Credential $credential)
    {
        $credential->markAsUsed();

        return response()->json([
            'success' => true,
            'password' => $credential->decrypted_password,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function toggleFavorite(Credential $credential)
    {
        $credential->update(['is_favorite' => ! $credential->is_favorite]);

        return response()->json([
            'success' => true,
            'is_favorite' => $credential->is_favorite,
        ]);
    }

    public function categories()
    {
        $categories = CredentialCategory::withCount('credentials')->ordered()->get();

        return view('admin.vault.categories', compact('categories'));
    }
}
