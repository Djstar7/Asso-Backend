<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SafeUpload
{
    public static function store(UploadedFile $file, string $directory, string $disk = 'public'): string
    {
        $path = $file->storeAs($directory, Str::uuid().'.'.$file->extension(), $disk);
        if (! $path) {
            throw new RuntimeException('Le fichier n’a pas pu être enregistré.');
        }
        try {
            if (! Storage::disk($disk)->exists($path) || Storage::disk($disk)->size($path) !== $file->getSize()) {
                throw new RuntimeException('Le fichier n’a pas pu être enregistré intégralement.');
            }
        } catch (Throwable $exception) {
            self::delete($path, $disk);
            throw $exception;
        }

        return $path;
    }

    /** Store every replacement before saving the model or removing its old files. */
    public static function save(Model $model, array $attributes, array $uploads): void
    {
        $new = [];
        $old = [];
        try {
            foreach ($uploads as $column => [$file, $directory, $prefix]) {
                if (! $file) {
                    continue;
                }
                $path = self::store($file, $directory);
                $new[] = $path;
                if ($model->$column) {
                    $old[] = Str::startsWith($model->$column, 'storage/') ? Str::after($model->$column, 'storage/') : $model->$column;
                }
                $attributes[$column] = $prefix.$path;
            }
            $model->fill($attributes)->saveOrFail();
        } catch (Throwable $exception) {
            foreach ($new as $path) {
                self::delete($path);
            }
            throw $exception;
        }
        foreach ($old as $path) {
            self::delete($path);
        }
    }

    public static function delete(string $path, string $disk = 'public'): void
    {
        try {
            if (! Storage::disk($disk)->delete($path)) {
                report(new RuntimeException('Impossible de supprimer un ancien fichier.'));
            }
        } catch (Throwable $exception) {
            // A cleanup failure must not turn a committed save into a retryable failure.
            report($exception);
        }
    }
}
