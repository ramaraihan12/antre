<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    /** Work the queue of this branch: use counters, call tickets, check in bookings. */
    public function operate(User $user, Branch $branch): bool
    {
        return $user->worksAt($branch->business_id, $branch->id);
    }

    public function viewDashboard(User $user, Branch $branch): bool
    {
        return $user->isOwner() && $user->worksAt($branch->business_id, $branch->id);
    }
}
