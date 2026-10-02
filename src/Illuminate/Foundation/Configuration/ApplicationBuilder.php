<?php namespace Illuminate\Foundation\Configuration;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;

/**
 * ponytail: the part of v13's ApplicationBuilder that the app's bootstrap/app.php uses,
 * with v13's names and signatures, so that file needs no change at the flip. Remove at
 * the flip.
 */
class ApplicationBuilder {

	/**
	 * Create a new application builder instance.
	 *
	 * @param  \Illuminate\Foundation\Application  $app
	 * @return void
	 */
	public function __construct(protected Application $app)
	{
	}

	/**
	 * Register the global middleware for the application.
	 *
	 * v13 applies the callback when it resolves the HTTP kernel, before the application
	 * bootstraps. The fork's application is its own HTTP kernel, so it applies it here.
	 *
	 * @param  callable|null  $callback
	 * @return $this
	 */
	public function withMiddleware(?callable $callback = null)
	{
		$middleware = new Middleware;

		if ( ! is_null($callback)) $callback($middleware);

		$this->app->setGlobalMiddleware($middleware->getGlobalMiddleware());

		return $this;
	}

	/**
	 * Register additional service providers.
	 *
	 * As in v13, they register after the configured providers, followed by those listed in
	 * bootstrap/providers.php.
	 *
	 * @param  array  $providers
	 * @param  bool  $withBootstrapProviders
	 * @return $this
	 */
	public function withProviders(array $providers = [], bool $withBootstrapProviders = true)
	{
		RegisterProviders::merge(
			$providers,
			$withBootstrapProviders ? $this->app->getBootstrapProvidersPath() : null
		);

		return $this;
	}

	/**
	 * Register additional Artisan commands with the application.
	 *
	 * As in v13, the commands go to the console Kernel once it resolves, and are built through
	 * the container when the console starts. Only command classes are supported.
	 *
	 * @param  array  $commands
	 * @return $this
	 */
	public function withCommands(array $commands = [])
	{
		$this->app->afterResolving('artisan', fn ($kernel) => $kernel->addCommands($commands));

		return $this;
	}

	/**
	 * Configure the application's exception handler.
	 *
	 * As in v13, the callback runs once the handler is first resolved.
	 *
	 * @param  callable|null  $using
	 * @return $this
	 */
	public function withExceptions(?callable $using = null)
	{
		if ( ! is_null($using))
		{
			$this->app->afterResolving('exception', fn ($handler) => $using(new Exceptions($handler)));
		}

		return $this;
	}

	/**
	 * Get the application instance.
	 *
	 * @return \Illuminate\Foundation\Application
	 */
	public function create()
	{
		return $this->app;
	}

}
