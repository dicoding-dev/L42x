<?php

use Illuminate\Pagination\Factory;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;
use Symfony\Contracts\Translation\TranslatorInterface;

class PaginationFactoryTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testCreationOfEnvironment()
    {
        $env = $this->getFactory();
    }


	public function testPaginatorCanBeCreated()
	{
		$env = $this->getFactory();
		$request = Illuminate\Http\Request::create('http://foo.com', 'GET');
		$env->setRequest($request);

		$this->assertInstanceOf(\Illuminate\Pagination\Paginator::class, $env->make(array('foo', 'bar'), 2, 2));
	}


	public function testPaginationViewCanBeCreated()
	{
		$env = $this->getFactory();
		$paginator = m::mock(\Illuminate\Pagination\Paginator::class);
		$env->getViewFactory()->shouldReceive('make')->once()->with('pagination::slider', array('environment' => $env, 'paginator' => $paginator))->andReturn('foo');

		$this->assertEquals('foo', $env->getPaginationView($paginator));
	}


	public function testCurrentPageCanBeRetrieved()
	{
		$env = $this->getFactory();
		$request = Illuminate\Http\Request::create('http://foo.com?page=2', 'GET');
		$env->setRequest($request);

		$this->assertEquals(2, $env->getCurrentPage());

		$env = $this->getFactory();
		$request = Illuminate\Http\Request::create('http://foo.com?page=-1', 'GET');
		$env->setRequest($request);

		$this->assertEquals(1, $env->getCurrentPage());
	}


	public function testSettingCurrentUrlOverrulesRequest()
	{
		$env = $this->getFactory();
		$request = Illuminate\Http\Request::create('http://foo.com?page=2', 'GET');
		$env->setRequest($request);
		$env->setCurrentPage(3);

		$this->assertEquals(3, $env->getCurrentPage());
	}


	public function testCurrentUrlCanBeRetrieved()
	{
		$env = $this->getFactory();
		$request = Illuminate\Http\Request::create('http://foo.com/bar?page=2', 'GET');
		$env->setRequest($request);

		$this->assertEquals('http://foo.com/bar', $env->getCurrentUrl());

		$env = $this->getFactory();
		$request = Illuminate\Http\Request::create('http://foo.com?page=2', 'GET');
		$env->setRequest($request);

		$this->assertEquals('http://foo.com', $env->getCurrentUrl());
	}


	public function testOverridingPageParam()
	{
		$env = $this->getFactory();
		$this->assertEquals('page', $env->getPageName());
		$env->setPageName('foo');
		$this->assertEquals('foo', $env->getPageName());
	}


	protected function getFactory()
    {
        $request = m::mock(\Illuminate\Http\Request::class);
        $view = m::mock(\Illuminate\View\Factory::class);
        $trans = m::mock(TranslatorInterface::class);
        $view->shouldReceive('addNamespace')->once()->with(
            'pagination',
            realpath(__DIR__ . '/../../src/Illuminate/Pagination') . '/views'
        );

        return new Factory($request, $view, $trans, 'page');
    }

}
