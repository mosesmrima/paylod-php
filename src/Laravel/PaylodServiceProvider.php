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
                // RAW, never pre-cast. See assertWholeNumber() below.
                'timeoutMs' => self::assertWholeNumber($config['timeout_ms'] ?? 30000, 'paylod.timeout_ms', 'PAYLOD_TIMEOUT_MS'),
                'maxRetries' => self::assertWholeNumber($config['max_retries'] ?? 2, 'paylod.max_retries', 'PAYLOD_MAX_RETRIES'),
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

    /**
     * Validate a numeric config value LEXICALLY, before any cast can destroy the evidence.
     *
     * The client itself refuses a fractional `timeoutMs`, because a fractional value truncates to
     * `0` and `0` DISABLES cURL's timeout - a hung request would then never return and a wait()
     * would never settle. That protection was being silenced right here: this provider cast with
     * `(int)` first, so `PAYLOD_TIMEOUT_MS=1.5` (or a `.env` typo, or a config cache written from a
     * float) arrived at the client as a well-formed `1`. The value the operator asked for was
     * neither honoured nor rejected - it was quietly replaced with a different one, and the guard
     * downstream never saw anything to complain about. `max_retries` had the identical problem.
     *
     * So the RAW value is inspected here, in the form the operator wrote it, and anything that is
     * not a whole number is refused by name - naming both the config key and the environment
     * variable, because the person who has to fix it is reading a `.env` file, not a stack trace.
     * A well-formed value is then passed through and the client's own bounds still apply.
     */
    private static function assertWholeNumber(mixed $value, string $configKey, string $envVar): int
    {
        // Accepted lexically: an int, an integral float, or a string of digits with an optional
        // sign. NOT accepted: "1.5", "1e3", "30s", true, null, [] - each of which (int)-casts to
        // something plausible and wrong.
        $ok = is_int($value)
            || (is_float($value) && is_finite($value) && fmod($value, 1.0) === 0.0)
            || (is_string($value) && preg_match('/^[+-]?[0-9]+\z/', trim($value)) === 1);

        if (!$ok) {
            throw new \Paylod\Exceptions\PaylodConfigError(
                "{$configKey} must be a whole number (got " . var_export($value, true) . '). Set '
                . "{$envVar} to an integer. It is refused rather than cast because casting is how "
                . 'this goes wrong silently: (int) 1.5 is 1, and for a timeout (int) 0.5 is 0 - '
                . 'which disables the timeout entirely and lets a hung request hang forever.'
            );
        }

        return (int) (is_string($value) ? trim($value) : $value);
    }

    private function configPath(): string
    {
        return __DIR__ . '/../../config/paylod.php';
    }
}
