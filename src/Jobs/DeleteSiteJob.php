<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Jobs;

use HardImpact\Orbit\Data\DeletionContext;
use HardImpact\Orbit\Models\Site;
use HardImpact\Orbit\Services\Deletion\DeletionLogger;
use HardImpact\Orbit\Services\Deletion\DeletionPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job for deleting a site via queue (web-initiated).
 *
 * This job handles the complete site deletion process:
 * - Database cleanup
 * - Project files removal
 * - Caddy configuration regeneration
 * - Site record deletion
 *
 * Status updates are broadcast via native Laravel events to Reverb.
 */
final class DeleteSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1; // Don't retry - deletion is not idempotent

    public function __construct(
        public int $siteId,
        public bool $keepDatabase = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(DeletionPipeline $pipeline): void
    {
        $site = Site::findOrFail($this->siteId);

        // Initialize logger with native Laravel broadcasting
        $logger = new DeletionLogger($site->slug, $this->siteId);

        Log::info("DeleteSiteJob: Starting deletion for {$site->slug}");
        $logger->broadcast('deleting');

        try {
            // Build deletion context from site
            $context = DeletionContext::fromSite($site, $this->keepDatabase)
                ->withDatabaseFromEnv();

            // Run the deletion pipeline
            $result = $pipeline->run($context, $logger);

            if ($result->isFailed()) {
                throw new \RuntimeException($result->error ?? 'Deletion pipeline failed');
            }

            // Delete site record from database
            $site->delete();

            $logger->broadcast('deleted');
            Log::info("DeleteSiteJob: Site {$site->slug} deleted successfully");

        } catch (\Throwable $e) {
            $logger->broadcast('delete_failed', $e->getMessage());
            Log::error("DeleteSiteJob: Site {$site->slug} deletion failed", [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get the tags for the job (for Horizon).
     */
    public function tags(): array
    {
        return [
            'delete-site',
            "site-id:{$this->siteId}",
        ];
    }
}
