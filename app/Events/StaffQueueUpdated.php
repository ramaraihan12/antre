<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Sent on a private channel only staff of the branch's business can join. */
class StaffQueueUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $branchId, public string $summary) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("staff.branch.{$this->branchId}")];
    }

    public function broadcastAs(): string
    {
        return 'staff.queue.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['summary' => $this->summary];
    }
}
