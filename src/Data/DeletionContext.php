<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data;

use HardImpact\Orbit\Models\Site;

/**
 * Context object passed through all deletion actions.
 *
 * Contains all parameters needed for site deletion.
 * Immutable - use factory methods to create instances.
 */
final readonly class DeletionContext
{
    public function __construct(
        public string $slug,
        public string $projectPath,
        public ?int $siteId = null,
        public bool $keepDatabase = false,
        public bool $keepRepository = true, // Always true for now - GitHub repo deletion not yet implemented
        public ?string $dbConnection = null,
        public ?string $dbName = null,
        public string $tld = 'ccc',
    ) {}

    /**
     * Create context from a Site model.
     */
    public static function fromSite(Site $site, bool $keepDatabase = false): self
    {
        return new self(
            slug: $site->slug,
            projectPath: $site->path ?? '',
            siteId: $site->id,
            keepDatabase: $keepDatabase,
            keepRepository: true,
            dbConnection: null,
            dbName: null,
            tld: self::extractTldFromDomain($site->domain),
        );
    }

    /**
     * Create a new context with database configuration loaded from .env file.
     */
    public function withDatabaseFromEnv(): self
    {
        if (! $this->projectPath || ! file_exists("{$this->projectPath}/.env")) {
            return $this;
        }

        $envContent = file_get_contents("{$this->projectPath}/.env");
        $dbConnection = null;
        $dbName = null;

        if (preg_match('/^DB_CONNECTION=(.+)$/m', $envContent, $matches)) {
            $dbConnection = trim($matches[1]);
        }

        if (preg_match('/^DB_DATABASE=(.+)$/m', $envContent, $matches)) {
            $dbName = trim($matches[1]);
        }

        // Fall back to slug as database name if not specified
        if (! $dbName) {
            $dbName = $this->slug;
        }

        return new self(
            slug: $this->slug,
            projectPath: $this->projectPath,
            siteId: $this->siteId,
            keepDatabase: $this->keepDatabase,
            keepRepository: $this->keepRepository,
            dbConnection: $dbConnection,
            dbName: $dbName,
            tld: $this->tld,
        );
    }

    /**
     * Check if this site uses PostgreSQL.
     */
    public function usesPostgres(): bool
    {
        $postgresConnections = ['pgsql', 'postgres', 'postgresql'];

        return $this->dbConnection && in_array(strtolower($this->dbConnection), $postgresConnections, true);
    }

    /**
     * Extract TLD from a domain string.
     */
    private static function extractTldFromDomain(?string $domain): string
    {
        if (! $domain) {
            return 'ccc';
        }

        $parts = explode('.', $domain);

        return end($parts) ?: 'ccc';
    }
}
