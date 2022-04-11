<?php

declare(strict_types=1);

namespace Becommerce\PostmanGenerator\Objects;

use Becommerce\PostmanGenerator\Config\LaravelPostman;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use Closure;
use ReflectionFunction;

class PostmanRoute implements Arrayable
{
    private string $name;

    /**
     * @var array<int,Request>|null
     */
    private ?array $methods;
    
    public function __construct(private Route $route, private LaravelPostman $config, private bool $usingAuthentication)
    {
        $this->setName();
        $this->processMethods();
        $this->processMiddleware();
        $this->processRules();
    }
    
    private function setName(): void
    {
        $this->name = $this->route->uri();
    }
    
    private function processMethods(): void
    {
        foreach ($this->route->methods as $method) {
            $this->methods[] = app(Request::class)
                ->setMethod($method)
                ->setUri($this->route->uri());
        }
    }
    
    private function processMiddleware()
    {
        $middleware = $this->route->gatherMiddleware();
        
        if (!count($middleware)) {
            return;
        }
        
        foreach ($this->methods as $method) {
            if (!count(array_intersect($middleware, $this->config->includeMiddleware()))) {
                continue;
            }

            $requestRules = [];
            $routeAction = $this->route->getAction();

            $reflectionMethod = $this->getReflectionMethod($routeAction);

            if (! $reflectionMethod) {
                continue;
            }

            if ($this->config->formDataEnabled()) {
                $requestRules = $this->getRequestRules($reflectionMethod);
            }

            $routeHeaders = $this->config->headers();

            if ($this->usingAuthentication && in_array($this->config->authenticationMiddleware(), $middleware, true)) {
                $routeHeaders[] = [
                    'key' => 'Authorization',
                    'value' => sprintf('%s {{token}}', $this->authType->value),
                ];
            }
            
            $method->setHeader($routeHeaders);
        }
    }
    
    private function processRules()
    {
        foreach ($this->methods as $method) {
            $requestRules = [];
            $routeAction = $this->route->getAction();

            $reflectionMethod = $this->getReflectionMethod($routeAction);

            if (! $reflectionMethod) {
                continue;
            }

            if ($this->config->formDataEnabled()) {
                $requestRules = $this->getRequestRules($reflectionMethod);
            }
            
            foreach ($requestRules as $rule) {
                $ruleData[] = app(RuleData::class, ['rule' => $rule])->toArray();
                $method->setBody($ruleData);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRequestRules(object $reflectionMethod): array
    {
        $requestRules = [];

        $rulesParameter = collect($reflectionMethod->getParameters())
            ->filter(static function ($value) {
                $value = $value->getType();

                return $value && is_subclass_of($value->getName(), FormRequest::class);
            })
            ->first();

        if ($rulesParameter) {
            $rulesParameter = $rulesParameter->getType()->getName();
            $rulesParameter = new $rulesParameter();
            $rules = method_exists($rulesParameter, 'rules')
                ? $rulesParameter->rules()
                : [];

            foreach ($rules as $fieldName => $rule) {
                if (is_string($rule)) {
                    $rule = preg_split('/\s*\|\s*/', $rule);
                }

                $printRules = $this->config['print_rules'];

                $requestRules[] = [
                    'name' => $fieldName,
                    'description' => $printRules ? $rule : '',
                ];

                if (!is_array($rule) || !in_array('confirmed', $rule, true)) {
                    continue;
                }

                $requestRules[] = [
                    'name' => $fieldName.'_confirmation',
                    'description' => $printRules ? $rule : '',
                ];
            }
        }

        return $requestRules;
    }

    /**
     * @param array<int, mixed> $routeAction
     *
     * @throws ReflectionException
     */
    protected function getReflectionMethod(array $routeAction): ?object
    {
        if (self::containsSerializedClosure($routeAction)) {
            $routeAction['uses'] = unserialize($routeAction['uses'], ['allowed_classes' => true])->getClosure();
        }

        if ($routeAction['uses'] instanceof Closure) {
            return new ReflectionFunction($routeAction['uses']);
        }

        $routeData = explode('@', $routeAction['uses']);
        $reflection = new ReflectionClass($routeData[0]);

        if (! $reflection->hasMethod($routeData[1])) {
            return null;
        }

        return $reflection->getMethod($routeData[1]);
    }

    /**
     * @param array<int, mixed> $action
     */
    public static function containsSerializedClosure(array $action): bool
    {
        return is_string($action['uses']) &&
            Str::startsWith($action['uses'], 'C:32:"Opis\\Closure\\SerializableClosure') !== false;
    }
    
    public function toArray(): array
    {
        $items = [];
        $folder = null;

        if ($this->config->usePrefix()) {
            $prefix = $this->route->getPrefix();
            $uriParts = explode('/', $this->route->uri());
            
            foreach ($uriParts as $key => $part) {
                if ($part === $prefix) {
                    unset($uriParts[$key]);
                }
            }
            
            $folder = reset($uriParts);
            $folder = ucfirst($folder);
        }

        if ($folder) {
            $items = [
                'name' => $folder,
                'item' => [],
            ];
        }
        
        foreach ($this->methods as $method) {
            if ($folder) {
                $items['item'][] = [
                    'name' => $this->name,
                    'request' => $method->toArray(),
                ];
                
                continue;
            }
            
            $items[] = [
                'name' => $this->name,
                'request' => $method->toArray(),
            ];
        }
        
        return $items;
    }
}
