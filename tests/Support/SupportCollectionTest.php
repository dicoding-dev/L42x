<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Contracts\ArrayableInterface;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class SupportCollectionTest extends BackwardCompatibleTestCase
{

    public function testFirstReturnsFirstItemInCollection(): void
    {
        $c = new Collection(['foo', 'bar']);
        $this->assertEquals('foo', $c->first());
    }


    public function testLastReturnsLastItemInCollection(): void
    {
		$c = new Collection(['foo', 'bar']);

		$this->assertEquals('bar', $c->last());
	}


	public function testPopReturnsAndRemovesLastItemInCollection(): void
    {
		$c = new Collection(['foo', 'bar']);

		$this->assertEquals('bar', $c->pop());
		$this->assertEquals('foo', $c->first());
	}


	public function testShiftReturnsAndRemovesFirstItemInCollection(): void
    {
		$c = new Collection(['foo', 'bar']);

		$this->assertEquals('foo', $c->shift());
		$this->assertEquals('bar', $c->first());
	}


	public function testEmptyCollectionIsEmpty(): void
    {
		$c = new Collection();

		$this->assertTrue($c->isEmpty());
	}


	public function testToArrayCallsToArrayOnEachItemInCollection(): void
    {
		$item1 = m::mock(ArrayableInterface::class);
		$item1->shouldReceive('toArray')->once()->andReturn('foo.array');
		$item2 = m::mock(ArrayableInterface::class);
		$item2->shouldReceive('toArray')->once()->andReturn('bar.array');
		$c = new Collection([$item1, $item2]);
		$results = $c->toArray();

		$this->assertEquals(['foo.array', 'bar.array'], $results);
	}


	public function testToJsonEncodesTheToArrayResult(): void
    {
		$c = $this->getMock(Collection::class, ['toArray']);
		$c->expects($this->once())->method('toArray')->willReturn('foo');
		$results = $c->toJson();

		$this->assertEquals(json_encode('foo'), $results);
	}


	public function testCastingToStringJsonEncodesTheToArrayResult(): void
    {
		$c = $this->getMock(\Illuminate\Database\Eloquent\Collection::class, ['toArray']);
		$c->expects($this->once())->method('toArray')->willReturn('foo');

		$this->assertEquals(json_encode('foo'), (string) $c);
	}


	public function testOffsetAccess(): void
    {
		$c = new Collection(['name' => 'taylor']);
		$this->assertEquals('taylor', $c['name']);
		$c['name'] = 'dayle';
		$this->assertEquals('dayle', $c['name']);
		$this->assertTrue(isset($c['name']));
		unset($c['name']);
		$this->assertFalse(isset($c['name']));
		$c[] = 'jason';
		$this->assertEquals('jason', $c[0]);
	}


	public function testCountable(): void
    {
		$c = new Collection(['foo', 'bar']);
		$this->assertCount(2, $c);
	}


	public function testIterable(): void
    {
		$c = new Collection(['foo']);
		$this->assertInstanceOf('ArrayIterator', $c->getIterator());
		$this->assertEquals(['foo'], $c->getIterator()->getArrayCopy());
	}


	public function testCachingIterator(): void
    {
		$c = new Collection(['foo']);
		$this->assertInstanceOf('CachingIterator', $c->getCachingIterator());
	}


	public function testFilter(): void
    {
		$c = new Collection([['id' => 1, 'name' => 'Hello'], ['id' => 2, 'name' => 'World']]);
		$this->assertEquals(
            [1 => ['id' => 2, 'name' => 'World']], $c->filter(function($item)
		{
			return $item['id'] == 2;
		})->all());
	}


	public function testValues(): void
    {
		$c = new Collection([['id' => 1, 'name' => 'Hello'], ['id' => 2, 'name' => 'World']]);
		$this->assertEquals(
            [['id' => 2, 'name' => 'World']], $c->filter(function($item)
		{
			return $item['id'] == 2;
		})->values()->all());
	}


	public function testFlatten(): void
    {
		$c = new Collection([['#foo', '#bar'], ['#baz']]);
		$this->assertEquals(['#foo', '#bar', '#baz'], $c->flatten()->all());
	}


	public function testMergeArray(): void
    {
		$c = new Collection(['name' => 'Hello']);
		$this->assertEquals(['name' => 'Hello', 'id' => 1], $c->merge(['id' => 1])->all());
	}


	public function testMergeCollection(): void
    {
		$c = new Collection(['name' => 'Hello']);
		$this->assertEquals(['name' => 'World', 'id' => 1], $c->merge(new Collection(['name' => 'World', 'id' => 1]))->all());
	}


	public function testDiffCollection(): void
    {
		$c = new Collection(['id' => 1, 'first_word' => 'Hello']);
		$this->assertEquals(['id' => 1], $c->diff(new Collection(['first_word' => 'Hello', 'last_word' => 'World']))->all());
	}


	public function testIntersectCollection(): void
    {
		$c = new Collection(['id' => 1, 'first_word' => 'Hello']);
		$this->assertEquals(
            ['first_word' => 'Hello'], $c->intersect(new Collection(
            ['first_world' => 'Hello', 'last_word' => 'World']
        ))->all());
	}


	public function testUnique(): void
    {
		$c = new Collection(['Hello', 'World', 'World']);
		$this->assertEquals(['Hello', 'World'], $c->unique()->all());
	}


	public function testCollapse(): void
    {
		$data = new Collection([[$object1 = new StdClass], [$object2 = new StdClass]]);
		$this->assertEquals([$object1, $object2], $data->collapse()->all());
	}


	public function testCollapseWithNestedCollactions(): void
    {
		$data = new Collection([new Collection([1, 2, 3]), new Collection([4, 5, 6])]);
		$this->assertEquals([1, 2, 3, 4, 5, 6], $data->collapse()->all());
	}


	public function testSort(): void
    {
		$data = new Collection([5, 3, 1, 2, 4]);
		$data->sort(function($a, $b)
		{
			if ($a === $b)
			{
				return 0;
			}
			return ($a < $b) ? -1 : 1;
		});

		$this->assertEquals(range(1, 5), array_values($data->all()));
	}


	public function testSortBy(): void
    {
		$data = new Collection(['taylor', 'dayle']);
		$data = $data->sortBy(function($x) { return $x; });

		$this->assertEquals(['dayle', 'taylor'], array_values($data->all()));

		$data = new Collection(['dayle', 'taylor']);
		$data->sortByDesc(function($x) { return $x; });

		$this->assertEquals(['taylor', 'dayle'], array_values($data->all()));
	}


	public function testSortByString(): void
    {
		$data = new Collection([['name' => 'taylor'], ['name' => 'dayle']]);
		$data = $data->sortBy('name');

		$this->assertEquals([['name' => 'dayle'], ['name' => 'taylor']], array_values($data->all()));
	}


	public function testReverse(): void
    {
		$data = new Collection(['zaeed', 'alan']);
		$reversed = $data->reverse();

		$this->assertEquals(['alan', 'zaeed'], array_values($reversed->all()));
	}


	public function testFlip(): void
    {
		$data = new Collection(['name' => 'taylor', 'framework' => 'laravel']);
		$this->assertEquals(['taylor' => 'name', 'laravel' => 'framework'], $data->flip()->toArray());
	}


	public function testChunk (): void
    {
		$data = new Collection([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
		$data = $data->chunk(3);

		$this->assertInstanceOf(Collection::class, $data);
		$this->assertInstanceOf(Collection::class, $data[0]);
		$this->assertEquals(4, $data->count());
		$this->assertEquals([1, 2, 3], $data[0]->toArray());
		$this->assertEquals([10], $data[3]->toArray());
	}


	public function testListsWithArrayAndObjectValues(): void
    {
		$data = new Collection([(object) ['name' => 'taylor', 'email' => 'foo'], ['name' => 'dayle', 'email' => 'bar']]);
		$this->assertEquals(['taylor' => 'foo', 'dayle' => 'bar'], $data->lists('email', 'name'));
		$this->assertEquals(['foo', 'bar'], $data->lists('email'));
	}


	public function testImplode(): void
    {
		$data = new Collection([['name' => 'taylor', 'email' => 'foo'], ['name' => 'dayle', 'email' => 'bar']]);
		$this->assertEquals('foobar', $data->implode('email'));
		$this->assertEquals('foobar', $data->implode('email', ''));
		$this->assertEquals('foobar', $data->implode('email', null));
		$this->assertEquals('foo,bar', $data->implode('email', ','));
	}


	public function testTake(): void
    {
		$data = new Collection(['taylor', 'dayle', 'shawn']);
		$data = $data->take(2);
		$this->assertEquals(['taylor', 'dayle'], $data->all());
	}


	public function testRandom(): void
    {
        $data = new Collection([1, 2, 3, 4, 5, 6]);
        $random = $data->random();
        $this->assertIsInt($random);
        $this->assertContains($random, $data->all());
        $random = $data->random(3);
        $this->assertCount(3, $random);
    }


	public function testRandomOnEmpty(): void
    {
		$data = new Collection();
		$random = $data->random();
		$this->assertNull($random);
	}


	public function testTakeLast(): void
    {
		$data = new Collection(['taylor', 'dayle', 'shawn']);
		$data = $data->take(-2);
		$this->assertEquals(['dayle', 'shawn'], $data->all());
	}


	public function testTakeAll(): void
    {
		$data = new Collection(['taylor', 'dayle', 'shawn']);
		$data = $data->take();
		$this->assertEquals(['taylor', 'dayle', 'shawn'], $data->all());
	}


	public function testMakeMethod(): void
    {
		$collection = Collection::make('foo');
		$this->assertEquals(['foo'], $collection->all());
	}


	public function testSplice(): void
    {
		$data = new Collection(['foo', 'baz']);
		$data->splice(1, 0, 'bar');
		$this->assertEquals(['foo', 'bar', 'baz'], $data->all());

		$data = new Collection(['foo', 'baz']);
		$data->splice(1, 1);
		$this->assertEquals(['foo'], $data->all());

		$data = new Collection(['foo', 'baz']);
		$cut = $data->splice(1, 1, 'bar');
		$this->assertEquals(['foo', 'bar'], $data->all());
		$this->assertEquals(['baz'], $cut->all());
	}


	public function testGetListValueWithAccessors(): void
    {
		$model    = new TestAccessorEloquentTestStub(['some' => 'foo']);
		$modelTwo = new TestAccessorEloquentTestStub(['some' => 'bar']);
		$data     = new Collection([$model, $modelTwo]);

		$this->assertEquals(['foo', 'bar'], $data->lists('some'));
	}


	public function testTransform(): void
    {
		$data = new Collection(['taylor', 'colin', 'shawn']);
		$data->transform(function($item) { return strrev($item); });
		$this->assertEquals(['rolyat', 'niloc', 'nwahs'], array_values($data->all()));
	}


	public function testFirstWithCallback(): void
    {
		$data = new Collection(['foo', 'bar', 'baz']);
		$result = $data->first(function($value, $key) { return $value === 'bar'; });
		$this->assertEquals('bar', $result);
	}


	public function testFirstWithCallbackAndDefault(): void
    {
		$data = new Collection(['foo', 'bar']);
		$result = $data->first(function($key, $value) { return $value === 'baz'; }, 'default');
		$this->assertEquals('default', $result);
	}


	public function testGroupByAttribute(): void
    {
		$data = new Collection(
            [['rating' => 1, 'url' => '1'], ['rating' => 1, 'url' => '1'], ['rating' => 2, 'url' => '2']]
        );

		$result = $data->groupBy('rating');
		$this->assertEquals(
            [1 => [['rating' => 1, 'url' => '1'], ['rating' => 1, 'url' => '1']], 2 => [['rating' => 2, 'url' => '2']]], $result->toArray());

		$result = $data->groupBy('url');
		$this->assertEquals(
            [1 => [['rating' => 1, 'url' => '1'], ['rating' => 1, 'url' => '1']], 2 => [['rating' => 2, 'url' => '2']]], $result->toArray());
	}


	public function testKeyByAttribute(): void
    {
		$data = new Collection([['rating' => 1, 'name' => '1'], ['rating' => 2, 'name' => '2'], ['rating' => 3, 'name' => '3']]);
		$result = $data->keyBy('rating');
		$this->assertEquals([1 => ['rating' => 1, 'name' => '1'], 2 => ['rating' => 2, 'name' => '2'], 3 => ['rating' => 3, 'name' => '3']], $result->all());
	}


	public function testContains(): void
    {
		$c = new Collection([1, 3, 5]);

		$this->assertTrue($c->contains(1));
		$this->assertFalse($c->contains(2));
		$this->assertTrue($c->contains(function($value) { return $value < 5; }));
		$this->assertFalse($c->contains(function($value) { return $value > 5; }));
	}


	public function testGettingSumFromCollection(): void
    {
		$c = new Collection([(object) ['foo' => 50], (object) ['foo' => 50]]);
		$this->assertEquals(100, $c->sum('foo'));

		$c = new Collection([(object) ['foo' => 50], (object) ['foo' => 50]]);
		$this->assertEquals(100, $c->sum(function($i) { return $i->foo; }));
	}


	public function testGettingSumFromEmptyCollection(): void
    {
		$c = new Collection();
		$this->assertEquals(0, $c->sum('foo'));
	}


	public function testCanSumValuesWithoutACallback(): void
    {
		$c = new Collection([1, 2, 3, 4, 5]);
		$this->assertEquals(15, $c->sum());
	}


	public function testValueRetrieverAcceptsDotNotation(): void
    {
		$c = new Collection([
			(object) ['id' => 1, 'foo' => ['bar' => 'B']], (object) ['id' => 2, 'foo' => ['bar' => 'A']]
        ]);

		$c = $c->sortBy('foo.bar');
		$this->assertEquals([2, 1], $c->lists('id'));
	}


	public function testPullRetrievesItemFromCollection(): void
    {
		$c = new Collection(['foo', 'bar']);

		$this->assertEquals('foo', $c->pull(0));
	}


	public function testPullRemovesItemFromCollection(): void
    {
		$c = new Collection(['foo', 'bar']);
		$c->pull(0);
		$this->assertEquals([1 => 'bar'], $c->all());
	}


	public function testPullReturnsDefault(): void
    {
		$c = new Collection([]);
		$value = $c->pull(0, 'foo');
		$this->assertEquals('foo', $value);
	}


	public function testRejectRemovesElementsPassingTruthTest(): void
    {
		$c = new Collection(['foo', 'bar']);
		$this->assertEquals(['foo'], $c->reject('bar')->values()->all());

		$c = new Collection(['foo', 'bar']);
		$this->assertEquals(['foo'], $c->reject(function($v) { return $v == 'bar'; })->values()->all());

		$c = new Collection(['foo', null]);
		$this->assertEquals(['foo'], $c->reject(null)->values()->all());

		$c = new Collection(['foo', 'bar']);
		$this->assertEquals(['foo', 'bar'], $c->reject('baz')->values()->all());

		$c = new Collection(['foo', 'bar']);
		$this->assertEquals(['foo', 'bar'], $c->reject(function($v) { return $v == 'baz'; })->values()->all());
	}


	public function testKeys(): void
    {
		$c = new Collection(['name' => 'taylor', 'framework' => 'laravel']);
		$this->assertEquals(['name', 'framework'], $c->keys());
	}

}

class TestAccessorEloquentTestStub
{
	protected array $attributes = [];

	public function __construct($attributes)
	{
		$this->attributes = $attributes;
	}


	public function __get($attribute)
	{
		$accessor = 'get' .lcfirst((string) $attribute). 'Attribute';
		if (method_exists($this, $accessor)) {
			return $this->$accessor();
		}

		return $this->$attribute;
	}


	public function getSomeAttribute()
	{
		return $this->attributes['some'];
	}
}
