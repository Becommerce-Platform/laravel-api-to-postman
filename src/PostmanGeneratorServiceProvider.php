<?php

declare(strict_types=1);

namespace Becommerce\PostmanGenerator;

use Becommerce\PostmanGenerator\Commands\ExportPostmanCommand;
use Illuminate\Support\ServiceProvider;

class PostmanGeneratorServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/postman.php' => config_path('postman.php'),
            ], 'postman-config');
        }

        $this->commands(ExportPostmanCommand::class);
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/postman.php', 'api-postman');
    }
}
