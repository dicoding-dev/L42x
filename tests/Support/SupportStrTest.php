<?php

use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class SupportStrTest extends TestCase
{

    /**
     * Test the Str::words method.
     *
     * @group laravel
     */
    public function testStringCanBeLimitedByWords(): void
    {
        $this->assertEquals('Taylor...', Str::words('Taylor Otwell', 1));
        $this->assertEquals('Taylor___', Str::words('Taylor Otwell', 1, '___'));
		$this->assertEquals('Taylor Otwell', Str::words('Taylor Otwell', 3));
	}


	public function testStringTrimmedOnlyWhereNecessary(): void
    {
		$this->assertEquals(' Taylor Otwell ', Str::words(' Taylor Otwell ', 3));
		$this->assertEquals(' Taylor...', Str::words(' Taylor Otwell ', 1));
	}


	public function testStringTitle(): void
    {
		$this->assertEquals('Jefferson Costella', Str::title('jefferson costella'));
		$this->assertEquals('Jefferson Costella', Str::title('jefFErson coSTella'));
        $this->assertEquals('Admin_Role', Str::title('admin_role'));
        $this->assertEquals('', Str::title(null));
        $this->assertEquals('', Str::title(''));
	}


	public function testStringWithoutWordsDoesntProduceError(): void
    {
		$nbsp = chr(0xC2).chr(0xA0);
		$this->assertEquals(' ', Str::words(' '));
		$this->assertEquals($nbsp, Str::words($nbsp));
	}


	public function testStartsWith(): void
    {
		$this->assertTrue(Str::startsWith('jason', 'jas'));
		$this->assertTrue(Str::startsWith('jason', 'jason'));
		$this->assertTrue(Str::startsWith('jason', ['jas']));
		$this->assertFalse(Str::startsWith('jason', 'day'));
		$this->assertFalse(Str::startsWith('jason', ['day']));
		$this->assertFalse(Str::startsWith('jason', ''));
	}

    public function testEquals(): void
    {
        self::assertTrue(Str::equals('1234', '1234'));
        self::assertTrue(Str::equals('Laravel', 'Laravel'));
        self::assertTrue(Str::equals('Laravel', 'laRaVeL'));
        self::assertFalse(Str::equals('Laravel', 'laRaVeL', true));
        self::assertTrue(Str::equals('', ''));
        self::assertTrue(Str::equals('', null));
        self::assertTrue(Str::equals());
        self::assertFalse(Str::equals(null, 'Laravel'));
        self::assertFalse(Str::equals('Laravel'));
        self::assertFalse(Str::equals('Laravel', null, true));
    }


	public function testEndsWith(): void
    {
		$this->assertTrue(Str::endsWith('jason', 'on'));
		$this->assertTrue(Str::endsWith('jason', 'jason'));
		$this->assertTrue(Str::endsWith('jason', ['on']));
		$this->assertFalse(Str::endsWith('jason', 'no'));
		$this->assertFalse(Str::endsWith('jason', ['no']));
		$this->assertFalse(Str::endsWith('jason', ''));
		$this->assertFalse(Str::endsWith('7', ' 7'));
	}


	public function testStrContains(): void
    {
		$this->assertTrue(Str::contains('taylor', 'ylo'));
		$this->assertTrue(Str::contains('taylor', ['ylo']));
		$this->assertFalse(Str::contains('taylor', 'xxx'));
		$this->assertFalse(Str::contains('taylor', ['xxx']));
		$this->assertFalse(Str::contains('taylor', ''));
        $this->assertFalse(Str::contains('taylor', null));
        $this->assertFalse(Str::contains('', 'y'));
        $this->assertFalse(Str::contains(null, 'y'));
	}


	public function testParseCallback(): void
    {
		$this->assertEquals(['Class', 'method'], Str::parseCallback('Class@method', 'foo'));
		$this->assertEquals(['Class', 'foo'], Str::parseCallback('Class', 'foo'));
	}


	public function testSlug(): void
    {
		$this->assertEquals('hello-world', Str::slug('hello world'));
		$this->assertEquals('hello-world', Str::slug('hello-world'));
		$this->assertEquals('hello-world', Str::slug('hello_world'));
		$this->assertEquals('hello_world', Str::slug('hello_world', '_'));
	}


	public function testFinish(): void
    {
		$this->assertEquals('abbc', Str::finish('ab', 'bc'));
		$this->assertEquals('abbc', Str::finish('abbcbc', 'bc'));
		$this->assertEquals('abcbbc', Str::finish('abcbbcbc', 'bc'));
	}


	public function testIs(): void
    {
		$this->assertTrue(Str::is('/', '/'));
		$this->assertFalse(Str::is('/', ' /'));
		$this->assertFalse(Str::is('/', '/a'));
		$this->assertTrue(Str::is('foo/*', 'foo/bar/baz'));
		$this->assertTrue(Str::is('*/foo', 'blah/baz/foo'));
        $this->assertFalse(Str::is('*/foo', ''));
        $this->assertFalse(Str::is('*/foo', null));
	}


	public function testLower(): void
    {
		$this->assertEquals('foo bar baz', Str::lower('FOO BAR BAZ'));
		$this->assertEquals('foo bar baz', Str::lower('fOo Bar bAz'));
        $this->assertEquals('', Str::lower(null));
	}


	public function testUpper(): void
    {
		$this->assertEquals('FOO BAR BAZ', Str::upper('foo bar baz'));
		$this->assertEquals('FOO BAR BAZ', Str::upper('foO bAr BaZ'));
        $this->assertEquals('', Str::upper(null));
	}


	public function testLimit(): void
    {
		$this->assertEquals('Laravel is...', Str::limit('Laravel is a free, open source PHP web application framework.', 10));
        $this->assertEquals('', Str::limit(null));
        $this->assertEquals('', Str::limit(''));
	}


	public function testLength(): void
    {
		$this->assertEquals(11, Str::length('foo bar baz'));
        $this->assertEquals(0, Str::length(''));
        $this->assertEquals(0, Str::length(null));
	}


	public function testQuickRandom(): void
    {
        $randomInteger = mt_rand(1, 100);
        $this->assertEquals($randomInteger, strlen(Str::quickRandom($randomInteger)));
        $this->assertIsString(Str::quickRandom());
        $this->assertEquals(16, strlen(Str::quickRandom()));
    }


	public function testRandom(): void
    {
        $this->assertEquals(16, strlen(Str::random()));
        $randomInteger = mt_rand(1, 100);
        $this->assertEquals($randomInteger, strlen(Str::random($randomInteger)));
        $this->assertIsString(Str::random());
    }

    public function testNumberFormat(): void
    {
        $this->assertEquals('1,000,000', Str::numberFormat(1000000));
        $this->assertEquals('150.000,00', Str::numberFormat(150000, 2, ',', '.'));
        $this->assertEquals('0', Str::numberFormat());
        $this->assertEquals('0', Str::numberFormat(null));
    }

    public function testReplace(): void
    {
        $this->assertSame('foo bar laravel', Str::replace('baz', 'laravel', 'foo bar baz'));
        $this->assertSame('foo bar baz 8.x', Str::replace('?', '8.x', 'foo bar baz ?'));
        $this->assertSame('foo/bar/baz', Str::replace(' ', '/', 'foo bar baz'));
        $this->assertSame('foo bar baz', Str::replace(['?1', '?2', '?3'], ['foo', 'bar', 'baz'], '?1 ?2 ?3'));
        $this->assertEquals('', Str::replace('Yo', 'Laravel', ''));
        $this->assertEquals('', Str::replace('Yo', 'Laravel', null));
    }
}
