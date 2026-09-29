<?php

use Illuminate\Routing\Generators\ControllerGenerator;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class RoutingMakeControllerCommandTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testGeneratorIsCalledWithProperOptions()
    {
        $command = new Illuminate\Routing\Console\MakeControllerCommand(
            $gen = m::mock(ControllerGenerator::class), __DIR__
        );
        $gen->shouldReceive('make')->once()->with(
            'FooController',
            __DIR__,
            ['only' => [], 'except' => []]
        );
        $command->setLaravel(tap(new Illuminate\Foundation\Application, fn($a) => $a->instance('env', 'testing')));
        $this->runCommand($command, ['name' => 'FooController']);
	}


	public function testGeneratorIsCalledWithProperOptionsForExceptAndOnly()
	{
		$command = new Illuminate\Routing\Console\MakeControllerCommand($gen = m::mock(
            ControllerGenerator::class
        ), __DIR__);
		$gen->shouldReceive('make')->once()->with('FooController', __DIR__.'/foo/bar', ['only' => ['foo', 'bar'], 'except' => ['baz', 'boom']]
        );
		$laravel = new Illuminate\Foundation\Application;
		$laravel->instance('path.base', __DIR__.'/foo');
		$laravel->instance('env', 'testing');
		$command->setLaravel($laravel);
		$this->runCommand($command, ['name' => 'FooController', '--only' => 'foo,bar', '--except' => 'baz,boom', '--path' => 'bar']
        );
	}


	public function runCommand($command, $input = [], $output = null)
	{
		$output = $output ?: new Symfony\Component\Console\Output\NullOutput;

		return $command->run(new Symfony\Component\Console\Input\ArrayInput($input), $output);
	}

}
