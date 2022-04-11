<?php

declare(strict_types=1);

namespace Becommerce\PostmanGenerator\Objects;

use Becommerce\PostmanGenerator\Config\LaravelPostman;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Postman implements Arrayable
{
    private array $info;
    private array $variable = [];
    private array $item = [];
    
    public function __construct(private LaravelPostman $config)
    {
        $this->info = [
            'name' => $this->getFilename(),
            'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
        ];

        $this->addVariable(
            [
                'key' => 'base_url',
                'value' => $this->config['base_url'],
            ]
        );
    }
    
    public function addVariable(array $variable): void
    {
        $this->variable[] = $variable;
    }
    
    public function addItem(array $item): Postman
    {
        $this->item[] = $item;
        
        return $this;
    }

    private function getFilename(): string
    {
        return str_replace(
            ['{timestamp}', '{app}'],
            [date('Y_m_d_His'), Str::snake(config('app.name'))],
            $this->config->filenameTemplate(),
        );
    }

    /**
     * @throws JsonException
     */
    public function exportFile(): string
    {
        $filename = sprintf('postman/%s', $this->getFilename());
        $storagePath = storage_path(sprintf('app/%s', $filename));

        Storage::disk($this->config['disk'])->put($filename, json_encode($this->toArray(), JSON_THROW_ON_ERROR));
        
        return $storagePath;
    }
    
    public function toArray(): array
    {
        $items = collect($this->item);
        $names = $items->pluck('name')->unique()->toArray();
        
        $gathered = [];
        
        foreach ($names as $name) {
            $gathered[] = [
                'name' => $name,
                'item' => $items->where('name', $name)->pluck('item')->flatten(1),
            ];
        }
   
        return [
            'info' => $this->info,
            'variable' => $this->variable,
            'item' => $gathered,
        ];
    }
}
