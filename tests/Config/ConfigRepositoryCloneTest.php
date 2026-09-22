<?php

use Illuminate\Config\Repository;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class ConfigRepositoryCloneTest extends BackwardCompatibleTestCase
{
	protected function tearDown(): void
	{
		m::close();
	}

	public function testCloneIsolatesItemsFromBase()
	{
		$loader = m::mock('Illuminate\Config\LoaderInterface');
		$loader->shouldReceive('load')->once()->with('testing', 'app', null)->andReturn(array());
		$base = new Repository($loader, 'testing');
		$base['app.name'] = 'BaseApp';

		$clone = clone $base;

		$clone['app.name'] = 'CloneApp';

		$this->assertEquals('BaseApp', $base['app.name']);
		$this->assertEquals('CloneApp', $clone['app.name']);
	}

	public function testCloneSharesLoaderByReference()
	{
		$loader = m::mock('Illuminate\Config\LoaderInterface');
		$base = new Repository($loader, 'testing');
		$clone = clone $base;

		$baseReflection = new \ReflectionProperty($base, 'loader');
		$cloneReflection = new \ReflectionProperty($clone, 'loader');
		$baseReflection->setAccessible(true);
		$cloneReflection->setAccessible(true);

		$this->assertSame($baseReflection->getValue($base), $cloneReflection->getValue($clone));
	}
}
