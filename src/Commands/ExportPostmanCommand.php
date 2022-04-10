<?php

declare(strict_types=1);

namespace Becommerce\PostmanGenerator\Commands;

use Becommerce\PostmanGenerator\Enums\AuthType;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationRuleParser;
use JsonException;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;

use function is_object;
use function is_subclass_of;
use function method_exists;
use function str_replace;
use function is_string;
use function is_array;
use function implode;
use function strtoupper;
use function count;
use function array_intersect;
use function in_array;
use function sprintf;
use function explode;
use function array_filter;
use function preg_split;
use function date;
use function assert;
use function json_encode;
use function unserialize;
use function end;

use const JSON_THROW_ON_ERROR;

class ExportPostmanCommand extends Command
{
    protected $signature = 'export:postman
                            {--bearer= : The bearer token to use on your endpoints}
                            {--basic= : The basic auth to use on your endpoints}';
    
    protected $description = 'Automatically generate a Postman collection for your API routes';

    /**
     * The folder structure for postman.
     * 
     * @var array<string, mixed>
     */
    protected array $structure;

    /**
     * Our configuration variables.
     * 
     * @var array<string, mixed>
     */
    protected array $config;
    
    protected ?string $filename;

    private string $token;

    private AuthType $authType;

    public function __construct(protected Router $router, Repository $config)
    {
        parent::__construct();

        $this->config = $config['api-postman'];
    }

    /**
     * @param array<int, mixed> $action
     */
    public static function containsSerializedClosure(array $action): bool
    {
        return is_string($action['uses']) &&
            Str::startsWith($action['uses'], 'C:32:"Opis\\Closure\\SerializableClosure') !== false;
    }

    /**
     * @throws ReflectionException
     * @throws JsonException
     */
    public function handle(): void
    {
        $this->setFilename();
        $this->setAuthToken();
        $this->initializeStructure();
        
        $routes = $this->router->getRoutes();
        
        assert($routes instanceof RouteCollection);
        
        foreach ($routes as $route) {
            $middlewares = $route->gatherMiddleware();
            
            if (!count($middlewares)) {
                continue;
            }

            foreach ($route->methods as $method) {
                if (!count(array_intersect($middlewares, $this->config['include_middleware']))) {
                    continue;
                }

                $requestRules = [];

                $routeAction = $route->getAction();

                $reflectionMethod = $this->getReflectionMethod($routeAction);

                if (! $reflectionMethod) {
                    continue;
                }

                if ($this->config['enable_form_data']) {
                    $requestRules = $this->getRequestRules($reflectionMethod);
                }

                $routeHeaders = $this->config['headers'];

                if ($this->token && in_array($this->config['auth_middleware'], $middlewares, true)) {
                    $routeHeaders[] = [
                        'key' => 'Authorization',
                        'value' => sprintf('%s {{token}}', $this->authType->value),
                    ];
                }

                $request = $this->makeRequest($route, $method, $routeHeaders, $requestRules);

                if ($this->isStructured()) {
                    $routeNames = $route->action['as'] ?? null;

                    if (! $routeNames) {
                        $routeUri = explode('/', $route->uri());
                        // remove "api" from the start
                        unset($routeUri[0]);
                        $routeNames = implode('.', $routeUri);
                    }

                    $routeNames = explode('.', $routeNames);
                    $routeNames = array_filter($routeNames);

                    $this->buildTree($this->structure, $routeNames, $request);
                } else {
                    $this->structure['item'][] = $request;
                }
            }
        }

        $this->exportFile();
    }

    /**
     * @param array<string, mixed> $routeHeaders
     * @param array<int, array<string, mixed>> $requestRules
     * 
     * @return array<string, mixed>
     */
    public function makeRequest(Route $route, string $method, array $routeHeaders, array $requestRules): array
    {
        $printRules = $this->config['print_rules'];
        $uri = Str::of($route->uri())->replaceMatches('/{([[:alnum:]]+)}/', ':$1');
        $variables = $uri->matchAll('/(?<={)[[:alnum:]]+(?=})/m');

        $data = [
            'name' => $route->uri(),
            'request' => [
                'method' => strtoupper($method),
                'header' => $routeHeaders,
                'url' => [
                    'raw' => '{{base_url}}/'.$uri,
                    'host' => ['{{base_url}}'],
                    'path' => $uri->explode('/')->filter(),
                    'variable' => $variables->transform(static function ($variable) {
                        return ['key' => $variable, 'value' => ''];
                    })->all(),
                ],
            ],
        ];

        if ($requestRules) {
            $ruleData = [];

            foreach ($requestRules as $rule) {
                $ruleData[] = [
                    'key' => $rule['name'],
                    'value' => $this->config['form_data'][$rule['name']] ?? null,
                    'type' => 'text',
                    'description' => 
                        $printRules ? $this->parseRulesIntoHumanReadable($rule['name'], $rule['description']) : '',
                ];
            }

            $data['request']['body'] = [
                'mode' => 'urlencoded',
                'urlencoded' => $ruleData,
            ];
        }

        return $data;
    }

