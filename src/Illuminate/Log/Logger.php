<?php namespace Illuminate\Log;

use Closure;
use Psr\Log\LoggerInterface;
use Illuminate\Events\Dispatcher;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\RotatingFileHandler;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Contracts\JsonableInterface;
use Illuminate\Support\Contracts\ArrayableInterface;

class Logger implements LoggerInterface {

	use Conditionable;

	/**
	 * The Monolog logger instance.
	 *
	 * @var \Monolog\Logger
	 */
	protected $monolog;

	/**
	 * All of the error levels.
	 *
	 * @var array
	 */
	protected $levels = array(
		'debug',
		'info',
		'notice',
		'warning',
		'error',
		'critical',
		'alert',
		'emergency',
	);

	/**
	 * The event dispatcher instance.
	 *
	 * @var \Illuminate\Events\Dispatcher
	 */
	protected $dispatcher;

	/**
	 * Shared context for all log messages.
	 *
	 * @var array
	 */
	protected $sharedContext = [];

	/**
	 * Create a new log logger instance.
	 *
	 * @param  \Monolog\Logger  $monolog
	 * @param  \Illuminate\Events\Dispatcher  $dispatcher
	 * @return void
	 */
	public function __construct(MonologLogger $monolog, ?Dispatcher $dispatcher = null)
	{
		$this->monolog = $monolog;

		if (isset($dispatcher))
		{
			$this->dispatcher = $dispatcher;
		}
	}

	/**
	 * Call Monolog with the given method and parameters.
	 *
	 * @param  string  $method
	 * @param  mixed   $parameters
	 * @return mixed
	 */
	protected function callMonolog($method, $parameters)
	{
		if (is_array($parameters[0]))
		{
			$parameters[0] = json_encode($parameters[0]);
		}

		return call_user_func_array(array($this->monolog, $method), $parameters);
	}

	/**
	 * Register a file log handler.
	 *
	 * @param  string  $path
	 * @param  string  $level
	 * @return void
	 */
	public function useFiles($path, $level = 'debug')
	{
		$level = $this->parseLevel($level);

		$this->monolog->pushHandler($handler = new StreamHandler($path, $level));

		$handler->setFormatter($this->getDefaultFormatter());
	}

	/**
	 * Register a daily file log handler.
	 *
	 * @param  string  $path
	 * @param  int     $days
	 * @param  string  $level
	 * @return void
	 */
	public function useDailyFiles($path, $days = 0, $level = 'debug')
	{
		$level = $this->parseLevel($level);

		$this->monolog->pushHandler($handler = new RotatingFileHandler($path, $days, $level));

		$handler->setFormatter($this->getDefaultFormatter());
	}

	/**
	 * Register an error_log handler.
	 *
	 * @param  string  $level
	 * @param  int     $messageType
	 * @return void
	 */
	public function useErrorLog($level = 'debug', $messageType = ErrorLogHandler::OPERATING_SYSTEM)
	{
		$level = $this->parseLevel($level);

		$this->monolog->pushHandler($handler = new ErrorLogHandler($messageType, $level));

		$handler->setFormatter($this->getDefaultFormatter());
	}

	/**
	 * Get a default Monolog formatter instance.
	 *
	 * @return \Monolog\Formatter\LineFormatter
	 */
	protected function getDefaultFormatter()
	{
		return new LineFormatter(null, null, true);
	}

	/**
	 * Parse the string level into a Monolog constant.
	 *
	 * @param  string  $level
	 * @return int
	 *
	 * @throws \InvalidArgumentException
	 */
	protected function parseLevel($level)
	{
		switch ($level)
		{
			case 'debug':
				return MonologLogger::DEBUG;

			case 'info':
				return MonologLogger::INFO;

			case 'notice':
				return MonologLogger::NOTICE;

			case 'warning':
				return MonologLogger::WARNING;

			case 'error':
				return MonologLogger::ERROR;

			case 'critical':
				return MonologLogger::CRITICAL;

			case 'alert':
				return MonologLogger::ALERT;

			case 'emergency':
				return MonologLogger::EMERGENCY;

			default:
				throw new \InvalidArgumentException("Invalid log level.");
		}
	}

	/**
	 * Register a new callback handler for when
	 * a log event is triggered.
	 *
	 * @param  \Closure  $callback
	 * @return void
	 *
	 * @throws \RuntimeException
	 */
	public function listen(Closure $callback)
	{
		if ( ! isset($this->dispatcher))
		{
			throw new \RuntimeException("Events dispatcher has not been set.");
		}

		$this->dispatcher->listen('illuminate.log', $callback);
	}

	/**
	 * Get the underlying logger instance.
	 *
	 * @return \Psr\Log\LoggerInterface
	 */
	public function getLogger(): LoggerInterface
	{
		return $this->monolog;
	}

	/**
	 * Get the underlying Monolog instance.
	 *
	 * @return \Monolog\Logger
	 */
	public function getMonolog()
	{
		return $this->monolog;
	}

	/**
	 * Get the event dispatcher instance.
	 *
	 * @return \Illuminate\Events\Dispatcher
	 */
	public function getEventDispatcher()
	{
		return $this->dispatcher;
	}

	/**
	 * Set the event dispatcher instance.
	 *
	 * @param  \Illuminate\Events\Dispatcher
	 * @return void
	 */
	public function setEventDispatcher(Dispatcher $dispatcher)
	{
		$this->dispatcher = $dispatcher;
	}

