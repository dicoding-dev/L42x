<?php

use Illuminate\Cookie\CookieJar;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

class CookieTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testCookiesAreCreatedWithProperOptions()
    {
        $cookie = $this->getCreator();
        $cookie->setDefaultPathAndDomain('foo', 'bar');
        $c = $cookie->make('color', 'blue', 10, '/path', '/domain', true, false);
		$this->assertEquals('blue', $c->getValue());
		$this->assertFalse($c->isHttpOnly());
		$this->assertTrue($c->isSecure());
		$this->assertEquals('/domain', $c->getDomain());
		$this->assertEquals('/path', $c->getPath());

		$c2 = $cookie->forever('color', 'blue', '/path', '/domain', true, false);
		$this->assertEquals('blue', $c2->getValue());
		$this->assertFalse($c2->isHttpOnly());
		$this->assertTrue($c2->isSecure());
		$this->assertEquals('/domain', $c2->getDomain());
		$this->assertEquals('/path', $c2->getPath());

		$c3 = $cookie->forget('color');
		$this->assertNull($c3->getValue());
		$this->assertTrue($c3->getExpiresTime() < time());
	}


	public function testCookiesAreCreatedWithProperOptionsUsingDefaultPathAndDomain()
	{
		$cookie = $this->getCreator();
		$cookie->setDefaultPathAndDomain('/path', '/domain');
		$c = $cookie->make('color', 'blue', 10, null, null, true, false);
		$this->assertEquals('blue', $c->getValue());
		$this->assertFalse($c->isHttpOnly());
		$this->assertTrue($c->isSecure());
		$this->assertEquals('/domain', $c->getDomain());
		$this->assertEquals('/path', $c->getPath());
	}


	public function testSameSiteAndRawWidening()
	{
		$cookie = $this->getCreator();

		// behavior-preserving default: L4.2/Symfony effective SameSite = lax
		$this->assertSame('lax', $cookie->make('a', 'b')->getSameSite());
		$this->assertFalse($cookie->make('a', 'b')->isRaw());

		// per-cookie overrides via the widened signature
		$c = $cookie->make('a', 'b', 0, null, null, null, true, true, 'strict');
		$this->assertSame('strict', $c->getSameSite());
		$this->assertTrue($c->isRaw());
	}


	public function testQueuedCookies()
	{
		$cookie = $this->getCreator();
		$this->assertEmpty($cookie->getQueuedCookies());
		$this->assertFalse($cookie->hasQueued('foo'));
		$cookie->queue($cookie->make('foo','bar'));
		$this->assertTrue($cookie->hasQueued('foo'));
		$this->assertInstanceOf(Cookie::class, $cookie->queued('foo'));
		$cookie->queue('qu','ux');
		$this->assertTrue($cookie->hasQueued('qu'));
		$this->assertInstanceOf(Cookie::class, $cookie->queued('qu'));
		$this->assertCount(2, $cookie->getQueuedCookies());
	}


	public function testUnqueue()
	{
		$cookie = $this->getCreator();
		$cookie->queue($cookie->make('foo','bar'));
		$this->assertTrue($cookie->hasQueued('foo'));
		$cookie->unqueue('foo');
		$this->assertEmpty($cookie->getQueuedCookies());
		$this->assertFalse($cookie->hasQueued('foo'));
	}


	public function testPathAwareQueuedCookies()
	{
		$cookie = $this->getCreator();
		$cookie->queue($cookie->make('foo', 'a', 0, '/a'));
		$cookie->queue($cookie->make('foo', 'b', 0, '/b'));

		$this->assertCount(2, $cookie->getQueuedCookies());
		$this->assertSame('a', $cookie->queued('foo', null, '/a')->getValue());
		$this->assertSame('b', $cookie->queued('foo', null, '/b')->getValue());
		$this->assertSame('b', $cookie->queued('foo')->getValue());

		$cookie->unqueue('foo', '/a');
		$this->assertNull($cookie->queued('foo', null, '/a'));
		$this->assertSame('b', $cookie->queued('foo', null, '/b')->getValue());
		$this->assertCount(1, $cookie->getQueuedCookies());

		$cookie->flushQueuedCookies();
		$this->assertEmpty($cookie->getQueuedCookies());
	}


	public function testExpireQueuesForgetCookie()
	{
		$cookie = $this->getCreator();
		$cookie->expire('foo');

		$this->assertTrue($cookie->hasQueued('foo'));
		$queued = $cookie->queued('foo');
		$this->assertInstanceOf(Cookie::class, $queued);
		$this->assertTrue($queued->getExpiresTime() < time());
	}


	public function getCreator()
	{
		return new CookieJar(Request::create('/foo', 'GET'), [
			'path'     => '/path',
			'domain'   => '/domain',
			'secure'   => true,
			'httpOnly' => false,
        ]);
	}

}
