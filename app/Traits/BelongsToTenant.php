<?php

namespace App\Traits;

use App\Models\Tenant;
use App\Services\TenantResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Attach to EVERY tenant-scoped model.
 * Automatically filters all queries by current tenant and
 * auto-fills tenant_id on create. This is the core of data isolation.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (app()->bound('currentTenant')) {
                $tenant = app('currentTenant');
                $builder->where($builder->getModel()->getTable().'.tenant_id', $tenant->id);
            }
        });

        static::creating(function ($model) {
            if (empty($model->tenant_id) && app()->bound('currentTenant')) {
                $model->tenant_id = app('currentTenant')->id;
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Route-model binding must NOT trust the global scope above being
     * active. Laravel's implicit route-model binding (SubstituteBindings,
     * part of the framework's 'web' middleware group applied to every route
     * in routes/web.php) runs BEFORE this app's own resolve.tenant
     * middleware for tenant-prefixed routes — app()->bound('currentTenant')
     * is still false at that point, so the global scope above silently
     * adds no filter at all, and `{order}`/`{product}`/etc. would resolve
     * to ANY tenant's row with that id, not just the current one.
     *
     * This override makes route-model binding independent of that
     * ordering: it resolves the tenant itself (via the same lookup
     * resolve.tenant uses, TenantResolver::fromRequest()) when the
     * container binding isn't there yet, and explicitly filters by it.
     * No tenant resolvable at all (e.g. a central route with no tenant
     * context) -> null -> Laravel's standard ModelNotFoundException -> 404,
     * never an unscoped match.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $tenant = app()->bound('currentTenant')
            ? app('currentTenant')
            : TenantResolver::fromRequest(request());

        if (! $tenant) {
            return null;
        }

        return $this->where($field ?? $this->getRouteKeyName(), $value)
            ->where($this->getTable().'.tenant_id', $tenant->id)
            ->first();
    }
}
