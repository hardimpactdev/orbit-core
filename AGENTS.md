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
    Environment.php          # Has editor_scheme for per-env editor preference
    Project.php
    Site.php                 # Site model for local dev sites
    TrackedJob.php           # Job tracking
    Deployment.php
    Setting.php              # Global settings (fallback for editor if env not set)
    SshKey.php
    TemplateFavorite.php
    UserPreference.php
  Contracts/
    ProvisionLoggerContract.php  # Interface for provision loggers
  Services/
    OrbitService.php         # Main orchestration service
    ProvisioningService.php  # Site provisioning logic
    SiteService.php          # Site management
    DoctorService.php        # Health checks
    SshService.php           # SSH operations
    CliUpdateService.php     # CLI update handling
    DnsResolverService.php   # DNS resolution
    NotificationService.php  # Notifications
    GitHubService.php        # GitHub API operations
    CaddyService.php         # Caddy configuration
    TemplateAnalyzer/        # Template analysis utilities
    Provision/               # Site provisioning pipeline
      ProvisionPipeline.php  # Main orchestrator (accepts ProvisionLoggerContract)
      ProvisionLogger.php    # orbit-core's logger (native Laravel events)
      Actions/               # Provisioning steps (CloneRepository, etc.)
    Deletion/                # Site deletion pipeline
      DeletionPipeline.php   # Main orchestrator
      DeletionLogger.php     # orbit-core's deletion logger
      Actions/               # Deletion steps (DropPostgresDatabase, etc.)
    OrbitCli/                # CLI interaction
      SiteCliService.php     # Site CLI operations
      ConfigurationService.php  # Includes DNS mapping methods
      ServiceControlService.php
      StatusService.php
      PackageService.php     # Package management
      WorkspaceService.php   # Workspace management
      WorktreeService.php    # Git worktree support
      Shared/
        CommandService.php
        ConnectorService.php
  Data/
    ProvisionContext.php     # Context for provisioning actions
    DeletionContext.php      # Context for deletion actions
    StepResult.php           # Action result wrapper
    # Note: ProvisionLoggerContract in Contracts/ allows CLI/web to use same pipeline
  Enums/
    RepoIntent.php           # Repository operation type enum
  Events/
    SiteProvisioningStatus.php  # Broadcasting provisioning progress
    SiteDeletionStatus.php      # Broadcasting deletion progress
  Jobs/
    CreateSiteJob.php        # Async site creation
    DeleteSiteJob.php        # Async site deletion
  Console/Commands/
    OrbitInit.php            # CLI initialization
  Http/
    Controllers/             # All route handlers
      DnsController.php      # DNS mappings management
      EnvironmentController.php  # Main environment operations
      DashboardController.php
      JobController.php
      ProvisioningController.php
      SettingsController.php
      SshKeyController.php
    Middleware/
      HandleInertiaRequests.php
      ImplicitEnvironment.php
    Integrations/Orbit/      # Saloon API connectors
config/
  orbit.php                  # Package configuration
database/
  migrations/                # Database migrations
  factories/                 # Model factories
resources/
  views/
    app.blade.php            # Root Blade template (Horizon-style)
  js/
    pages/                   # Vue page components
    components/              # Reusable Vue components
      DnsSettings.vue        # DNS mappings management UI
    layouts/                 # App layouts
    stores/                  # Pinia stores
    composables/             # Vue composables
    types/                   # TypeScript definitions
    lib/                     # Utility libraries
    app.ts                   # Frontend entry point (configures Echo)
  css/
    app.css                  # Tailwind styles
public/
  hot                        # Vite dev server marker (gitignored)
  build/                     # Production assets (gitignored)
routes/
  web.php                    # Web routes
  api.php                    # API routes
  environment.php            # Environment-scoped routes
```

## Namespace Convention

All classes use `HardImpact\Orbit` namespace:

```php
use HardImpact\Orbit\Models\Environment;
use HardImpact\Orbit\Services\OrbitCli\SiteCliService;
use HardImpact\Orbit\Http\Controllers\EnvironmentController;
```

## Mode Configuration

The package supports two modes via `config("orbit.multi_environment")`:

- **Web mode** (`false`): Single implicit environment, flat routes
- **Desktop mode** (`true`): Multiple environments, prefixed routes

## Development Workflow

### Local Development (HMR)

```bash
cd ~/projects/orbit-core
bun run dev                     # Creates public/hot, enables HMR
# Visit https://orbit-web.ccc   # Changes reflect instantly
```

### Production Build

```bash
cd ~/projects/orbit-core
bun run build                   # Creates public/build/

