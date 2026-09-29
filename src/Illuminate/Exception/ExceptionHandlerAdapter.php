<?php namespace Illuminate\Exception;

use Throwable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\Console\Application as ConsoleApplication;

/**
 * ponytail: the v13 queue Worker reports every failed job through
 * Contracts\Debug\ExceptionHandler, a contract L4.2 never had. Bridge it to the fork
 * Handler so App::error() handlers keep seeing queue-job exceptions, the same way the
 * L4.2 worker handed them to $app['exception']. Bound only for queue:work (see
 * Queue\FailConsoleServiceProvider). Remove at task 4.5 (the foundation swap brings
 * the v13 exception handler).
 */
class ExceptionHandlerAdapter implements ExceptionHandler {

	/**
	 * The fork exception handler instance.
	 *
	 * @var \Illuminate\Exception\Handler
	 */
	protected $handler;

	/**
	 * Create a new exception handler adapter.
	 *
	 * @param  \Illuminate\Exception\Handler  $handler
	 * @return void
	 */
	public function __construct(Handler $handler)
	{
		$this->handler = $handler;
	}

	/**
	 * Report an exception through the App::error() handlers.
	 *
	 * @param  \Throwable  $e
	 * @return void
	 */
	public function report(Throwable $e)
	{
		$this->handler->handleConsole($e);
	}

	/**
	 * Determine if the exception should be reported.
	 *
	 * @param  \Throwable  $e
	 * @return bool
	 */
	public function shouldReport(Throwable $e)
	{
		return true;
	}

	/**
	 * Render an exception into an HTTP response.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @param  \Throwable  $e
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function render($request, Throwable $e)
	{
		return $this->handler->handleException($e);
	}

	/**
	 * Render an exception to the console.
	 *
	 * @param  \Symfony\Component\Console\Output\OutputInterface  $output
	 * @param  \Throwable  $e
	 * @return void
	 */
	public function renderForConsole($output, Throwable $e)
	{
		(new ConsoleApplication)->renderThrowable($e, $output);
	}

}
