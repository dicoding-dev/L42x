<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\FrameGuard;
use Illuminate\Support\ServiceProvider;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

class FoundationApplicationTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testSetLocaleSetsLocaleAndFiresLocaleChangedEvent()
    {
        $app = new Application;
        $app['config'] = $config = m::mock('StdClass');
        $config->shouldReceive('set')->once()->with('app.locale', 'foo');
		$app['translator'] = $trans = m::mock('StdClass');
		$trans->shouldReceive('setLocale')->once()->with('foo');
		$app['events'] = $events = m::mock('StdClass');
		$events->shouldReceive('fire')->once()->with('locale.changed', ['foo']);

		$app->setLocale('foo');
	}


	public function testServiceProvidersAreCorrectlyRegistered()
	{
		$provider = m::mock(ServiceProvider::class);
		$class = get_class($provider);
		$provider->shouldReceive('register')->once();
		$app = new Application;
		$app->register($provider);

		$this->assertArrayHasKey($class, $app->getLoadedProviders());
	}


	public function testForgetMiddleware()
	{
		$app = new ApplicationGetMiddlewaresStub;
		$app->middleware(FrameGuard::class);
		$app->forgetMiddleware(FrameGuard::class);
		$this->assertCount(0, $app->getMiddlewares());
	}


	public function testDeferredServicesMarkedAsBound()
	{
		$app = new Application;
		$app->setDeferredServices(['foo' => 'ApplicationDeferredServiceProviderStub']);
		$this->assertTrue($app->bound('foo'));
		$this->assertEquals('foo', $app->make('foo'));
	}


	public function testDeferredServicesAreSharedProperly()
	{
		$app = new Application;
		$app->setDeferredServices(['foo' => 'ApplicationDeferredSharedServiceProviderStub']);
		$this->assertTrue($app->bound('foo'));
		$one = $app->make('foo'); $two = $app->make('foo');
		$this->assertInstanceOf('StdClass', $one);
		$this->assertInstanceOf('StdClass', $two);
		$this->assertSame($one, $two);
	}


	public function testDeferredServicesCanBeExtended()
	{
		$app = new Application;
		$app->setDeferredServices(['foo' => 'ApplicationDeferredServiceProviderStub']);
		$app->extend('foo', function($instance, $container) { return $instance.'bar'; });
		$this->assertEquals('foobar', $app->make('foo'));
	}


	public function testDeferredServiceProviderIsRegisteredOnlyOnce()
	{
		$app = new Application;
		$app->setDeferredServices(['foo' => 'ApplicationDeferredServiceProviderCountStub']);
		$obj = $app->make('foo');
		$this->assertInstanceOf('StdClass', $obj);
		$this->assertSame($obj, $app->make('foo'));
		$this->assertEquals(1, ApplicationDeferredServiceProviderCountStub::$count);
	}


	public function testDeferredServicesAreLazilyInitialized()
	{
		ApplicationDeferredServiceProviderStub::$initialized = false;
		$app = new Application;
		$app->setDeferredServices(['foo' => 'ApplicationDeferredServiceProviderStub']);
		$this->assertTrue($app->bound('foo'));
		$this->assertFalse(ApplicationDeferredServiceProviderStub::$initialized);
		$app->extend('foo', function($instance, $container) { return $instance.'bar'; });
		$this->assertTrue(ApplicationDeferredServiceProviderStub::$initialized);
		$this->assertEquals('foobar', $app->make('foo'));
		$this->assertTrue(ApplicationDeferredServiceProviderStub::$initialized);
	}


	public function testDeferredServicesCanRegisterFactories()
	{
		$app = new Application;
		$app->setDeferredServices(['foo' => 'ApplicationFactoryProviderStub']);
		$this->assertTrue($app->bound('foo'));
		$this->assertEquals(1, $app->make('foo'));
		$this->assertEquals(2, $app->make('foo'));
		$this->assertEquals(3, $app->make('foo'));
	}


	public function testSingleProviderCanProvideMultipleDeferredServices()
	{
		$app = new Application;
		$app->setDeferredServices([
			'foo' => 'ApplicationMultiProviderStub',
			'bar' => 'ApplicationMultiProviderStub',
        ]);
		$this->assertEquals('foo', $app->make('foo'));
		$this->assertEquals('foobar', $app->make('bar'));
	}


	public function testHandleRespectsCatchArgument()
	{
		$this->expectException('Exception');
		$app = new Application;
		$app['router'] = $router = m::mock('StdClass');
		$router->shouldReceive('dispatch')->andThrow('Exception');
		$app['env'] = 'temporarilynottesting';
		$app->handle(
			new Symfony\Component\HttpFoundation\Request(),
			Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST,
			false
		);
	}


    public function testEnvironment()
    {
        $app = new Application;
        $app['env'] = 'foo';

        $this->assertSame('foo', $app->environment());

        $this->assertTrue($app->environment('foo'));
        $this->assertTrue($app->environment('foo', 'bar'));
        $this->assertTrue($app->environment(['foo', 'bar']));

        $this->assertFalse($app->environment('qux'));
        $this->assertFalse($app->environment('qux', 'bar'));
        $this->assertFalse($app->environment(['qux', 'bar']));
    }

	public function testCloneSelfReferenceAppKey()
	{
		$base = new Application;
		$base->instance('app', $base);

		$clone = clone $base;

		$this->assertSame($clone, $clone['app'],
			'clone[\'app\'] must resolve to the clone, not the base');
		$this->assertSame($base, $base['app'],
			'base[\'app\'] must still resolve to the base after cloning');
		$this->assertNotSame($base, $clone['app'],
			'clone[\'app\'] must not point at the base app');
	}

	public function testCloneSelfReferenceContainerKey()
	{
		$base = new Application;
		$base->instance('Illuminate\Container\Container', $base);

		$clone = clone $base;

		$this->assertSame($clone, $clone['Illuminate\Container\Container'],
			'clone[Container] must resolve to the clone');
		$this->assertSame($base, $base['Illuminate\Container\Container'],
			'base[Container] must still resolve to the base after cloning');
	}

	public function testCloneDoesNotFatalOnUninitializedTags()
	{
		// An Application that has never called tag() has an uninitialized
		// $tags typed property (Container.php:108).  Cloning must not read
		// or write it, otherwise PHP 8.3 throws a fatal.
		$base = new Application;
		// Do NOT call $base->tag() - leave $tags uninitialized.

		$exception = null;
		try {
			$clone = clone $base;
		} catch (\Throwable $e) {
			$exception = $e;
		}

		$this->assertNull($exception,
			'clone $app must not throw when $tags has never been initialized; got: '
			. ($exception ? $exception->getMessage() : ''));
	}

	public function testHandleOctaneRequestRunsStackAndReturnsUnsentResponse()
	{
		$app = $this->newOctaneApplication($jar);
		$expected = new Response('octane');
		$app['router']->shouldReceive('dispatch')->once()->andReturn($expected);

		$jar->queue($jar->make('octane_probe', 'v'));

		$response = $app->handleOctaneRequest(SymfonyRequest::create('/octane', 'GET'));

		$this->assertInstanceOf(Response::class, $response);
		$this->assertSame($expected, $response);
		$this->assertTrue($this->responseHasCookie($response, 'octane_probe'));
	}

	public function testBareHandleDoesNotRunQueuedCookieStack()
	{
		$app = $this->newOctaneApplication($jar);
		$expected = new Response('bare');
		$app['router']->shouldReceive('dispatch')->once()->andReturn($expected);

		$jar->queue($jar->make('octane_probe', 'v'));

		$response = $app->handle(SymfonyRequest::create('/octane', 'GET'));

		$this->assertSame($expected, $response);
		$this->assertFalse($this->responseHasCookie($response, 'octane_probe'));
	}

	public function testRunningInOctaneDefaultsFalseAndClonesByValue()
	{
		$app = new Application;

		$this->assertFalse($app->runningInOctane());

		$cloneBefore = clone $app;

		$this->assertSame($app, $app->setRunningInOctane());
		$this->assertTrue($app->runningInOctane());
		$this->assertFalse($cloneBefore->runningInOctane());

		$cloneAfter = clone $app;
		$this->assertTrue($cloneAfter->runningInOctane());

		$app->setRunningInOctane(false);
		$this->assertFalse($app->runningInOctane());
		$this->assertTrue($cloneAfter->runningInOctane());
	}

	private function newOctaneApplication(&$jar)
	{
		$app = new Application;
		$jar = new Illuminate\Cookie\CookieJar;

		$app['env'] = 'temporarilynottesting';
		$app['config'] = array(
			'app.manifest' => sys_get_temp_dir(),
			'session.driver' => null,
			'session' => array(
				'driver' => null,
				'cookie' => 'laravel_session',
				'lottery' => array(0, 100),
				'path' => '/',
				'domain' => null,
				'lifetime' => 120,
				'expire_on_close' => false,
			),
		);
		$app['encrypter'] = new Illuminate\Encryption\Encrypter(str_repeat('a', 32));
		$app['cookie'] = $jar;
		$app['session'] = new Illuminate\Session\SessionManager($app);
		$app['router'] = m::mock('StdClass');

		return $app;
	}

	private function responseHasCookie(Response $response, $name)
	{
		foreach ($response->headers->getCookies() as $cookie)
		{
			if ($cookie->getName() === $name)
			{
				return true;
			}
		}

		return false;
	}
}

