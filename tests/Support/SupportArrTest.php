<?php

namespace Support;

use ArrayObject;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use stdClass;

class SupportArrTest extends TestCase
{
    public function testAccessible(): void
    {
        $this->assertTrue(Arr::accessible([]));
        $this->assertTrue(Arr::accessible([1, 2]));
        $this->assertTrue(Arr::accessible(['a' => 1, 'b' => 2]));
        $this->assertTrue(Arr::accessible(new Collection));

        $this->assertFalse(Arr::accessible(null));
        $this->assertFalse(Arr::accessible('abc'));
        $this->assertFalse(Arr::accessible(new stdClass));
        $this->assertFalse(Arr::accessible((object) ['a' => 1, 'b' => 2]));
    }

    public function testAdd(): void
    {
        $array = Arr::add(['name' => 'Desk'], 'price', 100);
        $this->assertEquals(['name' => 'Desk', 'price' => 100], $array);

        $this->assertEquals(['surname' => 'Mövsümov'], Arr::add([], 'surname', 'Mövsümov'));
        $this->assertEquals(['developer' => ['name' => 'Ferid']], Arr::add([], 'developer.name', 'Ferid'));
        $this->assertEquals([1 => 'hAz'], Arr::add([], 1, 'hAz'));
        $this->assertEquals([1 => [1 => 'hAz']], Arr::add([], 1.1, 'hAz'));
    }

    public function testCollapse(): void
    {
        $data = [['foo', 'bar'], ['baz']];
        $this->assertEquals(['foo', 'bar', 'baz'], Arr::collapse($data));

        $array = [[1], [2], [3], ['foo', 'bar'], new Collection(['baz', 'boom'])];
        $this->assertEquals([1, 2, 3, 'foo', 'bar', 'baz', 'boom'], Arr::collapse($array));
    }

    public function testCrossJoin(): void
    {
        // Single dimension
        $this->assertSame(
            [[1, 'a'], [1, 'b'], [1, 'c']],
            Arr::crossJoin([1], ['a', 'b', 'c'])
        );

        // Square matrix
        $this->assertSame(
            [[1, 'a'], [1, 'b'], [2, 'a'], [2, 'b']],
            Arr::crossJoin([1, 2], ['a', 'b'])
        );

        // Rectangular matrix
        $this->assertSame(
            [[1, 'a'], [1, 'b'], [1, 'c'], [2, 'a'], [2, 'b'], [2, 'c']],
            Arr::crossJoin([1, 2], ['a', 'b', 'c'])
        );

        // 3D matrix
        $this->assertSame(
            [
                [1, 'a', 'I'], [1, 'a', 'II'], [1, 'a', 'III'],
                [1, 'b', 'I'], [1, 'b', 'II'], [1, 'b', 'III'],
                [2, 'a', 'I'], [2, 'a', 'II'], [2, 'a', 'III'],
                [2, 'b', 'I'], [2, 'b', 'II'], [2, 'b', 'III'],
            ],
            Arr::crossJoin([1, 2], ['a', 'b'], ['I', 'II', 'III'])
        );

        // With 1 empty dimension
        $this->assertEmpty(Arr::crossJoin([], ['a', 'b'], ['I', 'II', 'III']));
        $this->assertEmpty(Arr::crossJoin([1, 2], [], ['I', 'II', 'III']));
        $this->assertEmpty(Arr::crossJoin([1, 2], ['a', 'b'], []));

        // With empty arrays
        $this->assertEmpty(Arr::crossJoin([], [], []));
        $this->assertEmpty(Arr::crossJoin([], []));
        $this->assertEmpty(Arr::crossJoin([]));

        // Not really a proper usage, still, test for preserving BC
        $this->assertSame([[]], Arr::crossJoin());
    }

    public function testDivide(): void
    {
        [$keys, $values] = Arr::divide(['name' => 'Desk']);
        $this->assertEquals(['name'], $keys);
        $this->assertEquals(['Desk'], $values);
    }

    public function testDot(): void
    {
        $this->assertEquals(['foo.bar' => 'baz'], Arr::dot(['foo' => ['bar' => 'baz']]));
        $this->assertEquals([], Arr::dot([]));
        $this->assertEquals(['foo' => []], Arr::dot(['foo' => []]));
        $this->assertEquals(['foo.bar' => []], Arr::dot(['foo' => ['bar' => []]]));
        $this->assertEquals(
            ['name' => 'taylor', 'languages.php' => true],
            Arr::dot(['name' => 'taylor', 'languages' => ['php' => true]])
        );
    }

