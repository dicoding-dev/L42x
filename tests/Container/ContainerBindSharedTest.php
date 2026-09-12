<?php

namespace Illuminate\Tests\Container;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use stdClass;

class ContainerBindSharedTest extends TestCase
{
	public function testBindSharedReturnsSameInstanceOnRepeatedMake()
	{
		$container = new Container;

		$container->bindShared('foo', function()
		{
			return new stdClass;
		});

		$this->assertSame($container->make('foo'), $container->make('foo'));
	}

	public function testBindSharedClosureRunsExactlyOnce()
	{
		$container = new Container;
		$count = 0;

		$container->bindShared('foo', function() use (&$count)
		{
			$count++;

			return new stdClass;
		});

		$container->make('foo');
		$container->make('foo');

		$this->assertSame(1, $count);
	}

	public function testBindSharedIsRegisteredAsSharedBinding()
	{
		$container = new Container;

		$container->bindShared('foo', function()
		{
			return new stdClass;
		});

		$this->assertTrue($container->isShared('foo'));
	}

	public function testClonedContainerForgetInstanceReResolvesIndependentObject()
	{
		$container = new Container;

		$container->bindShared('foo', function()
		{
			return new stdClass;
		});

		$original = $container->make('foo');
		$clone = clone $container;

		$clone->forgetInstance('foo');
		$resolved = $clone->make('foo');

		$this->assertNotSame($original, $resolved);
		$this->assertSame($resolved, $clone->make('foo'));
	}
}
