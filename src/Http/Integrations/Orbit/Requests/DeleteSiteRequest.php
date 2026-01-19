<?php

namespace HardImpact\Orbit\Http\Integrations\Orbit\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class DeleteSiteRequest extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        protected string $slug,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/sites/{$this->slug}";
    }
}