cd ~/projects/orbit-web
php artisan vendor:publish --tag=orbit-assets --force
```

### Package Changes

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

### Horizon-Style Architecture

orbit-core is a self-contained package (like Laravel Horizon) that serves its own views and assets. Shell apps (orbit-web, orbit-desktop) are empty wrappers.

**What orbit-core provides automatically:**
- Blade view (`orbit::app`) - set as Inertia root view
- Middleware (`HandleInertiaRequests`, `implicit.environment`) - auto-registered
- Vite configuration - hot file at `public/hot`, build at `vendor/orbit/build`

**Shell apps only need:**
```php
// bootstrap/app.php or routes/web.php
\HardImpact\Orbit\OrbitServiceProvider::routes();
```

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
4. Broadcast progress via WebSocket (Echo configured globally in `resources/js/app.ts`)

**Never call CLI commands synchronously from controllers.**

> **Clarification**: This async rule applies to web/desktop consumers (Controllers). Jobs are *supposed* to call the CLI synchronously - that's the whole point of queuing work. The "async" is about freeing the HTTP response, not the job execution itself.

## Site Provisioning Pipeline

Site provisioning logic lives in orbit-core and can be invoked from:
1. **Jobs** (via Horizon) - for web UI initiated creation
2. **CLI** (synchronously) - for `site:create` command with real-time output

### Architecture

The `ProvisionPipeline` accepts a `ProvisionLoggerContract` interface, allowing different consumers to provide their own logging implementation:

```
Web UI → CreateSiteJob (Horizon)        CLI → SiteCreateCommand
              ↓                                    ↓
       ProvisionPipeline (shared)          ProvisionPipeline (shared)
              ↓                                    ↓
  ProvisionLogger (native events)     ProvisionLogger (console + Pusher)
              ↓                                    ↓
    SiteProvisioningStatus → Reverb        Pusher SDK → Reverb
              ↓                                    ↓
          Frontend                            Frontend
```

### Key Classes

| Class | Purpose |
|-------|---------|
| `ProvisionPipeline` | Main orchestrator - determines flow based on RepoIntent |
| `ProvisionLoggerContract` | Interface for logging implementations |
| `ProvisionLogger` | orbit-core's implementation using native `event()` calls |
| `ProvisionContext` | Data transfer object for action context |
| `StepResult` | Result wrapper with success/failure + data |
| `RepoIntent` | Enum: Clone, Fork, Template, Composer |

### CLI Integration

The orbit-cli has its own `ProvisionLogger` that implements `ProvisionLoggerContract`:
- Outputs to console for real-time feedback
- Broadcasts to Reverb via Pusher SDK for web UI updates
- Both consumers use the same `ProvisionPipeline` logic

### Provision Actions

Located in `src/Services/Provision/Actions/`:

| Action | Purpose |
|--------|---------|
| `CloneRepository` | Clone repo via `gh repo clone` |
| `ForkRepository` | Fork repo to user's account |
| `CreateGitHubRepository` | Create new repo from template |
| `InstallComposerDependencies` | Run `composer install` |
| `InstallNodeDependencies` | Run `bun ci` or `npm ci` |
| `BuildAssets` | Run build scripts |
| `ConfigureEnvironment` | Generate `.env` file |
| `CreateDatabase` | Create SQLite/PostgreSQL database |
| `GenerateAppKey` | Run `artisan key:generate` |
| `RunMigrations` | Run `artisan migrate` |
| `ConfigureTrustedProxies` | Laravel proxy config |
| `SetPhpVersion` | Create `.php-version` file |
| `RestartPhpContainer` | Restart PHP-FPM |
| `ValidatePackagistPackage` | Check Packagist for composer create |

### Native Broadcasting Events

Status updates use `ShouldBroadcastNow` for immediate delivery:

```php
// SiteProvisioningStatus
event(new SiteProvisioningStatus(
    slug: $slug,
    status: 'installing_composer',
    error: null,
    siteId: $site->id,
));
```

Events broadcast on the `provisioning` channel with event names:
- `site.provision.status` - Site creation progress
- `site.deletion.status` - Site deletion progress

## Site Deletion Pipeline

Site deletion mirrors the provisioning architecture with discrete, testable actions:

### Architecture

```
Web UI → DeleteSiteJob (Horizon)        CLI → SiteDeleteCommand
              ↓                                    ↓
       DeletionPipeline (shared)          DeletionPipeline (shared)
              ↓                                    ↓
  DeletionLogger (native events)     DeletionLogger (console + Pusher)
              ↓                                    ↓
    SiteDeletionStatus → Reverb          Pusher SDK → Reverb
              ↓                                    ↓
          Frontend                            Frontend
```

### Key Classes

| Class | Purpose |
|-------|---------|
| `DeletionPipeline` | Main orchestrator - runs actions in sequence |
| `DeletionContext` | Data transfer object with site info + options |
| `DeletionLogger` | orbit-core's logger (native Laravel events) |
| `DeleteSiteJob` | Queueable job for web-initiated deletion |

### Deletion Actions

Located in `src/Services/Deletion/Actions/`:

| Action | Purpose | Failure Behavior |
|--------|---------|------------------|
| `DropPostgresDatabase` | Terminate connections + DROP DATABASE | Non-fatal (warn only) |
| `DeleteProjectFiles` | rm -rf project directory (sudo fallback) | Fatal (fails pipeline) |
| `RegenerateCaddyConfig` | Regenerate Caddyfile, reload Caddy | Non-fatal (warn only) |

### DeletionContext Factory Methods

```php
// From Site model
$context = DeletionContext::fromSite($site, keepDatabase: false);

