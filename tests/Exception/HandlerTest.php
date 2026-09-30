<?php

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Exception\ExceptionDisplayerInterface;
use Illuminate\Exception\ExceptionHandlerAdapter;
use Illuminate\Exception\Handler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\TokenMismatchException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class HandlerTest extends TestCase
{
    use ProphecyTrait;

    private const bool DEBUG_ENABLED = true;
    private const bool DEBUG_DISABLED = false;

    private Application $app;
    private HandlerTestLogger $log;
    private ObjectProphecy|ExceptionDisplayerInterface $plainDisplayer;
    private ObjectProphecy|ExceptionDisplayerInterface $debugDisplayer;

    #[WithoutErrorHandler]
    public function testHandleErrorExceptionArguments(): void
    {
		$error = null;
		try {
			$this->getHandler()->handleError(E_USER_ERROR, 'message', '/path/to/file', 111, []);
		} catch (ErrorException $error) {}

		$this->assertInstanceOf('ErrorException', $error);
		$this->assertSame(E_USER_ERROR, $error->getSeverity(), 'error handler should not modify severity');
		$this->assertSame('message', $error->getMessage(), 'error handler should not modify message');
		$this->assertSame('/path/to/file', $error->getFile(), 'error handler should not modify path');
		$this->assertSame(111, $error->getLine(), 'error handler should not modify line number');
		$this->assertSame(0, $error->getCode(), 'error handler should use 0 exception code');
	}

	#[WithoutErrorHandler]
	public function testHandleErrorOptionalArguments(): void
    {
		$error = null;
		try {
			$this->getHandler()->handleError(E_USER_ERROR, 'message');
		} catch (ErrorException $error) {}

		$this->assertInstanceOf('ErrorException', $error);
		$this->assertSame('', $error->getFile(), 'error handler should use correct default path');
		$this->assertSame(0, $error->getLine(), 'error handler should use correct default line');
	}

    #[Test]
    public function renderCallbacksRunInRegistrationOrder(): void
    {
        $handler = $this->getHandler();
        $handler->renderable(fn (Throwable $e) => new Response('any', 500));
        $handler->renderable(fn (NotFoundHttpException $e) => new Response('missing', 404));

        $response = $handler->render($this->app['request'], new NotFoundHttpException('not found'));

        self::assertSame('any', $response->getContent());
    }

    #[Test]
    public function aCallbackReturningNullPassesToTheNextOne(): void
    {
        $handler = $this->getHandler();
        $handler->renderable(fn (NotFoundHttpException $e) => null);
        $handler->renderable(fn (NotFoundHttpException|AccessDeniedHttpException $e) => new Response('missing', $e->getStatusCode()));

        $response = $handler->render($this->app['request'], new NotFoundHttpException('not found'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('missing', $response->getContent());
    }

    #[Test]
    public function callbacksSeeTheExceptionAfterPrepareException(): void
    {
        $handler = $this->getHandler();
        $handler->renderable(fn (NotFoundHttpException $e) => new Response(get_class($e->getPrevious()), 404));
        $handler->renderable(fn (HttpException $e) => new Response(get_class($e->getPrevious()), $e->getStatusCode()));

        $missing = $handler->render($this->app['request'], new ModelNotFoundException('no model'));
        $expired = $handler->render($this->app['request'], new TokenMismatchException('expired'));

        self::assertSame(ModelNotFoundException::class, $missing->getContent());
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame(TokenMismatchException::class, $expired->getContent());
        self::assertSame(419, $expired->getStatusCode());
    }

    #[Test]
    public function callbacksGetTheRequest(): void
    {
        $handler = $this->getHandler();
        $handler->renderable(fn (Throwable $e, Request $request) => new JsonResponse(array('path' => $request->path())));

        $response = $handler->render(Request::create('/things/1'), new DomainException('nope'));

        self::assertSame('{"path":"things\/1"}', $response->getContent());
    }

    #[Test]
    public function anHttpResponseExceptionWithoutACallbackReturnsItsResponse(): void
    {
        $response = $this->getHandler()->render($this->app['request'], new HttpResponseException(new Response('stop', 422)));

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function withoutACallbackResponseTheDebugDisplayerRenders(): void
    {
        $handler = $this->getHandler();
        $handler->renderable(fn (BindingResolutionException $e) => null);

        $handler->handleException(new BindingResolutionException('not found'));

        $this->debugDisplayer->display(Argument::type(BindingResolutionException::class))->shouldBeCalledOnce();
        $this->plainDisplayer->display(Argument::cetera())->shouldNotBeCalled();
    }

    #[Test]
    public function withoutACallbackResponseInProductionThePlainDisplayerRenders(): void
    {
        $handler = $this->getHandler(self::DEBUG_DISABLED);

        $handler->handleException(new BindingResolutionException('not found'));

        $this->debugDisplayer->display(Argument::cetera())->shouldNotBeCalled();
        $this->plainDisplayer->display(Argument::type(BindingResolutionException::class))->shouldBeCalledOnce();
    }

    #[Test]
    public function aThrowingCallbackIsDisplayed(): void
    {
        $handler = $this->getHandler(self::DEBUG_DISABLED);
        $handler->renderable(function (BindingResolutionException $e) {
            throw new DomainException('Nooo');
        });

        $handler->handleException(new BindingResolutionException('not found'));

        $this->plainDisplayer->display(Argument::type(DomainException::class))->shouldBeCalledOnce();
    }

    #[Test]
    public function handleExceptionReportsThenRendersForTheCurrentRequest(): void
    {
        $handler = $this->getHandler();
        $handler->renderable(fn (Throwable $e, Request $request) => new Response($request->path(), 500));

        $response = $handler->handleException(new DomainException('boom'));

        self::assertSame('hello', $response->getContent());
        self::assertSame(array(array('boom', DomainException::class)), $this->log->logged());
    }

    #[Test]
    public function reportLogsTheExceptionWithItsMessage(): void
    {
        $this->getHandler()->report($exception = new DomainException('boom'));

        self::assertSame(array(array('boom', DomainException::class)), $this->log->logged());
        self::assertSame($exception, $this->log->entries[0][1]['exception']);
    }

    #[Test]
    public function internalDontReportTypesAreNotReportedUntilStopIgnoring(): void
    {
        $handler = $this->getHandler();

        $handler->report(new NotFoundHttpException('missing'));
        $handler->report(new TokenMismatchException('expired'));
        self::assertSame(array(), $this->log->logged());

        $handler->stopIgnoring(HttpException::class);
        $handler->report(new NotFoundHttpException('missing'));
        self::assertSame(array(array('missing', NotFoundHttpException::class)), $this->log->logged());
        self::assertFalse($handler->shouldReport(new TokenMismatchException('expired')));
    }

    #[Test]
    public function dontReportSkipsTheTypeAndItsSubclasses(): void
    {
        $handler = $this->getHandler();
        $handler->dontReport(array(LogicException::class));

        $handler->report(new DomainException('boom'));
        $handler->report(new RuntimeException('kept'));

        self::assertSame(array(array('kept', RuntimeException::class)), $this->log->logged());
    }

    #[Test]
    public function reportCallbacksRunInOrderAndFalseStopsReporting(): void
    {
        $handler = $this->getHandler();
        $seen = array();
        $handler->reportable(function (DomainException $e) use (&$seen) { $seen[] = 'domain'; });
        $handler->reportable(function (Throwable $e) use (&$seen) { $seen[] = 'any'; return false; });
        $handler->reportable(function (Throwable $e) use (&$seen) { $seen[] = 'never'; });

        $handler->report(new DomainException('boom'));
        $handler->report(new RuntimeException('other'));

        self::assertSame(array('domain', 'any', 'any'), $seen);
        self::assertSame(array(), $this->log->logged());
    }

    #[Test]
    public function aStoppedReportCallbackEndsReportingAfterItRuns(): void
    {
        $handler = $this->getHandler();
        $seen = array();
        $handler->reportable(function (Throwable $e) use (&$seen) { $seen[] = 'first'; })->stop();
        $handler->reportable(function (Throwable $e) use (&$seen) { $seen[] = 'second'; });

        $handler->report(new DomainException('boom'));

        self::assertSame(array('first'), $seen);
        self::assertSame(array(), $this->log->logged());
    }

    #[Test]
    public function anExceptionsOwnReportMethodReplacesTheDefaultReport(): void
    {
        $this->getHandler()->report($exception = new HandlerTestSelfReportingException('boom'));

        self::assertTrue($exception->reported);
        self::assertSame(array(), $this->log->logged());
    }

    #[Test]
    public function exceptionsConfigurationRegistersOnTheHandler(): void
    {
        $handler = $this->getHandler();
        $exceptions = new Exceptions($handler);

        $exceptions->dontReport(DomainException::class)->stopIgnoring(HttpException::class);
        $exceptions->render(fn (Throwable $e) => new Response('rendered', 500));
        $exceptions->report(function (Throwable $e) { return false; });

        self::assertFalse($handler->shouldReport(new DomainException('boom')));
        self::assertTrue($handler->shouldReport(new NotFoundHttpException('missing')));
        self::assertSame('rendered', $handler->render($this->app['request'], new RuntimeException('x'))->getContent());
        $handler->report(new RuntimeException('x'));
        self::assertSame(array(), $this->log->logged());
    }

    #[Test]
    public function theWorkerAdapterReportsAndRendersThroughTheHandler(): void
    {
        $handler = $this->getHandler();
        $handler->renderable(fn (Throwable $e) => new Response('rendered', 500));
        $adapter = new ExceptionHandlerAdapter($handler);

        $adapter->report(new DomainException('boom'));

        self::assertSame(array(array('boom', DomainException::class)), $this->log->logged());
        self::assertFalse($adapter->shouldReport(new NotFoundHttpException('missing')));
        self::assertSame('rendered', $adapter->render($this->app['request'], new DomainException('boom'))->getContent());
    }

    protected function getHandler(bool $debug = self::DEBUG_ENABLED): Handler
    {
        $this->app = new Application;
        $this->app->instance('request', Request::create('/hello'));
        $this->app->instance('log', $this->log = new HandlerTestLogger);
        $this->plainDisplayer = $this->prophesize(ExceptionDisplayerInterface::class);
        $this->debugDisplayer = $this->prophesize(ExceptionDisplayerInterface::class);
        $this->plainDisplayer->display(Argument::cetera())->willReturn(new Response('plain', 500));
        $this->debugDisplayer->display(Argument::cetera())->willReturn(new Response('debug', 500));

        return new Handler(
            $this->app,
            $this->plainDisplayer->reveal(),
            $this->debugDisplayer->reveal(),
            $debug
        );
    }
}

class HandlerTestLogger
{
    public $entries = array();

    public function error($message, array $context = array())
    {
        $this->entries[] = array($message, $context);
    }

    public function logged()
    {
        return array_map(fn ($entry) => array($entry[0], get_class($entry[1]['exception'])), $this->entries);
    }
}

class HandlerTestSelfReportingException extends Exception
{
    public $reported = false;

    public function report()
    {
        $this->reported = true;
    }
}
