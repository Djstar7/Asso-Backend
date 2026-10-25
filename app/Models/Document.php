<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'title',
        'description',
        'file_name',
        'file_path',
        'file_disk',
        'legacy_public_path',
        'file_type',
        'file_size',
        'mime_type',
        'uploaded_by',
        'visibility',
        'allowed_users',
        'download_count',
        'last_accessed_at',
        'is_archived',
    ];

    protected $casts = [
        'allowed_users' => 'array',
        'is_archived' => 'boolean',
        'last_accessed_at' => 'datetime',
    ];

    /**
     * Relation avec la catégorie.
     */
    public function category()
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }

    /**
     * Relation avec l'utilisateur qui a uploadé.
     */
    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Obtenir l'URL du fichier.
     */
    public function getFileUrlAttribute()
    {
        return route('admin.documents.download', $this);
    }

    /**
     * Obtenir la taille formatée du fichier.
     */
    public function getFormattedSizeAttribute()
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Vérifier si un utilisateur a accès au document.
     */
    public function canBeAccessedBy($userId)
    {
        if ($this->visibility === 'public') {
            return true;
        }

        if ($this->uploaded_by === $userId) {
            return true;
        }

        if ($this->visibility === 'restricted' && $this->allowed_users) {
            return in_array($userId, $this->allowed_users);
        }

        return false;
    }

    /**
     * Incrémenter le compteur de téléchargements.
     */
    public function incrementDownloadCount()
    {
        $this->increment('download_count');
        $this->update(['last_accessed_at' => now()]);
    }

    /**
     * Scope pour les documents non archivés.
     */
    public function scopeActive($query)
    {
        return $query->where('is_archived', false);
    }

    /**
     * Scope pour les documents publics.
     */
    public function scopePublic($query)
    {
        return $query->where('visibility', 'public');
    }

    public function scopeAccessibleTo($query, User $user)
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(fn ($q) => $q->where('uploaded_by', $user->id)
            ->orWhere('visibility', 'public')
            ->orWhere(fn ($restricted) => $restricted->where('visibility', 'restricted')->whereJsonContains('allowed_users', $user->id)));
    }

    /**
     * Supprimer le fichier physique lors de la suppression du document.
     */
    protected static function boot()
    {
        parent::boot();

        static::forceDeleted(function ($document) {
            \App\Services\SafeUpload::delete($document->file_path, $document->file_disk ?? 'public');
        });
    }
}
