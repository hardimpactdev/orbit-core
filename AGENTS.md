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
      ConfigurationService.php
      ServiceControlService.php
      StatusService.php
      Shared/
        CommandService.php
        ConnectorService.php
  Http/
    Controllers/             # All route handlers
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

## After Making Changes

1. Run tests locally
2. Commit with descriptive message
3. Push to GitHub
4. Update orbit-web: `composer update hardimpactdev/orbit-core`
5. Update orbit-desktop: `composer update hardimpactdev/orbit-core`
