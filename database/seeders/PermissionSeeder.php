<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $modules = [
            'module' => ['index', 'show', 'create', 'edit', 'delete'],
            'company' => ['index', 'show', 'create', 'edit', 'delete'],
            'user' => ['index', 'show', 'create', 'edit', 'delete'],
            'role' => ['index', 'show', 'create', 'edit', 'delete'],
            'permission' => ['index', 'show', 'create', 'edit', 'delete'],
            'category' => ['index', 'show', 'create', 'edit', 'delete'],
            'customer' => ['index', 'show', 'create', 'edit', 'delete'],
            'product' => ['index', 'show', 'create', 'edit', 'delete'],
            'service' => ['index', 'show', 'create', 'edit', 'delete'],
            'discount' => ['index', 'show', 'create', 'edit', 'delete'],
            'billing' => ['index', 'show', 'create', 'delete'],
        ];

        foreach ($modules as $module => $actions) {
            foreach ($actions as $action) {
                Permission::updateOrCreate(
                    [
                        'name' => "{$module}.{$action}",
                        'guard_name' => 'api',
                    ],
                    [
                        'title' => ucfirst($module) . ' ' . ucfirst($action),
                        'description' => "Allow {$action} access for {$module}",
                        'type' => $action,
                        'module' => $module,
                        'group_type' => 'api',
                    ]
                );
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
