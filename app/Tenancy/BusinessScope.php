<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class BusinessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $businessId = app(TenantContext::class)->id();

        if ($businessId !== null) {
            $builder->where($model->qualifyColumn('business_id'), $businessId);
        }
    }
}
