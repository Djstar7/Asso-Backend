<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    /**
     * Afficher la liste des documents.
     */
    public function index(Request $request)
    {
        $query = Document::with(['category', 'uploader'])->active();

        // Filtrage par catégorie
        if ($request->filled('category')) {
            $query->where('category_id', $request->category);
        }

        // Filtrage par type de fichier
        if ($request->filled('type')) {
            $query->where('file_type', $request->type);
        }

        // Recherche
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%')
                  ->orWhere('file_name', 'like', '%' . $request->search . '%');
            });
        }

        $documents = $query->latest()->paginate(20);
        $categories = DocumentCategory::active()->ordered()->get();

        // Statistiques
        $stats = [
            'total' => Document::active()->count(),
            'total_size' => Document::active()->sum('file_size'),
            'categories' => DocumentCategory::active()->count(),
            'downloads' => Document::active()->sum('download_count'),
        ];

        return view('admin.documents.index', compact('documents', 'categories', 'stats'));
    }

    /**
     * Afficher le formulaire de création.
     */
    public function create()
    {
        $categories = DocumentCategory::active()->ordered()->get();
        $users = User::where('role', 'admin')->get();

        return view('admin.documents.create', compact('categories', 'users'));
    }

    /**
     * Enregistrer un nouveau document.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:document_categories,id',
            'file' => 'required|file|max:51200', // 50MB max
            'visibility' => 'required|in:public,private,restricted',
            'allowed_users' => 'nullable|array',
            'allowed_users.*' => 'exists:users,id',
        ]);

        try {
            $file = $request->file('file');

            // Stocker le fichier
            $fileName = time() . '_' . Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $file->getClientOriginalExtension();
            $filePath = $file->storeAs('documents', $fileName, 'public');

            // Créer le document
            $document = Document::create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'category_id' => $validated['category_id'] ?? null,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $filePath,
                'file_type' => $file->getClientOriginalExtension(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'uploaded_by' => auth()->id(),
                'visibility' => $validated['visibility'],
                'allowed_users' => $validated['allowed_users'] ?? null,
            ]);

            return redirect()->route('admin.documents.index')
                ->with('success', 'Document uploadé avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de l\'upload: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Afficher un document.
     */
    public function show(Document $document)
    {
        $document->load(['category', 'uploader']);

        return view('admin.documents.show', compact('document'));
    }

    /**
     * Afficher le formulaire d'édition.
     */
    public function edit(Document $document)
    {
        $categories = DocumentCategory::active()->ordered()->get();
        $users = User::where('role', 'admin')->get();

        return view('admin.documents.edit', compact('document', 'categories', 'users'));
    }

    /**
     * Mettre à jour un document.
     */
    public function update(Request $request, Document $document)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:document_categories,id',
            'file' => 'nullable|file|max:51200', // 50MB max
            'visibility' => 'required|in:public,private,restricted',
            'allowed_users' => 'nullable|array',
            'allowed_users.*' => 'exists:users,id',
        ]);

        try {
            // Si un nouveau fichier est uploadé
            if ($request->hasFile('file')) {
                // Supprimer l'ancien fichier
                if (Storage::disk('public')->exists($document->file_path)) {
                    Storage::disk('public')->delete($document->file_path);
                }

                $file = $request->file('file');
                $fileName = time() . '_' . Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $file->getClientOriginalExtension();
                $filePath = $file->storeAs('documents', $fileName, 'public');

                $document->update([
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $filePath,
                    'file_type' => $file->getClientOriginalExtension(),
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ]);
            }

            // Mettre à jour les autres informations
            $document->update([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'category_id' => $validated['category_id'] ?? null,
                'visibility' => $validated['visibility'],
                'allowed_users' => $validated['allowed_users'] ?? null,
            ]);

            return redirect()->route('admin.documents.index')
                ->with('success', 'Document mis à jour avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la mise à jour: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Télécharger un document.
     */
    public function download(Document $document)
    {
        if (!Storage::disk('public')->exists($document->file_path)) {
            abort(404, 'Fichier introuvable');
        }

        $document->incrementDownloadCount();

        return Storage::disk('public')->download($document->file_path, $document->file_name);
    }

    /**
     * Archiver un document.
     */
    public function archive(Document $document)
    {
        $document->update(['is_archived' => true]);

        return redirect()->back()
            ->with('success', 'Document archivé avec succès');
    }

    /**
     * Désarchiver un document.
     */
    public function unarchive(Document $document)
    {
        $document->update(['is_archived' => false]);

        return redirect()->back()
            ->with('success', 'Document désarchivé avec succès');
    }

    /**
     * Supprimer un document.
     */
    public function destroy(Document $document)
    {
        try {
            $document->delete();

            return redirect()->route('admin.documents.index')
                ->with('success', 'Document supprimé avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la suppression: ' . $e->getMessage());
        }
    }

    /**
     * Gérer les catégories.
     */
    public function categories()
    {
        $categories = DocumentCategory::withCount('documents')->ordered()->get();

        return view('admin.documents.categories', compact('categories'));
    }

    /**
     * Créer une catégorie.
     */
    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable|string',
            'color' => 'nullable|string',
            'order' => 'nullable|integer',
        ]);

        try {
            DocumentCategory::create($validated);

            return redirect()->back()
                ->with('success', 'Catégorie créée avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Mettre à jour une catégorie.
     */
    public function updateCategory(Request $request, DocumentCategory $category)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable|string',
            'color' => 'nullable|string',
            'order' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        try {
            $category->update($validated);

            return redirect()->back()
                ->with('success', 'Catégorie mise à jour avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Supprimer une catégorie.
     */
    public function destroyCategory(DocumentCategory $category)
    {
        try {
            // Mettre à null la catégorie des documents
            $category->documents()->update(['category_id' => null]);

            $category->delete();

            return redirect()->back()
                ->with('success', 'Catégorie supprimée avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur: ' . $e->getMessage());
        }
    }
}
