<?php

namespace HardImpact\Orbit\Jobs;

use HardImpact\Orbit\Data\ProvisionContext;
use HardImpact\Orbit\Enums\RepoIntent;
use HardImpact\Orbit\Models\Environment;
use HardImpact\Orbit\Models\Site;
use HardImpact\Orbit\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Services\Provision\ProvisionLogger;
use HardImpact\Orbit\Services\Provision\ProvisionPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Job for creating a new site with native Laravel broadcasting.
 *
 * This job handles the complete site provisioning process:
 * - Repository operations (clone, fork, template)
 * - Dependency installation
 * - Environment configuration
 * - Database setup
 *
 * Status updates are broadcast via native Laravel events to Reverb.
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
        protected int $siteId,
        protected array $options,
    ) {
        $this->slug = Str::slug($options['name']);
    }

    /**
     * Execute the job.
     */
    public function handle(ProvisionPipeline $pipeline, ConfigurationService $configService): void
    {
        $site = Site::findOrFail($this->siteId);
        $environment = Environment::findOrFail($site->environment_id);

        // Initialize logger with native Laravel broadcasting
        $logger = new ProvisionLogger($this->slug, $this->siteId);

        $site->update([
            'status' => Site::STATUS_QUEUED,
            'job_id' => $this->job?->uuid() ?? $site->job_id,
            'error_message' => null,
        ]);

        $logger->broadcast('provisioning');
        Log::info("CreateSiteJob: Starting site creation for {$this->slug}");

        try {
            // Determine project path
            $projectPath = $this->determineProjectPath($site, $configService, $environment);

            // Create project directory if it doesn't exist
            if (! is_dir($projectPath)) {
                if (! mkdir($projectPath, 0755, true)) {
                    throw new \RuntimeException("Failed to create directory: {$projectPath}");
                }
            }

            // Update site with path
            $site->update(['path' => $projectPath]);

            // Build provision context
            $context = $this->buildContext($projectPath, $environment);

            // Determine repo intent
            $intent = RepoIntent::fromPayload($this->options);

            // Phase 1: Repository Operations
            $context = $this->handleRepositoryOperations($context, $intent, $pipeline, $logger);

            // Phase 2: Clone repository (for clone/fork/template flows)
            if ($context->cloneUrl) {
                $logger->broadcast('cloning');
                $result = $pipeline->cloneRepository($context, $logger);
                if ($result->isFailed()) {
                    throw new \RuntimeException($result->error ?? 'Clone failed');
                }
            }

            // Phase 3: Run provision pipeline
            $logger->broadcast('setting_up');
            $result = $pipeline->run($context, $logger);

            if ($result->isFailed()) {
                throw new \RuntimeException($result->error ?? 'Provisioning failed');
            }

            // Phase 4: Finalize
            $logger->broadcast('finalizing');

            // Detect site type and public folder
            $hasPublicFolder = is_dir("{$projectPath}/public");
            $siteType = $this->detectSiteType($projectPath);

            // Update site with final details
            $site->update([
                'status' => Site::STATUS_READY,
                'github_repo' => $context->githubRepo,
                'site_url' => "https://{$this->slug}.{$context->tld}",
                'domain' => "{$this->slug}.{$context->tld}",
                'has_public_folder' => $hasPublicFolder,
                'site_type' => $siteType,
                'error_message' => null,
            ]);

            $logger->broadcast('ready');
            Log::info("CreateSiteJob: Site {$this->slug} created successfully");

        } catch (\Throwable $e) {
            $site->update([
                'status' => Site::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            $logger->broadcast('failed', $e->getMessage());
            Log::error("CreateSiteJob: Site {$this->slug} creation failed", [
                'error' => $e->getMessage(),
            ]);

            // Cleanup empty directory
            if (isset($projectPath) && is_dir($projectPath) && ! glob("{$projectPath}/*")) {
                @rmdir($projectPath);
            }

            throw $e;
        }
    }

    /**
     * Determine the project path for the site.
     */
    protected function determineProjectPath(Site $site, ConfigurationService $configService, Environment $environment): string
    {
        // If path already set on site, use it
        if ($site->path) {
            return $site->path;
        }

        // If directory option provided, use it
        if (! empty($this->options['directory'])) {
            return $this->expandPath($this->options['directory']);
        }

        // Get default path from config
        $config = $configService->getConfig($environment);
        $paths = $config['data']['paths'] ?? [];
        $basePath = $paths[0] ?? '~/projects';

        return $this->expandPath("{$basePath}/{$this->slug}");
    }

    /**
     * Build the provision context from options.
     */
    protected function buildContext(string $projectPath, Environment $environment): ProvisionContext
    {
        // Get TLD from environment
        $tld = $environment->tld ?? 'ccc';

        // Parse clone URL if provided
        $cloneUrl = $this->options['template'] ?? $this->options['clone_url'] ?? null;

        return new ProvisionContext(
            slug: $this->slug,
            projectPath: $projectPath,
            siteId: $this->siteId,
            cloneUrl: $cloneUrl,
            template: ($this->options['is_template'] ?? false) ? $cloneUrl : null,
            visibility: $this->options['visibility'] ?? 'private',
            phpVersion: $this->options['php_version'] ?? null,
            dbDriver: $this->options['db_driver'] ?? null,
            sessionDriver: $this->options['session_driver'] ?? null,
            cacheDriver: $this->options['cache_driver'] ?? null,
            queueDriver: $this->options['queue_driver'] ?? null,
            fork: $this->options['fork'] ?? false,
            displayName: $this->options['name'] ?? null,
            tld: $tld,
            organization: $this->options['org'] ?? null,
        );
    }

    /**
     * Handle repository operations (fork/template creation).
     */
    protected function handleRepositoryOperations(
        ProvisionContext $context,
        RepoIntent $intent,
        ProvisionPipeline $pipeline,
        ProvisionLogger $logger
    ): ProvisionContext {
        $github = $pipeline->getGitHubService();

        // Fork flow
        if ($intent === RepoIntent::Fork && $context->cloneUrl) {
            $result = $pipeline->forkRepository($context, $logger);
            if ($result->isFailed()) {
                throw new \RuntimeException($result->error ?? 'Fork failed');
            }

            return $context->withRepoInfo(
                $result->data['repo'] ?? null,
                $result->data['cloneUrl'] ?? null
            );
        }

        // Template flow
        if ($intent === RepoIntent::Template && $context->template) {
            $owner = $context->getGitHubOwner($github->getUsername());
            if (! $owner) {
                throw new \RuntimeException('Could not determine GitHub username for template');
            }

            $targetRepo = "{$owner}/{$context->slug}";
            $result = $pipeline->createFromTemplate($context, $logger, $targetRepo);

            if ($result->isFailed()) {
                throw new \RuntimeException($result->error ?? 'Template creation failed');
            }

            return $context->withRepoInfo(
                $result->data['repo'] ?? null,
                $result->data['cloneUrl'] ?? null
            );
        }

        return $context;
    }

    /**
     * Expand ~ to home directory.
     */
    protected function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            $home = $_SERVER['HOME'] ?? '/home/orbit';

            return $home.substr($path, 1);
        }

        return $path;
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
            "site-id:{$this->siteId}",
        ];
    }

    /**
     * Detect the site type based on file structure.
     */
    protected function detectSiteType(string $directory): string
    {
        $hasPublicFolder = is_dir("{$directory}/public");
        $hasArtisan = file_exists("{$directory}/artisan");
        $composerJson = "{$directory}/composer.json";

        if (file_exists($composerJson)) {
            $composer = json_decode(file_get_contents($composerJson), true);

            // Check if it's a Laravel package
            $type = $composer['type'] ?? null;
            if ($type === 'library' || $type === 'laravel-package') {
                return 'laravel-package';
            }

            // Check for package indicators in composer.json
            $extra = $composer['extra'] ?? [];
            if (isset($extra['laravel']['providers']) || isset($extra['laravel']['aliases'])) {
                return 'laravel-package';
            }

            // Check for Laravel Zero CLI apps
            if (isset($composer['require']['laravel-zero/framework'])) {
                return 'cli';
            }
        }

        // Laravel web application
        if ($hasPublicFolder && $hasArtisan) {
            return 'laravel-app';
        }

        // Laravel Zero or other CLI app
        if ($hasArtisan) {
            return 'cli';
        }

        // Generic PHP project with web interface
        if ($hasPublicFolder) {
            return 'web';
        }

        return 'unknown';
    }
}
