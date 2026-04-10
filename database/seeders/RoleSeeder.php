<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
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
                'company_id' => null,
            ],
            [
                'description' => 'System super admin role',
            ]
        );

        $firstCompanyId = DB::table('companies')->orderBy('id')->value('id');

        Role::updateOrCreate(
            [
                'name' => 'company_admin',
                'guard_name' => 'api',
                'company_id' => $firstCompanyId,
            ],
            [
                'description' => 'Company administrator; direct permissions from assigned company modules',
            ]
        );

        $superAdmin->syncPermissions(Permission::where('guard_name', 'api')->get());

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
