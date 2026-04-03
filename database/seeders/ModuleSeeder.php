<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /**
     * Seed catalog modules. permission_module_key must match permissions.module in PermissionSeeder.
     */
    public function run(): void
    {
        $rows = [
            ['module_name' => 'Module catalog', 'permission_module_key' => 'module', 'module_description' => 'Manage licensable modules'],
            ['module_name' => 'Companies', 'permission_module_key' => 'company', 'module_description' => 'Company administration'],
            ['module_name' => 'Users', 'permission_module_key' => 'user', 'module_description' => 'User management'],
            ['module_name' => 'Roles', 'permission_module_key' => 'role', 'module_description' => 'Roles'],
            ['module_name' => 'Permissions', 'permission_module_key' => 'permission', 'module_description' => 'Permissions'],
            ['module_name' => 'Categories', 'permission_module_key' => 'category', 'module_description' => 'Categories'],
            ['module_name' => 'Customers', 'permission_module_key' => 'customer', 'module_description' => 'Customers'],
            ['module_name' => 'Products', 'permission_module_key' => 'product', 'module_description' => 'Products'],
            ['module_name' => 'Services', 'permission_module_key' => 'service', 'module_description' => 'Services'],
            ['module_name' => 'Discounts', 'permission_module_key' => 'discount', 'module_description' => 'Discounts'],
            ['module_name' => 'Billing', 'permission_module_key' => 'billing', 'module_description' => 'Bills / POS'],
        ];

        foreach ($rows as $row) {
            Module::updateOrCreate(
                ['permission_module_key' => $row['permission_module_key']],
                [
                    'module_name' => $row['module_name'],
                    'module_description' => $row['module_description'],
                    'module_status' => '1',
                    'module_icon' => null,
                ]
            );
        }
    }
}
