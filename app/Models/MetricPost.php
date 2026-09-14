<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MetricPostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricPost extends Model
{
    /** @use HasFactory<MetricPostFactory> */
    use HasFactory;

    protected $table = 'metrics_post';

    protected $fillable = [
        'post_id',
        'collected_at',
        'reach',
        'impressions',
        'likes',
        'comments',
        'saves',
        'shares',
        'video_views',
        'engagement_rate',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'engagement_rate' => 'decimal:4',
            'raw' => 'array',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
