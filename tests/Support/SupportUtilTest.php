<?php

namespace Illuminate\Tests\Support;

use Illuminate\Support\Util;
use PHPUnit\Framework\TestCase;

class SupportUtilTest extends TestCase
{
    public function testUnwrapIfClosure(): void
    {
        $this->assertSame('foo', Util::unwrapIfClosure('foo'));
        $this->assertSame('foo', Util::unwrapIfClosure(function () {
            return 'foo';
        }));
    }
}