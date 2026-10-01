<?php namespace Illuminate\Foundation\Configuration;

/**
 * The subset of v13's middleware configuration (the object withMiddleware() passes
 * to the app) that the fork's global middleware stack supports (task 4.5). The fork
 * ships none of v13's default global middleware, so use() defines the whole stack,
 * exactly as it does on v13. Code written against it keeps its meaning once v13's
 * Foundation replaces the fork.
 */
class Middleware {

	/**
	 * The user defined global middleware stack.
	 *
	 * @var array
	 */
	protected $global = array();

	/**
	 * Define the global middleware for the application.
	 *
	 * @param  array  $middleware
	 * @return $this
	 */
	public function use(array $middleware)
	{
		$this->global = $middleware;

		return $this;
	}

	/**
	 * Get the global middleware.
	 *
	 * @return array
	 */
	public function getGlobalMiddleware()
	{
		return array_values($this->global);
	}

}
