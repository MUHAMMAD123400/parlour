<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

class CompanyAccessService
{
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
        $user->syncPermissions($perms);
    }

    public static function refreshCompanyAdminsPermissions(Company $company): void
    {
        $perms = static::permissionsForCompanyModuleKeys($company->activePermissionModuleKeys());

        User::query()
            ->where('company_id', $company->id)
            ->role('company_admin')
            ->get()
            ->each(fn (User $u) => $u->syncPermissions($perms));
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