	/**
	 * Add shared context to all subsequent log messages.
	 *
	 * @param  array  $context
	 * @return $this
	 */
	public function withContext(array $context): static
	{
		$this->sharedContext = array_merge($this->sharedContext, $context);

		return $this;
	}

	/**
	 * Flush the shared context.
	 *
	 * @return $this
	 */
	public function withoutContext(): static
	{
		$this->sharedContext = [];

		return $this;
	}

	/**
	 * Fires a log event.
	 *
	 * @param  string  $level
	 * @param  string  $message
	 * @param  array   $context
	 * @return void
	 */
	protected function fireLogEvent($level, $message, array $context = array())
	{
		if (isset($this->dispatcher))
		{
			$this->dispatcher->dispatch('illuminate.log', compact('level', 'message', 'context'));
		}
	}

	/**
	 * Write a message to the log.
	 *
	 * @param  string  $level
	 * @param  string  $message
	 * @param  array  $context
	 * @return void
	 */
	public function write($level, $message, $context = []): void
	{
		$this->writeLog($level, $message, $context);
	}

	/**
	 * Write a message to the log and fire the log event.
	 *
	 * @param  string  $level
	 * @param  string  $message
	 * @param  array  $context
	 * @return void
	 */
	public function writeLog($level, $message, array $context): void
	{
		$this->fireLogEvent($level, $message, $context);

		$this->monolog->log($level, $message, $context);
	}

	/**
	 * System is unusable.
	 *
	 * @param  string|\Stringable  $message
	 * @param  array  $context
	 * @return void
	 */
	public function emergency($message, array $context = []): void
	{
		$this->log('emergency', $message, $context);
	}

	/**
	 * Action must be taken immediately.
	 *
	 * @param  string|\Stringable  $message
	 * @param  array  $context
	 * @return void
	 */
	public function alert($message, array $context = []): void
	{
		$this->log('alert', $message, $context);
	}

	/**
	 * Critical conditions.
	 *
	 * @param  string|\Stringable  $message
	 * @param  array  $context
	 * @return void
	 */
	public function critical($message, array $context = []): void
	{
		$this->log('critical', $message, $context);
	}

	/**
	 * Runtime errors that do not require immediate action.
	 *
	 * @param  string|\Stringable  $message
	 * @param  array  $context
	 * @return void
	 */
	public function error($message, array $context = []): void
	{
		$this->log('error', $message, $context);
	}

	/**
	 * Exceptional occurrences that are not errors.
	 *
	 * @param  string|\Stringable  $message
	 * @param  array  $context
	 * @return void
	 */
	public function warning($message, array $context = []): void
	{
		$this->log('warning', $message, $context);
	}

	/**
	 * Normal but significant events.
	 *
	 * @param  string|\Stringable  $message
	 * @param  array  $context
	 * @return void
	 */
	public function notice($message, array $context = []): void
	{
		$this->log('notice', $message, $context);
	}

	/**
	 * Interesting events.
	 *
	 * @param  string|\Stringable  $message
	 * @param  array  $context
	 * @return void
	 */
	public function info($message, array $context = []): void
	{
		$this->log('info', $message, $context);
	}

	/**
	 * Detailed debug information.
	 *
	 * @param  string|\Stringable  $message
	 * @param  array  $context
	 * @return void
	 */
	public function debug($message, array $context = []): void
	{
		$this->log('debug', $message, $context);
	}

	/**
	 * Logs with an arbitrary level.
	 *
	 * @param  mixed  $level
	 * @param  string|\Stringable  $message
	 * @param  array  $context
	 * @return void
	 */
	public function log($level, $message, array $context = []): void
	{
		$context = array_merge($this->sharedContext, $context);

		$this->fireLogEvent($level, $message, $context);

		$this->monolog->log($level, $message, $context);
	}

	/**
	 * Format the parameters for the logger.
	 *
	 * @param  mixed  $parameters
	 * @return void
	 */
	protected function formatParameters(&$parameters)
	{
		if (isset($parameters[0]))
		{
			if (is_array($parameters[0]))
			{
				$parameters[0] = var_export($parameters[0], true);
			}
			elseif ($parameters[0] instanceof JsonableInterface)
			{
				$parameters[0] = $parameters[0]->toJson();
			}
			elseif ($parameters[0] instanceof ArrayableInterface)
			{
				$parameters[0] = var_export($parameters[0]->toArray(), true);
			}
		}
	}

	/**
	 * Dynamically handle calls to the logger.
	 *
	 * @param  string  $method
	 * @param  mixed   $parameters
	 * @return mixed
	 *
	 * @throws \BadMethodCallException
	 */
	public function __call($method, $parameters)
	{
		// ponytail: PSR-3 methods are explicit; __call handles macros + legacy level dispatch
		if (in_array($method, $this->levels))
		{
			$this->formatParameters($parameters);

			call_user_func_array($this->fireLogEvent(...), array_merge(array($method), $parameters));

			return $this->callMonolog($method, $parameters);
		}

		throw new \BadMethodCallException("Method [$method] does not exist.");
	}

}

// BC: class renamed from Writer to Logger (task 3.5); alias old FQN so type-hints resolve.
class_alias('Illuminate\Log\Logger', 'Illuminate\Log\Writer');
