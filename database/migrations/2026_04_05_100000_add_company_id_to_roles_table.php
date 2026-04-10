<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $rolesTable = $tableNames['roles'];
        $permPivot = $tableNames['role_has_permissions'];

        Schema::table($rolesTable, function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('id');
        });

        Schema::table($rolesTable, function (Blueprint $table) {
            $table->dropUnique(['name', 'guard_name']);
        });

        DB::table($rolesTable)->where('name', 'super_admin')->update(['company_id' => null]);

        $firstCompanyId = DB::table('companies')->orderBy('id')->value('id');

        if ($firstCompanyId) {
            DB::table($rolesTable)
                ->where('name', 'company_admin')
                ->where('guard_name', 'api')
                ->whereNull('company_id')
                ->update(['company_id' => $firstCompanyId]);
        }

        $companies = DB::table('companies')->orderBy('id')->get();
        $existingWithAdmin = DB::table($rolesTable)
            ->where('name', 'company_admin')
            ->where('guard_name', 'api')
            ->pluck('company_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $template = DB::table($rolesTable)
            ->where('name', 'company_admin')
            ->where('guard_name', 'api')
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->first();

        foreach ($companies as $company) {
            $cid = (int) $company->id;
            if (in_array($cid, $existingWithAdmin, true)) {
                continue;
            }

            $newId = DB::table($rolesTable)->insertGetId([
                'company_id' => $cid,
                'name' => 'company_admin',
                'guard_name' => 'api',
                'description' => $template->description ?? 'Company administrator; direct permissions from assigned company modules',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $existingWithAdmin[] = $cid;

            if ($template) {
                $sourceId = $template->id;
                $permRows = DB::table($permPivot)->where('role_id', $sourceId)->get();
                foreach ($permRows as $pr) {
                    DB::table($permPivot)->insert([
                        'permission_id' => $pr->permission_id,
                        'role_id' => $newId,
                    ]);
                }
            }
        }

        Schema::table($rolesTable, function (Blueprint $table) {
            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->nullOnDelete();
        });

        Schema::table($rolesTable, function (Blueprint $table) {
            $table->unique(['company_id', 'name', 'guard_name'], 'roles_company_name_guard_unique');
        });

        $this->remapCompanyAdminUserRoles($rolesTable);
    }

    protected function remapCompanyAdminUserRoles(string $rolesTable): void
    {
        $pivot = config('permission.table_names.model_has_roles');
        $modelKey = config('permission.column_names.model_morph_key');
        $roleKey = config('permission.column_names.role_pivot_key') ?? 'role_id';

        $users = DB::table('users')->whereNotNull('company_id')->get(['id', 'company_id']);

        foreach ($users as $u) {
            $companyId = (int) $u->company_id;

            $correctRoleId = DB::table($rolesTable)
                ->where('name', 'company_admin')
                ->where('guard_name', 'api')
                ->where('company_id', $companyId)
                ->value('id');

            if (! $correctRoleId) {
                continue;
            }

            $pivotRows = DB::table($pivot)
                ->where($modelKey, $u->id)
                ->where('model_type', 'App\\Models\\User')
                ->get();

            foreach ($pivotRows as $row) {
                $roleId = (int) $row->{$roleKey};
                $roleName = DB::table($rolesTable)->where('id', $roleId)->value('name');
                if ($roleName !== 'company_admin') {
                    continue;
                }

                $roleCompanyId = DB::table($rolesTable)->where('id', $roleId)->value('company_id');
                if ($roleCompanyId !== null && (int) $roleCompanyId === $companyId) {
                    continue;
                }

                DB::table($pivot)
                    ->where($modelKey, $u->id)
                    ->where('model_type', 'App\\Models\\User')
                    ->where($roleKey, $roleId)
                    ->update([$roleKey => $correctRoleId]);
            }
        }
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');
        $rolesTable = $tableNames['roles'];

        Schema::table($rolesTable, function (Blueprint $table) {
            $table->dropUnique('roles_company_name_guard_unique');
        });

        Schema::table($rolesTable, function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::table($rolesTable, function (Blueprint $table) {
            $table->dropColumn('company_id');
        });

        Schema::table($rolesTable, function (Blueprint $table) {
            $table->unique(['name', 'guard_name']);
        });
    }
};
