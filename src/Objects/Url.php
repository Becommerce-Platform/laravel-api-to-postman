<?php

declare(strict_types=1);

namespace Becommerce\PostmanGenerator\Objects;

use Illuminate\Contracts\Support\Arrayable;

class Url implements Arrayable
{
    private string $raw;
    private array $host;
    private array $path;
    private array $variable;

    /**
     * @param string $raw
     * @return Url
     */
    public function setRaw(string $raw): Url
    {
        $this->raw = $raw;
        return $this;
    }

    /**
     * @param array $host
     * @return Url
     */
    public function setHost(array $host): Url
    {
        $this->host = $host;
        return $this;
    }

    /**
     * @param array $path
     * @return Url
     */
    public function setPath(array $path): Url
    {
        $this->path = $path;
        return $this;
    }

    /**
     * @param array $variable
     * @return Url
     */
    public function setVariable(array $variable): Url
    {
        $this->variable = $variable;
        return $this;
    }
    
    public function toArray()
    {
        return [
            'raw' => $this->raw ?? null,
            'host' => $this->host ?? null,
            'path' => $this->path ?? null,
            'variable' => $this->variable ?? null,
        ];
    }
}
