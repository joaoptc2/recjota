<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use App\Support\Enums\RoleName;
use App\Support\Enums\UserType;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    use HasUlidKey;

    protected $fillable = [
        'token',
        'email',
        'name',
        'type',
        'role',
        'client_id',
        'invited_by',
        'expires_at',
        'accepted_at',
        'accepted_user_id',
        'revoked_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'type' => UserType::class,
            'role' => RoleName::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }
}
