<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace Illuminate\Tests\Container;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use stdClass;

class ResolvingCallbackTest extends TestCase
{
    public function testResolvingCallbacksAreCalledForSpecificAbstracts(): void
    {
        $container = new Container;
        $container->resolving('foo', function ($object) {
            return $object->name = 'taylor';
        });
        $container->bind('foo', function () {
            return new stdClass;
        });
        $instance = $container->make('foo');

        $this->assertSame('taylor', $instance->name);
    }

    public function testResolvingCallbacksAreCalled(): void
    {
        $container = new Container;
        $container->resolving(function ($object) {
            return $object->name = 'taylor';
        });
        $container->bind('foo', function () {
            return new stdClass;
        });
        $instance = $container->make('foo');

        $this->assertSame('taylor', $instance->name);
    }

    public function testResolvingCallbacksAreCalledForType(): void
    {
        $container = new Container;
        $container->resolving(stdClass::class, function ($object) {
            return $object->name = 'taylor';
        });
        $container->bind('foo', function () {
            return new stdClass;
        });
        $instance = $container->make('foo');

        $this->assertSame('taylor', $instance->name);
    }

    public function testResolvingCallbacksShouldBeFiredWhenCalledWithAliases(): void
    {
        $container = new Container;
        $container->alias(stdClass::class, 'std');
        $container->resolving('std', function ($object) {
            return $object->name = 'taylor';
        });
        $container->bind('foo', function () {
            return new stdClass;
        });
        $instance = $container->make('foo');

        $this->assertSame('taylor', $instance->name);
    }

    public function testResolvingCallbacksAreCalledOnceForImplementation(): void
    {
        $container = new Container;

        $callCounter = 0;
        $container->resolving(ResolvingContractStub::class, function () use (&$callCounter) {
            $callCounter++;
        });

        $container->bind(ResolvingContractStub::class, ResolvingImplementationStub::class);

        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(1, $callCounter);

        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(2, $callCounter);
    }

    public function testGlobalResolvingCallbacksAreCalledOnceForImplementation(): void
    {
        $container = new Container;

        $callCounter = 0;
        $container->resolving(function () use (&$callCounter) {
            $callCounter++;
        });

        $container->bind(ResolvingContractStub::class, ResolvingImplementationStub::class);

        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(1, $callCounter);

        $container->make(ResolvingContractStub::class);
        $this->assertEquals(2, $callCounter);
    }

    public function testResolvingCallbacksAreCalledOnceForSingletonConcretes(): void
    {
        $container = new Container;

        $callCounter = 0;
        $container->resolving(ResolvingContractStub::class, function ($object) use (&$callCounter) {
            $callCounter++;
        });

        $container->bind(ResolvingContractStub::class, ResolvingImplementationStub::class);
        $container->bind(ResolvingImplementationStub::class);

        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(1, $callCounter);

        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(2, $callCounter);

        $container->make(ResolvingContractStub::class);
        $this->assertEquals(3, $callCounter);
    }

    public function testResolvingCallbacksCanStillBeAddedAfterTheFirstResolution(): void
    {
        $container = new Container;

        $container->bind(ResolvingContractStub::class, ResolvingImplementationStub::class);

        $container->make(ResolvingImplementationStub::class);

        $callCounter = 0;
        $container->resolving(ResolvingContractStub::class, function () use (&$callCounter) {
            $callCounter++;
        });

        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(1, $callCounter);
    }

    public function testResolvingCallbacksAreCanceledWhenInterfaceGetsBoundToSomeOtherConcrete(): void
    {
        $container = new Container;

        $container->bind(ResolvingContractStub::class, ResolvingImplementationStub::class);

        $callCounter = 0;
        $container->resolving(ResolvingImplementationStub::class, function () use (&$callCounter) {
            $callCounter++;
        });

        $container->make(ResolvingContractStub::class);
        $this->assertEquals(1, $callCounter);

        $container->bind(ResolvingContractStub::class, ResolvingImplementationStubTwo::class);
        $container->make(ResolvingContractStub::class);
        $this->assertEquals(1, $callCounter);
    }

    public function testResolvingCallbacksAreCalledOnceForStringAbstractions(): void
    {
        $container = new Container;

        $callCounter = 0;
        $container->resolving('foo', function () use (&$callCounter) {
            $callCounter++;
        });

        $container->bind('foo', ResolvingImplementationStub::class);

        $container->make('foo');
        $this->assertEquals(1, $callCounter);

        $container->make('foo');
        $this->assertEquals(2, $callCounter);
    }

