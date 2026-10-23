<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function operate(User $user, Ticket $ticket): bool
    {
        return $user->worksAt($ticket->business_id, $ticket->branch_id);
    }
}
