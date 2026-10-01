<?php namespace Illuminate\Foundation;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Contracts\Container\BindingResolutionException;
use ReflectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Pipeline;
use Illuminate\Support\Facades\Facade;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Foundation\Providers\ExceptionServiceProvider;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Config\FileEnvironmentVariablesLoader;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Contracts\ResponsePreparerInterface;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class Application extends Container implements HttpKernelInterface, TerminableInterface, ResponsePreparerInterface {

	/**
	 * The Laravel framework version.
	 *
	 * @var string
	 */
	final const string VERSION = '4.2.72';

	/**
	 * Indicates if the application has "booted".
	 *
	 * @var bool
	 */
	protected $booted = false;

	/**
	 * Indicates if the application's start script has run.
	 *
	 * @var bool
	 */
	protected $hasBeenBootstrapped = false;

	/**
	 * The custom environment path defined by the developer.
	 *
	 * @var string|null
	 */
	protected $environmentPath;

	/**
	 * The environment file to load during bootstrapping.
	 *
	 * @var string
	 */
	protected $environmentFile = '.env';

	/**
	 * The array of booting callbacks.
	 *
	 * @var array
	 */
	protected $bootingCallbacks = array();

	/**
	 * The array of booted callbacks.
	 *
	 * @var array
	 */
	protected $bootedCallbacks = array();

	/**
	 * ponytail: the global middleware stack (v13 Http\Kernel::$middleware), run around
	 * the route dispatch in place of L4.2's App::before/after/down hooks. The fork has
	 * no HTTP Kernel, so the Application hosts it. Remove at task 4.5 foundation swap.
	 *
	 * @var array
	 */
	protected $globalMiddleware = array();

	/**
	 * ponytail: v13 terminating-callback shim (v13 ServiceProviders register
	 * these; fork Foundation predates the API). Remove at task 4.5 foundation swap.
	 *
	 * @var array
	 */
	protected $terminatingCallbacks = array();

	/**
	 * All of the registered service providers.
	 *
	 * @var array
	 */
	protected $serviceProviders = array();

	/**
	 * The names of the loaded service providers.
	 *
	 * @var array
	 */
	protected $loadedProviders = array();

	/**
	 * The deferred services and their providers.
	 *
	 * @var array
	 */
	protected $deferredServices = array();

	/**
	 * The request class used by the application.
	 *
	 * @var string
	 */
	protected static $requestClass = 'Illuminate\Http\Request';

	/**
	 * Create a new Illuminate application instance.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return void
	 */
	public function __construct(?Request $request = null)
	{
		$this->registerBaseBindings($request ?: $this->createNewRequest());

		$this->registerBaseServiceProviders();
	}

	/**
	 * Create a new request instance from the request class.
	 *
	 * @return \Illuminate\Http\Request
	 */
	protected function createNewRequest()
	{
		return forward_static_call(array(static::$requestClass, 'createFromGlobals'));
	}

	/**
	 * Register the basic bindings into the container.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return void
	 */
	protected function registerBaseBindings($request)
	{
		$this->instance('request', $request);

		$this->instance('Illuminate\Container\Container', $this);

		// ponytail: v13 resolves its console Kernel before bootstrapping (tests call
		// $app->make(Kernel::class)->bootstrap(); handleCommand() calls handle()). The fork has
		// no console Kernel (task 4.2), so the Artisan wrapper stands in for it from the start,
		// and the contract aliases it so the Artisan facade resolves it too. Remove at the flip.
		$this->singleton('artisan', fn ($app) => new Artisan($app));

		$this->alias('artisan', 'Illuminate\Contracts\Console\Kernel');

		// v13 code resolves the container via the static Container::getInstance()
		// (e.g. BladeCompiler::anonymousComponentPath); register the app as the
		// global instance so those calls hit the real bindings/aliases (task 4.3).
		static::setInstance($this);
	}

	/**
	 * Register all of the base service providers.
	 *
	 * @return void
	 */
	protected function registerBaseServiceProviders()
	{
		foreach (array('Event', 'Exception', 'Routing', 'Bus') as $name)
		{
			$this->{"register{$name}Provider"}();
		}
	}

	/**
	 * ponytail: L13 ships BusServiceProvider among its default providers. The v13 queue
	 * runs closure and object jobs through CallQueuedHandler, which needs the Bus
	 * dispatcher, so `Queue::push(Closure)` breaks without it (task 4.3 queue swap).
	 * Registered here because the app lists its providers explicitly (no default set).
	 * Remove at task 4.5 when the foundation swap brings the L13 default providers.
	 *
	 * @return void
	 */
	protected function registerBusProvider()
	{
		$this->register(new BusServiceProvider($this));
	}

	/**
	 * Register the exception service provider.
	 *
	 * @return void
	 */
	protected function registerExceptionProvider()
	{
		$this->register(new ExceptionServiceProvider($this));
	}

	/**
	 * Register the routing service provider.
	 *
	 * @return void
	 */
	protected function registerRoutingProvider()
	{
		$this->register(new RoutingServiceProvider($this));
	}

	/**
	 * Register the event service provider.
	 *
	 * @return void
	 */
	protected function registerEventProvider()
	{
		$this->register(new EventServiceProvider($this));
	}

	/**
	 * Begin configuring a new application instance.
	 *
	 * ponytail: v13's entry point for bootstrap/app.php. The returned builder carries only
	 * what the app's bootstrap uses; see Configuration\ApplicationBuilder. Remove at the flip.
	 *
	 * @param  string  $basePath
	 * @return \Illuminate\Foundation\Configuration\ApplicationBuilder
	 */
	public static function configure(string $basePath)
	{
		return new ApplicationBuilder((new static)->setBasePath($basePath));
	}

	/**
	 * Set the base path for the application and bind the paths derived from it.
	 *
	 * ponytail: v13's layout for app/public/storage under the base path. path.lang stays at
	 * app/lang, where the L4.2 layout keeps translations (v13's TranslationServiceProvider
	 * reads it). Remove once Foundation swaps to v13.
	 *
	 * @param  string  $basePath
	 * @return $this
	 */
	public function setBasePath($basePath)
	{
		$basePath = rtrim($basePath, '\/');

		$this->instance('path.base', $basePath);
		$this->instance('path', $basePath.'/app');
		$this->instance('path.config', $basePath.'/config');
		$this->instance('path.public', $basePath.'/public');
		$this->instance('path.storage', $basePath.'/storage');
		$this->instance('path.lang', $basePath.'/app/lang');

		return $this;
	}

	/**
	 * Set the storage directory.
	 *
	 * @param  string  $path
	 * @return $this
	 */
	public function useStoragePath($path)
	{
		$this->instance('path.storage', $path);

		return $this;
	}

	/**
	 * Get the path to the environment file directory.
	 *
	 * @return string
	 */
	public function environmentPath()
	{
		return $this->environmentPath ?: $this['path.base'];
	}

	/**
	 * Set the directory for the environment file.
	 *
	 * @param  string  $path
	 * @return $this
	 */
	public function useEnvironmentPath($path)
	{
		$this->environmentPath = $path;

		return $this;
	}

	/**
	 * Set the environment file to be loaded during bootstrapping.
	 *
	 * @param  string  $file
	 * @return $this
	 */
	public function loadEnvironmentFrom($file)
	{
		$this->environmentFile = $file;

		return $this;
	}

	/**
	 * Get the environment file the application is using.
	 *
	 * @return string
	 */
	public function environmentFile()
	{
		return $this->environmentFile ?: '.env';
	}

	/**
	 * Get the fully qualified path to the environment file.
	 *
	 * @return string
	 */
	public function environmentFilePath()
	{
		return $this->environmentPath().DIRECTORY_SEPARATOR.$this->environmentFile();
	}

	/**
	 * Get the path to the application configuration files.
	 *
	 * @param  string  $path
	 * @return string
	 */
	public function configPath($path = '')
	{
		return $this['path.config'].($path != '' ? DIRECTORY_SEPARATOR.$path : '');
	}

	/**
	 * Set the configuration directory.
	 *
	 * @param  string  $path
	 * @return $this
	 */
	public function useConfigPath($path)
	{
		$this->instance('path.config', $path);

		return $this;
	}

	/**
	 * Get the path to the resources directory.
	 *
	 * ponytail: v13 ServiceProviders (e.g. PaginationServiceProvider) call resourcePath();
	 * the L4.2 fork Application lacks it. Remove once Foundation swaps to v13.
	 *
	 * @param  string  $path
	 * @return string
	 */
	public function resourcePath($path = '')
	{
		return $this['path.base'].DIRECTORY_SEPARATOR.'resources'.($path != '' ? DIRECTORY_SEPARATOR.$path : '');
	}

	/**
	 * Get the base path of the installation.
	 *
	 * ponytail: v13 console commands (e.g. MigrateMakeCommand) call basePath();
	 * the L4.2 fork Application lacks it. Remove once Foundation swaps to v13.
	 *
	 * @param  string  $path
	 * @return string
	 */
	public function basePath($path = '')
	{
		return $this['path.base'].($path != '' ? DIRECTORY_SEPARATOR.$path : '');
	}

	/**
	 * Get the path to the database directory.
	 *
	 * ponytail: v13's migrate command resolves migrations via databasePath();
	 * the L4.2 fork keeps migrations under app/database (bound as 'path'), so this
	 * points there rather than the v13 base/database default. Remove at the console swap.
	 *
	 * @param  string  $path
	 * @return string
	 */
	public function databasePath($path = '')
	{
		return $this['path'].DIRECTORY_SEPARATOR.'database'.($path != '' ? DIRECTORY_SEPARATOR.$path : '');
	}

	/**
	 * Get the application bootstrap file.
	 *
	 * @return string
	 */
	public static function getBootstrapFile()
	{
		return __DIR__.'/start.php';
	}

	/**
	 * Determine if the application has been bootstrapped before.
	 *
	 * @return bool
	 */
	public function hasBeenBootstrapped()
	{
		return $this->hasBeenBootstrapped;
	}

	/**
	 * Bootstrap the application with the L4.2 start script, once.
	 *
	 * ponytail: v13 bootstraps through its kernels (environment, configuration, exception
	 * handling, facades, providers). The fork runs start.php in their place, the first time
	 * handleRequest(), handleCommand() or the console Kernel's bootstrap() asks for it.
	 * The environment defaults to production, as in v13. Remove at the flip.
	 *
	 * @return void
	 */
	public function bootstrapWithStartScript()
	{
		if ($this->hasBeenBootstrapped) return;

		$this->hasBeenBootstrapped = true;

		if ( ! $this->bound('env')) $this->detectEnvironment(array());

		$app = $this;

		$env = $this['env'];

		require static::getBootstrapFile();
	}

	/**
	 * Start the exception handling for the request.
	 *
	 * @return void
	 */
	public function startExceptionHandling()
	{
		$this['exception']->register($this->environment());
	}

	/**
	 * Get or check the current application environment.
	 *
	 * @param  string|array
	 * @return string|bool
	 */
	public function environment(...$environments)
	{
		if (count($environments) > 0)
		{
            $environments = is_array($environments[0]) ? $environments[0] : $environments;

			return in_array($this['env'], $environments, true);
		}

		return $this['env'];
	}

	/**
	 * Determine if application is in local environment.
	 *
	 * @return bool
	 */
	public function isLocal()
	{
		return $this['env'] == 'local';
	}

	/**
	 * Detect the application's current environment.
	 *
	 * @param  array|string  $envs
	 * @return string
	 */
	public function detectEnvironment($envs)
	{
		$args = isset($_SERVER['argv']) ? $_SERVER['argv'] : null;

		return $this['env'] = (new EnvironmentDetector())->detect($envs, $args);
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
	 * Determine if we are running unit tests.
	 *
	 * @return bool
	 */
	public function runningUnitTests()
	{
		return $this['env'] == 'testing';
	}

	/**
	 * Force register a service provider with the application.
	 *
	 * @param  \Illuminate\Support\ServiceProvider|string  $provider
	 * @param  array  $options
	 * @return \Illuminate\Support\ServiceProvider
	 */
	public function forceRegister($provider, $options = array())
	{
		return $this->register($provider, $options, true);
	}

	/**
	 * Register a service provider with the application.
	 *
	 * @param  \Illuminate\Support\ServiceProvider|string  $provider
	 * @param  array  $options
	 * @param  bool   $force
	 * @return \Illuminate\Support\ServiceProvider
	 */
	public function register($provider, $options = array(), $force = false)
	{
		if ($registered = $this->getRegistered($provider) && ! $force)
                                     return $registered;

		// If the given "provider" is a string, we will resolve it, passing in the
		// application instance automatically for the developer. This is simply
		// a more convenient way of specifying your service provider classes.
		if (is_string($provider))
		{
			$provider = $this->resolveProviderClass($provider);
		}

		$provider->register();

		// Once we have registered the service we will iterate through the options
		// and set each of them on the application so they will be available on
		// the actual loading of the service objects and for developer usage.
		foreach ($options as $key => $value)
		{
			$this[$key] = $value;
		}

		$this->markAsRegistered($provider);

		// If the application has already booted, we will call this boot method on
		// the provider class so it has an opportunity to do its boot logic and
		// will be ready for any usage by the developer's application logics.
		// v13 ServiceProvider has no default boot(); guard + container-call to
		// mirror boot() so deferred providers (e.g. RedisServiceProvider) resolved
		// after boot don't fatal on a missing boot() method.
		if ($this->booted && method_exists($provider, 'boot')) $this->call([$provider, 'boot']);

		return $provider;
	}

	/**
	 * Get the registered service provider instance if it exists.
	 *
	 * @param  \Illuminate\Support\ServiceProvider|string  $provider
	 * @return \Illuminate\Support\ServiceProvider|null
	 */
	public function getRegistered($provider)
	{
		$name = is_string($provider) ? $provider : get_class($provider);

		if (array_key_exists($name, $this->loadedProviders))
		{
			return Arr::first($this->serviceProviders, function($value, $key) use ($name)
			{
				return get_class($value) == $name;
			});
		}
	}

	/**
	 * Resolve a service provider instance from the class name.
	 *
	 * @param  string  $provider
	 * @return \Illuminate\Support\ServiceProvider
	 */
	public function resolveProviderClass($provider)
	{
		return new $provider($this);
	}

	/**
	 * Mark the given provider as registered.
	 *
	 * @param  \Illuminate\Support\ServiceProvider
	 * @return void
	 */
	protected function markAsRegistered($provider)
	{
		$this['events']->dispatch($class = get_class($provider), array($provider));

		$this->serviceProviders[] = $provider;

		$this->loadedProviders[$class] = true;
	}

	/**
	 * Load and boot all of the remaining deferred providers.
	 *
	 * @return void
	 */
	public function loadDeferredProviders()
	{
		// We will simply spin through each of the deferred providers and register each
		// one and boot them if the application has booted. This should make each of
		// the remaining services available to this application for immediate use.
		foreach ($this->deferredServices as $service => $provider)
		{
			$this->loadDeferredProvider($service);
		}

		$this->deferredServices = array();
	}

	/**
	 * Load the provider for a deferred service.
	 *
	 * @param  string  $service
	 * @return void
	 */
	protected function loadDeferredProvider($service)
	{
		$provider = $this->deferredServices[$service];

		// If the service provider has not already been loaded and registered we can
		// register it with the application and remove the service from this list
		// of deferred services, since it will already be loaded on subsequent.
		if ( ! isset($this->loadedProviders[$provider]))
		{
			$this->registerDeferredProvider($provider, $service);
		}
	}

	/**
	 * Register a deferred provider and service.
	 *
	 * @param  string  $provider
	 * @param  string  $service
	 * @return void
	 */
	public function registerDeferredProvider($provider, $service = null)
	{
		// Once the provider that provides the deferred service has been registered we
		// will remove it from our local list of the deferred services with related
		// providers so that this container does not try to resolve it out again.
		if ($service) unset($this->deferredServices[$service]);

		$this->register($instance = new $provider($this));

		if ( ! $this->booted)
		{
			$this->booting(function() use ($instance)
			{
				// v13 ServiceProvider has no default boot(); call only when defined
				// (mirrors the eager boot() loop). Via the container so boot() DI works.
				if (method_exists($instance, 'boot'))
				{
					$this->call([$instance, 'boot']);
				}
			});
		}
	}

    /**
     * Resolve the given type from the container.
     *
     * (Overriding Container::make)
     *
     * @param string $abstract
     * @param array  $parameters
     * @param bool   $raiseEvents
     *
     * @return mixed
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
	#[\Override]
    public function make($abstract, $parameters = array(), $raiseEvents = true)
	{
		$abstract = $this->getAlias($abstract);

		if (isset($this->deferredServices[$abstract]))
		{
			$this->loadDeferredProvider($abstract);
		}

		return parent::make($abstract, $parameters, $raiseEvents);
	}

	/**
	 * Register a shared binding.
	 *
	 * ponytail: transitional BC shim for the L4 container API removed by
	 * illuminate/container v13. Kept so third-party/vendor providers that still
	 * call bindShared()/share() on the app (spatie/laravel-blade-x,
	 * laracasts/commander, tomgrohl/laravel4-php71-encrypter, barryvdh/laravel-ide-helper)
	 * keep booting. Remove at the Foundation swap (task 4.5); fork src already uses singleton().
	 *
	 * @param  string    $abstract
	 * @param  \Closure  $closure
	 * @return void
	 */
	public function bindShared($abstract, Closure $closure): void
	{
		$this->singleton($abstract, $closure);
	}

	/**
	 * Wrap a closure so the resolved instance is memoized. BC shim; see bindShared().
	 *
	 * @param  \Closure  $closure
	 * @return \Closure
	 */
	public function share(Closure $closure): Closure
	{
		return function ($container) use ($closure)
		{
			static $object;

			if (is_null($object))
			{
				$object = $closure($container);
			}

			return $object;
		};
	}

	/**
	 * Determine if the given abstract type has been bound.
	 *
	 * (Overriding Container::bound)
	 *
	 * @param  string  $abstract
	 * @return bool
	 */
	#[\Override]
    public function bound($abstract): bool
	{
		return isset($this->deferredServices[$abstract]) || parent::bound($abstract);
	}

    /**
     * "Extend" an abstract type in the container.
     *
     * (Overriding Container::extend)
     *
     * @param string   $abstract
     * @param \Closure $closure
     *
     * @return void
     *
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
	#[\Override]
    public function extend($abstract, Closure $closure): void
	{
		$abstract = $this->getAlias($abstract);

		if (isset($this->deferredServices[$abstract]))
		{
			$this->loadDeferredProvider($abstract);
		}

		parent::extend($abstract, $closure);
	}

	/**
	 * Set the application's global middleware (v13 Http\Kernel API).
	 *
	 * @param  array  $middleware
	 * @return $this
	 */
	public function setGlobalMiddleware(array $middleware)
	{
		$this->globalMiddleware = $middleware;

		return $this;
	}

	/**
	 * Get the application's global middleware (v13 Http\Kernel API).
	 *
	 * @return array
	 */
	public function getGlobalMiddleware()
	{
		return $this->globalMiddleware;
	}

	/**
	 * Determine if middleware has been disabled for the application.
	 *
	 * @return bool
	 */
	public function shouldSkipMiddleware()
	{
		return $this->bound('middleware.disable') &&
			$this->make('middleware.disable') === true;
	}

	/**
	 * ponytail: v13 Foundation exposes getNamespace() (root PSR-4 namespace) which
	 * v13's ComponentTagCompiler calls to locate CLASS components. The app uses only
	 * anonymous components, so the value is never matched — return a benign default
	 * instead of parsing composer.json. Remove at task 4.5 foundation swap.
	 *
	 * @return string
	 */
	public function getNamespace()
	{
		return 'App\\';
	}

	/**
	 * Register a terminating callback (v13 API; see $terminatingCallbacks).
	 *
	 * @param  callable  $callback
	 * @return $this
	 */
	public function terminating(callable $callback)
	{
		$this->terminatingCallbacks[] = $callback;

		return $this;
	}

	/**
	 * Determine if the application has booted.
	 *
	 * @return bool
	 */
	public function isBooted()
	{
		return $this->booted;
	}

	/**
	 * Boot the application's service providers.
	 *
	 * @return void
	 */
	public function boot()
	{
		if ($this->booted) return;

		array_walk($this->serviceProviders, function($p) {
			// v13 ServiceProvider has no default boot(); call only when defined (via
			// the container so boot() method-injection keeps working).
			if (method_exists($p, 'boot')) $this->call([$p, 'boot']);
		});

		$this->bootApplication();
	}

	/**
	 * Boot the application and fire app callbacks.
	 *
	 * @return void
	 */
	protected function bootApplication()
	{
		// Once the application has booted we will also fire some "booted" callbacks
		// for any listeners that need to do work after this initial booting gets
		// finished. This is useful when ordering the boot-up processes we run.
		$this->fireAppCallbacks($this->bootingCallbacks);

		$this->booted = true;

		$this->fireAppCallbacks($this->bootedCallbacks);
	}

	/**
	 * Register a new boot listener.
	 *
	 * @param  mixed  $callback
	 * @return void
	 */
	public function booting($callback)
	{
		$this->bootingCallbacks[] = $callback;
	}

	/**
	 * Register a new "booted" listener.
	 *
	 * @param  mixed  $callback
	 * @return void
	 */
	public function booted($callback)
	{
		$this->bootedCallbacks[] = $callback;

		if ($this->isBooted()) $this->fireAppCallbacks(array($callback));
	}

	/**
	 * Register a callback to run after a bootstrapper.
	 *
	 * The start script fires it for the bootstrapper it runs, LoadConfiguration.
	 *
	 * @param  string  $bootstrapper
	 * @param  \Closure  $callback
	 * @return void
	 */
	public function afterBootstrapping($bootstrapper, Closure $callback)
	{
		$this['events']->listen('bootstrapped: '.$bootstrapper, $callback);
	}

	/**
	 * Handle the incoming HTTP request and send the response to the browser.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return void
	 */
	public function handleRequest(Request $request)
	{
		$this->bootstrapWithStartScript();

		$response = $this->handle($request);

		$response->send();

		$this->terminate($request, $response);
	}

	/**
	 * Handle the incoming Artisan command.
	 *
	 * @param  \Symfony\Component\Console\Input\InputInterface  $input
	 * @return int
	 */
	public function handleCommand(InputInterface $input)
	{
		return $this->make('Illuminate\Contracts\Console\Kernel')->handle($input, new ConsoleOutput);
	}

	/**
	 * Handle the given request and get the response.
	 *
	 * Provides compatibility with BrowserKit functional testing.
	 *
	 * @implements HttpKernelInterface::handle
	 *
	 * @param  \Symfony\Component\HttpFoundation\Request  $request
	 * @param  int   $type
	 * @param  bool  $catch
	 * @return \Symfony\Component\HttpFoundation\Response
	 *
	 * @throws \Exception
	 */
	public function handle(SymfonyRequest $request, $type = HttpKernelInterface::MAIN_REQUEST, $catch = true): SymfonyResponse
    {
		try
		{
			$this->refreshRequest($request = Request::createFromBase($request));

			$this->boot();

			return $this->dispatch($request, $catch);
		}
		catch (\Exception $e)
		{
			if ( ! $catch || $this->runningUnitTests()) throw $e;

			return $this['exception']->handleException($e);
		}
		catch (\Throwable $e)
		{
			if ( ! $catch || $this->runningUnitTests()) throw $e;

			return $this['exception']->handleException($e);
		}
	}

	/**
	 * Handle the given request and get the response.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @param  bool  $catch
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function dispatch(Request $request, $catch = true)
	{
		if ($this->runningUnitTests() && ! $this['session']->isStarted())
		{
			$this['session']->start();
		}

		return $this->globalMiddlewarePipeline($catch && ! $this->runningUnitTests())
			->send($this->prepareRequest($request))
			->through($this->shouldSkipMiddleware() ? array() : $this->globalMiddleware)
			->then(fn ($request) => $this['router']->dispatch($request));
	}

	/**
	 * ponytail: the pipeline for the global middleware stack. Like v13's Http\Kernel
	 * (where Routing\Pipeline renders through the bound ExceptionHandler), an exception
	 * is reported and rendered at the stage that threw it, so the middleware around it
	 * (session, cookies) still handles the error response. Outside tests only: the
	 * fork's tests expect exceptions to reach them, and binding the ExceptionHandler
	 * contract would also change the route pipeline. Remove at task 4.5 foundation swap.
	 *
	 * @param  bool  $renderExceptions
	 * @return \Illuminate\Routing\Pipeline
	 */
	protected function globalMiddlewarePipeline($renderExceptions)
	{
		return new class($this, $renderExceptions) extends Pipeline {

			public function __construct(Application $app, private bool $renderExceptions)
			{
				parent::__construct($app);
			}

			protected function handleException($passable, \Throwable $e)
			{
				if ( ! $this->renderExceptions) throw $e;

				return $this->handleCarry($this->container['exception']->handleException($e));
			}

		};
	}

	/**
	 * Call the terminating callbacks assigned to the application.
	 *
	 * @param  \Symfony\Component\HttpFoundation\Request  $request
	 * @param  \Symfony\Component\HttpFoundation\Response  $response
	 * @return void
	 */
	public function terminate(SymfonyRequest $request, SymfonyResponse $response): void
	{
		foreach ($this->terminatingCallbacks as $terminating)
		{
			$this->call($terminating);
		}
	}

	/**
	 * Refresh the bound request instance in the container.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return void
	 */
	protected function refreshRequest(Request $request)
	{
		$this->instance('request', $request);

		Facade::clearResolvedInstance('request');
	}

	/**
	 * Call the booting callbacks for the application.
	 *
	 * @param  array  $callbacks
	 * @return void
	 */
	protected function fireAppCallbacks(array $callbacks)
	{
		foreach ($callbacks as $callback)
		{
			call_user_func($callback, $this);
		}
	}

	/**
	 * Prepare the request by injecting any services.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return \Illuminate\Http\Request
	 */
	public function prepareRequest(Request $request)
	{
		if ( ! is_null($this['config']['session.driver']) && ! $request->hasSession())
		{
			$request->setLaravelSession($this['session']->driver());
		}

		return $request;
	}

	/**
	 * Prepare the given value as a Response object.
	 *
	 * @param  mixed  $value
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function prepareResponse($value)
	{
		if ( ! $value instanceof SymfonyResponse) $value = new Response($value);

		return $value->prepare($this['request']);
	}

	/**
	 * Determine if the application is ready for responses.
	 *
	 * @return bool
	 */
	public function readyForResponses()
	{
		return $this->booted;
	}

	/**
	 * Determine if the application is currently down for maintenance.
	 *
	 * @return bool
	 */
	public function isDownForMaintenance()
	{
		return file_exists($this['config']['app.manifest'].'/down');
	}

	/**
	 * Throw an HttpException with the given data.
	 *
	 * @param  int     $code
	 * @param  string  $message
	 * @param  array   $headers
	 * @return void
	 *
	 * @throws \Symfony\Component\HttpKernel\Exception\HttpException
	 * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
	 */
	public function abort($code, $message = '', array $headers = array())
	{
		if ($code == 404)
		{
			throw new NotFoundHttpException($message);
		}

		throw new HttpException($code, $message, null, $headers);
	}

	/**
	 * Get the environment variables loader instance.
	 *
	 * @return \Illuminate\Config\EnvironmentVariablesLoaderInterface
	 */
	public function getEnvironmentVariablesLoader()
	{
		return new FileEnvironmentVariablesLoader(new Filesystem, $this['path.base']);
	}

	/**
	 * Get the service provider repository instance.
	 *
	 * @return \Illuminate\Foundation\ProviderRepository
	 */
	public function getProviderRepository()
	{
		$manifest = $this['config']['app.manifest'];

		return new ProviderRepository(new Filesystem, $manifest);
	}

	/**
	 * Get the service providers that have been loaded.
	 *
	 * @return array
	 */
	public function getLoadedProviders()
	{
		return $this->loadedProviders;
	}

	/**
	 * Set the application's deferred services.
	 *
	 * @param  array  $services
	 * @return void
	 */
	public function setDeferredServices(array $services)
	{
		$this->deferredServices = $services;
	}

	/**
	 * Determine if the given service is a deferred service.
	 *
	 * @param  string  $service
	 * @return bool
	 */
	public function isDeferredService($service)
	{
		return isset($this->deferredServices[$service]);
	}

	/**
	 * Get or set the request class for the application.
	 *
	 * @param  string  $class
	 * @return string
	 */
	public static function requestClass($class = null)
	{
		if ( ! is_null($class)) static::$requestClass = $class;

		return static::$requestClass;
	}

	/**
	 * Set the application request for the console environment.
	 *
	 * @return void
	 */
	public function setRequestForConsoleEnvironment()
	{
		$url = $this['config']->get('app.url', 'http://localhost');

		$parameters = array($url, 'GET', array(), array(), array(), $_SERVER);

		$this->refreshRequest(static::onRequest('create', $parameters));
	}

	/**
	 * Call a method on the default request class.
	 *
	 * @param  string  $method
	 * @param  array  $parameters
	 * @return mixed
	 */
	public static function onRequest($method, $parameters = array())
	{
		return forward_static_call_array(array(static::requestClass(), $method), $parameters);
	}

	/**
	 * Get the current application locale.
	 *
	 * @return string
	 */
	public function getLocale()
	{
		return $this['config']->get('app.locale');
	}

	/**
	 * Get the current application fallback locale.
	 *
	 * ponytail: v13 TranslationServiceProvider calls getFallbackLocale(); the L4.2 fork
	 * Application lacks it. Remove once Foundation swaps to v13.
	 *
	 * @return string
	 */
	public function getFallbackLocale()
	{
		return $this['config']->get('app.fallback_locale');
	}

	/**
	 * Set the current application locale.
	 *
	 * @param  string  $locale
	 * @return void
	 */
	public function setLocale($locale)
	{
		$this['config']->set('app.locale', $locale);

		$this['translator']->setLocale($locale);

		$this['events']->dispatch('locale.changed', array($locale));
	}

	/**
	 * Register the core class aliases in the container.
	 *
	 * @return void
	 */
	public function registerCoreContainerAliases()
	{
		$aliases = array(
			'app'            => 'Illuminate\Foundation\Application',
			'artisan'        => 'Illuminate\Console\Application',
			'auth'           => 'Illuminate\Auth\AuthManager',
			'blade.compiler' => 'Illuminate\View\Compilers\BladeCompiler',
			'cache'          => 'Illuminate\Cache\CacheManager',
			'cache.store'    => 'Illuminate\Cache\Repository',
			'config'         => 'Illuminate\Config\Repository',
			'cookie'         => 'Illuminate\Cookie\CookieJar',
			'encrypter'      => 'Illuminate\Encryption\Encrypter',
			'db'             => 'Illuminate\Database\DatabaseManager',
			'events'         => 'Illuminate\Events\Dispatcher',
			'files'          => 'Illuminate\Filesystem\Filesystem',
			'hash'           => 'Illuminate\Contracts\Hashing\Hasher',
			'translator'     => 'Illuminate\Translation\Translator',
			'log'            => 'Illuminate\Log\LogManager',
			'mailer'         => 'Illuminate\Mail\Mailer',
			'queue'          => 'Illuminate\Queue\QueueManager',
			'redirect'       => 'Illuminate\Routing\Redirector',
			'redis'          => 'Illuminate\Redis\RedisManager',
			'request'        => 'Illuminate\Http\Request',
			'router'         => 'Illuminate\Routing\Router',
			'session'        => 'Illuminate\Session\SessionManager',
			'session.store'  => 'Illuminate\Session\Store',
			'url'            => 'Illuminate\Routing\UrlGenerator',
			'validator'      => 'Illuminate\Validation\Factory',
			'view'           => 'Illuminate\View\Factory',
		);

		foreach ($aliases as $key => $alias)
		{
			$this->alias($key, $alias);
		}

		// Encrypter now implements the L13 contracts (task 2.9); resolve them to 'encrypter'.
		$this->alias('encrypter', 'Illuminate\Contracts\Encryption\Encrypter');
		$this->alias('encrypter', 'Illuminate\Contracts\Encryption\StringEncrypter');

		// v13's cookie middleware (AddQueuedCookiesToResponse) resolves the jar by its contracts (task 4.5).
		$this->alias('cookie', 'Illuminate\Contracts\Cookie\Factory');
		$this->alias('cookie', 'Illuminate\Contracts\Cookie\QueueingFactory');

		// L13 SCC-1 swap (task 4.1): the swapped components ship v13 contracts. Alias them to
		// the core bindings so v13 code that type-hints the contracts resolves (the v13
		// providers don't always register these against the fork's core aliases).
		$this->alias('events', 'Illuminate\Contracts\Events\Dispatcher');
		$this->alias('redis', 'Illuminate\Contracts\Redis\Factory');
		$this->alias('cache', 'Illuminate\Contracts\Cache\Factory');
		$this->alias('cache.store', 'Illuminate\Contracts\Cache\Repository');
		$this->alias('config', 'Illuminate\Contracts\Config\Repository');
		$this->alias('db', 'Illuminate\Database\ConnectionResolverInterface');

		// L13 view swap (task 4.3): v13 view internals (component rendering) resolve
		// the Factory contract; alias it to the 'view' binding.
		$this->alias('view', 'Illuminate\Contracts\View\Factory');

		// L13 queue swap (task 4.3): the Bus dispatcher's queue resolver and the queue
		// commands resolve the queue contracts; mirror v13's queue alias cluster.
		$this->alias('queue', 'Illuminate\Contracts\Queue\Factory');
		$this->alias('queue', 'Illuminate\Contracts\Queue\Monitor');
		$this->alias('queue.connection', 'Illuminate\Contracts\Queue\Queue');
		$this->alias('queue.failer', 'Illuminate\Queue\Failed\FailedJobProviderInterface');

		// L13 mail swap (task 2.16): the Mail facade and SendQueuedMailable resolve the
		// mail contracts; mirror v13's mail alias cluster.
		$this->alias('mail.manager', 'Illuminate\Mail\MailManager');
		$this->alias('mail.manager', 'Illuminate\Contracts\Mail\Factory');
		$this->alias('mailer', 'Illuminate\Contracts\Mail\Mailer');
		$this->alias('mailer', 'Illuminate\Contracts\Mail\MailQueue');

		// L13 auth swap (task 4.5): the Auth facade, guards and middleware resolve the
		// auth contracts; mirror v13's auth alias cluster.
		$this->alias('auth', 'Illuminate\Contracts\Auth\Factory');
		$this->alias('auth.driver', 'Illuminate\Contracts\Auth\Guard');

		// v13 component rendering autowires the Application/Container contracts;
		// mirror v13's 'app' alias cluster (task 4.3).
		$this->alias('app', 'Illuminate\Contracts\Foundation\Application');
		$this->alias('app', 'Illuminate\Contracts\Container\Container');
		$this->alias('app', 'Psr\Container\ContainerInterface');
	}

}
