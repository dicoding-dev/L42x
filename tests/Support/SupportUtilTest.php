<?php

namespace Illuminate\Tests\Support;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Util;
use L4\Tests\BackwardCompatibleTestCase;
use PHPUnit\Framework\Attributes\Test;

class SupportUtilTest extends BackwardCompatibleTestCase
{
    public function testUnwrapIfClosure(): void
    {
        $this->assertSame('foo', Util::unwrapIfClosure('foo'));
        $this->assertSame('foo', Util::unwrapIfClosure(static function () {
            return 'foo';
        }));
    }

    #[Test]
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

    #[Test]
    public function isEmptyOnEmptyPaginatorObject(): void
    {
        $pagination = new Paginator([], 15);

        $this->assertTrue(Util::isEmpty($pagination));
    }

    #[Test]
    public function isEmptyOnNonEmptyPaginatorObject(): void
    {
        $pagination = new Paginator(['1', '2', '3'], 15);

        $this->assertFalse(Util::isEmpty($pagination));
    }
}
