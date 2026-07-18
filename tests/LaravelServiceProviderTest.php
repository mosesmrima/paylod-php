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
}
