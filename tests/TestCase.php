<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Client;
use App\Models\User;
use App\Support\Enums\RoleName;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // O contexto de tenant é resolvido uma vez por processo; entre testes
        // (e entre actingAs) precisa ser descartado.
        app(TenantContext::class)->reset();
    }

    protected function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** Cria um usuário com papel e, opcionalmente, vinculado a um cliente. */
    protected function userWithRole(RoleName $role, ?Client $client = null): User
    {
        $user = User::factory()->withRole($role)->create();

        if ($client !== null) {
            $user->clients()->attach($client->getKey(), [
                'role' => $role->value,
                'is_primary_contact' => false,
            ]);

            $user->forgetAccessibleClients();
        }

        return $user;
    }

    /** actingAs que também reinicia o contexto de tenant. */
    protected function actingAsUser(User $user): static
    {
        app(TenantContext::class)->reset();

        $this->actingAs($user);

        app(TenantContext::class)->reset()->forUser($user->fresh());

        return $this;
    }
}
