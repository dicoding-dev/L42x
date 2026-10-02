<?php namespace Illuminate\Exception;

use Closure;
use ErrorException;
use Throwable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Foundation\Exceptions\ReportableHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Routing\Router;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Arr;
use Illuminate\Support\Contracts\ResponsePreparerInterface;
use Illuminate\Support\Reflector;
use Illuminate\Support\Traits\ReflectsClosures;
use Illuminate\Validation\ValidationException;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reports and renders exceptions the way v13's Foundation\Exceptions\Handler does
 * (task 4.5): report callbacks, dontReport/stopIgnoring and the internal
 * don't-report list, then render callbacks in registration order after
 * prepareException(). Apps register through Foundation\Configuration\Exceptions,
 * the object v13's withExceptions() hands them. When no callback answers, the
 * fork's displayers render the exception, as before.
 */
class Handler {

	use ReflectsClosures;

	/**
	 * The application instance.
	 *
	 * @var \Illuminate\Container\Container&\Illuminate\Support\Contracts\ResponsePreparerInterface
	 */
	protected $app;

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
	 * The callbacks that should be used during reporting.
	 *
	 * @var \Illuminate\Foundation\Exceptions\ReportableHandler[]
	 */
	protected $reportCallbacks = array();

	/**
	 * The callbacks that should be used during rendering.
	 *
	 * @var \Closure[]
	 */
	protected $renderCallbacks = array();

	/**
	 * A list of the exception types that are not reported.
	 *
	 * @var array<int, class-string<\Throwable>>
	 */
	protected $dontReport = array();

	/**
	 * A list of the internal exception types that should not be reported.
	 *
	 * @var array<int, class-string<\Throwable>>
	 */
	protected $internalDontReport = array(
		AuthenticationException::class,
		AuthorizationException::class,
		BackedEnumCaseNotFoundException::class,
		HttpException::class,
		HttpResponseException::class,
		ModelNotFoundException::class,
		OriginMismatchException::class,
		RecordNotFoundException::class,
		RecordsNotFoundException::class,
		RequestExceptionInterface::class,
		TokenMismatchException::class,
		ValidationException::class,
	);

