<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $superAdmin = Role::updateOrCreate(
            [
                'name' => 'super_admin',
                'guard_name' => 'api',
            ],
            [
                'description' => 'System super admin role',
            ]
        );

        Role::updateOrCreate(
            [
                'name' => 'company_admin',
                'guard_name' => 'api',
            ],
            [
                'description' => 'Company administrator; direct permissions from assigned company modules',
            ]
        );

        $superAdmin->syncPermissions(Permission::where('guard_name', 'api')->get());

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
