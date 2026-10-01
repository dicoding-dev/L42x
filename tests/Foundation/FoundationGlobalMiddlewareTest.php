<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class FoundationGlobalMiddlewareTest extends TestCase
{
	private Application $app;
	private GlobalMiddlewareTestRouter $router;

	protected function setUp(): void
	{
		$this->app = new Application;
		$this->app['env'] = 'production';
		$this->app['config'] = array('session.driver' => null);
		$this->app->instance(GlobalMiddlewareTestTrail::class, $trail = new GlobalMiddlewareTestTrail);
		$this->app['router'] = $this->router = new GlobalMiddlewareTestRouter($trail);
	}

	#[Test]
	public function globalMiddlewareWrapsTheRouteDispatchInOrder()
	{
		$this->app->setGlobalMiddleware(array(GlobalMiddlewareTestOuter::class, GlobalMiddlewareTestInner::class));

		$response = $this->handle();

		$this->assertSame('routed', $response->getContent());
		$this->assertSame(array('outer:before', 'inner:before', 'router', 'inner:after', 'outer:after'), $this->trail());
	}

	#[Test]
	public function aMiddlewareResponseShortCircuitsTheRouter()
	{
		$this->app->setGlobalMiddleware(array(GlobalMiddlewareTestDown::class, GlobalMiddlewareTestOuter::class));

		$response = $this->handle();

		$this->assertSame(503, $response->getStatusCode());
		$this->assertSame(array(), $this->trail());
	}

	#[Test]
	public function globalMiddlewareIsSkippedOnlyWhileMiddlewareIsDisabled()
	{
		$this->app->setGlobalMiddleware(array(GlobalMiddlewareTestOuter::class));

		$this->app->instance('middleware.disable', true);
		$this->handle();
		$this->assertSame(array('router'), $this->trail());

		$this->app->instance('middleware.disable', false);
		$this->handle();
		$this->assertSame(array('router', 'outer:before', 'router', 'outer:after'), $this->trail());
	}

	#[Test]
	public function exceptionsLeaveThePipelineForTheForkHandler()
	{
		$this->app->setGlobalMiddleware(array(GlobalMiddlewareTestOuter::class));
		$this->router->exception = new DomainException('boom');

		try {
			$this->handle();
			$this->fail('The exception should reach handle().');
		} catch (DomainException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		$this->assertSame(array('outer:before', 'router'), $this->trail());
	}

	#[Test]
	public function theMiddlewareConfigurationDefinesTheWholeStack()
	{
		$middleware = (new Middleware)->use(array('first' => GlobalMiddlewareTestOuter::class, GlobalMiddlewareTestInner::class));

		$this->app->setGlobalMiddleware($middleware->getGlobalMiddleware());

		$this->assertSame(array(GlobalMiddlewareTestOuter::class, GlobalMiddlewareTestInner::class), $this->app->getGlobalMiddleware());
		$this->assertSame(array(), (new Middleware)->getGlobalMiddleware());
	}

	#[Test]
	public function theLaravel42HooksAreGone()
	{
		foreach (array('before', 'after', 'down', 'finish', 'shutdown', 'callFinishCallbacks') as $method)
		{
			$this->assertFalse(method_exists($this->app, $method), "Application::{$method}() should be gone");
		}
	}

	private function handle(): Response
	{
		return $this->app->handle(SymfonyRequest::create('/somewhere'), HttpKernelInterface::MAIN_REQUEST, false);
	}

	private function trail(): array
	{
		return $this->app->make(GlobalMiddlewareTestTrail::class)->steps;
	}
}

class GlobalMiddlewareTestTrail
{
	public array $steps = array();
}

class GlobalMiddlewareTestRouter
{
	public ?Throwable $exception = null;

	public function __construct(private GlobalMiddlewareTestTrail $trail)
	{
	}

	public function dispatch(Request $request)
	{
		$this->trail->steps[] = 'router';

		if ($this->exception) throw $this->exception;

		return new Response('routed');
	}
}

class GlobalMiddlewareTestOuter
{
	public function __construct(private GlobalMiddlewareTestTrail $trail)
	{
	}

	public function handle(Request $request, Closure $next)
	{
		$this->trail->steps[] = 'outer:before';
		$response = $next($request);
		$this->trail->steps[] = 'outer:after';

		return $response;
	}
}

class GlobalMiddlewareTestInner
{
	public function __construct(private GlobalMiddlewareTestTrail $trail)
	{
	}

	public function handle(Request $request, Closure $next)
	{
		$this->trail->steps[] = 'inner:before';
		$response = $next($request);
		$this->trail->steps[] = 'inner:after';

		return $response;
	}
}

class GlobalMiddlewareTestDown
{
	public function handle(Request $request, Closure $next)
	{
		return new Response('down', 503);
	}
}