    public function testResolvingCallbacksForConcretesAreCalledOnceForStringAbstractions(): void
    {
        $container = new Container;

        $callCounter = 0;
        $container->resolving(ResolvingImplementationStub::class, function () use (&$callCounter) {
            $callCounter++;
        });

        $container->bind('foo', ResolvingImplementationStub::class);
        $container->bind('bar', ResolvingImplementationStub::class);
        $container->bind(ResolvingContractStub::class, ResolvingImplementationStub::class);

        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(1, $callCounter);

        $container->make('foo');
        $this->assertEquals(2, $callCounter);

        $container->make('bar');
        $this->assertEquals(3, $callCounter);

        $container->make(ResolvingContractStub::class);
        $this->assertEquals(4, $callCounter);
    }

    public function testResolvingCallbacksAreCalledOnceForImplementation2(): void
    {
        $container = new Container;

        $callCounter = 0;
        $container->resolving(ResolvingContractStub::class, function () use (&$callCounter) {
            $callCounter++;
        });

        $container->bind(ResolvingContractStub::class, function () {
            return new ResolvingImplementationStub;
        });

        $container->make(ResolvingContractStub::class);
        $this->assertEquals(1, $callCounter);

        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(2, $callCounter);

        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(3, $callCounter);

        $container->make(ResolvingContractStub::class);
        $this->assertEquals(4, $callCounter);
    }

    public function testRebindingDoesNotAffectResolvingCallbacks(): void
    {
        $container = new Container;

        $callCounter = 0;
        $container->resolving(ResolvingContractStub::class, function () use (&$callCounter) {
            $callCounter++;
        });

        $container->bind(ResolvingContractStub::class, ResolvingImplementationStub::class);
        $container->bind(ResolvingContractStub::class, function () {
            return new ResolvingImplementationStub;
        });

        $container->make(ResolvingContractStub::class);
        $this->assertEquals(1, $callCounter);

        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(2, $callCounter);

        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(3, $callCounter);

        $container->make(ResolvingContractStub::class);
        $this->assertEquals(4, $callCounter);
    }

    public function testParametersPassedIntoResolvingCallbacks(): void
    {
        $container = new Container;

        $container->resolving(ResolvingContractStub::class, function ($obj, $app) use ($container) {
            $this->assertInstanceOf(ResolvingContractStub::class, $obj);
            $this->assertInstanceOf(ResolvingImplementationStubTwo::class, $obj);
            $this->assertSame($container, $app);
        });

        $container->afterResolving(ResolvingContractStub::class, function ($obj, $app) use ($container) {
            $this->assertInstanceOf(ResolvingContractStub::class, $obj);
            $this->assertInstanceOf(ResolvingImplementationStubTwo::class, $obj);
            $this->assertSame($container, $app);
        });

        $container->afterResolving(function ($obj, $app) use ($container) {
            $this->assertInstanceOf(ResolvingContractStub::class, $obj);
            $this->assertInstanceOf(ResolvingImplementationStubTwo::class, $obj);
            $this->assertSame($container, $app);
        });

        $container->bind(ResolvingContractStub::class, ResolvingImplementationStubTwo::class);
        $container->make(ResolvingContractStub::class);
    }

    public function testResolvingCallbacksAreCallWhenRebindHappenForResolvedAbstract(): void
    {
        $container = new Container;

        $callCounter = 0;
        $container->resolving(ResolvingContractStub::class, function () use (&$callCounter) {
            $callCounter++;
        });

        $container->bind(ResolvingContractStub::class, ResolvingImplementationStub::class);

        $container->make(ResolvingContractStub::class);
        $this->assertEquals(1, $callCounter);

        $container->bind(ResolvingContractStub::class, ResolvingImplementationStubTwo::class);
        $this->assertEquals(2, $callCounter);

        $container->make(ResolvingImplementationStubTwo::class);
        $this->assertEquals(3, $callCounter);

        $container->bind(ResolvingContractStub::class, function () {
            return new ResolvingImplementationStubTwo;
        });
        $this->assertEquals(4, $callCounter);

        $container->make(ResolvingContractStub::class);
        $this->assertEquals(5, $callCounter);
    }

