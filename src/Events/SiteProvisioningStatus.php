<?php

namespace HardImpact\Orbit\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts site provisioning status updates via Reverb.
 *
 * This event is dispatched during site creation to provide real-time
 * status updates to the frontend via WebSocket.
 *
 * Uses ShouldBroadcastNow to broadcast immediately without queueing,
 * ensuring real-time updates during the provisioning process.
 */
class SiteProvisioningStatus implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public string $slug,
        public string $status,
        public ?string $error = null,
        public ?int $siteId = null,
    ) {}

    /**
     * Get the channel the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        return new Channel('provisioning');
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'site.provision.status';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'slug' => $this->slug,
            'status' => $this->status,
            'error' => $this->error,
            'site_id' => $this->siteId,
        ];
    }
}
