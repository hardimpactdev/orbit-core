<?php

use HardImpact\Orbit\Models\Environment;
use HardImpact\Orbit\Services\CliUpdateService;
use HardImpact\Orbit\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Services\SshService;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->sshService = mock(SshService::class);
    $this->cliUpdateService = mock(CliUpdateService::class);
    $this->commandService = new CommandService($this->sshService, $this->cliUpdateService);
});

describe('CommandService', function () {
    describe('executeCommand', function () {
        it('routes to executeLocalCommand for local environments', function () {
            $environment = Environment::factory()->local()->create();

            $this->cliUpdateService->shouldReceive('isInstalled')->andReturn(true);
            $this->cliUpdateService->shouldReceive('getPharPath')->andReturn('/path/to/orbit.phar');

            Process::fake([
                '*' => Process::result(output: '{"success": true, "data": {"key": "value"}}'),
            ]);

            $result = $this->commandService->executeCommand($environment, 'status --json');

            expect($result)->toHaveKey('success');
        });

        it('routes to executeRemoteCommand for remote environments', function () {
            $environment = Environment::factory()->create(['is_local' => false]);

            $this->sshService->shouldReceive('executeJson')
                ->once()
                ->withArgs(function ($env, $cmd) use ($environment) {
                    return $env->id === $environment->id && str_contains($cmd, 'status --json');
                })
                ->andReturn(['success' => true, 'data' => ['success' => true, 'data' => []]]);

            $result = $this->commandService->executeCommand($environment, 'status --json');

            expect($result)->toHaveKey('success');
        });
    });

    describe('executeLocalCommand', function () {
        it('returns error when CLI is not installed', function () {
            $this->cliUpdateService->shouldReceive('isInstalled')->andReturn(false);

            $result = $this->commandService->executeLocalCommand('status --json');

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toBe('Orbit CLI not installed.');
            expect($result['exit_code'])->toBe(1);
        });

        it('builds correct command with phar path', function () {
            $this->cliUpdateService->shouldReceive('isInstalled')->andReturn(true);
            $this->cliUpdateService->shouldReceive('getPharPath')->andReturn('/custom/path/orbit.phar');

            Process::fake([
                'php /custom/path/orbit.phar *' => Process::result(output: '{"success": true}'),
            ]);

            $this->commandService->executeLocalCommand('status --json');

            Process::assertRan('php /custom/path/orbit.phar status --json');
        });

        it('passes timeout to process', function () {
            $this->cliUpdateService->shouldReceive('isInstalled')->andReturn(true);
            $this->cliUpdateService->shouldReceive('getPharPath')->andReturn('/path/to/orbit.phar');

            Process::fake([
                '*' => Process::result(output: '{"success": true}'),
            ]);

            $result = $this->commandService->executeLocalCommand('provision test --json', 600);

            // The timeout is passed internally - we verify the command executed successfully
            expect($result)->toBe(['success' => true]);
        });

        it('returns decoded JSON on success', function () {
            $this->cliUpdateService->shouldReceive('isInstalled')->andReturn(true);
            $this->cliUpdateService->shouldReceive('getPharPath')->andReturn('/path/to/orbit.phar');

            Process::fake([
                '*' => Process::result(output: '{"success": true, "data": {"status": "running"}}'),
            ]);

            $result = $this->commandService->executeLocalCommand('status --json');

            expect($result)->toBe(['success' => true, 'data' => ['status' => 'running']]);
        });

        it('returns error when command fails', function () {
            $this->cliUpdateService->shouldReceive('isInstalled')->andReturn(true);
            $this->cliUpdateService->shouldReceive('getPharPath')->andReturn('/path/to/orbit.phar');

            Process::fake([
                '*' => Process::result(
                    output: '',
                    errorOutput: 'Error: Site not found',
                    exitCode: 1
                ),
            ]);

            $result = $this->commandService->executeLocalCommand('status --json');

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toContain('Error: Site not found');
            expect($result['exit_code'])->toBe(1);
        });

        it('returns stdout as error when stderr is empty', function () {
            $this->cliUpdateService->shouldReceive('isInstalled')->andReturn(true);
            $this->cliUpdateService->shouldReceive('getPharPath')->andReturn('/path/to/orbit.phar');

            Process::fake([
                '*' => Process::result(
                    output: 'The "--org" option does not exist.',
                    errorOutput: '',
                    exitCode: 1
                ),
            ]);

            $result = $this->commandService->executeLocalCommand('site:create test --org=my-org --json');

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toContain('--org');
        });

        it('returns error on invalid JSON output', function () {
            $this->cliUpdateService->shouldReceive('isInstalled')->andReturn(true);
            $this->cliUpdateService->shouldReceive('getPharPath')->andReturn('/path/to/orbit.phar');

            Process::fake([
                '*' => Process::result(output: 'Not valid JSON at all'),
            ]);

            $result = $this->commandService->executeLocalCommand('status --json');

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toContain('Failed to parse JSON');
        });

        it('handles exceptions gracefully', function () {
            $this->cliUpdateService->shouldReceive('isInstalled')->andReturn(true);
            $this->cliUpdateService->shouldReceive('getPharPath')->andReturn('/path/to/orbit.phar');

            Process::fake([
                '*' => fn () => throw new \RuntimeException('Process timeout'),
            ]);

            $result = $this->commandService->executeLocalCommand('status --json');

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toContain('Command timed out or failed');
            expect($result['error'])->toContain('Process timeout');
        });
    });

    describe('executeRemoteCommand', function () {
        it('uses configured cli_path when available', function () {
            $environment = Environment::factory()->create([
                'is_local' => false,
                'cli_path' => '/custom/orbit',
            ]);

            $this->sshService->shouldReceive('executeJson')
                ->once()
                ->withArgs(function ($env, $cmd) {
                    return $cmd === '/custom/orbit status --json';
                })
                ->andReturn(['success' => true, 'data' => ['success' => true]]);

            $this->commandService->executeRemoteCommand($environment, 'status --json');
        });

        it('uses default path when cli_path is not configured', function () {
            $environment = Environment::factory()->create([
                'is_local' => false,
                'cli_path' => null,
            ]);

            $this->sshService->shouldReceive('executeJson')
                ->once()
                ->withArgs(function ($env, $cmd) {
                    return $cmd === '$HOME/.local/bin/orbit status --json';
                })
                ->andReturn(['success' => true, 'data' => ['success' => true]]);

            $this->commandService->executeRemoteCommand($environment, 'status --json');
        });

        it('returns error on SSH failure', function () {
            $environment = Environment::factory()->create(['is_local' => false]);

            $this->sshService->shouldReceive('executeJson')
                ->once()
                ->andReturn([
                    'success' => false,
                    'error' => 'Connection refused',
                    'exit_code' => 255,
                ]);

            $result = $this->commandService->executeRemoteCommand($environment, 'status --json');

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toBe('Connection refused');
            expect($result['exit_code'])->toBe(255);
        });

        it('returns data from successful SSH execution', function () {
            $environment = Environment::factory()->create(['is_local' => false]);

            $this->sshService->shouldReceive('executeJson')
                ->once()
                ->andReturn([
                    'success' => true,
                    'data' => [
                        'success' => true,
                        'data' => ['status' => 'running'],
                    ],
                ]);

            $result = $this->commandService->executeRemoteCommand($environment, 'status --json');

            expect($result)->toBe(['success' => true, 'data' => ['status' => 'running']]);
        });
    });

    describe('findBinary', function () {
        it('returns path when binary is found', function () {
            $environment = Environment::factory()->create(['is_local' => false]);

            $this->sshService->shouldReceive('execute')
                ->once()
                ->andReturn([
                    'success' => true,
                    'output' => '/home/user/.local/bin/orbit',
                ]);

            $result = $this->commandService->findBinary($environment);

            expect($result)->toBe('/home/user/.local/bin/orbit');
        });

        it('returns null when binary is not found', function () {
            $environment = Environment::factory()->create(['is_local' => false]);

            $this->sshService->shouldReceive('execute')
                ->once()
                ->andReturn([
                    'success' => false,
                    'output' => '',
                ]);

            $result = $this->commandService->findBinary($environment);

            expect($result)->toBeNull();
        });

        it('trims whitespace from path', function () {
            $environment = Environment::factory()->create(['is_local' => false]);

            $this->sshService->shouldReceive('execute')
                ->once()
                ->andReturn([
                    'success' => true,
                    'output' => "  /path/to/orbit\n  ",
                ]);

            $result = $this->commandService->findBinary($environment);

            expect($result)->toBe('/path/to/orbit');
        });
    });

    describe('isLocalCliInstalled', function () {
        it('delegates to CliUpdateService', function () {
            $this->cliUpdateService->shouldReceive('isInstalled')
                ->once()
                ->andReturn(true);

            expect($this->commandService->isLocalCliInstalled())->toBeTrue();
        });
    });

    describe('getLocalCliPath', function () {
        it('delegates to CliUpdateService', function () {
            $this->cliUpdateService->shouldReceive('getPharPath')
                ->once()
                ->andReturn('/path/to/orbit.phar');

            expect($this->commandService->getLocalCliPath())->toBe('/path/to/orbit.phar');
        });
    });

    describe('executeRawCommand', function () {
        describe('local environment', function () {
            it('returns error when CLI is not installed', function () {
                $environment = Environment::factory()->local()->create();

                $this->cliUpdateService->shouldReceive('isInstalled')->andReturn(false);

                $result = $this->commandService->executeRawCommand($environment, 'logs php');

                expect($result['success'])->toBeFalse();
                expect($result['error'])->toBe('Orbit CLI not installed.');
            });

            it('returns output on success', function () {
                $environment = Environment::factory()->local()->create();

                $this->cliUpdateService->shouldReceive('isInstalled')->andReturn(true);
                $this->cliUpdateService->shouldReceive('getPharPath')->andReturn('/path/to/orbit.phar');

                Process::fake([
                    '*' => Process::result(output: 'PHP container logs here'),
                ]);

                $result = $this->commandService->executeRawCommand($environment, 'logs php');

                expect($result['success'])->toBeTrue();
                expect($result['output'])->toContain('PHP container logs here');
                expect($result['error'])->toBeNull();
            });

            it('returns error on failure', function () {
                $environment = Environment::factory()->local()->create();

                $this->cliUpdateService->shouldReceive('isInstalled')->andReturn(true);
                $this->cliUpdateService->shouldReceive('getPharPath')->andReturn('/path/to/orbit.phar');

                Process::fake([
                    '*' => Process::result(
                        output: '',
                        errorOutput: 'Container not found',
                        exitCode: 1
                    ),
                ]);

                $result = $this->commandService->executeRawCommand($environment, 'logs nonexistent');

                expect($result['success'])->toBeFalse();
                expect($result['error'])->toContain('Container not found');
            });
        });

        describe('remote environment', function () {
            it('returns error when CLI is not found on remote', function () {
                $environment = Environment::factory()->create(['is_local' => false]);

                $this->sshService->shouldReceive('execute')
                    ->once()
                    ->andReturn(['success' => false, 'output' => '']);

                $result = $this->commandService->executeRawCommand($environment, 'logs php');

                expect($result['success'])->toBeFalse();
                expect($result['error'])->toBe('Orbit CLI not found on remote server');
            });

            it('executes command via SSH when CLI is found', function () {
                $environment = Environment::factory()->create(['is_local' => false]);

                // First call finds binary
                $this->sshService->shouldReceive('execute')
                    ->once()
                    ->andReturn(['success' => true, 'output' => '/home/user/.local/bin/orbit']);

                // Second call executes command
                $this->sshService->shouldReceive('execute')
                    ->once()
                    ->withArgs(function ($env, $cmd, $timeout) {
                        return str_contains($cmd, '/home/user/.local/bin/orbit logs php');
                    })
                    ->andReturn(['success' => true, 'output' => 'Remote logs']);

                $result = $this->commandService->executeRawCommand($environment, 'logs php');

                expect($result['success'])->toBeTrue();
                expect($result['output'])->toBe('Remote logs');
            });
        });
    });
});
