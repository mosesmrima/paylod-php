<?php

declare(strict_types=1);

namespace Paylod\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Paylod\Paylod;

/**
 * Laravel service provider - binds a singleton Paylod client, publishes the config, and (via
 * package auto-discovery in composer.json) needs no manual registration.
 *
 * The client is built from `config('paylod.*')`, which reads the PAYLOD_* environment variables.
 */
final class PaylodServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'paylod');

        $this->app->singleton(Paylod::class, static function (Container $app): Paylod {
            /** @var array<string,mixed> $config */
            $config = $app->make('config')->get('paylod', []);

            $options = [
                'baseUrl' => $config['base_url'] ?? Paylod::DEFAULT_BASE_URL,
                'timeoutMs' => (int) ($config['timeout_ms'] ?? 30000),
                'maxRetries' => (int) ($config['max_retries'] ?? 2),
                'simulate' => (bool) ($config['simulate'] ?? false),
            ];
            if (!empty($config['webhook_secret'])) {
                $options['webhookSecret'] = $config['webhook_secret'];
            }

            return new Paylod($config['api_key'] ?? null, $options);
        });

        // Convenience alias so both `app('paylod')` and the facade resolve the same instance.
        $this->app->alias(Paylod::class, 'paylod');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configPath() => $this->app->basePath('config/paylod.php'),
            ], 'paylod-config');
        }
    }

    /**
     * @return array<int,string>
     */
    public function provides(): array
    {
        return [Paylod::class, 'paylod'];
    }

    private function configPath(): string
    {
        return __DIR__ . '/../../config/paylod.php';
    }
}
