<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUlidKey;
use App\Support\Enums\ClientStatus;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use Auditable;
    use BelongsToClient;

    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    use HasUlidKey;
    use SoftDeletes;

    /** Colunas auditadas — tokens e senhas jamais entram aqui. */
    protected array $auditable = [
        'name',
        'legal_name',
        'document',
        'status',
        'timezone',
        'notes',
        'brand_colors',
    ];

    protected $fillable = [
        'name',
        'legal_name',
        'document',
        'logo_path',
        'brand_colors',
        'timezone',
        'contract_start',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'brand_colors' => 'array',
            'contract_start' => 'date',
            'status' => ClientStatus::class,
        ];
    }

    /** O escopo de tenant do próprio cliente age sobre a chave primária. */
    public function clientForeignKey(): string
    {
        return 'id';
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'is_primary_contact'])
            ->withTimestamps();
    }

    public function settings(): HasOne
    {
        return $this->hasOne(ClientSetting::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function cloudConnections(): HasMany
    {
        return $this->hasMany(CloudConnection::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function briefs(): HasMany
    {
        return $this->hasMany(Brief::class);
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function displayTimezone(): string
    {
        return $this->timezone ?: config('agency.default_timezone');
    }

    public function primaryColor(): string
    {
        return data_get($this->brand_colors, 'primary', config('agency.primary_color'));
    }

    public function isActive(): bool
    {
        return $this->status === ClientStatus::Active;
    }
}
