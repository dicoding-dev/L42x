<?php

use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class DatabaseProcessorTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testInsertGetIdProcessing()
    {
        $pdo = $this->getMock('ProcessorTestPDOStub');
        $pdo->expects($this->once())->method('lastInsertId')->with($this->equalTo('id'))->will($this->returnValue('1'));
        $connection = m::mock(\Illuminate\Database\Connection::class);
		$connection->shouldReceive('insert')->once()->with('sql', array('foo'));
		$connection->shouldReceive('getPdo')->once()->andReturn($pdo);
		$builder = m::mock(\Illuminate\Database\Query\Builder::class);
		$builder->shouldReceive('getConnection')->andReturn($connection);
		$processor = new Illuminate\Database\Query\Processors\Processor;
		$result = $processor->processInsertGetId($builder, 'sql', array('foo'), 'id');
		$this->assertSame(1, $result);
	}

}

class ProcessorTestPDOStub extends PDO {

	public function __construct() {}
	public function lastInsertId($sequence = null) {}

}
