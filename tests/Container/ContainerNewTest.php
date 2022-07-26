<?php
/** @noinspection PhpArrayIndexImmediatelyRewrittenInspection */

/** @noinspection SuspiciousAssignmentsInspection */

/** @noinspection PhpUnhandledExceptionInspection */

namespace Illuminate\Tests\Container;

use Illuminate\Container\BindingResolutionException;
use Illuminate\Container\Container;
use Illuminate\Container\EntryNotFoundException;
use stdClass;
use TypeError;

/**
 * These are the tests backported from L9
 */
class ContainerNewTest extends \L4\Tests\BackwardCompatibleTestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    public function testContainerSingleton(): void
    {
        $container = Container::setInstance(new Container);

        $this->assertSame($container, Container::getInstance());

        Container::setInstance(null);

        $container2 = Container::getInstance();

        $this->assertInstanceOf(Container::class, $container2);
        $this->assertNotSame($container, $container2);
    }

    public function testClosureResolution(): void
    {
        $container = new Container;
        $container->bind('name', function () {
            return 'Taylor';
        });
        $this->assertSame('Taylor', $container->make('name'));
    }

    public function testBindIfDoesntRegisterIfServiceAlreadyRegistered(): void
    {
        $container = new Container;
        $container->bind('name', function () {
            return 'Taylor';
        });
        $container->bindIf('name', function () {
            return 'Dayle';
        });

        $this->assertSame('Taylor', $container->make('name'));
    }

    public function testBindIfDoesRegisterIfServiceNotRegisteredYet(): void
    {
        $container = new Container;
        $container->bind('surname', function () {
            return 'Taylor';
        });
        $container->bindIf('name', function () {
            return 'Dayle';
        });

        $this->assertSame('Dayle', $container->make('name'));
    }

    public function testSingletonIfDoesntRegisterIfBindingAlreadyRegistered(): void
    {
        $container = new Container;
        $container->singleton('class', function () {
            return new stdClass;
        });
        $firstInstantiation = $container->make('class');
        $container->singletonIf('class', function () {
            return new ContainerNewConcreteStub;
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
            return new ContainerNewConcreteStub;
        });
        $firstInstantiation = $container->make('otherClass');
        $secondInstantiation = $container->make('otherClass');
        $this->assertSame($firstInstantiation, $secondInstantiation);
    }

    public function testSharedClosureResolution(): void
    {
        $container = new Container;
        $container->singleton('class', function () {
            return new stdClass;
        });
        $firstInstantiation = $container->make('class');
        $secondInstantiation = $container->make('class');
        $this->assertSame($firstInstantiation, $secondInstantiation);
    }

    public function testAutoConcreteResolution(): void
    {
        $container = new Container;
        $this->assertInstanceOf(ContainerNewConcreteStub::class, $container->make(ContainerNewConcreteStub::class));
    }

    public function testSharedConcreteResolution(): void
    {
        $container = new Container;
        $container->singleton(ContainerNewConcreteStub::class);

        $var1 = $container->make(ContainerNewConcreteStub::class);
        $var2 = $container->make(ContainerNewConcreteStub::class);
        $this->assertSame($var1, $var2);
    }

    public function testBindFailsLoudlyWithInvalidArgument(): void
    {
        $this->expectException(TypeError::class);
        $container = new Container;

        $concrete = new ContainerNewConcreteStub;
        $container->bind(ContainerNewConcreteStub::class, $concrete);
    }

    public function testAbstractToConcreteResolution(): void
    {
        $container = new Container;
        $container->bind(IContainerContractStub::class, ContainerImplementationStub::class);
        $class = $container->make(ContainerDependentStub::class);
        $this->assertInstanceOf(ContainerImplementationStub::class, $class->impl);
    }

    public function testNestedDependencyResolution(): void
    {
        $container = new Container;
        $container->bind(IContainerContractStub::class, ContainerImplementationStub::class);
        $class = $container->make(ContainerNestedDependentStub::class);
        $this->assertInstanceOf(ContainerDependentStub::class, $class->inner);
        $this->assertInstanceOf(ContainerImplementationStub::class, $class->inner->impl);
    }

    public function testContainerIsPassedToResolvers(): void
    {
        $container = new Container;
        $container->bind('something', function ($c) {
            return $c;
        });
        $c = $container->make('something');
        $this->assertSame($c, $container);
    }

    public function testArrayAccess(): void
    {
        $container = new Container;
        $container['something'] = function () {
            return 'foo';
        };
        $this->assertTrue(isset($container['something']));
        $this->assertSame('foo', $container['something']);
        unset($container['something']);
        $this->assertFalse(isset($container['something']));
    }

    public function testAliases(): void
    {
        $container = new Container;
        $container['foo'] = 'bar';
        $container->alias('foo', 'baz');
        $container->alias('baz', 'bat');
        $this->assertSame('bar', $container->make('foo'));
        $this->assertSame('bar', $container->make('baz'));
        $this->assertSame('bar', $container->make('bat'));
    }

    public function testAliasesWithArrayOfParameters(): void
    {
        $container = new Container;
        $container->bind('foo', function ($app, $config) {
            return $config;
        });
        $container->alias('foo', 'baz');
        $this->assertEquals([1, 2, 3], $container->make('baz', [1, 2, 3]));
    }

    public function testBindingsCanBeOverridden(): void
    {
        $container = new Container;
        $container['foo'] = 'bar';
        $container['foo'] = 'baz';
        $this->assertSame('baz', $container['foo']);
    }

    public function testBindingAnInstanceReturnsTheInstance(): void
    {
        $container = new Container;

        $bound = new stdClass;
        $resolved = $container->instance('foo', $bound);

        $this->assertSame($bound, $resolved);
    }

    public function testBindingAnInstanceAsShared(): void
    {
        $container = new Container;
        $bound = new stdClass;
        $container->instance('foo', $bound);
        $object = $container->make('foo');
        $this->assertSame($bound, $object);
    }

    public function testResolutionOfDefaultParameters(): void
    {
        $container = new Container;
        $instance = $container->make(ContainerDefaultValueStub::class);
        $this->assertInstanceOf(ContainerNewConcreteStub::class, $instance->stub);
        $this->assertSame('taylor', $instance->default);
    }

    public function testUnsetRemoveBoundInstances(): void
    {
        $container = new Container;
        $container->instance('object', new stdClass);
        unset($container['object']);

        $this->assertFalse($container->bound('object'));
    }

    public function testBoundInstanceAndAliasCheckViaArrayAccess(): void
    {
        $container = new Container;
        $container->instance('object', new stdClass);
        $container->alias('object', 'alias');

        $this->assertTrue(isset($container['object']));
        $this->assertTrue(isset($container['alias']));
    }

    public function testReboundListeners(): void
    {
        unset($_SERVER['__test.rebind']);

        $container = new Container;
        $container->bind('foo', function () {
            //
        });
        $container->rebinding('foo', function () {
            $_SERVER['__test.rebind'] = true;
        });
        $container->bind('foo', function () {
            //
        });

        $this->assertTrue($_SERVER['__test.rebind']);
    }

    public function testReboundListenersOnInstances(): void
    {
        unset($_SERVER['__test.rebind']);

        $container = new Container;
        $container->instance('foo', function () {
            //
        });
        $container->rebinding('foo', function () {
            $_SERVER['__test.rebind'] = true;
        });
        $container->instance('foo', function () {
            //
        });

        $this->assertTrue($_SERVER['__test.rebind']);
    }

    public function testReboundListenersOnInstancesOnlyFiresIfWasAlreadyBound(): void
    {
        $_SERVER['__test.rebind'] = false;

        $container = new Container;
        $container->rebinding('foo', function () {
            $_SERVER['__test.rebind'] = true;
        });
        $container->instance('foo', function () {
            //
        });

        $this->assertFalse($_SERVER['__test.rebind']);
    }

    public function testInternalClassWithDefaultParameters(): void
    {
        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Unresolvable dependency resolving [Parameter #0 [ <required> $first ]] in class Illuminate\Tests\Container\ContainerMixedPrimitiveStub');

        $container = new Container;
        $container->make(ContainerMixedPrimitiveStub::class, []);
    }

    public function testBindingResolutionExceptionMessage(): void
    {
        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Target [Illuminate\Tests\Container\IContainerContractStub] is not instantiable.');

        $container = new Container;
        $container->make(IContainerContractStub::class, []);
    }

    public function testBindingResolutionExceptionMessageIncludesBuildStack(): void
    {
        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Target [Illuminate\Tests\Container\IContainerContractStub] is not instantiable while building [Illuminate\Tests\Container\ContainerDependentStub].');

        $container = new Container;
        $container->make(ContainerDependentStub::class, []);
    }

    public function testBindingResolutionExceptionMessageWhenClassDoesNotExist(): void
    {
        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Target class [Foo\Bar\Baz\DummyClass] does not exist.');

        $container = new Container;
        $container->build('Foo\Bar\Baz\DummyClass');
    }

    public function testForgetInstanceForgetsInstance(): void
    {
        $container = new Container;
        $containerConcreteStub = new ContainerNewConcreteStub;
        $container->instance(ContainerNewConcreteStub::class, $containerConcreteStub);
        $this->assertTrue($container->isShared(ContainerNewConcreteStub::class));
        $container->forgetInstance(ContainerNewConcreteStub::class);
        $this->assertFalse($container->isShared(ContainerNewConcreteStub::class));
    }

    public function testForgetInstancesForgetsAllInstances(): void
    {
        $container = new Container;
        $containerConcreteStub1 = new ContainerNewConcreteStub;
        $containerConcreteStub2 = new ContainerNewConcreteStub;
        $containerConcreteStub3 = new ContainerNewConcreteStub;
        $container->instance('Instance1', $containerConcreteStub1);
        $container->instance('Instance2', $containerConcreteStub2);
        $container->instance('Instance3', $containerConcreteStub3);
        $this->assertTrue($container->isShared('Instance1'));
        $this->assertTrue($container->isShared('Instance2'));
        $this->assertTrue($container->isShared('Instance3'));
        $container->forgetInstances();
        $this->assertFalse($container->isShared('Instance1'));
        $this->assertFalse($container->isShared('Instance2'));
        $this->assertFalse($container->isShared('Instance3'));
    }

    public function testContainerFlushFlushesAllBindingsAliasesAndResolvedInstances(): void
    {
        $container = new Container;
        $container->bind('ConcreteStub', function () {
            return new ContainerNewConcreteStub;
        }, true);
        $container->alias('ConcreteStub', 'ContainerConcreteStub');
        $container->make('ConcreteStub');
        $this->assertTrue($container->resolved('ConcreteStub'));
        $this->assertTrue($container->isAlias('ContainerConcreteStub'));
        $this->assertArrayHasKey('ConcreteStub', $container->getBindings());
        $this->assertTrue($container->isShared('ConcreteStub'));
        $container->flush();
        $this->assertFalse($container->resolved('ConcreteStub'));
        $this->assertFalse($container->isAlias('ContainerConcreteStub'));
        $this->assertEmpty($container->getBindings());
        $this->assertFalse($container->isShared('ConcreteStub'));
    }

    public function testResolvedResolvesAliasToBindingNameBeforeChecking(): void
    {
        $container = new Container;
        $container->bind('ConcreteStub', function () {
            return new ContainerNewConcreteStub;
        }, true);
        $container->alias('ConcreteStub', 'foo');

        $this->assertFalse($container->resolved('ConcreteStub'));
        $this->assertFalse($container->resolved('foo'));

        $container->make('ConcreteStub');

        $this->assertTrue($container->resolved('ConcreteStub'));
        $this->assertTrue($container->resolved('foo'));
    }

    public function testGetAlias(): void
    {
        $container = new Container;
        $container->alias('ConcreteStub', 'foo');
        $this->assertSame('ConcreteStub', $container->getAlias('foo'));
    }

    public function testItThrowsExceptionWhenAbstractIsSameAsAlias(): void
    {
        $this->expectException('LogicException');
        $this->expectExceptionMessage('[name] is aliased to itself.');

        $container = new Container;
        $container->alias('name', 'name');
    }

    public function testContainerGetFactory(): void
    {
        $container = new Container;
        $container->bind('name', function () {
            return 'Taylor';
        });

        $factory = $container->factory('name');
        $this->assertEquals($container->make('name'), $factory());
    }

    public function testMakeWithMethodIsAnAliasForMakeMethod(): void
    {
        $mock = $this->getMockBuilder(Container::class)
            ->onlyMethods(['make'])
            ->getMock();

        $mock->expects($this->once())
            ->method('make')
            ->with(ContainerDefaultValueStub::class, ['default' => 'laurence'])
            ->willReturn(new stdClass);

        $result = $mock->makeWith(ContainerDefaultValueStub::class, ['default' => 'laurence']);

        $this->assertInstanceOf(stdClass::class, $result);
    }

    public function testResolvingWithArrayOfParameters(): void
    {
        $container = new Container;
        $instance = $container->make(ContainerDefaultValueStub::class, ['default' => 'adam']);
        $this->assertSame('adam', $instance->default);

        $instance = $container->make(ContainerDefaultValueStub::class);
        $this->assertSame('taylor', $instance->default);

        $container->bind('foo', function ($app, $config) {
            return $config;
        });

        $this->assertEquals([1, 2, 3], $container->make('foo', [1, 2, 3]));
    }

    public function testResolvingWithUsingAnInterface(): void
    {
        $container = new Container;
        $container->bind(IContainerContractStub::class, ContainerInjectVariableStubWithInterfaceImplementation::class);
        $instance = $container->make(IContainerContractStub::class, ['something' => 'laurence']);
        $this->assertSame('laurence', $instance->something);
    }

    public function testNestedParameterOverride(): void
    {
        $container = new Container;
        $container->bind('foo', function ($app, $config) {
            return $app->make('bar', ['name' => 'Taylor']);
        });
        $container->bind('bar', function ($app, $config) {
            return $config;
        });

        $this->assertEquals(['name' => 'Taylor'], $container->make('foo', ['something']));
    }

    public function testNestedParametersAreResetForFreshMake(): void
    {
        $container = new Container;

        $container->bind('foo', function ($app, $config) {
            return $app->make('bar');
        });

        $container->bind('bar', function ($app, $config) {
            return $config;
        });

        $this->assertEquals([], $container->make('foo', ['something']));
    }

    public function testSingletonBindingsNotRespectedWithMakeParameters(): void
    {
        $container = new Container;

        $container->singleton('foo', function ($app, $config) {
            return $config;
        });

        $this->assertEquals(['name' => 'taylor'], $container->make('foo', ['name' => 'taylor']));
        $this->assertEquals(['name' => 'abigail'], $container->make('foo', ['name' => 'abigail']));
    }

    public function testCanBuildWithoutParameterStackWithNoConstructors(): void
    {
        $container = new Container;
        $this->assertInstanceOf(ContainerNewConcreteStub::class, $container->build(ContainerNewConcreteStub::class));
    }

    public function testCanBuildWithoutParameterStackWithConstructors(): void
    {
        $container = new Container;
        $container->bind(IContainerContractStub::class, ContainerImplementationStub::class);
        $this->assertInstanceOf(ContainerDependentStub::class, $container->build(ContainerDependentStub::class));
    }

    public function testContainerKnowsEntry(): void
    {
        $container = new Container;
        $container->bind(IContainerContractStub::class, ContainerImplementationStub::class);
        $this->assertTrue($container->has(IContainerContractStub::class));
    }

    public function testContainerCanBindAnyWord(): void
    {
        $container = new Container;
        $container->bind('Taylor', stdClass::class);
        $this->assertInstanceOf(stdClass::class, $container->get('Taylor'));
    }

    public function testContainerCanDynamicallySetService(): void
    {
        $container = new Container;
        $this->assertFalse(isset($container['name']));
        $container['name'] = 'Taylor';
        $this->assertTrue(isset($container['name']));
        $this->assertSame('Taylor', $container['name']);
    }

    public function testUnknownEntryThrowsException(): void
    {
        $this->expectException(EntryNotFoundException::class);

        $container = new Container;
        $container->get('Taylor');
    }

    public function testBoundEntriesThrowsContainerExceptionWhenNotResolvable(): void
    {
        $this->expectException(BindingResolutionException::class);

        $container = new Container;
        $container->bind('Taylor', IContainerContractStub::class);

        $container->get('Taylor');
    }

    public function testContainerCanResolveClasses(): void
    {
        $container = new Container;
        $class = $container->get(ContainerNewConcreteStub::class);

        $this->assertInstanceOf(ContainerNewConcreteStub::class, $class);
    }

    // public function testContainerCanCatchCircularDependency()
    // {
    //     $this->expectException(\Illuminate\Contracts\Container\CircularDependencyException::class);

    //     $container = new Container;
    //     $container->get(CircularAStub::class);
    // }
}

