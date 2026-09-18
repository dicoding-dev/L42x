<?php namespace Illuminate\Console;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class Application extends \Symfony\Component\Console\Application {

	/**
	 * The exception handler instance.
	 *
	 * @var \Illuminate\Exception\Handler
	 */
	protected $exceptionHandler;

	/**
	 * The Laravel application instance.
	 *
	 * @var \Illuminate\Foundation\Application
	 */
	protected $laravel;

	/**
	 * Callbacks to run when a console application is starting.
	 *
	 * ponytail: BC shim for v13 Support\ServiceProvider::commands(), which calls
	 * Illuminate\Console\Application::starting(). Remove when console swaps to v13 (task 4.2/4.3).
	 *
	 * @var callable[]
	 */
	protected static $startingCallbacks = array();

	/**
	 * Register a callback to run when the console application is starting.
	 *
	 * @param  callable  $callback
	 * @return void
	 */
	public static function starting($callback)
	{
		static::$startingCallbacks[] = $callback;
	}

	/**
	 * Create and boot a new Console application.
	 *
	 * @param  \Illuminate\Foundation\Application  $app
	 * @return \Illuminate\Console\Application
	 */
	public static function start($app)
	{
		return static::make($app)->boot();
	}

	/**
	 * Create a new Console application.
	 *
	 * @param  \Illuminate\Foundation\Application  $app
	 * @return \Illuminate\Console\Application
	 */
	public static function make($app)
	{
		$app->boot();

		$console = with($console = new static('Laravel Framework', $app::VERSION))
								->setLaravel($app)
								->setExceptionHandler($app['exception']);

		$console->setAutoExit(false);

		$app->instance('artisan', $console);

		// ponytail: v13's Artisan facade resolves Illuminate\Contracts\Console\Kernel, which
		// the fork (no console Kernel yet, task 4.2) doesn't bind. Point it at this console
		// application so the facade works. Remove at the console swap.
		$app->alias('artisan', 'Illuminate\Contracts\Console\Kernel');

		foreach (static::$startingCallbacks as $callback)
		{
			$callback($console);
		}

		return $console;
	}

	/**
	 * Boot the Console application.
	 *
	 * @return $this
	 */
	public function boot()
	{
		$path = $this->laravel['path'].'/start/artisan.php';

		if (file_exists($path))
		{
			require $path;
		}

		// If the event dispatcher is set on the application, we will fire an event
		// with the Artisan instance to provide each listener the opportunity to
		// register their commands on this application before it gets started.
		if (isset($this->laravel['events']))
		{
			$this->laravel['events']
					->dispatch('artisan.start', array($this));
		}

		return $this;
	}

	/**
	 * Run an Artisan console command by name.
	 *
	 * @param  string  $command
	 * @param  array   $parameters
	 * @param  \Symfony\Component\Console\Output\OutputInterface  $output
	 * @return void
	 */
	public function call($command, array $parameters = array(), ?OutputInterface $output = null)
	{
		$parameters['command'] = $command;

		// Unless an output interface implementation was specifically passed to us we
		// will use the "NullOutput" implementation by default to keep any writing
		// suppressed so it doesn't leak out to the browser or any other source.
		$output = $output ?: new NullOutput;

		$input = new ArrayInput($parameters);

		return $this->find($command)->run($input, $output);
	}

	/**
	 * Add a command to the console.
	 *
	 * @param  \Symfony\Component\Console\Command\Command  $command
	 * @return \Symfony\Component\Console\Command\Command
	 */
	#[\Override]
    public function add(SymfonyCommand $command): ?SymfonyCommand
	{
		if ($command instanceof Command)
		{
			$command->setLaravel($this->laravel);
		}

		return $this->addToParent($command);
	}

	/**
	 * Add the command to the parent instance.
	 *
	 * @param  \Symfony\Component\Console\Command\Command  $command
	 * @return \Symfony\Component\Console\Command\Command
	 */
	protected function addToParent(SymfonyCommand $command)
	{
		return parent::add($command);
	}

	/**
	 * Add a command, resolving through the application.
	 *
	 * @param  string  $command
	 * @return \Symfony\Component\Console\Command\Command
	 */
	public function resolve($command)
	{
		return $this->add($this->laravel[$command]);
	}

	/**
	 * Resolve an array of commands through the application.
	 *
	 * @param  array|mixed  $commands
	 * @return void
	 */
	public function resolveCommands($commands)
	{
		$commands = is_array($commands) ? $commands : func_get_args();

		foreach ($commands as $command)
		{
			try
			{
				$this->resolve($command);
			}
			catch (\Throwable $e)
			{
				// ponytail: some v13 components (session/cache/…) register console commands
				// that extend v13 console base classes absent from the not-yet-swapped fork
				// console. Skip the ones that can't load so artisan still boots; they return
				// with the console swap (task 4.2). Commands that load fine still register.
				error_log('[l13] skipped unresolvable console command '
					. (is_string($command) ? $command : gettype($command)) . ': ' . $e->getMessage());
			}
		}
	}

	/**
	 * Get the default input definitions for the applications.
	 *
	 * @return \Symfony\Component\Console\Input\InputDefinition
	 */
	#[\Override]
    protected function getDefaultInputDefinition(): \Symfony\Component\Console\Input\InputDefinition
    {
		$definition = parent::getDefaultInputDefinition();

		$definition->addOption($this->getEnvironmentOption());

		return $definition;
	}

	/**
	 * Get the global environment option for the definition.
	 *
	 * @return \Symfony\Component\Console\Input\InputOption
	 */
	protected function getEnvironmentOption()
	{
		$message = 'The environment the command should run under.';

		return new InputOption('--env', null, InputOption::VALUE_OPTIONAL, $message);
	}

	/**
	 * Render the given exception.
	 * @deprecated since Symfony 4.4, use "renderThrowable()" instead
	 * @param  \Exception  $e
	 * @param  \Symfony\Component\Console\Output\OutputInterface  $output
	 * @return void
	 */
	public function renderException(\Exception $e, OutputInterface $output)
	{
		// If we have an exception handler instance, we will call that first in case
		// it has some handlers that need to be run first. We will pass "true" as
		// the second parameter to indicate that it's handling a console error.
		if (isset($this->exceptionHandler))
		{
			$this->exceptionHandler->handleConsole($e);
		}

		parent::renderException($e, $output);
	}

	/**
	 * Set the exception handler instance.
	 *
	 * @param  \Illuminate\Exception\Handler  $handler
	 * @return $this
	 */
	public function setExceptionHandler($handler)
	{
		$this->exceptionHandler = $handler;

		return $this;
	}

	/**
	 * Set the Laravel application instance.
	 *
	 * @param  \Illuminate\Foundation\Application  $laravel
	 * @return $this
	 */
	public function setLaravel($laravel)
	{
		$this->laravel = $laravel;

		return $this;
	}

}
