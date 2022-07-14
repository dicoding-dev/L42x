<?php

namespace Support;

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
}