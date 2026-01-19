<?php

namespace HardImpact\Orbit\Http\Integrations\Orbit\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class RebuildSiteRequest extends Request
{
    protected Method $method = Method::POST;

    public function __construct(
        protected string $slug,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/sites/{$this->slug}/rebuild";
    }

    protected function defaultConfig(): array
    {
        return [
            'timeout' => 300,
        ];
    }
}
