<?php

namespace HardImpact\Orbit\Http\Controllers;

use HardImpact\Orbit\Http\Integrations\Orbit\Requests\CreateSiteRequest;
use HardImpact\Orbit\Http\Integrations\Orbit\Requests\DeleteSiteRequest;
use HardImpact\Orbit\Models\Environment;
use HardImpact\Orbit\Services\OrbitCli\Shared\ConnectorService;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function __construct(
        protected ConnectorService $connector,
    ) {}

    /**
     * Create a new site in the active environment.
     * Always uses the Saloon API connector for consistency.
     */
    public function store(Request $request)
    {
        $environment = Environment::getActive();

        if (! $environment) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'No active environment',
                ], 400);
            }

            return redirect()->back()->withErrors(['error' => 'No active environment']);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'org' => 'nullable|string|max:255',
            'template' => 'nullable|string|max:255',
            'is_template' => 'boolean',
            'fork' => 'boolean',
            'visibility' => 'nullable|string|in:private,public',
            'php_version' => 'nullable|string',
            'db_driver' => 'nullable|string',
            'session_driver' => 'nullable|string',
            'cache_driver' => 'nullable|string',
            'queue_driver' => 'nullable|string',
        ]);

        $result = $this->connector->sendRequest(
            $environment,
            new CreateSiteRequest($validated)
        );

        if (! $result['success']) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Failed to create site',
                ], 422);
            }

            return redirect()->back()->withErrors(['error' => $result['error'] ?? 'Failed to create site']);
        }

        // API requests get JSON response
        if ($request->wantsJson()) {
            return response()->json($result);
        }

        // Web requests get redirect with provisioning slug for WebSocket tracking
        $slug = $result['slug'] ?? $result['data']['slug'] ?? null;

        return redirect()->route('environments.sites', ['environment' => $environment->id])
            ->with([
                'provisioning' => $slug,
                'success' => "Site '{$validated['name']}' is being created...",
            ]);
    }

    /**
     * Delete a site from the active environment.
     * Always uses the Saloon API connector for consistency.
     */
    public function destroy(Request $request, string $slug)
    {
        $environment = Environment::getActive();

        if (! $environment) {
            return response()->json([
                'success' => false,
                'error' => 'No active environment',
            ], 400);
        }

        $keepDb = $request->boolean('keep_db', false);

        $result = $this->connector->sendRequest(
            $environment,
            new DeleteSiteRequest($slug, $keepDb)
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to delete site',
            ], 422);
        }

        return response()->json($result);
    }
}
