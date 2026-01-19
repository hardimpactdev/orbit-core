<?php

namespace HardImpact\Orbit\Http\Middleware;

use HardImpact\Orbit\Models\Environment;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $currentPath = $request->path();
        $multiEnvironment = config('orbit.multi_environment');

        // Cache current environment to avoid duplicate queries
        $currentEnv = null;
        $getCurrentEnv = function () use (&$currentEnv, $multiEnvironment): ?\HardImpact\Orbit\Models\Environment {
            if ($multiEnvironment) {
                return null;
            }

            if (! $currentEnv instanceof \HardImpact\Orbit\Models\Environment) {
                $currentEnv = Environment::where('is_local', true)->first();
            }

            return $currentEnv;
        };

        return [
            ...parent::share($request),
            'multi_environment' => $multiEnvironment,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'provisioning' => fn () => $request->session()->get('provisioning'),
            ],
            'environments' => fn () => Environment::where('status', 'active')
                ->orderBy('is_default', 'desc')
                ->orderBy('name')
                ->get(['id', 'name', 'host', 'is_local', 'is_default']),
            'navigation' => function () use ($currentPath, $getCurrentEnv, $multiEnvironment, $request): array {
                // In web mode, get current environment from middleware injection or query
                $currentEnv = $multiEnvironment ? null : $getCurrentEnv();

                // In desktop mode, try to get environment from route parameter
                if ($multiEnvironment && $request->route('environment')) {
                    $currentEnv = $request->route('environment');
                }

                $envId = $currentEnv?->id;

                // Build URLs based on mode
                $urlPrefix = $multiEnvironment && $envId ? "/environments/{$envId}" : '';
                $pathPrefix = $multiEnvironment && $envId ? "environments/{$envId}/" : '';

                $mainItems = [];

                // In web mode, always show navigation (single implicit environment)
                // In desktop mode, only show when an environment is selected
                if ($envId || ! $multiEnvironment) {
                    $mainItems = [
                        [
                            'title' => 'Dashboard',
                            'href' => $urlPrefix ?: '/',
                            'icon' => 'LayoutDashboard',
                            'isActive' => in_array($currentPath, [$pathPrefix ? rtrim($pathPrefix, '/') : '', '/', ''], true),
                        ],
                        [
                            'title' => 'Sites',
                            'href' => "{$urlPrefix}/sites",
                            'icon' => 'FolderGit2',
                            'isActive' => str_starts_with($currentPath, "{$pathPrefix}sites") && ! str_contains($currentPath, 'workspaces'),
                        ],
                        [
                            'title' => 'Workspaces',
                            'href' => "{$urlPrefix}/workspaces",
                            'icon' => 'Boxes',
                            'isActive' => str_starts_with($currentPath, "{$pathPrefix}workspaces"),
                        ],
                        [
                            'title' => 'Services',
                            'href' => "{$urlPrefix}/services",
                            'icon' => 'Server',
                            'isActive' => str_starts_with($currentPath, "{$pathPrefix}services"),
                        ],
                        [
                            'title' => 'Settings',
                            'href' => "{$urlPrefix}/settings",
                            'icon' => 'Settings',
                            'isActive' => str_starts_with($currentPath, "{$pathPrefix}settings"),
                        ],
                    ];
                }

                $footerItems = [
                    [
                        'title' => 'App Settings',
                        'href' => '/settings',
                        'icon' => 'Cog',
                        'isActive' => $currentPath === 'settings',
                    ],
                ];

                return [
                    'app' => [
                        'main' => [
                            'items' => $mainItems,
                        ],
                        'footer' => [
                            'items' => $footerItems,
                        ],
                    ],
                ];
            },
        ];
    }
}
