<?php

namespace HardImpact\Orbit\Services;

use HardImpact\Orbit\Models\Site;
use Illuminate\Support\Collection;

class SiteService
{
    public function all(): Collection
    {
        return Site::all();
    }

    public function findBySlug(string $slug): ?Site
    {
        return Site::where('slug', $slug)->first();
    }

    public function create(array $data): Site
    {
        return Site::create($data);
    }

    public function update(Site $site, array $data): bool
    {
        return $site->update($data);
    }

    public function delete(Site $site): bool
    {
        return $site->delete();
    }
}
