<?php

use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Contracts\ArrayableInterface;
use Illuminate\Support\Facades\Response;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class SupportFacadeResponseTest extends BackwardCompatibleTestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container;
        $app->instance(ResponseFactoryContract::class, new ResponseFactory(null));

        Response::clearResolvedInstances();
        Response::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Response::setFacadeApplication(null);
        m::close();
    }


    public function testArrayableSendAsJson()
    {
        $data = m::mock(ArrayableInterface::class);
        $data->shouldReceive('toArray')->andReturn(['foo' => 'bar']);

		$response = Response::json($data);
		$this->assertEquals('{"foo":"bar"}', $response->getContent());
	}


    public function testMakeWorksWithoutViewBinding()
    {
        $response = Response::make('hello', 201);

        $this->assertEquals('hello', $response->getContent());
        $this->assertEquals(201, $response->getStatusCode());
    }


    public function testViewResolvesViewFactoryLazilyFromContainer()
    {
        $viewFactory = m::mock('StdClass');
        $viewFactory->shouldReceive('make')->once()->with('welcome', ['a' => 1])->andReturn('rendered');

        $container = new Container;
        $container->instance('view', $viewFactory);
        $factory = new ResponseFactory($container);

        $this->assertEquals('rendered', $factory->view('welcome', ['a' => 1])->getContent());
    }

}
