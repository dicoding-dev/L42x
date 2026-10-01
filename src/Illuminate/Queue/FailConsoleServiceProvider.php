<?php namespace Illuminate\Queue;

use Illuminate\Support\ServiceProvider;
use Illuminate\Exception\ExceptionHandlerAdapter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Queue\Console\RetryCommand;
use Illuminate\Queue\Console\ListenCommand;
use Illuminate\Queue\Console\RestartCommand;
use Illuminate\Queue\Console\ListFailedCommand;
use Illuminate\Queue\Console\FlushFailedCommand;
use Illuminate\Queue\Console\FailedTableCommand;
use Illuminate\Queue\Console\ForgetFailedCommand;

/**
 * ponytail: v13's QueueServiceProvider registers no console commands (L13 registers them
 * in Foundation's ArtisanServiceProvider), so after the queue swap (task 4.3) the fork
 * registers them here: the L4.2 queue:work/listen/restart (formerly in the fork
 * QueueServiceProvider) plus the failed-job commands. Bound by class name, like L13, so
 * the console lazily resolves them by their #[AsCommand] names. queue:subscribe (Iron)
 * has no v13 equivalent and is dropped. Remove at task 4.5 (foundation swap).
 */
class FailConsoleServiceProvider extends ServiceProvider {

	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = true;

	/**
	 * The queue commands registered by the provider.
	 *
	 * @var array
	 */
	protected $queueCommands = array(
		WorkCommand::class,
		ListenCommand::class,
		RestartCommand::class,
		ListFailedCommand::class,
		RetryCommand::class,
		ForgetFailedCommand::class,
		FlushFailedCommand::class,
		FailedTableCommand::class,
	);

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		$this->app->singleton(WorkCommand::class, function($app)
		{
			$this->bindWorkerExceptionHandler($app);

			return new WorkCommand($app['queue.worker'], $app['cache.store']);
		});

		$this->app->singleton(ListenCommand::class, function($app)
		{
			return new ListenCommand($app['queue.listener']);
		});

		$this->app->singleton(RestartCommand::class, function($app)
		{
			return new RestartCommand($app['cache.store']);
		});

		$this->app->singleton(FailedTableCommand::class, function($app)
		{
			return new FailedTableCommand($app['files']);
		});

		$this->commands($this->queueCommands);
	}

	/**
	 * ponytail: v13's Worker (built by QueueServiceProvider as 'queue.worker') reports
	 * failed jobs through Contracts\Debug\ExceptionHandler. Bind the fork adapter only
	 * when the worker command is built: bound globally, v13's routing Pipeline would
	 * start reporting+rendering HTTP exceptions itself instead of letting them reach the
	 * fork Handler (and stop rethrowing them in tests). Remove at task 4.5.
	 *
	 * @param  \Illuminate\Foundation\Application  $app
	 * @return void
	 */
	protected function bindWorkerExceptionHandler($app)
	{
		if ($app->bound(ExceptionHandler::class)) return;

		$app->singleton(ExceptionHandler::class, function($app)
		{
			return new ExceptionHandlerAdapter($app['exception']);
		});
	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	#[\Override]
    public function provides()
	{
		return $this->queueCommands;
	}

}
