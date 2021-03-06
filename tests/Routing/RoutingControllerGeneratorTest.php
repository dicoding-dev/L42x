<?php

use Illuminate\Routing\Generators\ControllerGenerator;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class RoutingControllerGeneratorTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testFullControllerCanBeCreated()
    {
        $gen = new ControllerGenerator($files = m::mock('Illuminate\Filesystem\Filesystem[put]'));
        $controller = file_get_contents(__DIR__ . '/fixtures/controller.php');
        $files->shouldReceive('put')->once()->andReturnUsing(
            function ($path, $actual)
		{
			$_SERVER['__controller.actual'] = $actual;
		});
		$gen->make('FooController', __DIR__);

		$controller = preg_replace('/\s+/', '', $controller);
		$actual = preg_replace('/\s+/', '', $_SERVER['__controller.actual']);
		$this->assertEquals($controller, $actual);
	}


	public function testOnlyPartialControllerCanBeCreated()
	{
		$gen = new ControllerGenerator($files = m::mock('Illuminate\Filesystem\Filesystem[put]'));
		$controller = file_get_contents(__DIR__.'/fixtures/only_controller.php');
		$files->shouldReceive('put')->once()->andReturnUsing(function($path, $actual)
		{
			$_SERVER['__controller.actual'] = $actual;
		});
		$gen->make('FooController', __DIR__, ['only' => ['index', 'show']]);

		$controller = preg_replace('/\s+/', '', $controller);
		$actual = preg_replace('/\s+/', '', $_SERVER['__controller.actual']);
		$this->assertEquals($controller, $actual);
	}


	public function testExceptPartialControllerCanBeCreated()
	{
		$gen = new ControllerGenerator($files = m::mock('Illuminate\Filesystem\Filesystem[put]'));
		$controller = file_get_contents(__DIR__.'/fixtures/except_controller.php');
		$files->shouldReceive('put')->once()->andReturnUsing(function($path, $actual)
		{
			$_SERVER['__controller.actual'] = $actual;
		});
		$gen->make('FooController', __DIR__, ['except' => ['index', 'show']]);

		$controller = preg_replace('/\s+/', '', $controller);
		$actual = preg_replace('/\s+/', '', $_SERVER['__controller.actual']);
		$this->assertEquals($controller, $actual);
	}

}
