<?php

namespace HardImpact\Orbit\Http\Integrations\Orbit\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetConfigRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/config';
    }
}
