<?php namespace Illuminate\Exception;

use Closure;
use ErrorException;
use ReflectionFunction;
use Illuminate\Support\Contracts\ResponsePreparerInterface;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class Handler {

	/**
	 * The response preparer implementation.
	 *
	 * @var \Illuminate\Support\Contracts\ResponsePreparerInterface
	 */
	protected $responsePreparer;

	/**
	 * The plain exception displayer.
	 *
	 * @var \Illuminate\Exception\ExceptionDisplayerInterface
	 */
	protected $plainDisplayer;

	/**
	 * The debug exception displayer.
	 *
	 * @var \Illuminate\Exception\ExceptionDisplayerInterface
	 */
	protected $debugDisplayer;

	/**
	 * Indicates if the application is in debug mode.
	 *
	 * @var bool
	 */
	protected $debug;

	/**
	 * All of the register exception handlers.
	 *
	 * @var array
	 */
	protected $handlers = array();

	/**
	 * All of the handled error messages.
	 *
	 * @var array
	 */
	protected $handled = array();

	/**
	 * Create a new error handler instance.
	 *
	 * @param  \Illuminate\Support\Contracts\ResponsePreparerInterface  $responsePreparer
	 * @param  \Illuminate\Exception\ExceptionDisplayerInterface  $plainDisplayer
	 * @param  \Illuminate\Exception\ExceptionDisplayerInterface  $debugDisplayer
	 * @param  bool  $debug
	 * @return void
	 */
	public function __construct(ResponsePreparerInterface $responsePreparer,
                                ExceptionDisplayerInterface $plainDisplayer,
                                ExceptionDisplayerInterface $debugDisplayer,
                                $debug = false)
	{
		$this->debug = $debug;
		$this->plainDisplayer = $plainDisplayer;
		$this->debugDisplayer = $debugDisplayer;
		$this->responsePreparer = $responsePreparer;
	}

	/**
	 * Register the exception / error handlers for the application.
	 *
	 * @param  string  $environment
	 * @return void
	 */
	public function register($environment)
	{
		$this->registerErrorHandler();

		$this->registerExceptionHandler();

		if ($environment != 'testing') $this->registerShutdownHandler();
	}

	/**
	 * Register the PHP error handler.
	 *
	 * @return void
	 */
	protected function registerErrorHandler()
	{
        set_error_handler(array($this, 'handleError'));
	}

	/**
	 * Register the PHP exception handler.
	 *
	 * @return void
	 */
	protected function registerExceptionHandler()
	{
        set_exception_handler(array($this, 'handleUncaughtException'));
	}

	/**
	 * Register the PHP shutdown handler.
	 *
	 * @return void
	 */
	protected function registerShutdownHandler()
	{
		register_shutdown_function(array($this, 'handleShutdown'));
	}

	/**
	 * Handle a PHP error for the application.
	 *
	 * @param  int     $level
	 * @param  string  $message
	 * @param  string  $file
	 * @param  int     $line
	 * @param  array   $context
	 *
	 * @throws \ErrorException
	 */
	public function handleError($level, $message, $file = '', $line = 0, $context = array())
	{
		if (error_reporting() & $level)
		{
			throw new ErrorException($message, 0, $level, $file, $line);
		}
	}

	/**
	 * Handle an exception for the application.
	 *
	 * @param  \Exception  $exception
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function handleException($exception)
	{
		try {
            $response = $this->callCustomHandlers($exception);

            // If one of the custom error handlers returned a response, we will send that
            // response back to the client after preparing it. This allows a specific
            // type of exceptions to handled by a Closure giving great flexibility.
            if ( ! is_null($response)) {
                return $this->prepareResponse($response);
            }
        } catch (\Throwable $throwable) {
            $exception = $throwable;
        }

		// If no response was sent by this custom exception handler, we will call the
		// default exception displayer for the current application context and let
		// it show the exception to the user / developer based on the situation.
		return $this->displayException($exception);
	}

	/**
	 * Handle an uncaught exception.
	 *
	 * @param  \Exception  $exception
	 * @return void
	 */
	public function handleUncaughtException($exception)
	{
		$this->handleException($exception)->send();
	}

	/**
	 * Handle the PHP shutdown event.
	 *
	 * @return void
	 */
	public function handleShutdown()
	{
		$error = error_get_last();

		// If an error has occurred that has not been displayed, we will create a fatal
		// error exception instance and pass it into the regular exception handling
		// code so it can be displayed back out to the developer for information.
		if ( ! is_null($error))
		{
			if ( ! $this->isFatal($error['type'])) return;

			$this->handleException(new FatalError($error['message'], 0, $error, 0))->send();
		}
	}

	/**
	 * Determine if the error type is fatal.
	 *
	 * @param  int   $type
	 * @return bool
	 */
	protected function isFatal($type)
	{
		return in_array($type, array(E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE));
	}

	/**
	 * Handle a console exception.
	 *
	 * @param  \Exception  $exception
	 * @return void
	 */
	public function handleConsole($exception)
	{
		try {
            return $this->callCustomHandlers($exception, true);
        } catch (\Throwable $throwable) {
            return $throwable->getMessage();
        }
	}

	/**
	 * Handle the given exception.
	 *
	 * @param  \Exception  $exception
	 * @param  bool  $fromConsole
	 */
	protected function callCustomHandlers($exception, $fromConsole = false)
	{
        if ($exception instanceof HttpExceptionInterface) {
            $code = $exception->getStatusCode();
        }

        // If the exception doesn't implement the HttpExceptionInterface, we will just
        // use the generic 500 error code for a server side error. If it implements
        // the HttpException interfaces we'll grab the error code from the class.
        else {
            $code = 500;
        }

		foreach ($this->handlers as $handler)
		{
			// If this exception handler does not handle the given exception, we will just
			// go the next one. A handler may type-hint an exception that it handles so
			//  we can have more granularity on the error handling for the developer.
            if (! $this->handlesException($handler, $exception)) {
                continue;
            }

            $response = $handler($exception, $code, $fromConsole);

			// If this handler returns a "non-null" response, we will return it so it will
			// get sent back to the browsers. Once the handler returns a valid response
			// we will cease iterating through them and calling these other handlers.
			if (isset($response) && ! is_null($response))
			{
				return $response;
			}
		}
	}

	/**
	 * Display the given exception to the user.
	 *
	 * @param  \Exception  $exception
	 */
	protected function displayException($exception)
	{
		$displayer = $this->debug ? $this->debugDisplayer : $this->plainDisplayer;

		if (! $exception instanceof \Exception) {
            if ($exception instanceof \ParseError) {
                $severity = \E_PARSE;
            } elseif ($exception instanceof \TypeError) {
                $severity = \E_RECOVERABLE_ERROR;
            } else {
                $severity = \E_ERROR;
            }

			$exception = new ErrorException(
                $exception->getMessage(),
                $exception->getCode(),
                $severity,
                $exception->getFile(),
                $exception->getLine(),
            );
		}

		return $displayer->display($exception);
	}

	/**
	 * Determine if the given handler handles this exception.
	 *
	 * @param  \Closure    $handler
	 * @param  \Exception  $exception
	 * @return bool
	 */
	protected function handlesException(Closure $handler, $exception)
	{
		$reflection = new ReflectionFunction($handler);

		return $reflection->getNumberOfParameters() == 0 || $this->hints($reflection, $exception);
	}

	/**
	 * Determine if the given handler type hints the exception.
	 *
	 * @param  \ReflectionFunction  $reflection
	 * @param  \Exception  $exception
	 * @return bool
	 */
	protected function hints(ReflectionFunction $reflection, $exception)
	{
		$parameters = $reflection->getParameters();

		$expected = $parameters[0];

        $type = $expected->getType();
        if (!$type || $type->isBuiltin()) {
            return false;
        }

        return (new \ReflectionClass($expected->getType()->getName()))->isInstance($exception);
	}

	/**
	 * Format an exception thrown by a handler.
	 *
	 * @return string
     * @deprecated
	 */
	protected function formatException(\Throwable $e): string
    {
		if ($this->debug)
		{
			$location = $e->getMessage().' in '.$e->getFile().':'.$e->getLine();

			return 'Error in exception handler: '.$location;
		}

		return 'Error in exception handler.';
	}

	/**
	 * Register an application error handler.
	 *
	 * @param  \Closure  $callback
	 * @return void
	 */
	public function error(Closure $callback)
	{
		array_unshift($this->handlers, $callback);
	}

	/**
	 * Register an application error handler at the bottom of the stack.
	 *
	 * @param  \Closure  $callback
	 * @return void
	 */
	public function pushError(Closure $callback)
	{
		$this->handlers[] = $callback;
	}

	/**
	 * Prepare the given response.
	 *
	 * @param  mixed  $response
	 * @return \Illuminate\Http\Response
	 */
	protected function prepareResponse($response)
	{
		return $this->responsePreparer->prepareResponse($response);
	}

	/**
	 * Determine if we are running in the console.
	 *
	 * @return bool
	 */
	public function runningInConsole()
	{
		return php_sapi_name() == 'cli';
	}

	/**
	 * Set the debug level for the handler.
	 *
	 * @param  bool  $debug
	 * @return void
	 */
	public function setDebug($debug)
	{
		$this->debug = $debug;
	}

}