	/**
	 * Create a new error handler instance.
	 *
	 * @param  \Illuminate\Container\Container&\Illuminate\Support\Contracts\ResponsePreparerInterface  $app
	 * @param  \Illuminate\Exception\ExceptionDisplayerInterface  $plainDisplayer
	 * @param  \Illuminate\Exception\ExceptionDisplayerInterface  $debugDisplayer
	 * @param  bool  $debug
	 * @return void
	 */
	public function __construct(Container&ResponsePreparerInterface $app,
                                ExceptionDisplayerInterface $plainDisplayer,
                                ExceptionDisplayerInterface $debugDisplayer,
                                $debug = false)
	{
		$this->app = $app;
		$this->debug = $debug;
		$this->plainDisplayer = $plainDisplayer;
		$this->debugDisplayer = $debugDisplayer;
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
	 * Report the given exception and render it for the current request.
	 *
	 * @param  \Throwable  $exception
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function handleException($exception)
	{
		try {
			$this->report($exception);

			return $this->render($this->app['request'], $exception);
		} catch (Throwable $throwable) {
			return $this->displayException($throwable);
		}
	}

	/**
	 * Handle an uncaught exception.
	 *
	 * @param  \Throwable  $exception
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
	 * Register a reportable callback.
	 *
	 * @param  callable  $reportUsing
	 * @return \Illuminate\Foundation\Exceptions\ReportableHandler
	 */
	public function reportable(callable $reportUsing)
	{
		if ( ! $reportUsing instanceof Closure)
		{
			$reportUsing = Closure::fromCallable($reportUsing);
		}

		return $this->reportCallbacks[] = new ReportableHandler($reportUsing);
	}

	/**
	 * Register a renderable callback.
	 *
	 * @param  callable  $renderUsing
	 * @return $this
	 */
	public function renderable(callable $renderUsing)
	{
		if ( ! $renderUsing instanceof Closure)
		{
			$renderUsing = Closure::fromCallable($renderUsing);
		}

		$this->renderCallbacks[] = $renderUsing;

		return $this;
	}

	/**
	 * Indicate that the given exception type should not be reported.
	 *
	 * @param  array|string  $exceptions
	 * @return $this
	 */
	public function dontReport(array|string $exceptions)
	{
		$this->dontReport = array_values(array_unique(array_merge($this->dontReport, Arr::wrap($exceptions))));

		return $this;
	}

	/**
	 * Remove the given exception class from the list of exceptions that should be ignored.
	 *
	 * @param  array|string  $exceptions
	 * @return $this
	 */
	public function stopIgnoring(array|string $exceptions)
	{
		$exceptions = Arr::wrap($exceptions);

		$this->dontReport = array_values(array_diff($this->dontReport, $exceptions));

		$this->internalDontReport = array_values(array_diff($this->internalDontReport, $exceptions));

		return $this;
	}

	/**
	 * Report or log an exception.
	 *
	 * @param  \Throwable  $e
	 * @return void
	 */
	public function report(Throwable $e)
	{
		if ($this->shouldntReport($e)) return;

		if (Reflector::isCallable($reportCallable = array($e, 'report')) && $this->app->call($reportCallable) !== false)
		{
			return;
		}

		foreach ($this->reportCallbacks as $reportCallback)
		{
			if ($reportCallback->handles($e) && $reportCallback($e) === false) return;
		}

		$this->app->make('log')->error($e->getMessage(), array_merge($this->exceptionContext($e), $this->context(), array('exception' => $e)));
	}

	/**
	 * Determine if the exception should be reported.
	 *
	 * @param  \Throwable  $e
	 * @return bool
	 */
	public function shouldReport(Throwable $e)
	{
		return ! $this->shouldntReport($e);
	}

	/**
	 * Determine if the exception is in the "do not report" list.
	 *
	 * @param  \Throwable  $e
	 * @return bool
	 */
	protected function shouldntReport(Throwable $e)
	{
		foreach (array_merge($this->dontReport, $this->internalDontReport) as $type)
		{
			if ($e instanceof $type) return true;
		}

		return false;
	}

	/**
	 * Get the default exception context variables for logging.
	 *
	 * @param  \Throwable  $e
	 * @return array
	 */
	protected function exceptionContext(Throwable $e)
	{
		return method_exists($e, 'context') ? $e->context() : array();
	}

	/**
	 * Get the default context variables for logging.
	 *
	 * @return array
	 */
	protected function context()
	{
		try {
			return array_filter(array('userId' => $this->app['auth']->id()));
		} catch (Throwable) {
			return array();
		}
	}

	/**
	 * Render an exception into a response.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @param  \Throwable  $e
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function render($request, Throwable $e)
	{
		if (method_exists($e, 'render') && $response = $e->render($request))
		{
			return Router::toResponse($request, $response);
		}

		if ($e instanceof Responsable)
		{
			return $e->toResponse($request);
		}

		$e = $this->prepareException($e);

		foreach ($this->renderCallbacks as $renderCallback)
		{
			foreach ($this->firstClosureParameterTypes($renderCallback) as $type)
			{
				if (is_a($e, $type))
				{
					$response = $renderCallback($e, $request);

					if ( ! is_null($response)) return $this->app->prepareResponse($response);
				}
			}
		}

		if ($e instanceof HttpResponseException)
		{
			return $e->getResponse();
		}

		return $this->displayException($e);
	}

	/**
	 * Prepare exception for rendering.
	 *
	 * @param  \Throwable  $e
	 * @return \Throwable
	 */
	protected function prepareException(Throwable $e)
	{
		return match (true) {
			$e instanceof BackedEnumCaseNotFoundException => new NotFoundHttpException($e->getMessage(), $e),
			$e instanceof ModelNotFoundException => new NotFoundHttpException($e->getMessage(), $e),
			$e instanceof AuthorizationException && $e->hasStatus() => new HttpException(
				$e->status(), $e->response()?->message() ?: (Response::$statusTexts[$e->status()] ?? 'Whoops, looks like something went wrong.'), $e
			),
			$e instanceof AuthorizationException && ! $e->hasStatus() => new AccessDeniedHttpException($e->getMessage(), $e),
			$e instanceof OriginMismatchException => new HttpException(403, $e->getMessage(), $e),
			$e instanceof TokenMismatchException => new HttpException(419, $e->getMessage(), $e),
			$e instanceof RequestExceptionInterface => new BadRequestHttpException('Bad request.', $e),
			$e instanceof RecordNotFoundException => new NotFoundHttpException('Not found.', $e),
			$e instanceof RecordsNotFoundException => new NotFoundHttpException('Not found.', $e),
			default => $e,
		};
	}

	/**
	 * Display the given exception to the user.
	 *
	 * @param  \Throwable  $exception
	 * @return \Symfony\Component\HttpFoundation\Response
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