    public function testUndot(): void
    {
        $array = Arr::undot([
            'user.name' => 'Taylor',
            'user.age' => 25,
            'user.languages.0' => 'PHP',
            'user.languages.1' => 'C#',
        ]);
        $this->assertEquals(['user' => ['name' => 'Taylor', 'age' => 25, 'languages' => ['PHP', 'C#']]], $array);

        $array = Arr::undot([
            'pagination.previous' => '<<',
            'pagination.next' => '>>',
        ]);
        $this->assertEquals(['pagination' => ['previous' => '<<', 'next' => '>>']], $array);

        $array = Arr::undot([
            'foo',
            'foo.bar' => 'baz',
            'foo.baz' => ['a' => 'b'],
        ]);
        $this->assertEquals(['foo', 'foo' => ['bar' => 'baz', 'baz' => ['a' => 'b']]], $array);
    }

    public function testExcept(): void
    {
        $array = ['name' => 'taylor', 'age' => 26];
        $this->assertEquals(['age' => 26], Arr::except($array, ['name']));
        $this->assertEquals(['age' => 26], Arr::except($array, 'name'));

        $array = ['name' => 'taylor', 'framework' => ['language' => 'PHP', 'name' => 'Laravel']];
        $this->assertEquals(['name' => 'taylor'], Arr::except($array, 'framework'));
        $this->assertEquals(['name' => 'taylor', 'framework' => ['name' => 'Laravel']], Arr::except($array, 'framework.language'));
        $this->assertEquals(['framework' => ['language' => 'PHP']], Arr::except($array, ['name', 'framework.name']));

        $array = [1 => 'hAz', 2 => [5 => 'foo', 12 => 'baz']];
        $this->assertEquals([1 => 'hAz'], Arr::except($array, 2));
        $this->assertEquals([1 => 'hAz', 2 => [12 => 'baz']], Arr::except($array, 2.5));
    }

    public function testExists(): void
    {
        $this->assertTrue(Arr::exists([1], 0));
        $this->assertTrue(Arr::exists([null], 0));
        $this->assertTrue(Arr::exists(['a' => 1], 'a'));
        $this->assertTrue(Arr::exists(['a' => null], 'a'));
        $this->assertTrue(Arr::exists(new Collection(['a' => null]), 'a'));

        $this->assertFalse(Arr::exists([1], 1));
        $this->assertFalse(Arr::exists([null], 1));
        $this->assertFalse(Arr::exists(['a' => 1], 0));
        $this->assertFalse(Arr::exists(new Collection(['a' => null]), 'b'));
    }

    public function testFirst(): void
    {
        $array = [100, 200, 300];

        // Callback is null and array is empty
        $this->assertNull(Arr::first([], null));
        $this->assertSame('foo', Arr::first([], null, 'foo'));
        $this->assertSame('bar', Arr::first([], null, function () {
            return 'bar';
        }));

        // Callback is null and array is not empty
        $this->assertEquals(100, Arr::first($array));

        // Callback is not null and array is not empty
        $value = Arr::first($array, function ($value) {
            return $value >= 150;
        });
        $this->assertEquals(200, $value);

        // Callback is not null, array is not empty but no satisfied item
        $value2 = Arr::first($array, function ($value) {
            return $value > 300;
        });
        $value3 = Arr::first($array, function ($value) {
            return $value > 300;
        }, 'bar');
        $value4 = Arr::first($array, function ($value) {
            return $value > 300;
        }, function () {
            return 'baz';
        });
        $this->assertNull($value2);
        $this->assertSame('bar', $value3);
        $this->assertSame('baz', $value4);
    }

    public function testLast(): void
    {
        $array = [100, 200, 300];

        $last = Arr::last($array, function ($value) {
            return $value < 250;
        });
        $this->assertEquals(200, $last);

        $last = Arr::last($array, function ($value, $key) {
            return $key < 2;
        });
        $this->assertEquals(200, $last);

        $this->assertEquals(300, Arr::last($array));
    }

