<?php

use Illuminate\Container\BindingResolutionException;
use Illuminate\Exception\ExceptionDisplayerInterface;
use Illuminate\Exception\Handler;
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
        $this->debugDisplayer->display(Argument::type(BindingResolutionException::class))->shouldBeCalledOnce();
        $this->plainDisplayer->display(Argument::cetera())->shouldNotBeCalled();

        $exceptionCaught = new stdClass();
        $handler->error(function(BindingResolutionException $exception, $code, $fromConsole) use (&$exceptionCaught) {
            $exceptionCaught->exception = $exception;
            $exceptionCaught->code = $code;
            $exceptionCaught->fromConsole = $fromConsole;
        });

        $handler->handleException(new BindingResolutionException("not resolved", 111));

        self::assertNotNull($exceptionCaught->exception);
        self::assertInstanceOf(BindingResolutionException::class, $exceptionCaught->exception);
        self::assertEquals(500, $exceptionCaught->code);
        self::assertFalse($exceptionCaught->fromConsole);
    }

    /**
     * @test
     */
    public function handlingHttpException(): void
    {
        $handler = $this->getHandler();
        $this->debugDisplayer->display(Argument::type(NotFoundHttpException::class))->shouldBeCalledOnce();
        $this->plainDisplayer->display(Argument::cetera())->shouldNotBeCalled();

        $exceptionCaught = new stdClass();
        $handler->error(function(NotFoundHttpException $exception, $code, $fromConsole) use (&$exceptionCaught) {
            $exceptionCaught->exception = $exception;
            $exceptionCaught->code = $code;
            $exceptionCaught->fromConsole = $fromConsole;
        });

        $handler->handleException(new NotFoundHttpException("not found"));

        self::assertNotNull($exceptionCaught->exception);
        self::assertInstanceOf(NotFoundHttpException::class, $exceptionCaught->exception);
        self::assertEquals(404, $exceptionCaught->code);
        self::assertFalse($exceptionCaught->fromConsole);
    }

    protected function getHandler(bool $debug = true): Handler
    {
        $this->responsePreparer = $this->prophesize(ResponsePreparerInterface::class);
        $this->plainDisplayer = $this->prophesize(ExceptionDisplayerInterface::class);
        $this->debugDisplayer = $this->prophesize(ExceptionDisplayerInterface::class);
        return new Handler(
            $this->responsePreparer->reveal(),
            $this->plainDisplayer->reveal(),
            $this->debugDisplayer->reveal(),
            $debug
        );
    }
}
