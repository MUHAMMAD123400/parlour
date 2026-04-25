<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class CompanyAccessService
{
    private static function companyAdminRole(Company $company): ?Role
    {
        return Role::query()
            ->where('name', 'company_admin')
            ->where('guard_name', 'api')
            ->where('company_id', $company->id)
            ->first();
    }

    public static function companyHasModuleKey(?int $companyId, string $moduleKey): bool
    {
        if (! $companyId || $moduleKey === '') {
            return false;
        }

        $company = Company::find($companyId);

        return $company
            ? in_array($moduleKey, $company->activePermissionModuleKeys(), true)
            : false;
    }

    /**
     * @return Collection<int, Permission>
     */
    public static function permissionsForCompanyModuleKeys(array $keys): Collection
    {
        if ($keys === []) {
            return collect();
        }

        return Permission::query()
            ->where('guard_name', 'api')
            ->whereIn('module', $keys)
            ->get();
    }

    public static function grantCompanyAdminAllModulePermissions(User $user, Company $company): void
    {
        $perms = static::permissionsForCompanyModuleKeys($company->activePermissionModuleKeys());

        $adminRole = static::companyAdminRole($company);
        if ($adminRole) {
            $adminRole->syncPermissions($perms);
        }

        $user->syncPermissions($perms);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public static function refreshCompanyAdminsPermissions(Company $company): void
    {
        $perms = static::permissionsForCompanyModuleKeys($company->activePermissionModuleKeys());

        $adminRole = static::companyAdminRole($company);

        if (! $adminRole) {
            return;
        }

        // Keep role permissions aligned with active company modules.
        $adminRole->syncPermissions($perms);

        User::query()
            ->where('company_id', $company->id)
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $adminRole->id))
            ->get()
            ->each(function (User $u) use ($perms) {
                $u->syncPermissions($perms);
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<string>
     */
    public static function allowedPermissionNamesForCompany(?int $companyId): array
    {
        if (! $companyId) {
            return [];
        }

        $company = Company::find($companyId);

        if (! $company) {
            return [];
        }

        return static::permissionsForCompanyModuleKeys($company->activePermissionModuleKeys())
            ->pluck('name')
            ->all();
    }
}