    public function testFlatten(): void
    {
        // Flat arrays are unaffected
        $array = ['#foo', '#bar', '#baz'];
        $this->assertEquals(['#foo', '#bar', '#baz'], Arr::flatten($array));

        // Nested arrays are flattened with existing flat items
        $array = [['#foo', '#bar'], '#baz'];
        $this->assertEquals(['#foo', '#bar', '#baz'], Arr::flatten($array));

        // Flattened array includes "null" items
        $array = [['#foo', null], '#baz', null];
        $this->assertEquals(['#foo', null, '#baz', null], Arr::flatten($array));

        // Sets of nested arrays are flattened
        $array = [['#foo', '#bar'], ['#baz']];
        $this->assertEquals(['#foo', '#bar', '#baz'], Arr::flatten($array));

        // Deeply nested arrays are flattened
        $array = [['#foo', ['#bar']], ['#baz']];
        $this->assertEquals(['#foo', '#bar', '#baz'], Arr::flatten($array));

        // Nested arrays are flattened alongside arrays
        $array = [new Collection(['#foo', '#bar']), ['#baz']];
        $this->assertEquals(['#foo', '#bar', '#baz'], Arr::flatten($array));

        // Nested arrays containing plain arrays are flattened
        $array = [new Collection(['#foo', ['#bar']]), ['#baz']];
        $this->assertEquals(['#foo', '#bar', '#baz'], Arr::flatten($array));

        // Nested arrays containing arrays are flattened
        $array = [['#foo', new Collection(['#bar'])], ['#baz']];
        $this->assertEquals(['#foo', '#bar', '#baz'], Arr::flatten($array));

        // Nested arrays containing arrays containing arrays are flattened
        $array = [['#foo', new Collection(['#bar', ['#zap']])], ['#baz']];
        $this->assertEquals(['#foo', '#bar', '#zap', '#baz'], Arr::flatten($array));
    }

    public function testFlattenWithDepth(): void
    {
        // No depth flattens recursively
        $array = [['#foo', ['#bar', ['#baz']]], '#zap'];
        $this->assertEquals(['#foo', '#bar', '#baz', '#zap'], Arr::flatten($array));

        // Specifying a depth only flattens to that depth
        $array = [['#foo', ['#bar', ['#baz']]], '#zap'];
        $this->assertEquals(['#foo', ['#bar', ['#baz']], '#zap'], Arr::flatten($array, 1));

        $array = [['#foo', ['#bar', ['#baz']]], '#zap'];
        $this->assertEquals(['#foo', '#bar', ['#baz'], '#zap'], Arr::flatten($array, 2));
    }

    public function testGet(): void
    {
        $array = ['products.desk' => ['price' => 100]];
        $this->assertEquals(['price' => 100], Arr::get($array, 'products.desk'));

        $array = ['products' => ['desk' => ['price' => 100]]];
        $value = Arr::get($array, 'products.desk');
        $this->assertEquals(['price' => 100], $value);

        // Test null array values
        $array = ['foo' => null, 'bar' => ['baz' => null]];
        $this->assertNull(Arr::get($array, 'foo', 'default'));
        $this->assertNull(Arr::get($array, 'bar.baz', 'default'));

        // Test direct ArrayAccess object
        $array = ['products' => ['desk' => ['price' => 100]]];
        $arrayAccessObject = new ArrayObject($array);
        $value = Arr::get($arrayAccessObject, 'products.desk');
        $this->assertEquals(['price' => 100], $value);

        // Test array containing ArrayAccess object
        $arrayAccessChild = new ArrayObject(['products' => ['desk' => ['price' => 100]]]);
        $array = ['child' => $arrayAccessChild];
        $value = Arr::get($array, 'child.products.desk');
        $this->assertEquals(['price' => 100], $value);

        // Test array containing multiple nested ArrayAccess objects
        $arrayAccessChild = new ArrayObject(['products' => ['desk' => ['price' => 100]]]);
        $arrayAccessParent = new ArrayObject(['child' => $arrayAccessChild]);
        $array = ['parent' => $arrayAccessParent];
        $value = Arr::get($array, 'parent.child.products.desk');
        $this->assertEquals(['price' => 100], $value);

        // Test missing ArrayAccess object field
        $arrayAccessChild = new ArrayObject(['products' => ['desk' => ['price' => 100]]]);
        $arrayAccessParent = new ArrayObject(['child' => $arrayAccessChild]);
        $array = ['parent' => $arrayAccessParent];
        $value = Arr::get($array, 'parent.child.desk');
        $this->assertNull($value);

        // Test missing ArrayAccess object field
        $arrayAccessObject = new ArrayObject(['products' => ['desk' => null]]);
        $array = ['parent' => $arrayAccessObject];
        $value = Arr::get($array, 'parent.products.desk.price');
        $this->assertNull($value);

        // Test null ArrayAccess object fields
        $array = new ArrayObject(['foo' => null, 'bar' => new ArrayObject(['baz' => null])]);
        $this->assertNull(Arr::get($array, 'foo', 'default'));
        $this->assertNull(Arr::get($array, 'bar.baz', 'default'));

        // Test null key returns the whole array
        $array = ['foo', 'bar'];
        $this->assertEquals($array, Arr::get($array, null));

        // Test $array not an array
        $this->assertSame('default', Arr::get(null, 'foo', 'default'));
        $this->assertSame('default', Arr::get(false, 'foo', 'default'));

        // Test $array not an array and key is null
        $this->assertSame('default', Arr::get(null, null, 'default'));

        // Test $array is empty and key is null
        $this->assertEmpty(Arr::get([], null));
        $this->assertEmpty(Arr::get([], null, 'default'));

        // Test numeric keys
        $array = [
            'products' => [
                ['name' => 'desk'],
                ['name' => 'chair'],
            ],
        ];
        $this->assertSame('desk', Arr::get($array, 'products.0.name'));
        $this->assertSame('chair', Arr::get($array, 'products.1.name'));

        // Test return default value for non-existing key.
        $array = ['names' => ['developer' => 'taylor']];
        $this->assertSame('dayle', Arr::get($array, 'names.otherDeveloper', 'dayle'));
        $this->assertSame('dayle', Arr::get($array, 'names.otherDeveloper', function () {
            return 'dayle';
        }));
    }

