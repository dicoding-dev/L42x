<?php

use Illuminate\Container\BindingResolutionException;
use Illuminate\Exception\ExceptionDisplayerInterface;
use Illuminate\Exception\Handler;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Contracts\ResponsePreparerInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class HandlerTest extends TestCase
{
    use ProphecyTrait;

    private ObjectProphecy|ResponsePreparerInterface $responsePreparer;
    private ObjectProphecy|ExceptionDisplayerInterface $plainDisplayer;
    private ObjectProphecy|ExceptionDisplayerInterface $debugDisplayer;

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

    /**
     * @test
     */
    public function handleExceptions(): void
    {
        $handler = $this->getHandler();

        $handler->error(function(BindingResolutionException $exception, $code, $fromConsole) {
            return new JsonResponse([
                'from_console'  => $fromConsole,
                'message'       => $exception->getMessage()
            ], $code);
        });

        $response = $handler->handleException(new BindingResolutionException("not resolved", 111));

        self::assertEquals(500, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"from_console":false,"message":"not resolved"}', $response->getContent());
    }

    /**
     * @test
     */
    public function handlingHttpException(): void
    {
        $handler = $this->getHandler();

        $handler->error(function(NotFoundHttpException $exception, $code, $fromConsole) use (&$exceptionCaught) {
            return new JsonResponse([
                'from_console'  => $fromConsole,
                'message'       => $exception->getMessage()
            ], $code);
        });

        $response = $handler->handleException(new NotFoundHttpException("not found"));

        self::assertEquals(404, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"from_console":false,"message":"not found"}', $response->getContent());
    }

    /**
     * @test
     */
    public function whenVeryBasicHandlerIsUsedButTypeHintedHandlerReturnsResponse(): void
    {
        $handler = $this->getHandler();

        $handler->error(function(Throwable $throwable, $code, $fromConsole) {
            return new JsonResponse([
                'from_console'  => $fromConsole,
                'message'       => $throwable->getMessage()
            ], 500);
        });

        $handler->error(function(NotFoundHttpException $exception, $code, $fromConsole) {
            return new JsonResponse([
                'from_console'  => $fromConsole,
                'message'       => $exception->getMessage()
            ], $code);
        });

        $response = $handler->handleException(new NotFoundHttpException("not found"));

        self::assertEquals(404, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"from_console":false,"message":"not found"}', $response->getContent());
    }

    /**
     * @test
     */
    public function whenBasicErrorHandlerIsUsed(): void
    {
        $handler = $this->getHandler();

        $handler->error(function(Throwable $throwable, $code, $fromConsole) {
            return new JsonResponse([
                'from_console'  => $fromConsole,
                'message'       => 'Error'
            ], 500);
        });

        $handler->error(function(NotFoundHttpException $exception, $code, $fromConsole) {
            return new JsonResponse([
                'from_console'  => $fromConsole,
                'message'       => $exception->getMessage()
            ], $code);
        });

        $response = $handler->handleException(new BindingResolutionException("not found"));

        self::assertEquals(500, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"from_console":false,"message":"Error"}', $response->getContent());
    }

    /**
     * @test
     */
    public function whenHandlerDoesNotReturnResponse(): void
    {
        $handler = $this->getHandler();

        $handler->error(function(BindingResolutionException $exception, $code, $fromConsole) {
            return;
        });

        $handler->handleException(new BindingResolutionException("not found"));

        $this->debugDisplayer->display(Argument::type(BindingResolutionException::class))->shouldBeCalledOnce();
        $this->plainDisplayer->display(Argument::cetera())->shouldNotBeCalled();
    }

    /**
     * @test
     */
    public function whenHandlerDoesNotReturnResponseInProduction(): void
    {
        $handler = $this->getHandler(false);

        $handler->error(function(BindingResolutionException $exception, $code, $fromConsole) {
            return;
        });

        $handler->handleException(new BindingResolutionException("not found"));

        $this->debugDisplayer->display(Argument::cetera())->shouldNotBeCalled();
        $this->plainDisplayer->display(Argument::type(BindingResolutionException::class))->shouldBeCalledOnce();
    }

    /**
     * @test
     */
    public function whenHandlerThrows(): void
    {
        $handler = $this->getHandler();

        $handler->error(function(BindingResolutionException $exception, $code, $fromConsole) {
            throw new DomainException('Nooo');
        });

        $handler->handleException(new BindingResolutionException("not found"));

        $this->debugDisplayer->display(Argument::type(DomainException::class))->shouldBeCalledOnce();
        $this->plainDisplayer->display(Argument::cetera())->shouldNotBeCalled();
    }

    /**
     * @test
     */
    public function whenHandlerThrowsInProduction(): void
    {
        $handler = $this->getHandler(false);

        $handler->error(function(BindingResolutionException $exception, $code, $fromConsole) {
            throw new DomainException('Nooo');
        });

        $handler->handleException(new BindingResolutionException("not found"));

        $this->debugDisplayer->display(Argument::cetera())->shouldNotBeCalled();
        $this->plainDisplayer->display(Argument::type(DomainException::class))->shouldBeCalledOnce();
    }

    protected function getHandler(bool $debug = true): Handler
    {
        $this->responsePreparer = $this->prophesize(ResponsePreparerInterface::class);
        $this->plainDisplayer = $this->prophesize(ExceptionDisplayerInterface::class);
        $this->debugDisplayer = $this->prophesize(ExceptionDisplayerInterface::class);

        $this->responsePreparer->prepareResponse(Argument::cetera())->will(function($args) {
            return $args[0];
        });

        return new Handler(
            $this->responsePreparer->reveal(),
            $this->plainDisplayer->reveal(),
            $this->debugDisplayer->reveal(),
            $debug
        );
    }
}