class ApplicationCustomExceptionHandlerStub extends Illuminate\Foundation\Application {

	#[\Override]
    public function prepareResponse($value)
	{
		$response = m::mock(Response::class);
		$response->shouldReceive('send')->once();
		return $response;
	}

	protected function setExceptionHandler(Closure $handler) { return $handler; }

}

class ApplicationKernelExceptionHandlerStub extends Illuminate\Foundation\Application {

	protected function setExceptionHandler(Closure $handler) { return $handler; }

}

class ApplicationGetMiddlewaresStub extends Illuminate\Foundation\Application
{
	public function getMiddlewares()
	{
		return $this->middlewares;
	}
}

class ApplicationDeferredSharedServiceProviderStub extends Illuminate\Support\ServiceProvider {
	protected $defer = true;
	public function register()
	{
		$this->app->bindShared('foo', function() {
			return new StdClass;
		});
	}
}

class ApplicationDeferredServiceProviderCountStub extends Illuminate\Support\ServiceProvider {
	public static $count = 0;
	protected $defer = true;
	public function register()
	{
		static::$count++;
		$this->app['foo'] = new StdClass;
	}
}

class ApplicationDeferredServiceProviderStub extends Illuminate\Support\ServiceProvider {
	public static $initialized = false;
	protected $defer = true;
	public function register()
	{
		static::$initialized = true;
		$this->app['foo'] = 'foo';
	}
}

class ApplicationFactoryProviderStub extends Illuminate\Support\ServiceProvider {
	protected $defer = true;
	public function register()
	{
		$this->app->bind('foo', function() {
			static $count = 0;
			return ++$count;
		});
	}
}

class ApplicationMultiProviderStub extends Illuminate\Support\ServiceProvider {
	protected $defer = true;
	public function register()
	{
		$this->app->bindShared('foo', function() { return 'foo'; });
		$this->app->bindShared('bar', function($app) { return $app['foo'].'bar'; });
	}
}
