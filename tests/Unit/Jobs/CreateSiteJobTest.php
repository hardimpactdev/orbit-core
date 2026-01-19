<?php

use HardImpact\Orbit\Jobs\CreateSiteJob;
use HardImpact\Orbit\Models\Environment;
use HardImpact\Orbit\Models\TrackedJob;
use HardImpact\Orbit\Services\OrbitCli\Shared\CommandService;

beforeEach(function () {
    $this->environment = Environment::factory()->local()->create();
});

describe('CreateSiteJob', function () {
    describe('constructor', function () {
        it('generates slug from name option', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: ['name' => 'My Test Site'],
            );

            expect($job->getSlug())->toBe('my-test-site');
        });

        it('handles special characters in name', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: ['name' => 'Test Site #1 (2024)'],
            );

            expect($job->getSlug())->toBe('test-site-1-2024');
        });
    });

    describe('buildCommand', function () {
        it('builds basic command with name and json flag', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: ['name' => 'test-site'],
            );

            $command = invokeBuildCommand($job);

            expect($command)->toBe("site:create 'test-site' --json");
        });

        it('escapes shell characters in name', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: ['name' => "test's site"],
            );

            $command = invokeBuildCommand($job);

            expect($command)->toContain("'test'\\''s site'");
        });

        it('adds --template flag when template is provided with is_template=true', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: [
                    'name' => 'test-site',
                    'template' => 'laravel/laravel',
                    'is_template' => true,
                ],
            );

            $command = invokeBuildCommand($job);

            expect($command)->toContain("--template='laravel/laravel'");
            expect($command)->not->toContain('--clone=');
        });

        it('adds --clone flag when template is provided with is_template=false', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: [
                    'name' => 'test-site',
                    'template' => 'owner/repo',
                    'is_template' => false,
                ],
            );

            $command = invokeBuildCommand($job);

            expect($command)->toContain("--clone='owner/repo'");
            expect($command)->not->toContain('--template=');
        });

        it('adds --fork flag when cloning with fork=true', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: [
                    'name' => 'test-site',
                    'template' => 'owner/repo',
                    'is_template' => false,
                    'fork' => true,
                ],
            );

            $command = invokeBuildCommand($job);

            expect($command)->toContain('--clone=');
            expect($command)->toContain('--fork');
        });

        it('does not add --fork when using template', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: [
                    'name' => 'test-site',
                    'template' => 'laravel/laravel',
                    'is_template' => true,
                    'fork' => true,
                ],
            );

            $command = invokeBuildCommand($job);

            expect($command)->not->toContain('--fork');
        });

        /**
         * CRITICAL TEST: This would have caught the --org vs --organization bug
         */
        it('adds --organization flag (not --org) when org option is provided', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: [
                    'name' => 'test-site',
                    'org' => 'my-organization',
                ],
            );

            $command = invokeBuildCommand($job);

            expect($command)->toContain("--organization='my-organization'");
            expect($command)->not->toContain('--org=');
        });

        it('adds --visibility flag when provided', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: [
                    'name' => 'test-site',
                    'visibility' => 'public',
                ],
            );

            $command = invokeBuildCommand($job);

            expect($command)->toContain("--visibility='public'");
        });

        it('adds --path flag when directory option is provided', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: [
                    'name' => 'test-site',
                    'directory' => '/custom/path',
                ],
            );

            $command = invokeBuildCommand($job);

            expect($command)->toContain("--path='/custom/path'");
        });

        it('adds --php flag when php_version is provided', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: [
                    'name' => 'test-site',
                    'php_version' => '8.4',
                ],
            );

            $command = invokeBuildCommand($job);

            expect($command)->toContain("--php='8.4'");
        });

        it('adds --db-driver flag when provided', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: [
                    'name' => 'test-site',
                    'db_driver' => 'pgsql',
                ],
            );

            $command = invokeBuildCommand($job);

            expect($command)->toContain("--db-driver='pgsql'");
        });

        it('adds --session-driver flag when provided', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: [
                    'name' => 'test-site',
                    'session_driver' => 'redis',
                ],
            );

            $command = invokeBuildCommand($job);

            expect($command)->toContain("--session-driver='redis'");
        });

        it('adds --cache-driver flag when provided', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: [
                    'name' => 'test-site',
                    'cache_driver' => 'redis',
                ],
            );

            $command = invokeBuildCommand($job);

            expect($command)->toContain("--cache-driver='redis'");
        });

        it('adds --queue-driver flag when provided', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: [
                    'name' => 'test-site',
                    'queue_driver' => 'redis',
                ],
            );

            $command = invokeBuildCommand($job);

            expect($command)->toContain("--queue-driver='redis'");
        });

        it('does not add empty options', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: [
                    'name' => 'test-site',
                    'org' => null,
                    'template' => '',
                    'visibility' => null,
                ],
            );

            $command = invokeBuildCommand($job);

            expect($command)->toBe("site:create 'test-site' --json");
        });

        it('builds complete command with all options', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: [
                    'name' => 'test-site',
                    'org' => 'my-org',
                    'template' => 'laravel/laravel',
                    'is_template' => true,
                    'visibility' => 'private',
                    'directory' => '/custom/path',
                    'php_version' => '8.5',
                    'db_driver' => 'pgsql',
                    'session_driver' => 'database',
                    'cache_driver' => 'redis',
                    'queue_driver' => 'redis',
                ],
            );

            $command = invokeBuildCommand($job);

            expect($command)
                ->toContain("site:create 'test-site'")
                ->toContain("--template='laravel/laravel'")
                ->toContain("--organization='my-org'")
                ->toContain("--visibility='private'")
                ->toContain("--path='/custom/path'")
                ->toContain("--php='8.5'")
                ->toContain("--db-driver='pgsql'")
                ->toContain("--session-driver='database'")
                ->toContain("--cache-driver='redis'")
                ->toContain("--queue-driver='redis'")
                ->toContain('--json');
        });
    });

    describe('tags', function () {
        it('returns correct horizon tags', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: ['name' => 'My Test Site'],
            );

            $tags = $job->tags();

            expect($tags)->toBe([
                'create-site',
                'site:my-test-site',
                "environment:{$this->environment->id}",
            ]);
        });
    });

    describe('handle', function () {
        it('creates tracked job when trackedJobId is null', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: ['name' => 'test-site'],
                trackedJobId: null,
            );

            $commandService = mock(CommandService::class);
            $commandService->shouldReceive('executeCommand')
                ->once()
                ->andReturn(['success' => true, 'data' => ['status' => 'ready']]);

            $job->handle($commandService);

            $trackedJob = TrackedJob::where('name', 'create-site:test-site')->first();
            expect($trackedJob)->not->toBeNull();
            expect($trackedJob->status)->toBe('completed');
        });

        it('uses existing tracked job when trackedJobId is provided', function () {
            $existingJob = TrackedJob::factory()->pending()->create([
                'name' => 'create-site:test-site',
            ]);

            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: ['name' => 'test-site'],
                trackedJobId: $existingJob->id,
            );

            $commandService = mock(CommandService::class);
            $commandService->shouldReceive('executeCommand')
                ->once()
                ->andReturn(['success' => true, 'data' => ['status' => 'ready']]);

            $job->handle($commandService);

            $existingJob->refresh();
            expect($existingJob->status)->toBe('completed');
        });

        it('marks job as failed on command failure', function () {
            $trackedJob = TrackedJob::factory()->pending()->create();

            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: ['name' => 'test-site'],
                trackedJobId: $trackedJob->id,
            );

            $commandService = mock(CommandService::class);
            $commandService->shouldReceive('executeCommand')
                ->once()
                ->andReturn(['success' => false, 'error' => 'CLI error message']);

            $job->handle($commandService);

            $trackedJob->refresh();
            expect($trackedJob->status)->toBe('failed');
            expect($trackedJob->output)->toBe('CLI error message');
        });

        it('marks job as failed on exception', function () {
            $trackedJob = TrackedJob::factory()->pending()->create();

            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: ['name' => 'test-site'],
                trackedJobId: $trackedJob->id,
            );

            $commandService = mock(CommandService::class);
            $commandService->shouldReceive('executeCommand')
                ->once()
                ->andThrow(new \RuntimeException('Connection failed'));

            expect(fn () => $job->handle($commandService))
                ->toThrow(\RuntimeException::class);

            $trackedJob->refresh();
            expect($trackedJob->status)->toBe('failed');
            expect($trackedJob->output)->toBe('Connection failed');
        });

        it('passes correct timeout to CommandService', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: ['name' => 'test-site'],
            );

            $commandService = mock(CommandService::class);
            $commandService->shouldReceive('executeCommand')
                ->once()
                ->withArgs(function ($env, $cmd, $timeout) {
                    return $timeout === 600;
                })
                ->andReturn(['success' => true, 'data' => []]);

            $job->handle($commandService);
        });
    });

    describe('job configuration', function () {
        it('has 600 second timeout', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: ['name' => 'test-site'],
            );

            expect($job->timeout)->toBe(600);
        });

        it('has 1 try (no retries)', function () {
            $job = new CreateSiteJob(
                environmentId: $this->environment->id,
                options: ['name' => 'test-site'],
            );

            expect($job->tries)->toBe(1);
        });
    });
});

/**
 * Helper to invoke the protected buildCommand method
 */
function invokeBuildCommand(CreateSiteJob $job): string
{
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('buildCommand');

    return $method->invoke($job);
}
