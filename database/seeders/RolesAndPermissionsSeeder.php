<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Enums\Permission as PermissionEnum;
use App\Support\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotente: pode rodar em todo deploy para sincronizar a matriz de
 * permissões da Seção 4.2 sem tocar nos vínculos de usuários.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::values() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (RoleName::cases() as $roleName) {
            $role = Role::findOrCreate($roleName->value, 'web');

            $role->syncPermissions(
                array_map(fn (PermissionEnum $p) => $p->value, PermissionEnum::forRole($roleName))
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
