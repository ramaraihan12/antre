<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Staff of the branch's own business only. The branch is looked up without the tenant scope
// because channel authorisation does not run through the tenant middleware.
Broadcast::channel('staff.branch.{branchId}', function (User $user, int $branchId) {
    $branch = Branch::withoutGlobalScopes()->find($branchId);

    return $branch !== null && $user->can('operate', $branch);
});
