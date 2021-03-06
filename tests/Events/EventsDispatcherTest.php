<?php

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class EventsDispatcherTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testBasicEventExecution()
    {
        unset($_SERVER['__event.test']);
        $d = new Dispatcher;
        $d->listen(
            'foo',
            function ($foo) {
                $_SERVER['__event.test'] = $foo;
            }
        );
		$d->fire('foo', ['bar']);
		$this->assertEquals('bar', $_SERVER['__event.test']);
	}


	public function testContainerResolutionOfEventHandlers()
	{
		$d = new Dispatcher($container = m::mock(Container::class));
		$container->shouldReceive('make')->once()->with('FooHandler')->andReturn($handler = m::mock('StdClass'));
		$handler->shouldReceive('onFooEvent')->once()->with('foo', 'bar');
		$d->listen('foo', 'FooHandler@onFooEvent');
		$d->fire('foo', ['foo', 'bar']);
	}


	public function testContainerResolutionOfEventHandlersWithDefaultMethods()
	{
		$d = new Dispatcher($container = m::mock(Container::class));
		$container->shouldReceive('make')->once()->with('FooHandler')->andReturn($handler = m::mock('StdClass'));
		$handler->shouldReceive('handle')->once()->with('foo', 'bar');
		$d->listen('foo', 'FooHandler');
		$d->fire('foo', ['foo', 'bar']);
	}


	public function testQueuedEventsAreFired()
	{
		unset($_SERVER['__event.test']);
		$d = new Dispatcher;
		$d->queue('update', ['name' => 'taylor']);
		$d->listen('update', function($name)
		{
			$_SERVER['__event.test'] = $name;
		});

		$this->assertFalse(isset($_SERVER['__event.test']));
		$d->flush('update');
		$this->assertEquals('taylor', $_SERVER['__event.test']);
	}


	public function testQueuedEventsCanBeForgotten()
	{
		$_SERVER['__event.test'] = 'unset';
		$d = new Dispatcher;
		$d->queue('update', ['name' => 'taylor']);
		$d->listen('update', function($name)
		{
			$_SERVER['__event.test'] = $name;
		});

		$d->forgetQueued();
		$d->flush('update');
		$this->assertEquals('unset', $_SERVER['__event.test']);
	}


	public function testWildcardListeners()
	{
		unset($_SERVER['__event.test']);
		$d = new Dispatcher;
		$d->listen('foo.bar', function() { $_SERVER['__event.test'] = 'regular'; });
		$d->listen('foo.*', function() { $_SERVER['__event.test'] = 'wildcard'; });
		$d->listen('bar.*', function() { $_SERVER['__event.test'] = 'nope'; });
		$d->fire('foo.bar');

		$this->assertEquals('wildcard', $_SERVER['__event.test']);
	}


	public function testListenersCanBeRemoved()
	{
		unset($_SERVER['__event.test']);
		$d = new Dispatcher;
		$d->listen('foo', function() { $_SERVER['__event.test'] = 'foo'; });
		$d->forget('foo');
		$d->fire('foo');

		$this->assertFalse(isset($_SERVER['__event.test']));
	}


	public function testFiringReturnsCurrentlyFiredEvent()
	{
		unset($_SERVER['__event.test']);
		$d = new Dispatcher;
		$d->listen('foo', function() use ($d) { $_SERVER['__event.test'] = $d->firing(); $d->fire('bar'); });
		$d->listen('bar', function() use ($d) { $_SERVER['__event.test'] = $d->firing(); });
		$d->fire('foo');

		$this->assertEquals('bar', $_SERVER['__event.test']);
	}

}
