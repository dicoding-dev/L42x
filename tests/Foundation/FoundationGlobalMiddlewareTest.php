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
	public function outsideTestsAnExceptionIsRenderedWhereItIsThrownSoOuterMiddlewareWrapsTheErrorPage()
	{
		$this->app['exception'] = $handler = new GlobalMiddlewareTestExceptionHandler;
		$this->app->setGlobalMiddleware(array(GlobalMiddlewareTestOuter::class, GlobalMiddlewareTestInner::class));
		$this->router->exception = new DomainException('boom');

		$response = $this->app->handle(SymfonyRequest::create('/somewhere'));

		$this->assertSame(500, $response->getStatusCode());
		$this->assertSame('rendered boom', $response->getContent());
		$this->assertSame(array('outer:before', 'inner:before', 'router', 'inner:after', 'outer:after'), $this->trail());
		$this->assertSame(array('boom'), $handler->handled);
	}

	#[Test]
	public function anExceptionFromAMiddlewareIsRenderedAtItsStage()
	{
		$this->app['exception'] = $handler = new GlobalMiddlewareTestExceptionHandler;
		$this->app->setGlobalMiddleware(array(GlobalMiddlewareTestOuter::class, GlobalMiddlewareTestThrowing::class, GlobalMiddlewareTestInner::class));

		$response = $this->app->handle(SymfonyRequest::create('/somewhere'));

		$this->assertSame('rendered middleware failed', $response->getContent());
		$this->assertSame(array('outer:before', 'outer:after'), $this->trail());
		$this->assertSame(array('middleware failed'), $handler->handled);
	}

	#[Test]
	public function underUnitTestsTheExceptionStillReachesTheCaller()
	{
		$this->app['env'] = 'testing';
		$this->app['session'] = new GlobalMiddlewareTestStartedSession;
		$this->app['exception'] = $handler = new GlobalMiddlewareTestExceptionHandler;
		$this->app->setGlobalMiddleware(array(GlobalMiddlewareTestOuter::class));
		$this->router->exception = new DomainException('boom');

		try {
			$this->app->handle(SymfonyRequest::create('/somewhere'));
			$this->fail('The exception should reach the test.');
		} catch (DomainException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		$this->assertSame(array(), $handler->handled);
	}

	#[Test]
	public function runSendsTheHandledResponseAndCallsTheTerminatingCallbacks()
	{
		$terminated = false;
		$this->app->terminating(function () use (&$terminated) { $terminated = true; });
		$this->app->setGlobalMiddleware(array(GlobalMiddlewareTestOuter::class));

		ob_start();
		$this->app->run(Request::create('/somewhere'));
		$output = ob_get_clean();

		$this->assertSame('routed', $output);
		$this->assertTrue($terminated);
		$this->assertSame(array('outer:before', 'router', 'outer:after'), $this->trail());
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
	public function theLaravel42HooksAndStackPhpApiAreGone()
	{
		foreach (array('before', 'after', 'down', 'finish', 'shutdown', 'callFinishCallbacks', 'middleware', 'forgetMiddleware', 'useArraySessions') as $method)
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

class GlobalMiddlewareTestThrowing
{
	public function handle(Request $request, Closure $next)
	{
		throw new RuntimeException('middleware failed');
	}
}

class GlobalMiddlewareTestExceptionHandler
{
	public array $handled = array();

	public function handleException($e)
	{
		$this->handled[] = $e->getMessage();

		return new Response('rendered '.$e->getMessage(), 500);
	}
}

class GlobalMiddlewareTestStartedSession
{
	public function isStarted()
	{
		return true;
	}
}
