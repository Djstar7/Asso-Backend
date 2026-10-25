<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\DocumentStorage;
use App\Services\SafeUpload;
use Illuminate\Console\Command;
use Throwable;

class SecureDocumentStorage extends Command
{
    protected $signature = 'documents:secure-storage';

    protected $description = 'Déplace et vérifie les anciens documents publics vers le stockage privé';

    public function handle(DocumentStorage $storage): int
    {
        $failed = false;
        Document::withTrashed()->where(fn ($q) => $q->where('file_disk', 'public')->orWhereNotNull('legacy_public_path'))->chunkById(100, function ($documents) use ($storage, &$failed) {
            foreach ($documents as $document) {
                $old = $document->file_disk === 'public' ? $document->file_path : $document->legacy_public_path;
                $new = null;
                try {
                    if ($document->file_disk === 'public') {
                        $new = $storage->copyToPrivate($old);
                        $document->update(['file_path' => $new, 'file_disk' => 'local', 'legacy_public_path' => $old]);
                    }
                } catch (Throwable $e) {
                    if ($new) {
                        SafeUpload::delete($new, 'local');
                    }
                    report($e);
                    $this->error('Document '.$document->id.' non déplacé ; le fichier source est conservé.');
                    $failed = true;

                    continue;
                }
                // Older duplicate paths may still be referenced by another record.
                if (! Document::withTrashed()->where('file_disk', 'public')->where('file_path', $old)->exists()) {
                    SafeUpload::delete($old);
                    if (\Illuminate\Support\Facades\Storage::disk('public')->exists($old)) {
                        $failed = true;
                        $this->error('Ancienne copie publique du document '.$document->id.' à supprimer.');
                    } else {
                        Document::withTrashed()->where('legacy_public_path', $old)->update(['legacy_public_path' => null]);
                    }
                }
            }
        });

        if ($failed) {
            return self::FAILURE;
        }
        $this->info('Documents déplacés et vérifiés.');

        return self::SUCCESS;
    }
}
