<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUlidKey;
use App\Support\Enums\MediaSource;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaAsset extends Model
{
    use BelongsToClient;

    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    use HasUlidKey;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'uploaded_by',
        'folder_id',
        'source',
        'external_file_id',
        'external_account_id',
        'filename',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'duration_ms',
        'checksum',
        'local_path',
        'local_thumb_path',
        'local_preview_path',
        'public_temp_path',
        'public_temp_expires_at',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'source' => MediaSource::class,
            'tags' => 'array',
            'public_temp_expires_at' => 'datetime',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function cloudConnection(): BelongsTo
    {
        return $this->belongsTo(CloudConnection::class, 'external_account_id');
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_media')
            ->withPivot(['position', 'alt_text', 'thumbnail_offset_ms', 'cover_path'])
            ->withTimestamps();
    }

    public function isVideo(): bool
    {
        return str_starts_with((string) $this->mime_type, 'video/');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    public function aspectRatio(): ?float
    {
        if (! $this->width || ! $this->height) {
            return null;
        }

        return round($this->width / $this->height, 4);
    }

    /** Arquivos da ponte pública vencidos, varridos pelo cron horário. */
    public function scopeWithExpiredPublicCopy(Builder $query): Builder
    {
        return $query->whereNotNull('public_temp_path')
            ->where('public_temp_expires_at', '<=', now());
    }
}
