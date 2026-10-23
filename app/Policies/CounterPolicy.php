<?php

namespace App\Policies;

use App\Models\Counter;
use App\Models\User;

class CounterPolicy
{
    public function operate(User $user, Counter $counter): bool
    {
        return $user->worksAt($counter->business_id, $counter->branch_id);
    }
}
