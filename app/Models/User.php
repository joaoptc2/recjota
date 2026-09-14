<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUlidKey;
use App\Models\Scopes\ClientScope;
use App\Support\Enums\RoleName;
use App\Support\Enums\UserType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use Auditable;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasUlidKey;
    use Notifiable;
    use SoftDeletes;

    /** Colunas auditadas — tokens e senhas jamais entram aqui. */
    protected array $auditable = [
        'name',
        'email',
        'type',
        'is_active',
        'timezone',
        'locale',
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar_path',
        'type',
        'timezone',
        'locale',
        'is_active',
        'notification_preferences',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'is_active' => 'boolean',
            'type' => UserType::class,
            'notification_preferences' => 'array',
        ];
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class)
            ->withPivot(['role', 'is_primary_contact'])
            ->withTimestamps();
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    public function isAgency(): bool
    {
        return $this->type === UserType::Agency;
    }

    public function isClient(): bool
    {
        return $this->type === UserType::Client;
    }

    /** Owner e admin enxergam toda a agência sem vínculo explícito (Seção 4.2). */
    public function seesEveryClient(): bool
    {
        return $this->isAgency()
            && $this->hasAnyRole([RoleName::Owner->value, RoleName::Admin->value]);
    }

    /**
     * IDs de clientes acessíveis. Cacheado na instância porque o ClientScope
     * consulta isto em toda query.
     *
     * O withoutGlobalScope é obrigatório: é este método que ALIMENTA o
     * ClientScope. Sem ele a consulta se auto-restringiria a um contexto ainda
     * vazio e devolveria sempre uma lista vazia.
     *
     * @return array<int, int>
     */
    public function accessibleClientIds(): array
    {
        return $this->memoizedClientIds ??= $this->clients()
            ->withoutGlobalScope(ClientScope::class)
            ->pluck('clients.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @var array<int, int>|null */
    private ?array $memoizedClientIds = null;

    public function forgetAccessibleClients(): void
    {
        $this->memoizedClientIds = null;
    }

    public function canAccessClient(Client|int $client): bool
    {
        if ($this->seesEveryClient()) {
            return true;
        }

        $id = $client instanceof Client ? $client->getKey() : $client;

        return in_array($id, $this->accessibleClientIds(), true);
    }

    /** Papel deste usuário dentro de um cliente específico. */
    public function roleForClient(Client|int $client): ?RoleName
    {
        $id = $client instanceof Client ? $client->getKey() : $client;

        $pivotRole = $this->clients()
            ->withoutGlobalScope(ClientScope::class)
            ->where('clients.id', $id)
            ->value('client_user.role');

        return $pivotRole ? RoleName::tryFrom($pivotRole) : null;
    }

    /** @return Collection<int, RoleName> */
    public function roleEnums(): Collection
    {
        return $this->roles->pluck('name')
            ->map(fn (string $name) => RoleName::tryFrom($name))
            ->filter()
            ->values();
    }

    public function primaryRole(): ?RoleName
    {
        return $this->roleEnums()->first();
    }

    public function displayTimezone(): string
    {
        return $this->timezone ?: config('agency.default_timezone');
    }
}
