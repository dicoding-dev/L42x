<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\FrameGuard;
use Illuminate\Support\ServiceProvider;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;
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
}

class ApplicationCustomExceptionHandlerStub extends Illuminate\Foundation\Application {

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
