<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DocumentStorage
{
    public function copyToPrivate(string $path): string
    {
        $source = Storage::disk('public');
        $target = Storage::disk('local');
        if (! Str::startsWith($path, 'documents/') || str_contains($path, '..') || ! $source->exists($path)) {
            throw new RuntimeException('Document source introuvable.');
        }
        $destination = 'documents/'.Str::uuid().'.'.pathinfo($path, PATHINFO_EXTENSION);
        $stream = $source->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('Impossible de lire le document.');
        }
        try {
            if (! $target->put($destination, $stream)) {
                throw new RuntimeException('Impossible de copier le document.');
            }
        } catch (\Throwable $exception) {
            SafeUpload::delete($destination, 'local');
            throw $exception;
        } finally {
            fclose($stream);
        }
        $originalHash = hash_file('sha256', $source->path($path));
        $copiedHash = hash_file('sha256', $target->path($destination));
        if (! is_string($originalHash) || ! is_string($copiedHash) || ! hash_equals($originalHash, $copiedHash)) {
            SafeUpload::delete($destination, 'local');
            throw new RuntimeException('La copie du document est incomplète.');
        }

        return $destination;
    }
}
