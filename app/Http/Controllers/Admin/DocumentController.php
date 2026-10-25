<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\User;
use App\Services\SafeUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    /**
     * Afficher la liste des documents.
     */
    public function index(Request $request)
    {
        $query = Document::with(['category', 'uploader'])->active()->accessibleTo($request->user());

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
                $q->where('title', 'like', '%'.$request->search.'%')
                    ->orWhere('description', 'like', '%'.$request->search.'%')
                    ->orWhere('file_name', 'like', '%'.$request->search.'%');
            });
        }

        $documents = $query->latest()->paginate(20);
        $categories = DocumentCategory::active()->ordered()->get();

        // Statistiques
        $stats = [
            'total' => Document::active()->accessibleTo($request->user())->count(),
            'total_size' => Document::active()->accessibleTo($request->user())->sum('file_size'),
            'categories' => DocumentCategory::active()->count(),
            'downloads' => Document::active()->accessibleTo($request->user())->sum('download_count'),
        ];

        return view('admin.documents.index', compact('documents', 'categories', 'stats'));
    }

    /**
     * Afficher le formulaire de création.
     */
    public function create()
    {
        $categories = DocumentCategory::active()->ordered()->get();
        $users = User::where('role', 'admin')->get()->reject->isTechnicalAccount()->values();

        return view('admin.documents.create', compact('categories', 'users'));
    }

    /**
     * Enregistrer un nouveau document.
     */
    public function store(Request $request)
    {
        $validated = $this->validated($request, true);
        $file = $request->file('file');
        $path = SafeUpload::store($file, 'documents', 'local');
        try {
            Document::create($validated + [
                'file_path' => $path, 'file_disk' => 'local',
                'file_name' => $file->getClientOriginalName(),
                'file_type' => strtolower($file->getClientOriginalExtension()),
                'file_size' => $file->getSize(), 'mime_type' => $file->getMimeType(),
                'uploaded_by' => $request->user()->id,
            ]);
        } catch (\Throwable $e) {
            SafeUpload::delete($path, 'local');
            throw $e;
        }

        return redirect()->route('admin.documents.index')->with('success', 'Document enregistré avec succès.');
    }

    /**
     * Afficher un document.
     */
    public function show(Document $document)
    {
        $this->assertAccess($document);

        return redirect()->route('admin.documents.download', $document);
    }

    /**
     * Afficher le formulaire d'édition.
     */
    public function edit(Document $document)
    {
        $this->assertAccess($document, true);
        $categories = DocumentCategory::active()->ordered()->get();
        $users = User::where('role', 'admin')->get()->reject->isTechnicalAccount()->values();

        return view('admin.documents.edit', compact('document', 'categories', 'users'));
    }

    /**
     * Mettre à jour un document.
     */
    public function update(Request $request, Document $document)
    {
        $this->assertAccess($document, true);
        $validated = $this->validated($request);
        if (! $request->exists('allowed_users')) {
            $validated['allowed_users'] = $document->allowed_users;
        }
        $oldPath = $document->file_path;
        $oldDisk = $document->file_disk ?? 'public';
        $path = null;
        try {
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $path = SafeUpload::store($file, 'documents', 'local');
                $validated += [
                    'file_path' => $path, 'file_disk' => 'local',
                    'file_name' => $file->getClientOriginalName(),
                    'file_type' => strtolower($file->getClientOriginalExtension()),
                    'file_size' => $file->getSize(), 'mime_type' => $file->getMimeType(),
                ];
            } elseif ($oldDisk === 'public') {
                $path = $this->copyToPrivate($oldPath);
                $validated += ['file_path' => $path, 'file_disk' => 'local'];
            }
            if ($oldDisk === 'public') {
                $validated['legacy_public_path'] = $oldPath;
            }
            $document->update($validated);
        } catch (\Throwable $e) {
            if ($path) {
                SafeUpload::delete($path, 'local');
            }
            throw $e;
        }
        if ($path && ! Document::withTrashed()->where('file_disk', $oldDisk)->where('file_path', $oldPath)->exists()) {
            SafeUpload::delete($oldPath, $oldDisk);
            if ($oldDisk === 'public' && ! Storage::disk('public')->exists($oldPath)) {
                Document::withTrashed()->where('legacy_public_path', $oldPath)->update(['legacy_public_path' => null]);
            }
        }

        return redirect()->route('admin.documents.index')->with('success', 'Document mis à jour avec succès.');
    }

    /**
     * Télécharger un document.
     */
    public function download(Document $document)
    {
        $this->assertAccess($document);
        $disk = Storage::disk($document->file_disk ?? 'public');
        abort_unless($disk->exists($document->file_path), 404, 'Fichier introuvable.');
        $document->incrementDownloadCount();

        return $disk->download($document->file_path, $document->file_name, [
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Archiver un document.
     */
    public function archive(Document $document)
    {
        $this->assertAccess($document, true);
        $document->update(['is_archived' => true]);

        return redirect()->back()
            ->with('success', 'Document archivé avec succès');
    }

    /**
     * Désarchiver un document.
     */
    public function unarchive(Document $document)
    {
        $this->assertAccess($document, true);
        $document->update(['is_archived' => false]);

        return redirect()->back()
            ->with('success', 'Document désarchivé avec succès');
    }

    /**
     * Supprimer un document.
     */
    public function destroy(Document $document)
    {
        $this->assertAccess($document, true);
        try {
            $document->delete();

            return redirect()->route('admin.documents.index')
                ->with('success', 'Document supprimé avec succès');
        } catch (\Exception $e) {
            report($e);

            return redirect()->back()
                ->with('error', 'L’action n’a pas pu être enregistrée. Réessayez plus tard.');
        }
    }

    /**
     * Gérer les catégories.
     */
    public function categories()
    {
        $categories = DocumentCategory::withCount(['documents' => fn ($q) => $q->accessibleTo(request()->user())])->ordered()->get();

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
            report($e);

            return redirect()->back()
                ->with('error', 'L’action n’a pas pu être enregistrée. Réessayez plus tard.')
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
            report($e);

            return redirect()->back()
                ->with('error', 'L’action n’a pas pu être enregistrée. Réessayez plus tard.')
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
            report($e);

            return redirect()->back()
                ->with('error', 'L’action n’a pas pu être enregistrée. Réessayez plus tard.');
        }
    }

    private function validated(Request $request, bool $creating = false): array
    {
        $extensions = 'pdf,jpg,jpeg,png,gif,webp,txt,csv,doc,docx,xls,xlsx,ppt,pptx,odt,ods,odp,rtf';
        $data = $request->validate([
            'title' => 'required|string|max:255', 'description' => 'nullable|string|max:10000',
            'category_id' => 'nullable|exists:document_categories,id',
            'file' => [($creating ? 'required' : 'nullable'), 'file', 'max:51200', 'mimes:'.$extensions, 'extensions:'.$extensions],
            'visibility' => 'required|in:public,private,restricted',
            'allowed_users' => 'nullable|array|max:500', 'allowed_users.*' => 'integer|exists:users,id',
        ]);
        unset($data['file']);
        $data['allowed_users'] = array_values(array_unique(array_map('intval', $data['allowed_users'] ?? [])));

        return $data;
    }

    private function assertAccess(Document $document, bool $write = false): void
    {
        $user = request()->user();
        abort_unless($user->isAdmin() || ($write ? (int) $document->uploaded_by === $user->id : $document->canBeAccessedBy($user->id)), 403);
    }

    private function copyToPrivate(string $path): string
    {
        return app(\App\Services\DocumentStorage::class)->copyToPrivate($path);
    }
}
