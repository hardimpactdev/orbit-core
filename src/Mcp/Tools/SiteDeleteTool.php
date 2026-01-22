<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Mcp\Tools;

use HardImpact\Orbit\Models\Environment;
use HardImpact\Orbit\Services\OrbitCli\SiteCliService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class SiteDeleteTool extends Tool
{
    protected string $name = 'orbit_site_delete';

    protected string $description = 'Delete a site with cascade deletion of GitHub repo and sequence entry';

    public function __construct(protected SiteCliService $siteService) {}

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->required()->description('Site slug to delete'),
            'confirm' => $schema->boolean()->required()->description('Must be true to confirm deletion'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $environment = Environment::getLocal();

        if (! $environment) {
            return Response::error('No local environment configured');
        }

        $slug = $request->get('slug');
        $confirm = $request->get('confirm');

        if (! $slug) {
            return Response::error('Site slug is required');
        }

        if ($confirm !== true) {
            return Response::error('Deletion not confirmed. Set confirm=true to proceed');
        }

        $result = $this->siteService->deleteSite($environment, $slug, force: true);

        if (! $result['success']) {
            return Response::error($result['error'] ?? 'Failed to delete site');
        }

        return Response::structured([
            'success' => true,
            'slug' => $slug,
            'message' => $result['data']['message'] ?? 'Site deleted successfully',
            'steps' => $result['data']['steps'] ?? [],
        ]);
    }
}
