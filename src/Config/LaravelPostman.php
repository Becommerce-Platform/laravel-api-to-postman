<?php

declare(strict_types=1);

namespace Becommerce\PostmanGenerator\Config;

use Illuminate\Config\Repository;

class LaravelPostman extends Repository
{
    public function __construct()
    {
        parent::__construct(config('postman'));
    }
    
    public function baseUrl(): string
    {
        return $this->get('base_url', 'http://localhost');
    }
    
    public function filenameTemplate(): string
    {
        return $this->get('filename_template', '{timestamp}_{app}_collection.json');
    }
    
    public function usePrefix(): bool
    {
        return $this->get('use_prefix', false);
    }
    
    public function authenticationMiddleware(): string
    {
        return $this->get('authentication_middleware', 'auth:api');
    }
    
    public function headers(): array
    {
        $default = [
            [
                'key' => 'Accept',
                'value' => 'application/json',
            ],
            [
                'key' => 'Content-Type',
                'value' => 'application/json',
            ],
        ];
        
        return $this->get('headers', $default);
    }
    
    public function addHeader(array $header): void
    {
        $headers = array_merge($this->headers(), $header);
        
        $this->set('headers', $headers);
    }
    
    public function formDataEnabled(): bool
    {
        return $this->get('form_data_enabled', false);
    }
    
    public function printRulesEnabled(): bool
    {
        return $this->get('print_rules_enabled', false);
    }
    
    public function rulesToHumanReadableEnabled(): bool
    {
        return $this->get('rules_to_human_readable_enabled', false);
    }
    
    public function includeMiddleware(): array
    {
        return $this->get('include_middleware', ['api']);
    }
    
    public function storageDisk(): string
    {
        return $this->get('disk', 'local');
    }
    
    public function getFormDataRule(string $name): string
    {
        return $this->get(sprintf('form_data.%s', $name));
    }
}
