<?php

namespace HardImpact\Orbit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'name',
        'github_url',
    ];

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public static function findByGithubUrl(string $url): ?self
    {
        return static::where('github_url', $url)->first();
    }

    public static function findOrCreateByGithubUrl(string $url, string $name): self
    {
        return static::firstOrCreate(
            ['github_url' => $url],
            ['name' => $name]
        );
    }
}
