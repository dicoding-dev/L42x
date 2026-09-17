<?php

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class RoutingRouterOctaneSetContainerTest extends BackwardCompatibleTestCase
{
	protected function tearDown(): void
	{
		m::close();
	}

	public function testSetContainerReplacesContainerAndReturnsThis()
	{
		$router = $this->makeRouter();
		$other = new Container();

		$result = $router->setContainer($other);

		$this->assertSame($result, $router);
		$this->assertSame($other, $this->getPrivate($router, 'container'));
	}

	public function testSetContainerNullsControllerDispatcherCache()
	{
		$router = $this->makeRouter();

		$router->getControllerDispatcher();
		$this->assertNotNull($this->getPrivate($router, 'controllerDispatcher'));

		$other = new Container();
		$router->setContainer($other);

		$this->assertNull($this->getPrivate($router, 'controllerDispatcher'));
	}

	public function testDispatcherRebuildsFromNewContainerAfterSetContainer()
	{
		$router = $this->makeRouter();

		$base = $this->getPrivate($router, 'container');
		$router->getControllerDispatcher();

		$sandbox = new Container();
		$router->setContainer($sandbox);

		$rebuilt = $router->getControllerDispatcher();

		$this->assertSame($sandbox, $this->getPrivate($rebuilt, 'container'));
		$this->assertNotSame($base, $this->getPrivate($rebuilt, 'container'));
	}

	private function makeRouter(): Router
	{
		$container = new Container();
		$events = new Dispatcher($container);

		return new Router($events, $container);
	}

	private function getPrivate(object $object, string $property)
	{
		$reflection = new \ReflectionProperty($object, $property);
		$reflection->setAccessible(true);

		return $reflection->getValue($object);
	}
}
