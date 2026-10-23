<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A nudge for customers' ticket pages: something changed, ask the server for your position. */
class QueueChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $branchId) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new Channel("queue.{$this->branchId}")];
    }

    public function broadcastAs(): string
    {
        return 'queue.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['branch_id' => $this->branchId];
    }
}