    /**
     * Process a rule set and utilize the Validator to create human-readable descriptions
     * to help users provide valid data.
     *
     * @param mixed $attribute
     * @param mixed $rules
     */
    protected function parseRulesIntoHumanReadable($attribute, $rules): string
    {
        // ... bail if user has asked for non interpreted strings:
        if (! $this->config['rules_to_human_readable']) {
            return is_array($rules) 
                ? implode(', ', $rules) 
                : $this->safelyStringifyClassBasedRule($rules);
        }

        /*
         * An object based rule is presumably a Laravel default class based rule or one that implements to Illuminate
         * Rule interface. Let's try to safely access the string representation...
         */
        if (is_object($rules)) {
            $rules = [$this->safelyStringifyClassBasedRule($rules)];
        }

        /*
         * Handle string based rules (e.g. required|string|max:30)
         */
        if (is_array($rules)) {
            $validator = Validator::make([], [$attribute => implode('|', $rules)]);

            foreach ($rules as $rule) {
                [$rule, $parameters] = ValidationRuleParser::parse($rule);

                $validator->addFailure($attribute, $rule, $parameters);
            }

            $messages = $validator->getMessageBag()->toArray()[$attribute];

            if (is_array($messages)) {
                $messages = $this->handleEdgeCases($messages);
            }

            return implode(', ', is_array($messages) ? $messages : $messages->toArray());
        }

        // ...safely return a safe value if we encounter neither a string or object based rule set:
        return '';
    }

    protected function initializeStructure(): void
    {
        $this->structure = [
            'variable' => [
                [
                    'key' => 'base_url',
                    'value' => $this->config['base_url'],
                ],
            ],
            'info' => [
                'name' => $this->filename,
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'item' => [],
        ];

        if (!$this->token) {
            return;
        }

        $this->structure['variable'][] = [
            'key' => 'token',
            'value' => $this->token,
        ];
    }

    protected function setFilename(): void
    {
        $this->filename = str_replace(
            ['{timestamp}', '{app}'],
            [date('Y_m_d_His'), Str::snake(config('app.name'))],
            $this->config['filename'],
        );
    }

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

    protected function isStructured(): bool
    {
        return $this->config['structured'];
    }

    /**
     * Certain fields are not handled via the normal throw failure method in the validator
     * We need to add a human-readable message.
     * 
     * @param array<string, string> $messages
     * 
     * @return array<string, string>
     */
    protected function handleEdgeCases(array $messages): array
    {
        foreach ($messages as $key => $message) {
            $messages[$key] = match ($message) {
                'validation.nullable' => '(Nullable)',
                'validation.sometimes' => '(Optional)',
                default => $message,
            };
        }

        return $messages;
    }

    /**
     * In this case we have received what is most likely a Rule Object but are not certain.
     */
    protected function safelyStringifyClassBasedRule(mixed $probableRule): string
    {
        if (is_object($probableRule) 
            && (
                is_subclass_of($probableRule, Rule::class) 
                || method_exists($probableRule, '__toString'))
        ) {
            return (string) $probableRule;
        }

        return '';
    }

    /**
     * @param array<int, mixed> $routeAction
     * 
     * @throws ReflectionException
     */
    protected function getReflectionMethod(array $routeAction): ?object
    {
        // Hydrates the closure if it is an instance of Opis\Closure\SerializableClosure
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
     * @param array<string, mixed> $routes
     * @param array<int, mixed> $segments
     * @param array<string, mixed> $request
     * 
     * @return void
     */
    protected function buildTree(array &$routes, array $segments, array $request): void
    {
        $parent = &$routes;
        $destination = end($segments);

        foreach ($segments as $segment) {
            $matched = false;

            foreach ($parent['item'] as &$item) {
                if ($item['name'] === $segment) {
                    $parent = &$item;

                    if ($segment === $destination) {
                        $parent['item'][] = $request;
                    }

                    $matched = true;

                    break;
                }
            }

            unset($item);

            if (! $matched) {
                $item = [
                    'name' => $segment,
                    'item' => $segment === $destination ? [$request] : [],
                ];

                $parent['item'][] = &$item;
                $parent = &$item;
            }

            unset($item);
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
     * @throws JsonException
     */
    private function exportFile(): void
    {
        $filename = sprintf('postman/%s', $this->filename);
        $storagePath = storage_path(sprintf('app/%s', $filename));

        Storage::disk($this->config['disk'])->put($filename, json_encode($this->structure, JSON_THROW_ON_ERROR));
        $this->info(sprintf('Postman Collection Exported: %s', $storagePath));
    }
}
