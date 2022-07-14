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
}