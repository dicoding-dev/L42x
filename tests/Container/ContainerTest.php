<?php

use Illuminate\Container\BindingResolutionException;
use Illuminate\Container\Container;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class ContainerTest extends BackwardCompatibleTestCase {

    public function tearDown(): void
    {
        m::close();
    }

	public function testClosureResolution(): void
    {
		$container = new Container;
		$container->bind('name', function() { return 'Taylor'; });
		$this->assertEquals('Taylor', $container->make('name'));
	}


	public function testBindIfDoesntRegisterIfServiceAlreadyRegistered(): void
    {
		$container = new Container;
		$container->bind('name', function() { return 'Taylor'; });
		$container->bindIf('name', function() { return 'Dayle'; });

		$this->assertEquals('Taylor', $container->make('name'));
	}


	public function testSharedClosureResolution(): void
    {
		$container = new Container;
		$class = new stdClass;
		$container->singleton('class', function() use ($class) { return $class; });
		$this->assertSame($class, $container->make('class'));
	}


	public function testAutoConcreteResolution(): void
    {
		$container = new Container;
		$this->assertInstanceOf('ContainerConcreteStub', $container->make('ContainerConcreteStub'));
	}


	public function testSlashesAreHandled(): void
    {
		$container = new Container;
		$container->bind('\Foo', function() { return 'hello'; });
		$this->assertEquals('hello', $container->make('Foo'));
	}


	public function testParametersCanOverrideDependencies(): void
    {
		$container = new Container;
		$stub = new ContainerDependentStub($mock = m::mock('IContainerContractStub'));
		$resolved = $container->make('ContainerNestedDependentStub', [$stub]);
		$this->assertInstanceOf('ContainerNestedDependentStub', $resolved);
		$this->assertEquals($mock, $resolved->inner->impl);
	}


	public function testSharedConcreteResolution(): void
    {
		$container = new Container;
		$container->singleton('ContainerConcreteStub');
		$bindings = $container->getBindings();

		$var1 = $container->make('ContainerConcreteStub');
		$var2 = $container->make('ContainerConcreteStub');
		$this->assertSame($var1, $var2);
	}

    public function testSingletonIfDoesntRegisterIfBindingAlreadyRegistered(): void
    {
        $container = new Container;
        $container->singleton('class', function () {
            return new stdClass;
        });
        $firstInstantiation = $container->make('class');
        $container->singletonIf('class', function () {
            return new ContainerConcreteStub;
        });
        $secondInstantiation = $container->make('class');
        $this->assertSame($firstInstantiation, $secondInstantiation);
    }

    public function testSingletonIfDoesRegisterIfBindingNotRegisteredYet(): void
    {
        $container = new Container;
        $container->singleton('class', function () {
            return new stdClass;
        });
        $container->singletonIf('otherClass', function () {
            return new ContainerConcreteStub;
        });
        $firstInstantiation = $container->make('otherClass');
        $secondInstantiation = $container->make('otherClass');
        $this->assertSame($firstInstantiation, $secondInstantiation);
    }

	public function testAbstractToConcreteResolution(): void
    {
		$container = new Container;
		$container->bind('IContainerContractStub', 'ContainerImplementationStub');
		$class = $container->make('ContainerDependentStub');
		$this->assertInstanceOf('ContainerImplementationStub', $class->impl);
	}


	public function testNestedDependencyResolution(): void
    {
		$container = new Container;
		$container->bind('IContainerContractStub', 'ContainerImplementationStub');
		$class = $container->make('ContainerNestedDependentStub');
		$this->assertInstanceOf('ContainerDependentStub', $class->inner);
		$this->assertInstanceOf('ContainerImplementationStub', $class->inner->impl);
	}


	public function testContainerIsPassedToResolvers(): void
    {
		$container = new Container;
		$container->bind('something', function($c) { return $c; });
		$c = $container->make('something');
		$this->assertSame($c, $container);
	}


	public function testArrayAccess(): void
    {
		$container = new Container;
		$container['something'] = function() { return 'foo'; };
		$this->assertTrue(isset($container['something']));
		$this->assertEquals('foo', $container['something']);
		unset($container['something']);
		$this->assertFalse(isset($container['something']));
	}


	public function testAliases(): void
    {
		$container = new Container;
		$container['foo'] = 'bar';
		$container->alias('foo', 'baz');
		$this->assertEquals('bar', $container->make('foo'));
		$this->assertEquals('bar', $container->make('baz'));
		$container->bind(['bam' => 'boom'], function() { return 'pow'; });
		$this->assertEquals('pow', $container->make('bam'));
		$this->assertEquals('pow', $container->make('boom'));
		$container->instance(['zoom' => 'zing'], 'wow');
		$this->assertEquals('wow', $container->make('zoom'));
		$this->assertEquals('wow', $container->make('zing'));
	}


	public function testShareMethod(): void
    {
		$container = new Container;
		$closure = $container->share(function() { return new stdClass; });
		$class1 = $closure($container);
		$class2 = $closure($container);
		$this->assertSame($class1, $class2);
	}


	public function testBindingsCanBeOverridden(): void
    {
		$container = new Container;
		$container['foo'] = 'bar';
		$foo = $container['foo'];
		$container['foo'] = 'baz';
		$this->assertEquals('baz', $container['foo']);
	}


	public function testExtendedBindings(): void
    {
		$container = new Container;
		$container['foo'] = 'foo';
		$container->extend('foo', function($old, $container)
		{
			return $old.'bar';
		});

		$this->assertEquals('foobar', $container->make('foo'));

		$container = new Container;

		$container['foo'] = $container->share(function()
		{
			return (object) ['name' => 'taylor'];
		});
		$container->extend('foo', function($old, $container)
		{
			$old->age = 26;
			return $old;
		});

		$result = $container->make('foo');

		$this->assertEquals('taylor', $result->name);
		$this->assertEquals(26, $result->age);
		$this->assertSame($result, $container->make('foo'));
	}


	public function testMultipleExtends(): void
    {
		$container = new Container;
		$container['foo'] = 'foo';
		$container->extend('foo', function($old, $container)
		{
			return $old.'bar';
		});
		$container->extend('foo', function($old, $container)
		{
			return $old.'baz';
		});

		$this->assertEquals('foobarbaz', $container->make('foo'));
	}


	public function testExtendInstancesArePreserved(): void
    {
		$container = new Container;
		$container->bind('foo', function() { $obj = new StdClass; $obj->foo = 'bar'; return $obj; });
		$obj = new StdClass; $obj->foo = 'foo';
		$container->instance('foo', $obj);
		$container->extend('foo', function($obj, $container) { $obj->bar = 'baz'; return $obj; });
		$container->extend('foo', function($obj, $container) { $obj->baz = 'foo'; return $obj; });
		$this->assertEquals('foo', $container->make('foo')->foo);
	}


	public function testExtendIsLazyInitialized(): void
    {
		$container = new Container;
		$container->bind('ContainerLazyExtendStub');
		$container->extend('ContainerLazyExtendStub', function($obj, $container) { $obj->init(); return $obj; });
		$this->assertFalse(ContainerLazyExtendStub::$initialized);
		$container->make('ContainerLazyExtendStub');
		$this->assertTrue(ContainerLazyExtendStub::$initialized);
	}


	public function testParametersCanBePassedThroughToClosure(): void
    {
		$container = new Container;
		$container->bind('foo', function($c, $parameters)
		{
			return $parameters;
		});

		$this->assertEquals([1, 2, 3], $container->make('foo', [1, 2, 3]));
	}


	public function testResolutionOfDefaultParameters(): void
    {
		$container = new Container;
		$instance = $container->make('ContainerDefaultValueStub');
		$this->assertInstanceOf('ContainerConcreteStub', $instance->stub);
		$this->assertEquals('taylor', $instance->default);
	}


	public function testResolvingCallbacksAreCalledForSpecificAbstracts(): void
    {
		$container = new Container;
		$container->resolving('foo', function($object) { return $object->name = 'taylor'; });
		$container->bind('foo', function() { return new StdClass; });
		$instance = $container->make('foo');

		$this->assertEquals('taylor', $instance->name);
	}


	public function testResolvingCallbacksAreCalled(): void
    {
		$container = new Container;
		$container->resolvingAny(function($object) { return $object->name = 'taylor'; });
		$container->bind('foo', function() { return new StdClass; });
		$instance = $container->make('foo');

		$this->assertEquals('taylor', $instance->name);
	}


	public function testUnsetRemoveBoundInstances(): void
    {
		$container = new Container;
		$container->instance('object', new StdClass);
		unset($container['object']);

		$this->assertFalse($container->bound('object'));
	}


	public function testReboundListeners(): void
    {
		unset($_SERVER['__test.rebind']);

		$container = new Container;
		$container->bind('foo', function() {});
		$container->rebinding('foo', function() { $_SERVER['__test.rebind'] = true; });
		$container->bind('foo', function() {});

		$this->assertTrue($_SERVER['__test.rebind']);
	}


	public function testReboundListenersOnInstances(): void
    {
		unset($_SERVER['__test.rebind']);

		$container = new Container;
		$container->instance('foo', function() {});
		$container->rebinding('foo', function() { $_SERVER['__test.rebind'] = true; });
		$container->instance('foo', function() {});

		$this->assertTrue($_SERVER['__test.rebind']);
	}


	public function testPassingSomePrimitiveParameters(): void
    {
		$container = new Container;
		$value = $container->make('ContainerMixedPrimitiveStub', ['first' => 'taylor', 'last' => 'otwell']);
		$this->assertInstanceOf('ContainerMixedPrimitiveStub', $value);
		$this->assertEquals('taylor', $value->first);
		$this->assertEquals('otwell', $value->last);
		$this->assertInstanceOf('ContainerConcreteStub', $value->stub);

		$container = new Container;
		$value = $container->make('ContainerMixedPrimitiveStub', [0 => 'taylor', 2 => 'otwell']);
		$this->assertInstanceOf('ContainerMixedPrimitiveStub', $value);
		$this->assertEquals('taylor', $value->first);
		$this->assertEquals('otwell', $value->last);
		$this->assertInstanceOf('ContainerConcreteStub', $value->stub);
	}


	public function testCreatingBoundConcreteClassPassesParameters(): void
    {
		$container = new Container;
		$container->bind('TestAbstractClass', 'ContainerConstructorParameterLoggingStub');
		$parameters = ['First', 'Second'];
		$instance = $container->make('TestAbstractClass', $parameters);
		$this->assertEquals($parameters, $instance->receivedParameters);
	}


	public function testInternalClassWithDefaultParameters(): void
    {
		$this->expectException(BindingResolutionException::class, 'Unresolvable dependency resolving [Parameter #0 [ <required> $first ]] in class ContainerMixedPrimitiveStub');
		$container = new Container;
		$parameters = [];
		$container->make('ContainerMixedPrimitiveStub', $parameters);
	}


	public function testUnsetAffectsResolved(): void
    {
		$container = new Container;
		$container->make('ContainerConcreteStub');

		unset($container['ContainerConcreteStub']);
		$this->assertFalse($container->resolved('ContainerConcreteStub'));
	}

}

class ContainerConcreteStub {}

interface IContainerContractStub {}

class ContainerImplementationStub implements IContainerContractStub {}

class ContainerDependentStub {
	public $impl;
	public function __construct(IContainerContractStub $impl)
	{
		$this->impl = $impl;
	}
}

class ContainerNestedDependentStub {
	public $inner;
	public function __construct(ContainerDependentStub $inner)
	{
		$this->inner = $inner;
	}
}

class ContainerDefaultValueStub {
	public $stub; public $default;
	public function __construct(ContainerConcreteStub $stub, $default = 'taylor')
	{
		$this->stub = $stub;
		$this->default = $default;
	}
}

class ContainerMixedPrimitiveStub {
	public $first; public $last; public $stub;
	public function __construct($first, ContainerConcreteStub $stub, $last)
	{
		$this->stub = $stub;
		$this->last = $last;
		$this->first = $first;
	}
}

class ContainerConstructorParameterLoggingStub {
	public $receivedParameters;

	public function __construct($first, $second)
	{
		$this->receivedParameters = func_get_args();
	}
}

class ContainerLazyExtendStub {
	public static $initialized = false;
	public function init(): void
    { static::$initialized = true; }
}
