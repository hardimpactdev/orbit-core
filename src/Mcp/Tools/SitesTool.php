<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Mcp\Tools;

use HardImpact\Orbit\Models\Environment;
use HardImpact\Orbit\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Services\OrbitCli\StatusService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class SitesTool extends Tool
{
    protected string $name = 'orbit_sites';

    protected string $description = 'List all registered sites with their domains, paths, PHP versions, and configuration details';

    public function __construct(
        protected StatusService $statusService,
        protected ConfigurationService $configService
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): ResponseFactory
    {
        $environment = Environment::getLocal();

        if (! $environment) {
            return Response::structured([
                'success' => false,
                'error' => 'No local environment configured',
            ]);
        }

        $sitesResult = $this->statusService->sites($environment);
        $configResult = $this->configService->getConfig($environment);

        if (! $sitesResult['success']) {
            return Response::structured([
                'success' => false,
                'error' => $sitesResult['error'] ?? 'Failed to get sites',
            ]);
        }

        $sites = $sitesResult['data']['sites'] ?? [];
        $config = $configResult['success'] ? ($configResult['data'] ?? []) : [];
        $defaultPhp = $config['default_php_version'] ?? '8.4';

        $formattedSites = array_map(fn ($project) => [
            'name' => $project['name'],
            'domain' => $project['domain'] ?? null,
            'path' => $project['path'],
            'php_version' => $project['php_version'] ?? $defaultPhp,
            'has_custom_php' => ($project['php_version'] ?? $defaultPhp) !== $defaultPhp,
            'secure' => $project['secure'] ?? true,
        ], $sites);

        return Response::structured([
            'sites' => $formattedSites,
            'default_php_version' => $defaultPhp,
            'sites_count' => count($formattedSites),
        ]);
    }
}
