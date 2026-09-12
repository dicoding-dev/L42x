<?php

use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class ConsoleApplicationTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testAddSetsLaravelInstance()
    {
        $app = $this->getMock(\Illuminate\Console\Application::class, ['addToParent']);
        $app->setLaravel('foo');
        $command = m::mock(\Illuminate\Console\Command::class);
		$command->shouldReceive('setLaravel')->once()->with('foo');
		$app->expects($this->once())->method('addToParent')->with($this->equalTo($command))->willReturn($command);
		$result = $app->add($command);

		$this->assertEquals($command, $result);
	}


	public function testLaravelNotSetOnSymfonyCommands()
	{
		$app = $this->getMock(\Illuminate\Console\Application::class, ['addToParent']);
		$app->setLaravel('foo');
		$command = m::mock(Command::class);
		$command->shouldReceive('setLaravel')->never();
		$app->expects($this->once())->method('addToParent')->with($this->equalTo($command))->willReturn($command);
		$result = $app->add($command);

		$this->assertEquals($command, $result);
	}


	public function testResolveAddsCommandViaApplicationResolution()
	{
		$app = $this->getMock(\Illuminate\Console\Application::class, ['addToParent']);
		$command = m::mock(Command::class);
		$app->setLaravel(['foo' => $command]);
		$app->expects($this->once())->method('addToParent')->with($this->equalTo($command))->willReturn($command);
		$result = $app->resolve('foo');

		$this->assertEquals($command, $result);
	}


	public function testResolveCommandsCallsResolveForAllCommandsItsGiven()
	{
		$app = m::mock('Illuminate\Console\Application[resolve]');
		$app->shouldReceive('resolve')->twice()->with('foo');
		$app->resolveCommands('foo', 'foo');
	}


	public function testResolveCommandsCallsResolveForAllCommandsItsGivenViaArray()
	{
		$app = m::mock('Illuminate\Console\Application[resolve]');
		$app->shouldReceive('resolve')->twice()->with('foo');
		$app->resolveCommands(['foo', 'foo']);
	}


	public function testExecuteResolvesHandleThenFallsBackToFire()
	{
		$execute = new \ReflectionMethod(\Illuminate\Console\Command::class, 'execute');
		$execute->setAccessible(true);
		$input = new ArrayInput([]);
		$output = new NullOutput;

		// handle() is preferred (L13 idiom)
		$this->assertSame(0, $execute->invoke(new ConsoleHandleStub, $input, $output));
		$this->assertEquals('handle', $_SERVER['__console.ran']);

		// fire() still runs as the L4.2 fallback when no handle() exists
		$execute->invoke(new ConsoleFireStub, $input, $output);
		$this->assertEquals('fire', $_SERVER['__console.ran']);
	}

}

class ConsoleHandleStub extends \Illuminate\Console\Command
{
	protected $name = 'stub:handle';
	public function handle() { $_SERVER['__console.ran'] = 'handle'; }
}

class ConsoleFireStub extends \Illuminate\Console\Command
{
	protected $name = 'stub:fire';
	public function fire() { $_SERVER['__console.ran'] = 'fire'; }
}
