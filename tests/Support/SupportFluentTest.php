<?php

use Illuminate\Support\Fluent;
use L4\Tests\BackwardCompatibleTestCase;

class SupportFluentTest extends BackwardCompatibleTestCase {

	public function testAttributesAreSetByConstructor(): void
    {
		$array  = ['name' => 'Taylor', 'age' => 25];
		$fluent = new Fluent($array);

		$refl = new \ReflectionObject($fluent);
		$attributes = $refl->getProperty('attributes');
		$attributes->setAccessible(true);

		$this->assertEquals($array, $attributes->getValue($fluent));
		$this->assertEquals($array, $fluent->getAttributes());
	}


	public function testAttributesAreSetByConstructorGivenStdClass(): void
    {
		$array  = ['name' => 'Taylor', 'age' => 25];
		$fluent = new Fluent((object) $array);

		$refl = new \ReflectionObject($fluent);
		$attributes = $refl->getProperty('attributes');
		$attributes->setAccessible(true);

		$this->assertEquals($array, $attributes->getValue($fluent));
		$this->assertEquals($array, $fluent->getAttributes());
	}


	public function testAttributesAreSetByConstructorGivenArrayIterator(): void
    {
		$array  = ['name' => 'Taylor', 'age' => 25];
		$fluent = new Fluent(new FluentArrayIteratorStub($array));

		$refl = new \ReflectionObject($fluent);
		$attributes = $refl->getProperty('attributes');
		$attributes->setAccessible(true);

		$this->assertEquals($array, $attributes->getValue($fluent));
		$this->assertEquals($array, $fluent->getAttributes());
	}


	public function testGetMethodReturnsAttribute(): void
    {
		$fluent = new Fluent(['name' => 'Taylor']);

		$this->assertEquals('Taylor', $fluent->get('name'));
		$this->assertEquals('Default', $fluent->get('foo', 'Default'));
		$this->assertEquals('Taylor', $fluent->name);
		$this->assertNull($fluent->foo);
	}


	public function testMagicMethodsCanBeUsedToSetAttributes(): void
    {
		$fluent = new Fluent;

		$fluent->name = 'Taylor';
		$fluent->developer();
		$fluent->age(25);

		$this->assertEquals('Taylor', $fluent->name);
		$this->assertTrue($fluent->developer);
		$this->assertEquals(25, $fluent->age);
		$this->assertInstanceOf(Fluent::class, $fluent->programmer());
	}


	public function testIssetMagicMethod(): void
    {
		$array  = ['name' => 'Taylor', 'age' => 25];
		$fluent = new Fluent($array);

		$this->assertTrue(isset($fluent->name));

		unset($fluent->name);

		$this->assertFalse(isset($fluent->name));
	}


	public function testToArrayReturnsAttribute(): void
    {
		$array  = ['name' => 'Taylor', 'age' => 25];
		$fluent = new Fluent($array);

		$this->assertEquals($array, $fluent->toArray());
	}


	public function testToJsonEncodesTheToArrayResult(): void
    {
		$fluent = $this->getMock(Fluent::class, ['toArray']);
		$fluent->expects($this->once())->method('toArray')->willReturn('foo');
		$results = $fluent->toJson();

		$this->assertEquals(json_encode('foo'), $results);
	}

}


class FluentArrayIteratorStub implements \IteratorAggregate {
	protected array $items = [];

	public function __construct(array $items = [])
	{
		$this->items = (array) $items;
	}

	public function getIterator(): Traversable
	{
		return new \ArrayIterator($this->items);
	}
}
