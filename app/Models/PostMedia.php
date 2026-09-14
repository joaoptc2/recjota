<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PostMediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostMedia extends Model
{
    /** @use HasFactory<PostMediaFactory> */
    use HasFactory;

    protected $table = 'post_media';

    protected $fillable = [
        'post_id',
        'media_asset_id',
        'position',
        'alt_text',
        'thumbnail_offset_ms',
        'cover_path',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'thumbnail_offset_ms' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
