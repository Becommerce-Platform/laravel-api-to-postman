<?php

declare(strict_types=1);

namespace Becommerce\PostmanGenerator\Commands;

use Becommerce\PostmanGenerator\Config\LaravelPostman;
use Becommerce\PostmanGenerator\Enums\AuthType;
use Becommerce\PostmanGenerator\Objects\Postman;
use Becommerce\PostmanGenerator\Objects\PostmanRoute;
use Becommerce\PostmanGenerator\Objects\RuleData;
use Becommerce\PostmanGenerator\Traits\HasAuthHeader;
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
    use HasAuthHeader;
    
    protected $signature = 'postman:generate
                            {--bearer= : The bearer token to use on your endpoints}
                            {--basic= : The basic auth to use on your endpoints}';
    
    protected $description = 'Automatically generate a Postman collection for your API routes';

    /**
     * The folder structure for postman.
     * 
     * @var array<string, mixed>
     */
    protected array $structure;
    
    protected ?string $filename;

    public function __construct(protected Router $router, protected LaravelPostman $config, protected Postman $postman)
    {
        parent::__construct();
    }

    /**
     * @throws ReflectionException
     * @throws JsonException
     */
    public function handle(): void
    {
        $this->setAuthToken();
        
        if ($this->usingAuthentication()) {
            $this->postman->addVariable($this->getTokenArray());
        }
        
        $routes = $this->router->getRoutes();
        
        assert($routes instanceof RouteCollection);
        
        foreach ($routes as $route) {
            if (!Str::startsWith($route->getPrefix(), ['v', 'oauth'])) {
                continue;
            }
            $postmanRoute = new PostmanRoute($route, $this->config, $this->usingAuthentication());
            $this->postman->addItem($postmanRoute->toArray());
        }
        
        $this->info(sprintf('Postman Collection Exported: %s', $this->postman->exportFile()));
    }
}
