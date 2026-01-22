<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Mcp\Resources;

use HardImpact\Orbit\Models\Environment;
use HardImpact\Orbit\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Services\OrbitCli\StatusService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class SitesResource extends Resource
{
    protected string $uri = 'orbit://sites';

    protected string $mimeType = 'application/json';

    public function __construct(
        protected StatusService $statusService,
        protected ConfigurationService $configService
    ) {}

    public function name(): string
    {
        return 'sites';
    }

    public function title(): string
    {
        return 'Sites';
    }

    public function description(): string
    {
        return 'All registered sites with their domains, paths, PHP versions, and custom settings.';
    }

    public function handle(Request $request): Response
    {
        $environment = Environment::getLocal();

        if (! $environment) {
            return Response::json([
                'error' => 'No local environment configured',
            ]);
        }

        // Get sites from CLI
        $sitesResult = $this->statusService->sites($environment);
        $configResult = $this->configService->getConfig($environment);

        if (! $sitesResult['success']) {
            return Response::json([
                'error' => $sitesResult['error'] ?? 'Failed to get sites',
            ]);
        }

        $sites = $sitesResult['data']['sites'] ?? [];
        $config = $configResult['success'] ? ($configResult['data'] ?? []) : [];
        $defaultPhp = $config['default_php_version'] ?? '8.4';
        $tld = $config['tld'] ?? 'test';

        $formattedSites = array_values(array_map(fn ($site) => [
            'name' => $site['name'],
            'display_name' => $site['display_name'] ?? ucwords(str_replace(['-', '_'], ' ', $site['name'])),
            'github_repo' => $site['github_repo'] ?? null,
            'project_type' => $site['project_type'] ?? 'unknown',
            'domain' => $site['domain'] ?? null,
            'path' => $site['path'],
            'php_version' => $site['php_version'] ?? $defaultPhp,
            'has_custom_php' => ($site['php_version'] ?? $defaultPhp) !== $defaultPhp,
            'secure' => true,
        ], $sites));

        return Response::json([
            'sites' => $formattedSites,
            'summary' => [
                'total' => count($sites),
                'with_custom_php' => count(array_filter($formattedSites, fn ($s) => $s['has_custom_php'])),
                'default_php_version' => $defaultPhp,
                'tld' => $tld,
            ],
        ]);
    }
}