    public function testHas(): void
    {
        $array = ['products.desk' => ['price' => 100]];
        $this->assertTrue(Arr::has($array, 'products.desk'));

        $array = ['products' => ['desk' => ['price' => 100]]];
        $this->assertTrue(Arr::has($array, 'products.desk'));
        $this->assertTrue(Arr::has($array, 'products.desk.price'));
        $this->assertFalse(Arr::has($array, 'products.foo'));
        $this->assertFalse(Arr::has($array, 'products.desk.foo'));

        $array = ['foo' => null, 'bar' => ['baz' => null]];
        $this->assertTrue(Arr::has($array, 'foo'));
        $this->assertTrue(Arr::has($array, 'bar.baz'));

        $array = new ArrayObject(['foo' => 10, 'bar' => new ArrayObject(['baz' => 10])]);
        $this->assertTrue(Arr::has($array, 'foo'));
        $this->assertTrue(Arr::has($array, 'bar'));
        $this->assertTrue(Arr::has($array, 'bar.baz'));
        $this->assertFalse(Arr::has($array, 'xxx'));
        $this->assertFalse(Arr::has($array, 'xxx.yyy'));
        $this->assertFalse(Arr::has($array, 'foo.xxx'));
        $this->assertFalse(Arr::has($array, 'bar.xxx'));

        $array = new ArrayObject(['foo' => null, 'bar' => new ArrayObject(['baz' => null])]);
        $this->assertTrue(Arr::has($array, 'foo'));
        $this->assertTrue(Arr::has($array, 'bar.baz'));

        $array = ['foo', 'bar'];
        $this->assertFalse(Arr::has($array, null));

        $this->assertFalse(Arr::has(null, 'foo'));
        $this->assertFalse(Arr::has(false, 'foo'));

        $this->assertFalse(Arr::has(null, null));
        $this->assertFalse(Arr::has([], null));

        $array = ['products' => ['desk' => ['price' => 100]]];
        $this->assertTrue(Arr::has($array, ['products.desk']));
        $this->assertTrue(Arr::has($array, ['products.desk', 'products.desk.price']));
        $this->assertTrue(Arr::has($array, ['products', 'products']));
        $this->assertFalse(Arr::has($array, ['foo']));
        $this->assertFalse(Arr::has($array, []));
        $this->assertFalse(Arr::has($array, ['products.desk', 'products.price']));

        $array = [
            'products' => [
                ['name' => 'desk'],
            ],
        ];
        $this->assertTrue(Arr::has($array, 'products.0.name'));
        $this->assertFalse(Arr::has($array, 'products.0.price'));

        $this->assertFalse(Arr::has([], [null]));
        $this->assertFalse(Arr::has(null, [null]));

        $this->assertTrue(Arr::has(['' => 'some'], ''));
        $this->assertTrue(Arr::has(['' => 'some'], ['']));
        $this->assertFalse(Arr::has([''], ''));
        $this->assertFalse(Arr::has([], ''));
        $this->assertFalse(Arr::has([], ['']));
    }
}