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
		// make()/start()/boot(). ponytail: the L4.2 static bootstrap — rebinding 'artisan' to the
		// console so the Artisan facade in start/artisan.php resolves it, then loading that file —
		// is inlined here until the Foundation Kernel lands (task 4.5).
		$console = new ConsoleApplication($this->app, $this->app['events'], $this->app::VERSION);

		$this->app->instance('artisan', $console);

		// Memoize before requiring start/artisan.php: that file calls Artisan::add() ~149x, and the
		// Artisan facade has already cached THIS wrapper, so each add() re-enters __call()->getArtisan().
		// Without the early memo it re-boots + re-requires artisan.php recursively (OOM). With it, the
		// re-entrant getArtisan() short-circuits and add() forwards to the console instance.
		$this->artisan = $console;

		$path = $this->app['path'].'/start/artisan.php';

		if (file_exists($path)) require $path;

		// v13 registers attribute-named commands (#[AsCommand]) lazily into a commandMap; they
		// only become resolvable once the container command loader is attached (the v13 Kernel
		// does the same after resolving). Without this, e.g. illuminate/database's migrate stays
		// invisible. Called last, so the map is complete (ctor bootstrappers + start/artisan.php).
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
