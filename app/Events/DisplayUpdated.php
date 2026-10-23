<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Sent to the public TV display of a branch. Carries only ticket codes and counter labels. */
class DisplayUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array{id: string, code: string, text: string}|null  $announcement
     */
    public function __construct(
        public int $branchId,
        public array $snapshot,
        public ?array $announcement = null,
    ) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new Channel("display.{$this->branchId}")];
    }

    public function broadcastAs(): string
    {
        return 'display.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['snapshot' => $this->snapshot, 'announcement' => $this->announcement];
    }
}
