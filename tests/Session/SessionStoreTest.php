<?php

use Illuminate\Cookie\CookieJar;
use Illuminate\Session\CookieSessionHandler;
use Illuminate\Session\Store;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;

class SessionStoreTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testSessionIsLoadedFromHandler()
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('read')->once()->with($this->getSessionId())->andReturn(
            serialize(['foo' => 'bar', 'bagged' => ['name' => 'taylor']])
        );
        $session->registerBag(new Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag('bagged'));
		$session->start();

		$this->assertEquals('bar', $session->get('foo'));
		$this->assertEquals('baz', $session->get('bar', 'baz'));
		$this->assertTrue($session->has('foo'));
		$this->assertFalse($session->has('bar'));
		$this->assertEquals('taylor', $session->getBag('bagged')->get('name'));
		$this->assertInstanceOf(MetadataBag::class, $session->getMetadataBag());
		$this->assertTrue($session->isStarted());

		$session->put('baz', 'boom');
		$this->assertTrue($session->has('baz'));
	}


	public function testSessionGetBagException()
	{
		$this->expectException('InvalidArgumentException');
		$session = $this->getSession();
		$session->getBag('doesNotExist');
	}


	public function testSessionMigration()
	{
		$session = $this->getSession();
		$oldId = $session->getId();
		$session->getHandler()->shouldReceive('destroy')->never();
		$this->assertTrue($session->migrate());
		$this->assertNotEquals($oldId, $session->getId());


		$session = $this->getSession();
		$oldId = $session->getId();
		$session->getHandler()->shouldReceive('destroy')->once()->with($oldId);
		$this->assertTrue($session->migrate(true));
		$this->assertNotEquals($oldId, $session->getId());
	}


	public function testSessionRegeneration()
	{
		$session = $this->getSession();
		$oldId = $session->getId();
		$session->getHandler()->shouldReceive('destroy')->never();
		$this->assertTrue($session->regenerate());
		$this->assertNotEquals($oldId, $session->getId());
	}


	public function testCantSetInvalidId()
	{
		$session = $this->getSession();

		$session->setId(null);
		$this->assertFalse(null == $session->getId());

		$session->setId(['a']);
		$this->assertFalse(['a'] == $session->getId());

		$session->setId('wrong');
		$this->assertFalse('wrong' == $session->getId());
	}


	public function testSessionInvalidate()
	{
		$session = $this->getSession();
		$oldId = $session->getId();
		$session->set('foo','bar');
		$this->assertGreaterThan(0, count($session->all()));
		$session->getHandler()->shouldReceive('destroy')->never();
		$this->assertTrue($session->invalidate());
		$this->assertNotEquals($oldId, $session->getId());
		$this->assertCount(0, $session->all());
	}


	public function testSessionIsProperlySaved()
	{
		$session = $this->getSession();
		$session->getHandler()->shouldReceive('read')->once()->andReturn(serialize([]));
		$session->start();
		$session->put('foo', 'bar');
		$session->flash('baz', 'boom');
		$session->getHandler()->shouldReceive('write')->once()->with(
			$this->getSessionId(),
			serialize([
				'_token' => $session->token(),
				'foo' => 'bar',
				'baz' => 'boom',
				'flash' => [
					'new' => [],
					'old' => ['baz'],
                ],
				'_sf2_meta' => $session->getBagData('_sf2_meta'),
            ])
		);
		$session->save();

		$this->assertFalse($session->isStarted());
	}


	public function testOldInputFlashing()
	{
		$session = $this->getSession();
		$session->put('boom', 'baz');
		$session->flashInput(['foo' => 'bar', 'bar' => 0]);

		$this->assertTrue($session->hasOldInput('foo'));
		$this->assertEquals('bar', $session->getOldInput('foo'));
		$this->assertEquals(0, $session->getOldInput('bar'));
		$this->assertFalse($session->hasOldInput('boom'));

		$session->ageFlashData();

		$this->assertTrue($session->hasOldInput('foo'));
		$this->assertEquals('bar', $session->getOldInput('foo'));
		$this->assertEquals(0, $session->getOldInput('bar'));
		$this->assertFalse($session->hasOldInput('boom'));
	}


	public function testDataFlashing()
	{
		$session = $this->getSession();
		$session->flash('foo', 'bar');
		$session->flash('bar', 0);

		$this->assertTrue($session->has('foo'));
		$this->assertEquals('bar', $session->get('foo'));
		$this->assertEquals(0, $session->get('bar'));

		$session->ageFlashData();

		$this->assertTrue($session->has('foo'));
		$this->assertEquals('bar', $session->get('foo'));
		$this->assertEquals(0, $session->get('bar'));

		$session->ageFlashData();

		$this->assertFalse($session->has('foo'));
		$this->assertNull($session->get('foo'));
	}


	public function testDataMergeNewFlashes()
	{
		$session = $this->getSession();
		$session->flash('foo', 'bar');
		$session->set('fu', 'baz');
		$session->set('flash.old', ['qu']);
		$this->assertNotFalse(array_search('foo', $session->get('flash.new')));
		$this->assertFalse(array_search('fu', $session->get('flash.new')));
		$session->keep(['fu','qu']);
		$this->assertNotFalse(array_search('foo', $session->get('flash.new')));
		$this->assertNotFalse(array_search('fu', $session->get('flash.new')));
		$this->assertNotFalse(array_search('qu', $session->get('flash.new')));
		$this->assertFalse(array_search('qu', $session->get('flash.old')));
	}


	public function testReflash()
	{
		$session = $this->getSession();
		$session->flash('foo', 'bar');
		$session->set('flash.old', ['foo']);
		$session->reflash();
		$this->assertNotFalse(array_search('foo', $session->get('flash.new')));
		$this->assertFalse(array_search('foo', $session->get('flash.old')));
	}


	public function testReplace()
	{
		$session = $this->getSession();
		$session->set('foo', 'bar');
		$session->set('qu', 'ux');
		$session->replace(['foo' => 'baz']);
		$this->assertEquals('baz', $session->get('foo'));
		$this->assertEquals('ux', $session->get('qu'));
	}


	public function testRemove()
	{
		$session = $this->getSession();
		$session->set('foo', 'bar');
		$pulled = $session->remove('foo');
		$this->assertFalse($session->has('foo'));
		$this->assertEquals('bar', $pulled);
	}


	public function testClear()
	{
		$session = $this->getSession();
		$session->set('foo', 'bar');

		$bag = new Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag('bagged');
		$bag->set('qu', 'ux');
		$session->registerBag($bag);

		$session->clear();
		$this->assertFalse($session->has('foo'));
		$this->assertFalse($session->getBag('bagged')->has('qu'));

		$session->set('foo', 'bar');
		$session->getBag('bagged')->set('qu', 'ux');

		$session->flush();
		$this->assertFalse($session->has('foo'));
		$this->assertFalse($session->getBag('bagged')->has('qu'));
	}


	public function testHasOldInputWithoutKey()
	{
		$session = $this->getSession();
		$session->flash('boom', 'baz');
		$this->assertFalse($session->hasOldInput());

		$session->flashInput(['foo' => 'bar']);
		$this->assertTrue($session->hasOldInput());
	}


	public function testHandlerNeedsRequest()
    {
        $session = $this->getSession();
        $this->assertFalse($session->handlerNeedsRequest());
        $session->getHandler()->shouldReceive('setRequest')->never();

        $session = new Store('test', m::mock(new CookieSessionHandler(new CookieJar(), 60)));
        $this->assertTrue($session->handlerNeedsRequest());
        $session->getHandler()->shouldReceive('setRequest')->once();
        $request = new Request();
        $session->setRequestOnHandler($request);
    }


	public function testToken()
	{
		$session = $this->getSession();
		$this->assertEquals($session->token(), $session->getToken());
	}


	public function testRegenerateToken()
	{
		$session = $this->getSession();
		$token = $session->getToken();
		$session->regenerateToken();
		$this->assertNotEquals($token, $session->getToken());
	}


	public function testName()
	{
		$session = $this->getSession();
		$this->assertEquals($session->getName(), $this->getSessionName());
		$session->setName('foo');
		$this->assertEquals($session->getName(), 'foo');
	}


    public function testSetPreviousUrl()
    {
        $session = $this->getSession();
        $session->setPreviousUrl('https://example.com/foo/bar');

        $this->assertTrue($session->has('_previous.url'));
        $this->assertSame('https://example.com/foo/bar', $session->get('_previous.url'));

        $url = $session->previousUrl();
        $this->assertSame('https://example.com/foo/bar', $url);
    }


	public function getSession()
	{
		$reflection = new ReflectionClass(Store::class);
		return $reflection->newInstanceArgs($this->getMocks());
	}


	public function getMocks()
	{
		return [
			$this->getSessionName(),
			m::mock('SessionHandlerInterface'),
			$this->getSessionId(),
        ];
	}


	public function getSessionId()
	{
		return 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	}


	public function getSessionName()
	{
		return 'name';
	}

}