// Load database config from .env file
$context = $context->withDatabaseFromEnv();
```

### Pipeline Flow

1. **DropPostgresDatabase** - Skipped if `keepDatabase=true` or not PostgreSQL
2. **DeleteProjectFiles** - Required step (pipeline fails if this fails)
3. **RegenerateCaddyConfig** - Removes site from Caddyfile

**Note:** Site model deletion is handled by the caller (CLI/Job), not the pipeline.

### Broadcast Statuses

| Status | Description |
|--------|-------------|
| `deleting` | Starting deletion |
| `dropping_database` | Dropping PostgreSQL database |
| `removing_files` | Deleting project directory |
| `regenerating_caddy` | Updating Caddy config |
| `deleted` | Successfully deleted |
| `delete_failed` | Deletion failed |

### Future Extensions

When GitHub repository deletion is implemented:
1. Add `DeleteGitHubRepository` action
2. Set `DeletionContext.keepRepository = false`
3. Add `--delete-repo` flag to CLI/web UI

## WebSocket (Echo/Reverb)

Orbit's frontend uses Laravel's official `@laravel/echo-vue` composables with a
single global Echo connection. The Reverb configuration comes from the active
environment and is injected as an Inertia prop. Component-level subscriptions are
managed by the composables and automatically cleaned up when components unmount.

Key files:
- `resources/js/app.ts` configures Echo from the `reverb` page prop
- `resources/js/composables/useSiteProvisioning.ts` subscribes via `useEchoPublic`
- `resources/js/pages/environments/Services.vue` listens for service status updates

## Host Services

**IMPORTANT:** Caddy runs on the host via systemd, NOT in Docker.

| Service | Type | Management |
|---------|------|------------|
| Caddy | systemd | `sudo systemctl reload caddy` |
| Horizon | systemd | `sudo systemctl restart orbit-horizon` |
| PHP-FPM | systemd | `sudo systemctl restart php8.4-fpm` |
| Reverb | Docker | `docker restart orbit-reverb` |

Caddy config: `~/.config/orbit/caddy/Caddyfile` (imported by `/etc/caddy/Caddyfile`)

## CLI Integration (CommandService)

The CLI is called via `CommandService` which reads the CLI path from `ORBIT_CLI_PATH` env var:

```php
// CommandService reads from config('orbit.cli_path')
$cliPath = $this->getCliPath();  // e.g., /home/user/projects/orbit-cli/orbit
$result = Process::timeout(60)->run("{$cliPath} {$command}");
$decoded = json_decode($result->output(), true);
```

### Configuration

Set `ORBIT_CLI_PATH` in `.env`:
- **Development**: `/home/user/projects/orbit-cli/orbit` (points to dev orbit-cli)
- **Production**: `/home/user/.local/bin/orbit` (installed phar)

The CLI executable uses a shebang (`#!/usr/bin/env php`) so no `php` prefix is needed.

### Critical Requirements

1. **CLI must output clean JSON to stdout** when `--json` flag is passed
2. **stderr is separate** - warnings via `error_log()` don't corrupt JSON parsing
3. **Timeout is 60 seconds** - long operations must complete within this window
4. **JSON structure expected**: `{"success": bool, "data": {...}}` or `{"success": false, "error": "message"}`

### CLI Flag Naming Convention

**CRITICAL**: CLI option names in Jobs MUST match exactly what orbit-cli expects.

| Job Parameter | CLI Flag | NOT |
|---------------|----------|-----|
| `org` | `--organization` | ~~`--org`~~ |
| `template` | `--template` | |
| `visibility` | `--visibility` | |
| `php_version` | `--php` | |

The `CreateSiteJob::buildCommand()` method constructs CLI commands. Tests in `tests/Unit/Jobs/CreateSiteJobTest.php` verify flag names match the CLI.

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
   cd ~/projects/orbit-core
   bun run build                 # Build assets in orbit-core

   cd ~/projects/orbit-web
   composer update hardimpactdev/orbit-core
   php artisan vendor:publish --tag=orbit-assets --force
   ```
5. **Update orbit-desktop** (when on Mac):
   ```bash
   cd ~/projects/orbit-core
   bun run build

   cd ~/projects/orbit-desktop
   composer update hardimpactdev/orbit-core
   php artisan vendor:publish --tag=orbit-assets --force
   ```

**Assets are built in orbit-core and published to shell apps via `--tag=orbit-assets`.**
