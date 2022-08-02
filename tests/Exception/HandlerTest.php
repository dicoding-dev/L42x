<?php

use Illuminate\Container\BindingResolutionException;
use Illuminate\Exception\ExceptionDisplayerInterface;
use Illuminate\Exception\Handler;
use Illuminate\Support\Contracts\ResponsePreparerInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

class HandlerTest extends TestCase
{
    use ProphecyTrait;

    protected function setUp(): void
    {
        $this->responsePreparer = $this->prophesize(ResponsePreparerInterface::class);
        $this->plainDisplayer = $this->prophesize(ExceptionDisplayerInterface::class);
        $this->debugDisplayer = $this->prophesize(ExceptionDisplayerInterface::class);
        $this->handler = new Handler(
            $this->responsePreparer->reveal(),
            $this->plainDisplayer->reveal(),
            $this->debugDisplayer->reveal()
        );
    }


    public function testHandleErrorExceptionArguments(): void
    {
		$error = null;
		try {
			$this->handler->handleError(E_USER_ERROR, 'message', '/path/to/file', 111, []);
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
			$this->handler->handleError(E_USER_ERROR, 'message');
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
        $exceptionCaught = null;
        $this->handler->error(function(BindingResolutionException $exception) use (&$exceptionCaught) {
            $exceptionCaught = $exception;
        });

        $this->debugDisplayer->display(Argument::type(BindingResolutionException::class))->shouldBeCalledOnce();

        $this->handler->handleException(new BindingResolutionException("not resolved", 111));

        self::assertNotNull($exceptionCaught);
    }
}
