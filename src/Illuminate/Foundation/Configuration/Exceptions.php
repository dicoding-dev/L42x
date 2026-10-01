<?php namespace Illuminate\Foundation\Configuration;

use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Arr;

/**
 * The subset of v13's exception configuration (the object withExceptions() passes
 * to the app) that the fork's Handler supports (task 4.5). Code written against it
 * keeps its meaning once v13's Foundation replaces the fork.
 */
class Exceptions {

	/**
	 * Create a new exception handling configuration instance.
	 *
	 * @param  \Illuminate\Foundation\Exceptions\Handler  $handler
	 * @return void
	 */
	public function __construct(public Handler $handler)
	{
	}

	/**
	 * Register a reportable callback.
	 *
	 * @param  callable  $using
	 * @return \Illuminate\Foundation\Exceptions\ReportableHandler
	 */
	public function report(callable $using)
	{
		return $this->handler->reportable($using);
	}

	/**
	 * Register a renderable callback.
	 *
	 * @param  callable  $using
	 * @return $this
	 */
	public function render(callable $using)
	{
		$this->handler->renderable($using);

		return $this;
	}

	/**
	 * Indicate that the given exception type should not be reported.
	 *
	 * @param  array|string  $class
	 * @return $this
	 */
	public function dontReport(array|string $class)
	{
		foreach (Arr::wrap($class) as $exceptionClass)
		{
			$this->handler->dontReport($exceptionClass);
		}

		return $this;
	}

	/**
	 * Do not ignore the given exceptions classes.
	 *
	 * @param  array|string  $class
	 * @return $this
	 */
	public function stopIgnoring(array|string $class)
	{
		$this->handler->stopIgnoring($class);

		return $this;
	}

}
