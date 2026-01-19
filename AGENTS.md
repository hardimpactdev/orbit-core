# Agent Instructions

## Project Overview

**orbit-core** is a Laravel package that provides shared functionality for the Orbit ecosystem. It is required by both orbit-desktop and orbit-web.

## Repository Locations

| Project | Location | Purpose |
|---------|----------|---------|
| orbit-core | `~/projects/orbit-core` (remote) | Shared Laravel package |
| orbit-web | `~/projects/orbit-web` (remote) | Web dashboard shell |
| orbit-desktop | Local Mac | NativePHP desktop shell |
| orbit-cli | `~/projects/orbit-cli` (remote) | CLI tool |

## Package Structure

```
src/
  Models/                    # Eloquent models
    Environment.php
    Project.php
    Deployment.php
    Setting.php
    SshKey.php
    TemplateFavorite.php
    UserPreference.php
  Services/
    DoctorService.php        # Health checks
    SshService.php           # SSH operations
    OrbitCli/                # CLI interaction
      ProjectService.php
      ConfigurationService.php  # Includes DNS mapping methods
      ServiceControlService.php
      StatusService.php
      Shared/
        CommandService.php
        ConnectorService.php
  Http/
    Controllers/             # All route handlers
      DnsController.php      # DNS mappings management
    Middleware/
      HandleInertiaRequests.php
      ImplicitEnvironment.php
      DesktopOnly.php
    Integrations/Orbit/      # Saloon API connectors
config/
  orbit.php                  # Package configuration
database/
  migrations/                # Database migrations
resources/
  js/
    pages/                   # Vue page components
    components/              # Reusable Vue components
      DnsSettings.vue        # DNS mappings management UI
    layouts/                 # App layouts
    stores/                  # Pinia stores
    composables/             # Vue composables
    app.ts                   # Frontend entry point
    app.css                  # Tailwind styles
routes/
  web.php                    # Web routes
  api.php                    # API routes
  environment.php            # Environment-scoped routes
```

## Namespace Convention

All classes use `HardImpact\Orbit` namespace:

```php
use HardImpact\Orbit\Models\Environment;
use HardImpact\Orbit\Services\OrbitCli\ProjectService;
use HardImpact\Orbit\Http\Controllers\ProjectController;
```

## Mode Configuration

The package supports two modes via `config("orbit.multi_environment")`:

- **Web mode** (`false`): Single implicit environment, flat routes
- **Desktop mode** (`true`): Multiple environments, prefixed routes

## Development Workflow

1. Make changes in this package
2. Run tests: `composer test`
3. Commit and push
4. Update consumers: `composer update hardimpactdev/orbit-core`

## Testing

```bash
composer test           # Run all tests
composer analyse        # PHPStan analysis
composer format         # Format with Pint
```

## Important Notes

- Always use fully qualified namespace for models/services
- Never use `App\Models\*` - always `HardImpact\Orbit\Models\*`
- Routes are registered via `OrbitServiceProvider::routes()` in consumer apps
- Frontend assets live in `resources/js/` and are compiled by consumer apps

## Flow Documentation

**Critical workflow documentation lives in `/docs/flows/`.**

| Flow | Document | Status |
|------|----------|--------|
| Site Creation | [docs/flows/site-creation.md](docs/flows/site-creation.md) | Authoritative |

**Always reference these documents during refactoring to ensure consistency.**

### Core Architecture Principle

All long-running operations (site creation, provisioning, etc.) MUST:
1. Dispatch a Job to the queue
2. Return immediately to the user
3. Process asynchronously via Horizon
4. Broadcast progress via WebSocket

**Never call CLI commands synchronously from controllers.**

## CLI Integration (CommandService)

The CLI is called from **Jobs only**, not directly from controllers:

```php
$pharPath = $this->cliUpdate->getPharPath();
$result = Process::timeout(60)->run("php {$pharPath} {$command}");
$decoded = json_decode($result->output(), true);
```

### Critical Requirements

1. **CLI must output clean JSON to stdout** when `--json` flag is passed
2. **stderr is separate** - warnings via `error_log()` don't corrupt JSON parsing
3. **Timeout is 60 seconds** - long operations must complete within this window
4. **JSON structure expected**: `{"success": bool, "data": {...}}` or `{"success": false, "error": "message"}`

### Common Failure Modes

| Symptom | Cause | Fix |
|---------|-------|-----|
| "Failed to parse JSON: Syntax error" | CLI outputs console messages to stdout | Pass `--json` properly, suppress console output in CLI |
| 502 Bad Gateway | CLI restarts PHP-FPM | Remove PHP-FPM restarts from web-callable operations |
| Timeout | Operation exceeds 60s | Optimize operation or increase timeout |

### Testing CLI Integration

Test CLI JSON output directly before assuming web integration works:

```bash
# stdout only (simulates what Process::run() captures)
php ~/.local/bin/orbit site:create "test" --json 2>/dev/null

# Should output ONLY valid JSON, nothing else
```

## After Making Changes

**IMPORTANT: Always complete the full workflow below:**

1. **Test locally**: `composer test` (if applicable)
2. **Commit changes**: Use descriptive commit message
3. **Push via gh CLI**: `git push`
4. **Update orbit-web** (if changes affect frontend or backend):
   ```bash
   cd ~/projects/orbit-web
   composer update hardimpactdev/orbit-core
   bun run build  # CRITICAL: Always rebuild assets after package update
   ```
5. **Update orbit-desktop** (when on Mac):
   ```bash
   composer update hardimpactdev/orbit-core
   npm run build  # Use npm for NativePHP projects
   ```

**Never skip step 4 - orbit-web must be updated and rebuilt after every push!**
