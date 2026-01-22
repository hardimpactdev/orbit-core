<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Services\Provision;

use HardImpact\Orbit\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Events\SiteProvisioningStatus;
use Illuminate\Support\Facades\Log;

/**
 * Logger for site provisioning operations.
 *
 * Handles logging to Laravel logs and broadcasting status updates
 * via native Laravel events to Reverb WebSocket.
 *
 * Used by Horizon jobs for background provisioning.
 */
final class ProvisionLogger implements ProvisionLoggerContract
{
    private ?string $logFile = null;

    public function __construct(
        private readonly string $slug,
        private readonly ?int $siteId = null,
    ) {
        $this->initializeLogFile();
    }

    private function initializeLogFile(): void
    {
        $home = $_SERVER['HOME'] ?? '/home/orbit';
        $logsDir = "{$home}/.config/orbit/logs/provision";

        if (! is_dir($logsDir)) {
            @mkdir($logsDir, 0755, true);
        }

        $this->logFile = "{$logsDir}/{$this->slug}.log";

        // Clear previous log
        @file_put_contents($this->logFile, '');
    }

    /**
     * Log an info message.
     */
    public function info(string $message): void
    {
        $this->log($message);
        Log::info("[{$this->slug}] {$message}");
    }

    /**
     * Log a warning message.
     */
    public function warn(string $message): void
    {
        $this->log("WARNING: {$message}");
        Log::warning("[{$this->slug}] {$message}");
    }

    /**
     * Log an error message.
     */
    public function error(string $message): void
    {
        $this->log("ERROR: {$message}");
        Log::error("[{$this->slug}] {$message}");
    }

    /**
     * Write to the file log.
     */
    public function log(string $message): void
    {
        if ($this->logFile) {
            $timestamp = date('Y-m-d H:i:s');
            @file_put_contents(
                $this->logFile,
                "[{$timestamp}] {$message}\n",
                FILE_APPEND
            );
        }
    }

    /**
     * Broadcast a status update via native Laravel events.
     *
     * This dispatches a SiteProvisioningStatus event which implements
     * ShouldBroadcastNow, sending immediately to Reverb without queueing.
     */
    public function broadcast(string $status, ?string $error = null): void
    {
        $errorSuffix = $error ? " - Error: {$error}" : '';
        $this->log("Status: {$status}{$errorSuffix}");

        Log::info("Site {$this->slug}: {$status}", [
            'site_id' => $this->siteId,
            'error' => $error,
        ]);

        event(new SiteProvisioningStatus(
            slug: $this->slug,
            status: $status,
            error: $error,
            siteId: $this->siteId,
        ));
    }

    /**
     * Get the slug for this logger instance.
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * Get the site ID for this logger instance.
     */
    public function getSiteId(): ?int
    {
        return $this->siteId;
    }
}
