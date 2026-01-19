<?php

namespace HardImpact\Orbit\Http\Integrations\Orbit\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class AddWorkspaceProjectRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $workspace,
        protected string $site,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/workspaces/{$this->workspace}/sites";
    }

    protected function defaultBody(): array
    {
        return [
            'site' => $this->site,
        ];
    }
}
