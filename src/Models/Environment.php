<?php

namespace HardImpact\Orbit\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Environment extends Model
{
    use HasFactory;

    const STATUS_PROVISIONING = 'provisioning';

    const STATUS_ACTIVE = 'active';

    const STATUS_ERROR = 'error';

    protected $table = 'environments';

    protected $fillable = [
        'name',
        'host',
        'user',
        'port',
        'is_local',
        'is_default',
        'tld',
        'cli_version',
        'cli_path',
        'cli_checked_at',
        'metadata',
        'last_connected_at',
        'status',
        'provisioning_log',
        'provisioning_error',
        'provisioning_step',
        'provisioning_total_steps',
    ];

    protected $casts = [
        'is_local' => 'boolean',
        'is_default' => 'boolean',
        'metadata' => 'array',
        'last_connected_at' => 'datetime',
        'cli_checked_at' => 'datetime',
        'provisioning_log' => 'array',
    ];

    protected $hidden = [
        'provisioning_log',
    ];

    public function hasCliCache(): bool
    {
        return $this->cli_version !== null
            && $this->cli_checked_at !== null
            && $this->cli_checked_at->diffInHours(now()) < 1;
    }

    public function updateCliCache(string $version, ?string $path = null): void
    {
        $this->update([
            'cli_version' => $version,
            'cli_path' => $path,
            'cli_checked_at' => now(),
        ]);
    }

    public function isProvisioning(): bool
    {
        return $this->status === self::STATUS_PROVISIONING;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    public function getSshConnectionString(): string
    {
        if ($this->is_local) {
            return 'local';
        }

        $port = $this->port !== 22 ? "-p {$this->port}" : '';

        return trim("{$this->user}@{$this->host} {$port}");
    }

    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->first();
    }

    public static function getLocal(): ?self
    {
        return static::where('is_local', true)->first();
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }
}
