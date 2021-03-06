<?php

use L4\Tests\BackwardCompatibleTestCase;

class CacheMemcachedStoreTest extends BackwardCompatibleTestCase {

    protected function setUp(): void
    {
        $this->markTestSkipped("We dont use Memcached");
    }

    public function testGetReturnsNullWhenNotFound()
	{
		$memcache = $this->getMock(stdClass::class, ['get', 'getResultCode']);
		$memcache->expects($this->once())->method('get')->with($this->equalTo('foo:bar'))->willReturn(null);
		$memcache->expects($this->once())->method('getResultCode')->willReturn(1);
		$store = new Illuminate\Cache\MemcachedStore($memcache, 'foo');
		$this->assertNull($store->get('bar'));
	}


	public function testMemcacheValueIsReturned()
	{
		$memcache = $this->getMock(stdClass::class, ['get', 'getResultCode']);
		$memcache->expects($this->once())->method('get')->willReturn('bar');
		$memcache->expects($this->once())->method('getResultCode')->willReturn(0);
		$store = new Illuminate\Cache\MemcachedStore($memcache);
		$this->assertEquals('bar', $store->get('foo'));
	}


	public function testSetMethodProperlyCallsMemcache()
	{
		$memcache = $this->getMock(Memcached::class, ['set']);
		$memcache->expects($this->once())->method('set')->with($this->equalTo('foo'), $this->equalTo('bar'), $this->equalTo(60));
		$store = new Illuminate\Cache\MemcachedStore($memcache);
		$store->put('foo', 'bar', 1);
	}


	public function testIncrementMethodProperlyCallsMemcache()
	{
		$memcache = $this->getMock(Memcached::class, ['increment']);
		$memcache->expects($this->once())->method('increment')->with($this->equalTo('foo'), $this->equalTo(5));
		$store = new Illuminate\Cache\MemcachedStore($memcache);
		$store->increment('foo', 5);
	}


	public function testDecrementMethodProperlyCallsMemcache()
	{
		$memcache = $this->getMock(Memcached::class, ['decrement']);
		$memcache->expects($this->once())->method('decrement')->with($this->equalTo('foo'), $this->equalTo(5));
		$store = new Illuminate\Cache\MemcachedStore($memcache);
		$store->decrement('foo', 5);
	}


	public function testStoreItemForeverProperlyCallsMemcached()
	{
		$memcache = $this->getMock(Memcached::class, ['set']);
		$memcache->expects($this->once())->method('set')->with($this->equalTo('foo'), $this->equalTo('bar'), $this->equalTo(0));
		$store = new Illuminate\Cache\MemcachedStore($memcache);
		$store->forever('foo', 'bar');
	}


	public function testForgetMethodProperlyCallsMemcache()
	{
		$memcache = $this->getMock(Memcached::class, ['delete']);
		$memcache->expects($this->once())->method('delete')->with($this->equalTo('foo'));
		$store = new Illuminate\Cache\MemcachedStore($memcache);
		$store->forget('foo');
	}

}
