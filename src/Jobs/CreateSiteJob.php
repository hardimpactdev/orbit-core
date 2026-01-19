<?php

namespace HardImpact\Orbit\Jobs;

use HardImpact\Orbit\Models\Environment;
use HardImpact\Orbit\Models\TrackedJob;
use HardImpact\Orbit\Services\OrbitCli\Shared\CommandService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Async job for creating a new site.
 *
 * This job is dispatched by the controller and processed by Horizon.
 * It calls the CLI provision command and broadcasts progress via WebSocket.
 *
 * @see /docs/flows/site-creation.md
 */
class CreateSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600; // 10 minutes for provisioning

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1; // Don't retry - provisioning is not idempotent

    protected string $slug;

    public function __construct(
        protected int $environmentId,
        protected array $options,
        protected ?string $trackedJobId = null
    ) {
        $this->slug = Str::slug($options['name']);
    }

    /**
     * Execute the job.
     */
    public function handle(CommandService $commandService): void
    {
        $environment = Environment::findOrFail($this->environmentId);
        $trackedJob = $this->getOrCreateTrackedJob();

        $trackedJob->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);

        try {
            $command = $this->buildCommand();
            // Use 600s timeout to match job timeout - provisioning can take several minutes
            $result = $commandService->executeCommand($environment, $command, 600);

            if ($result['success']) {
                $trackedJob->update([
                    'status' => 'completed',
                    'output' => json_encode($result['data'] ?? []),
                    'finished_at' => now(),
                ]);
            } else {
                $trackedJob->update([
                    'status' => 'failed',
                    'output' => $result['error'] ?? 'Unknown error',
                    'finished_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            $trackedJob->update([
                'status' => 'failed',
                'output' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Build the CLI command from options.
     */
    protected function buildCommand(): string
    {
        $name = escapeshellarg($this->options['name']);
        $command = "site:create {$name}";

        // Handle template vs clone
        if (! empty($this->options['template'])) {
            $isTemplate = $this->options['is_template'] ?? false;
            if ($isTemplate) {
                $command .= ' --template='.escapeshellarg($this->options['template']);
            } else {
                $command .= ' --clone='.escapeshellarg($this->options['template']);
                if (! empty($this->options['fork'])) {
                    $command .= ' --fork';
                }
            }
        }

        // Optional flags
        if (! empty($this->options['org'])) {
            $command .= ' --organization='.escapeshellarg($this->options['org']);
        }
        if (! empty($this->options['visibility'])) {
            $command .= ' --visibility='.escapeshellarg($this->options['visibility']);
        }
        if (! empty($this->options['directory'])) {
            $command .= ' --path='.escapeshellarg($this->options['directory']);
        }
        if (! empty($this->options['php_version'])) {
            $command .= ' --php='.escapeshellarg($this->options['php_version']);
        }
        if (! empty($this->options['db_driver'])) {
            $command .= ' --db-driver='.escapeshellarg($this->options['db_driver']);
        }
        if (! empty($this->options['session_driver'])) {
            $command .= ' --session-driver='.escapeshellarg($this->options['session_driver']);
        }
        if (! empty($this->options['cache_driver'])) {
            $command .= ' --cache-driver='.escapeshellarg($this->options['cache_driver']);
        }
        if (! empty($this->options['queue_driver'])) {
            $command .= ' --queue-driver='.escapeshellarg($this->options['queue_driver']);
        }

        $command .= ' --json';

        return $command;
    }

    /**
     * Get or create the tracked job record.
     */
    protected function getOrCreateTrackedJob(): TrackedJob
    {
        if ($this->trackedJobId) {
            return TrackedJob::findOrFail($this->trackedJobId);
        }

        return TrackedJob::create([
            'name' => "create-site:{$this->slug}",
            'status' => 'pending',
        ]);
    }

    /**
     * Get the slug for this site.
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * Get the tags for the job (for Horizon).
     */
    public function tags(): array
    {
        return [
            'create-site',
            "site:{$this->slug}",
            "environment:{$this->environmentId}",
        ];
    }
}
