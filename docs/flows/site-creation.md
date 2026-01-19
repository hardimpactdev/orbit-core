# Site Creation Flow

> **Single Source of Truth** for the site creation workflow.  
> Reference this document during any refactoring to ensure consistency.

## Core Principle

**The flow is IDENTICAL for local and remote environments.**

There is no branching based on environment type. Every site creation:
1. Goes through the job queue
2. Is processed asynchronously
3. Uses the CLI for provisioning

## Sequence Diagram

```mermaid
sequenceDiagram
    participant U as User (Browser)
    participant C as Controller
    participant Q as Job Queue
    participant H as Horizon Worker
    participant CLI as Orbit CLI
    participant WS as WebSocket (Reverb)
    participant DB as Database

    U->>C: POST /environments/{id}/sites
    Note over C: Validate input
    
    C->>DB: Save TemplateFavorite (if template provided)
    C->>Q: Dispatch CreateSiteJob
    C-->>U: Redirect with {provisioning: slug}
    
    Note over U: User sees "Creating..." UI
    U->>WS: Subscribe to project.{slug} channel
    
    Q->>H: CreateSiteJob.handle()
    activate H
    
    H->>CLI: Execute: provision {slug} --json
    activate CLI
    
    CLI->>WS: Broadcast: status=provisioning
    CLI->>CLI: Phase 1: Repository operations
    CLI->>WS: Broadcast: status=cloning
    CLI->>CLI: Phase 2: Clone repository
    CLI->>WS: Broadcast: status=setting_up
    CLI->>CLI: Phase 3: Install deps, build, migrate
    CLI->>WS: Broadcast: status=finalizing
    CLI->>CLI: Phase 4: Restart PHP, register MCP
    CLI->>WS: Broadcast: status=ready
    
    CLI-->>H: Return {success: true, slug: ...}
    deactivate CLI
    
    H->>DB: Update TrackedJob status
    deactivate H
    
    WS-->>U: Receive status=ready
    Note over U: UI updates to show site ready
```

## State Machine

```mermaid
stateDiagram-v2
    [*] --> Pending: Job dispatched
    Pending --> Provisioning: Horizon picks up job
    Provisioning --> Cloning: Repository ready
    Cloning --> SettingUp: Clone complete
    SettingUp --> Finalizing: Deps installed, built
    Finalizing --> Ready: PHP restarted
    
    Provisioning --> Failed: Error
    Cloning --> Failed: Error
    SettingUp --> Failed: Error
    Finalizing --> Failed: Error
    
    Failed --> [*]: User acknowledges
    Ready --> [*]: Complete
```

## Component Responsibilities

| Component | Responsibility |
|-----------|----------------|
| **Controller** | Validate input, dispatch job, return immediately |
| **CreateSiteJob** | Call CLI, handle errors, update TrackedJob |
| **Orbit CLI** | Execute provisioning, broadcast progress |
| **WebSocket** | Real-time status updates to browser |
| **TrackedJob** | Persist job status for recovery/polling |

## API Contract

### Request
```
POST /environments/{id}/sites
Content-Type: application/json

{
  "name": "my-project",
  "template": "laravel/laravel",
  "is_template": true,
  "visibility": "private",
  "php_version": "8.4",
  "db_driver": "pgsql",
  "session_driver": "redis",
  "cache_driver": "redis",
  "queue_driver": "redis"
}
```

### Response (Web Request)
```
302 Redirect to /environments/{id}/sites
Session: {provisioning: "my-project", success: "Site is being created..."}
```

### Response (API Request)
```json
HTTP 202 Accepted

{
  "success": true,
  "message": "Site creation queued",
  "slug": "my-project",
  "job_id": "01234567-89ab-cdef-0123-456789abcdef"
}
```

## Error Handling

| Error Type | Handler | User Experience |
|------------|---------|-----------------|
| Validation error | Controller | Immediate redirect back with errors |
| Job dispatch failure | Controller | Error flash message |
| CLI failure | CreateSiteJob | WebSocket broadcasts error, TrackedJob marked failed |
| Timeout | Horizon | Job marked failed, user sees error via WebSocket |

## What NOT to Do

1. **Never call CLI synchronously** from the controller
2. **Never branch** on `$environment->is_local` for the dispatch flow
3. **Never skip** the job queue for "faster" local execution
4. **Never return** from controller before dispatching the job

## Related Files

- `src/Http/Controllers/EnvironmentController.php:879` - `storeSite()` method
- `src/Jobs/CreateSiteJob.php` - Async job class
- `src/Models/TrackedJob.php` - Job status tracking
- `orbit-cli/app/Commands/ProvisionCommand.php` - CLI provisioning
- `resources/js/composables/useSiteProvisioning.ts` - WebSocket listener

## Changelog

| Date | Change |
|------|--------|
| 2026-01-19 | Initial documentation |
| 2026-01-19 | Implemented CreateSiteJob, updated controller to dispatch async |
