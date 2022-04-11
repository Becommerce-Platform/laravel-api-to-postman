<?php

declare(strict_types=1);

namespace Becommerce\PostmanGenerator\Traits;

use Becommerce\PostmanGenerator\Enums\AuthType;

trait HasAuthHeader
{
    private ?string $token;

    private AuthType $authType;

    protected function setAuthToken(): void
    {
        if ($this->option(AuthType::BASIC->value)) {
            $this->token = $this->option(AuthType::BASIC->value);
            $this->authType = AuthType::BASIC;
        }

        if (!$this->option(AuthType::BEARER->value)) {
            return;
        }

        $this->token = $this->option(AuthType::BEARER->value);
        $this->authType = AuthType::BEARER;
    }

    /**
     * @return array<string, string>
     */
    private function getTokenArray(): array
    {
        return [
            'key' => 'token',
            'value' => $this->token,
        ];
    }
    
    private function usingAuthentication(): bool
    {
        return isset($this->token) && $this->token;
    }
}
