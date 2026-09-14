<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUlidKey;
use App\Support\Enums\BriefStatus;
use Database\Factories\BriefFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brief extends Model
{
    use BelongsToClient;

    /** @use HasFactory<BriefFactory> */
    use HasFactory;

    use HasUlidKey;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'title',
        'body',
        'status',
        'submitted_by',
        'submitted_at',
        'attachments',
    ];

    protected function casts(): array
    {
        return [
            'status' => BriefStatus::class,
            'submitted_at' => 'datetime',
            'attachments' => 'array',
        ];
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