class CircularAStub
{
    public function __construct(CircularBStub $b)
    {
        //
    }
}

class CircularBStub
{
    public function __construct(CircularCStub $c)
    {
        //
    }
}

class CircularCStub
{
    public function __construct(CircularAStub $a)
    {
        //
    }
}

class ContainerNewConcreteStub
{
    //
}

interface IContainerContractStub
{
    //
}

class ContainerImplementationStub implements IContainerContractStub
{
    //
}

class ContainerImplementationStubTwo implements IContainerContractStub
{
    //
}

class ContainerDependentStub
{
    public $impl;

    public function __construct(IContainerContractStub $impl)
    {
        $this->impl = $impl;
    }
}

class ContainerNestedDependentStub
{
    public $inner;

    public function __construct(ContainerDependentStub $inner)
    {
        $this->inner = $inner;
    }
}

class ContainerDefaultValueStub
{
    public $stub;
    public $default;

    public function __construct(ContainerNewConcreteStub $stub, $default = 'taylor')
    {
        $this->stub = $stub;
        $this->default = $default;
    }
}

class ContainerMixedPrimitiveStub
{
    public $first;
    public $last;
    public $stub;

    public function __construct($first, ContainerNewConcreteStub $stub, $last)
    {
        $this->stub = $stub;
        $this->last = $last;
        $this->first = $first;
    }
}

class ContainerInjectVariableStub
{
    public $something;

    public function __construct(ContainerNewConcreteStub $concrete, $something)
    {
        $this->something = $something;
    }
}

class ContainerInjectVariableStubWithInterfaceImplementation implements IContainerContractStub
{
    public $something;

    public function __construct(ContainerNewConcreteStub $concrete, $something)
    {
        $this->something = $something;
    }
}