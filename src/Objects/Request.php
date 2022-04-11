<?php

declare(strict_types=1);

namespace Becommerce\PostmanGenerator\Objects;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;

class Request implements Arrayable
{
    private string $method;
    
    private array $header;
    
    private Url $url;
    
    private Stringable $uri;
    
    private Collection $variables;
    
    private array $body;
    
    public function setUri(string $uri): Request
    {
        $this->uri = Str::of($uri)->replaceMatches('/{([[:alnum:]]+)}/', ':$1');
        $this->variables = $this->uri->matchAll('/(?<={)[[:alnum:]]+(?=})/m');
        $this->setUrl();
        
        return $this;
    }
    
    public function setMethod(string $method): Request
    {
        $this->method = $method;
        
        return $this;
    }
    
    public function setHeader(array $header): Request
    {
        $this->header = $header;
        
        return $this;
    }
    
    private function setUrl(): Request
    {
        $this->url = app(Url::class)
            ->setRaw(sprintf('{{base_url}}/%s', $this->uri))
            ->setHost(['{{base_url}}'])
            ->setPath($this->uri->explode('/')->filter()->toArray())
            ->setVariable($this->variables->transform(static function ($variable) {
                return ['key' => $variable, 'value' => ''];
            })->all());
        
        return $this;
    }

    /**
     * @param array $body
     * @return Request
     */
    public function setBody(array $body): Request
    {
        $this->body = [
            'mode' => 'urlencoded',
            'urlencoded' => $ruleData,
        ];
        
        return $this;
    }
    
    

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method ?? null,
            'header' => $this->header ?? null,
            'url' => $this->url->toArray() ?? null,
        ];
    }
}
