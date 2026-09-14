<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUlidKey;
use App\Support\Enums\CloudProvider;
use App\Support\Enums\ConnectionStatus;
use Database\Factories\CloudConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CloudConnection extends Model
{
    use BelongsToClient;

    /** @use HasFactory<CloudConnectionFactory> */
    use HasFactory;

    use HasUlidKey;

    protected $fillable = [
        'client_id',
        'user_id',
        'provider',
        'account_email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'root_folder_id',
        'status',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'provider' => CloudProvider::class,
            'status' => ConnectionStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
