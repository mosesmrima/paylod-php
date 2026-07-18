<?php

declare(strict_types=1);

namespace Paylod\Tests;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Paylod\Laravel\Facades\Paylod as PaylodFacade;
use Paylod\Laravel\PaylodServiceProvider;
use Paylod\Paylod;
use PHPUnit\Framework\TestCase;

/**
 * Boots the service provider against a minimal Illuminate container - no full framework, no
 * Testbench - to prove the wiring: singleton binding, config merge, and the facade accessor.
 */
final class LaravelServiceProviderTest extends TestCase
{
    private function bootContainer(array $config = []): Container
    {
        $app = new Container();
        $app->instance('config', new ConfigRepository(['paylod' => array_merge([
            'api_key' => 'mp_test_laravel',
            'base_url' => Paylod::DEFAULT_BASE_URL,
            'webhook_secret' => 'whsec_laravel',
            'timeout_ms' => 30000,
            'max_retries' => 2,
            'simulate' => false,
        ], $config)]));

        // runningInConsole() is called in boot(); the minimal container needs it stubbed off.
        $app->instance('path.base', sys_get_temp_dir());

        $provider = new PaylodServiceProvider($app);
        $provider->register();

        return $app;
    }

    public function testBindsPaylodSingleton(): void
    {
        $app = $this->bootContainer();

        $a = $app->make(Paylod::class);
        $b = $app->make(Paylod::class);
        $this->assertInstanceOf(Paylod::class, $a);
        $this->assertSame($a, $b, 'the client must be a singleton');
    }

    public function testResolvesViaAlias(): void
    {
        $app = $this->bootContainer();
        $this->assertInstanceOf(Paylod::class, $app->make('paylod'));
        $this->assertSame($app->make(Paylod::class), $app->make('paylod'));
    }

    public function testFacadeResolvesToTheBoundClient(): void
    {
        $app = $this->bootContainer();
        Container::setInstance($app);
        PaylodFacade::clearResolvedInstances();
        PaylodFacade::setFacadeApplication($app);

        $client = PaylodFacade::getFacadeRoot();
        $this->assertInstanceOf(Paylod::class, $client);
        $this->assertSame($app->make(Paylod::class), $client);
    }

    public function testProvidesListsTheBindings(): void
    {
        $app = new Container();
        $provider = new PaylodServiceProvider($app);
        $this->assertContains(Paylod::class, $provider->provides());
        $this->assertContains('paylod', $provider->provides());
    }

    // -- THE REAL CONFIG PATH ------------------------------------------------------
    //
    // Every test above injects a config Repository directly, so the SHIPPED config/paylod.php was
    // never executed by anything - and that file was where the protection was being silenced. It
    // cast with `(int) env(...)`, so `PAYLOD_TIMEOUT_MS=1.5` reached the provider as a well-formed
    // `1` and the provider's lexical check had nothing left to object to. The operator's value was
    // neither honoured nor rejected. The tests could not see it because they never loaded the file.

    /** Load the SHIPPED config file, with the environment actually set, exactly as Laravel does. */
    private function loadShippedConfig(array $env): array
    {
        foreach ($env as $k => $v) {
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
            putenv("{$k}={$v}");
        }

        try {
            /** @var array<string,mixed> $config */
            $config = require dirname(__DIR__) . '/config/paylod.php';

            return $config;
        } finally {
            foreach (array_keys($env) as $k) {
                unset($_ENV[$k], $_SERVER[$k]);
                putenv($k);
            }
        }
    }

    /**
     * A fractional timeout written in the environment must be REFUSED, not silently truncated.
     * `(int) 0.5` is `0`, and `0` disables cURL's timeout entirely - a hung request then never
     * returns and a wait() never settles.
     *
     * @dataProvider fractionalEnvValues
     */
    public function testAFractionalEnvValueSurvivesTheConfigFileAndIsRefused(string $var, string $value): void
    {
        $config = $this->loadShippedConfig([$var => $value]);
        $key = $var === 'PAYLOD_TIMEOUT_MS' ? 'timeout_ms' : 'max_retries';

        // THE POINT: the config file hands the value on in the form the operator wrote it, so the
        // provider's lexical check still has something to check.
        $this->assertSame($value, (string) $config[$key]);
        $this->assertIsNotInt($config[$key], 'the config file must not pre-cast the value');

        $app = $this->bootContainer([$key => $config[$key]]);

        $this->expectException(\Paylod\Exceptions\PaylodConfigError::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($var, '/') . '/');
        $app->make(Paylod::class);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function fractionalEnvValues(): array
    {
        return [
            'fractional timeout' => ['PAYLOD_TIMEOUT_MS', '1.5'],
            'sub-millisecond timeout' => ['PAYLOD_TIMEOUT_MS', '0.5'],
            'exponent timeout' => ['PAYLOD_TIMEOUT_MS', '1e3'],
            'suffixed timeout' => ['PAYLOD_TIMEOUT_MS', '30s'],
            'fractional retries' => ['PAYLOD_MAX_RETRIES', '2.5'],
        ];
    }

    /** A well-formed environment still works all the way through the real file. */
    public function testWholeNumberEnvValuesLoadThroughTheShippedConfig(): void
    {
        $config = $this->loadShippedConfig([
            'PAYLOD_TIMEOUT_MS' => '5000',
            'PAYLOD_MAX_RETRIES' => '1',
        ]);

        $app = $this->bootContainer([
            'timeout_ms' => $config['timeout_ms'],
            'max_retries' => $config['max_retries'],
        ]);
        $this->assertInstanceOf(Paylod::class, $app->make(Paylod::class));
    }

    /** The defaults still resolve when nothing is set in the environment. */
    public function testTheShippedConfigDefaultsAreWholeNumbers(): void
    {
        $config = $this->loadShippedConfig([]);

        $this->assertSame(30000, $config['timeout_ms']);
        $this->assertSame(2, $config['max_retries']);
    }
}