    public function testRebindingDoesNotAffectMultipleResolvingCallbacks(): void
    {
        $container = new Container;

        $callCounter = 0;

        $container->resolving(ResolvingContractStub::class, function () use (&$callCounter) {
            $callCounter++;
        });

        $container->resolving(ResolvingImplementationStubTwo::class, function () use (&$callCounter) {
            $callCounter++;
        });

        $container->bind(ResolvingContractStub::class, ResolvingImplementationStub::class);

        // it should call the callback for interface
        $container->make(ResolvingContractStub::class);
        $this->assertEquals(1, $callCounter);

        // it should call the callback for interface
        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(2, $callCounter);

        // should call the callback for the interface it implements
        // plus the callback for ResolvingImplementationStubTwo.
        $container->make(ResolvingImplementationStubTwo::class);
        $this->assertEquals(4, $callCounter);
    }

    public function testResolvingCallbacksAreCalledForInterfaces(): void
    {
        $container = new Container;

        $callCounter = 0;
        $container->resolving(ResolvingContractStub::class, function () use (&$callCounter) {
            $callCounter++;
        });

        $container->bind(ResolvingContractStub::class, ResolvingImplementationStub::class);

        $container->make(ResolvingContractStub::class);

        $this->assertEquals(1, $callCounter);
    }

    public function testResolvingCallbacksAreCalledForConcretesWhenAttachedOnInterface(): void
    {
        $container = new Container;

        $callCounter = 0;
        $container->resolving(ResolvingImplementationStub::class, function () use (&$callCounter) {
            $callCounter++;
        });

        $container->bind(ResolvingContractStub::class, ResolvingImplementationStub::class);

        $container->make(ResolvingContractStub::class);
        $this->assertEquals(1, $callCounter);

        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(2, $callCounter);
    }

    public function testResolvingCallbacksAreCalledForConcretesWhenAttachedOnConcretes(): void
    {
        $container = new Container;

        $callCounter = 0;
        $container->resolving(ResolvingImplementationStub::class, function () use (&$callCounter) {
            $callCounter++;
        });

        $container->bind(ResolvingContractStub::class, ResolvingImplementationStub::class);

        $container->make(ResolvingContractStub::class);
        $this->assertEquals(1, $callCounter);

        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(2, $callCounter);
    }

    public function testResolvingCallbacksAreCalledForConcretesWithNoBinding(): void
    {
        $container = new Container;

        $callCounter = 0;
        $container->resolving(ResolvingImplementationStub::class, function () use (&$callCounter) {
            $callCounter++;
        });

        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(1, $callCounter);
        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(2, $callCounter);
    }

    public function testResolvingCallbacksAreCalledForInterFacesWithNoBinding(): void
    {
        $container = new Container;

        $callCounter = 0;
        $container->resolving(ResolvingContractStub::class, function () use (&$callCounter) {
            $callCounter++;
        });

        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(1, $callCounter);

        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(2, $callCounter);
    }

    public function testAfterResolvingCallbacksAreCalledOnceForImplementation(): void
    {
        $container = new Container;

        $callCounter = 0;
        $container->afterResolving(ResolvingContractStub::class, function () use (&$callCounter) {
            $callCounter++;
        });

        $container->bind(ResolvingContractStub::class, ResolvingImplementationStub::class);

        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(1, $callCounter);

        $container->make(ResolvingContractStub::class);
        $this->assertEquals(2, $callCounter);
    }

    public function testBeforeResolvingCallbacksAreCalled(): void
    {
        // Given a call counter initialized to zero.
        $container = new Container;
        $callCounter = 0;

        // And a contract/implementation stub binding.
        $container->bind(ResolvingContractStub::class, ResolvingImplementationStub::class);

        // When we add a before resolving callback that increment the counter by one.
        $container->beforeResolving(ResolvingContractStub::class, function () use (&$callCounter) {
            $callCounter++;
        });

        // Then resolving the implementation stub increases the counter by one.
        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(1, $callCounter);

        // And resolving the contract stub increases the counter by one.
        $container->make(ResolvingContractStub::class);
        $this->assertEquals(2, $callCounter);
    }

    public function testGlobalBeforeResolvingCallbacksAreCalled(): void
    {
        // Given a call counter initialized to zero.
        $container = new Container;
        $callCounter = 0;

        // When we add a global before resolving callback that increment that counter by one.
        $container->beforeResolving(function () use (&$callCounter) {
            $callCounter++;
        });

        // Then resolving anything increases the counter by one.
        $container->make(ResolvingImplementationStub::class);
        $this->assertEquals(1, $callCounter);
    }
}

interface ResolvingContractStub
{
    //
}

class ResolvingImplementationStub implements ResolvingContractStub
{
    //
}

class ResolvingImplementationStubTwo implements ResolvingContractStub
{
    //
}
