<?php namespace Illuminate\Foundation;

use Illuminate\Console\Application as ConsoleApplication;

class Artisan {

	/**
	 * The application instance.
	 *
	 * @var \Illuminate\Foundation\Application
	 */
	protected $app;

	/**
	 * The Artisan console instance.
	 *
	 * @var \Illuminate\Console\Application
	 */
	protected $artisan;

	/**
	 * The commands registered through the application builder.
	 *
	 * @var array
	 */
	protected $commands = array();

	/**
	 * Create a new Artisan command runner instance.
	 *
	 * @param  \Illuminate\Foundation\Application  $app
	 * @return void
	 */
	public function __construct(Application $app)
	{
		$this->app = $app;
	}

	/**
	 * Bootstrap the application for Artisan commands, as v13's console Kernel does.
	 *
	 * @return void
	 */
	public function bootstrap()
	{
		$this->app->bootstrapWithStartScript();
	}

	/**
	 * Add the given commands to the ones the console will resolve, as v13's console Kernel does.
	 *
	 * @param  array  $commands
	 * @return void
	 */
	public function addCommands(array $commands)
	{
		$this->commands = array_values(array_unique(array_merge($this->commands, $commands)));
	}

	/**
	 * Run an incoming console command, as v13's console Kernel does.
	 *
	 * @param  \Symfony\Component\Console\Input\InputInterface  $input
	 * @param  \Symfony\Component\Console\Output\OutputInterface|null  $output
	 * @return int
	 */
	public function handle($input, $output = null)
	{
		$this->bootstrap();

		$this->app->setRequestForConsoleEnvironment();

		return $this->getArtisan()->run($input, $output);
	}

	/**
	 * Get the Artisan console instance.
	 *
	 * @return \Illuminate\Console\Application
	 */
	protected function getArtisan()
	{
		if ( ! is_null($this->artisan)) return $this->artisan;

		$this->app->loadDeferredProviders();

		// Boot the app before building the console (the old Console\Application::make() did this):
		// provider boot()s register runtime extensions — e.g. the app's custom auth driver via
		// Auth::extend — that command resolution depends on.
		$this->app->boot();

		// v13's Console\Application self-bootstraps in its constructor (dispatches ArtisanStarting
		// and runs the starting() callbacks registered by ServiceProvider::commands()). It has no
		// make()/start()/boot(). ponytail: rebinding 'artisan' to the console, so the Artisan facade
		// resolves it, stands in for the Foundation Kernel until the flip (task 4.5).
		$console = new ConsoleApplication($this->app, $this->app['events'], $this->app::VERSION);

		$this->app->instance('artisan', $console);

		// Memoize before resolving the commands: a command built through the container may use the
		// Artisan facade, which has cached THIS wrapper and would re-enter getArtisan().
		$this->artisan = $console;

		// As v13's Kernel does, the commands registered with withCommands() resolve through the
		// container; #[AsCommand] ones join the lazy command map instead.
		$console->resolveCommands($this->commands);

		// v13 registers attribute-named commands (#[AsCommand]) lazily into a commandMap; they
		// only become resolvable once the container command loader is attached (the v13 Kernel
		// does the same after resolving). Without this, e.g. illuminate/database's migrate stays
		// invisible. Called last, so the map is complete.
		$console->setContainerCommandLoader();

		return $this->artisan = $console;
	}

	/**
	 * Dynamically pass all missing methods to console Artisan.
	 *
	 * @param  string  $method
	 * @param  array   $parameters
	 * @return mixed
	 */
	public function __call($method, $parameters)
	{
		return call_user_func_array(array($this->getArtisan(), $method), $parameters);
	}

}
