<?php

namespace HardImpact\Orbit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    protected $casts = [
        'has_public_folder' => 'boolean',
    ];

    public const STATUS_QUEUED = 'queued';
    public const STATUS_CREATING_REPO = 'creating_repo';
    public const STATUS_CLONING = 'cloning';
    public const STATUS_SETTING_UP = 'setting_up';
    public const STATUS_INSTALLING_COMPOSER = 'installing_composer';
    public const STATUS_INSTALLING_NPM = 'installing_npm';
    public const STATUS_BUILDING = 'building';
    public const STATUS_FINALIZING = 'finalizing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'environment_id',
        'name',
        'display_name',
        'slug',
        'path',
        'php_version',
        'github_repo',
        'site_type',
        'has_public_folder',
        'domain',
        'site_url',
        'status',
        'error_message',
        'job_id',
    ];

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function isProvisioning(): bool
    {
        return in_array($this->status, [
            self::STATUS_QUEUED,
            self::STATUS_CREATING_REPO,
            self::STATUS_CLONING,
            self::STATUS_SETTING_UP,
            self::STATUS_INSTALLING_COMPOSER,
            self::STATUS_INSTALLING_NPM,
            self::STATUS_BUILDING,
            self::STATUS_FINALIZING,
        ], true);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
