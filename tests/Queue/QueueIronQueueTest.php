<?php

use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\IronQueue;
use Illuminate\Queue\Jobs\IronJob;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class QueueIronQueueTest extends BackwardCompatibleTestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        $this->markTestSkipped();
    }

    protected function tearDown(): void
    {
        m::close();
    }


    public function testPushProperlyPushesJobOntoIron()
    {
        $queue = new Illuminate\Queue\IronQueue(
            $iron = m::mock('IronMQ'),
            m::mock(Request::class),
            'default',
            true
        );
        $crypt = m::mock(Encrypter::class);
        $queue->setEncrypter($crypt);
		$crypt->shouldReceive('encrypt')->once()->with(json_encode(
            ['job' => 'foo', 'data' => [1, 2, 3], 'attempts' => 1, 'queue' => 'default']
        ))->andReturn('encrypted');
		$iron->shouldReceive('postMessage')->once()->with('default', 'encrypted', [])->andReturn((object) ['id' => 1]);
		$queue->push('foo', [1, 2, 3]);
	}


	public function testPushProperlyPushesJobOntoIronWithoutEncryption()
	{
		$queue = new Illuminate\Queue\IronQueue($iron = m::mock('IronMQ'), m::mock(Request::class), 'default');
		$crypt = m::mock(Encrypter::class);
		$queue->setEncrypter($crypt);
		$crypt->shouldReceive('encrypt')->never();
		$iron->shouldReceive('postMessage')->once()->with('default', json_encode(['job' => 'foo', 'data' => [1, 2, 3], 'attempts' => 1, 'queue' => 'default']), []
        )->andReturn((object) ['id' => 1]);
		$queue->push('foo', [1, 2, 3]);
	}


	public function testPushProperlyPushesJobOntoIronWithClosures()
	{
		$queue = new Illuminate\Queue\IronQueue($iron = m::mock('IronMQ'), m::mock(Request::class), 'default', true);
		$crypt = m::mock(Encrypter::class);
		$queue->setEncrypter($crypt);
		$name = 'Foo';
		$closure = new Illuminate\Support\SerializableClosure($innerClosure = function() use ($name) { return $name; });
		$crypt->shouldReceive('encrypt')->once()->with(serialize($closure))->andReturn('serial_closure');
		$crypt->shouldReceive('encrypt')->once()->with(json_encode([
			'job' => 'IlluminateQueueClosure', 'data' => ['closure' => 'serial_closure'], 'attempts' => 1, 'queue' => 'default',
        ]))->andReturn('encrypted');
		$iron->shouldReceive('postMessage')->once()->with('default', 'encrypted', [])->andReturn((object) ['id' => 1]);
		$queue->push($innerClosure);
	}


	public function testDelayedPushProperlyPushesJobOntoIron()
	{
		$queue = new Illuminate\Queue\IronQueue($iron = m::mock('IronMQ'), m::mock(Request::class), 'default', true);
		$crypt = m::mock(Encrypter::class);
		$queue->setEncrypter($crypt);
		$crypt->shouldReceive('encrypt')->once()->with(json_encode([
			'job' => 'foo', 'data' => [1, 2, 3], 'attempts' => 1, 'queue' => 'default',
        ]))->andReturn('encrypted');
		$iron->shouldReceive('postMessage')->once()->with('default', 'encrypted', ['delay' => 5]
        )->andReturn((object) ['id' => 1]);
		$queue->later(5, 'foo', [1, 2, 3]);
	}


	public function testDelayedPushProperlyPushesJobOntoIronWithTimestamp()
	{
		$now = Carbon::now();
		$queue = $this->getMock(IronQueue::class, ['getTime'], [
            $iron = m::mock('IronMQ'), m::mock(
            Request::class
        ), 'default', true
        ]);
		$crypt = m::mock(Encrypter::class);
		$queue->setEncrypter($crypt);
		$queue->expects($this->once())->method('getTime')->willReturn($now->getTimestamp());
		$crypt->shouldReceive('encrypt')->once()->with(json_encode(
            ['job' => 'foo', 'data' => [1, 2, 3], 'attempts' => 1, 'queue' => 'default']
        ))->andReturn('encrypted');
		$iron->shouldReceive('postMessage')->once()->with('default', 'encrypted', ['delay' => 5]
        )->andReturn((object) ['id' => 1]);
		$queue->later($now->addSeconds(5), 'foo', [1, 2, 3]);
	}


	public function testPopProperlyPopsJobOffOfIron()
	{
		$queue = new Illuminate\Queue\IronQueue($iron = m::mock('IronMQ'), m::mock(Request::class), 'default', true);
		$crypt = m::mock(Encrypter::class);
		$queue->setEncrypter($crypt);
		$queue->setContainer(m::mock(Container::class));
		$iron->shouldReceive('getMessage')->once()->with('default')->andReturn($job = m::mock('IronMQ_Message'));
		$job->body = 'foo';
		$crypt->shouldReceive('decrypt')->once()->with('foo')->andReturn('foo');
		$result = $queue->pop();

		$this->assertInstanceOf(IronJob::class, $result);
	}


	public function testPopProperlyPopsJobOffOfIronWithoutEncryption()
	{
		$queue = new Illuminate\Queue\IronQueue($iron = m::mock('IronMQ'), m::mock(Request::class), 'default');
		$crypt = m::mock(Encrypter::class);
		$queue->setEncrypter($crypt);
		$queue->setContainer(m::mock(Container::class));
		$iron->shouldReceive('getMessage')->once()->with('default')->andReturn($job = m::mock('IronMQ_Message'));
		$job->body = 'foo';
		$crypt->shouldReceive('decrypt')->never();
		$result = $queue->pop();

		$this->assertInstanceOf(IronJob::class, $result);
	}


	public function testPushedJobsCanBeMarshaled()
	{
		$queue = $this->getMock(IronQueue::class, ['createPushedIronJob'], [
            $iron = m::mock('IronMQ'), $request = m::mock(
            Request::class
        ), 'default', true
        ]);
		$crypt = m::mock(Encrypter::class);
		$queue->setEncrypter($crypt);
		$request->shouldReceive('header')->once()->with('iron-message-id')->andReturn('message-id');
		$request->shouldReceive('getContent')->once()->andReturn($content = json_encode(['foo' => 'bar']));
		$crypt->shouldReceive('decrypt')->once()->with($content)->andReturn($content);
		$job = (object) ['id' => 'message-id', 'body' => json_encode(['foo' => 'bar']), 'pushed' => true];
		$queue->expects($this->once())->method('createPushedIronJob')->with($this->equalTo($job))->willReturn(
            $mockIronJob = m::mock('StdClass')
        );
		$mockIronJob->shouldReceive('fire')->once();

		$response = $queue->marshal();

		$this->assertInstanceOf(Response::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
	}

}
