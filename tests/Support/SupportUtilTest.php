<?php

namespace Illuminate\Tests\Support;

use Illuminate\Pagination\Factory;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Util;
use PHPUnit\Framework\TestCase;

class SupportUtilTest extends TestCase
{
    public function testUnwrapIfClosure(): void
    {
        $this->assertSame('foo', Util::unwrapIfClosure('foo'));
        $this->assertSame('foo', Util::unwrapIfClosure(static function () {
            return 'foo';
        }));
    }

    /**
     * @test
     */
    public function isValueEmpty(): void
    {
        $this->assertTrue(Util::isEmpty(null));
        $this->assertTrue(Util::isEmpty([]));
        $this->assertTrue(Util::isEmpty(0));
        $this->assertTrue(Util::isEmpty(''));
        $this->assertTrue(Util::isEmpty(false));
        $this->assertTrue(Util::isEmpty(0.0));

        $this->assertFalse(Util::isEmpty(new \stdClass()));
        $this->assertFalse(Util::isEmpty(['abc']));
        $this->assertFalse(Util::isEmpty(1));
        $this->assertFalse(Util::isEmpty('a'));
        $this->assertFalse(Util::isEmpty(true));
        $this->assertFalse(Util::isEmpty(1.1));
    }

    /**
     * @test
     */
    public function isEmptyOnEmptyPaginatorObject(): void
    {
        $pagination = new Paginator(
            $this->prophesize(Factory::class)->reveal(),
            [],
            0
        );

        $this->assertTrue(Util::isEmpty($pagination));
    }

    /**
     * @test
     */
    public function isEmptyOnNonEmptyPaginatorObject(): void
    {
        $pagination = new Paginator(
            $this->prophesize(Factory::class)->reveal(),
            ['1', '2', '3'],
            3
        );

        $this->assertFalse(Util::isEmpty($pagination));
    }
}