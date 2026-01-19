<?php

namespace HardImpact\Orbit\Http\Integrations\Orbit\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetProvisionStatusRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $slug,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/sites/{$this->slug}/provision-status";
    }
}
