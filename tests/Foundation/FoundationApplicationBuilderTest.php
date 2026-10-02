<?php

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Artisan;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

class FoundationApplicationBuilderTest extends TestCase
{
	private string $base;
	private int $errorReporting;
	private string $displayErrors;
	private string $timezone;
	private int $bootstraps = 0;
	private ?AliasLoader $aliasLoader = null;

	protected function setUp(): void
	{
		$this->errorReporting = error_reporting();
		$this->displayErrors = (string) ini_get('display_errors');
		$this->timezone = date_default_timezone_get();
		$this->aliasLoader = AliasLoader::getInstance();
		AliasLoader::setInstance(new AliasLoader);

		$this->base = sys_get_temp_dir().'/fork-builder-'.uniqid();
		mkdir($this->base.'/config', 0777, true);
		mkdir($this->base.'/app/start', 0777, true);
		mkdir($this->base.'/storage/meta', 0777, true);

		$this->writeConfig('app', array(
			'debug' => false,
			'url' => 'http://builder.test',
			'timezone' => date_default_timezone_get(),
			'aliases' => array(),
			'providers' => array(),
			'manifest' => $this->base.'/storage/meta',
		));
		file_put_contents($this->base.'/storage/meta/services.json', json_encode(array('providers' => array(), 'eager' => array(), 'deferred' => array())));
		file_put_contents($this->base.'/app/routes.php', '<?php $app["router"]->get("/probe", fn () => "probed in ".$app["env"]);');
		file_put_contents($this->base.'/app/start/artisan.php', '<?php throw new RuntimeException("start/artisan.php is no longer loaded");');

		BuilderTestCommand::$requestRoot = null;
		BuilderTestCommand::$greeting = null;
		BuilderTestProvider::$registeredWith = null;
	}

	protected function tearDown(): void
	{
		for (; $this->bootstraps > 0; $this->bootstraps--)
		{
			restore_error_handler();
			restore_exception_handler();
		}

		if (AliasLoader::getInstance()->isRegistered()) spl_autoload_unregister(array(AliasLoader::getInstance(), 'load'));
		AliasLoader::setInstance($this->aliasLoader);

		error_reporting($this->errorReporting);
		ini_set('display_errors', $this->displayErrors);
		date_default_timezone_set($this->timezone);
		foreach (array('FORK_START_SHARED', 'FORK_START_DOTENV', 'APP_ENV') as $key)
		{
			putenv($key);
			unset($_ENV[$key], $_SERVER[$key]);
		}
		Facade::clearResolvedInstances();
		Facade::setFacadeApplication(null);

		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		rmdir($this->base);
	}

	#[Test]
	public function configureBindsTheV13PathsUnderTheBasePath()
	{
		$app = Application::configure($this->base.'/')->create();

		$this->assertSame($this->base, $app['path.base']);
		$this->assertSame($this->base.'/app', $app['path']);
		$this->assertSame($this->base.'/public', $app['path.public']);
		$this->assertSame($this->base.'/storage', $app['path.storage']);
		$this->assertSame($this->base.'/app/lang', $app['path.lang']);
		$this->assertSame($this->base.'/config', $app['path.config']);
		$this->assertSame($this->base.'/config/app.php', $app->configPath('app.php'));

		$this->assertSame($app, $app->useStoragePath('/elsewhere/storage'));
		$this->assertSame('/elsewhere/storage', $app['path.storage']);

		$this->assertSame($app, $app->useConfigPath('/elsewhere/config'));
		$this->assertSame('/elsewhere/config', $app['path.config']);
		$this->assertSame('/elsewhere/config', $app->configPath());
	}

	#[Test]
	public function everyConfigurationFileLoadsWithoutAnEnvironmentCascade()
	{
		$this->writeConfig('services', array('probe' => array('a' => 1, 'b' => 2)));
		$this->writeConfig('testing/services', array('probe' => array('b' => 3)));
		$this->writeConfig('nested/deep/thing', array('x' => 1));

		$config = $this->bootstrapped('testing')['config'];

		$this->assertSame(array('a' => 1, 'b' => 2), $config['services.probe']);
		$this->assertSame(3, $config['testing.services.probe.b']);
		$this->assertSame(1, $config['nested.deep.thing.x']);
		$this->assertSame('http://builder.test', $config['app.url']);
	}

	#[Test]
	public function theEnvFileLoadsFirstAndTheEnvironmentsPhpFileWins()
	{
		file_put_contents($this->base.'/.env', "FORK_START_SHARED=dotenv\nFORK_START_DOTENV=dotenv-only\n");
		file_put_contents($this->base.'/.env.testing.php', '<?php return array("FORK_START_SHARED" => "php");');

		$this->bootstrapped('testing');

		$this->assertSame('php', getenv('FORK_START_SHARED'));
		$this->assertSame('dotenv-only', getenv('FORK_START_DOTENV'));
	}

	#[Test]
	public function theConfigurationLoadsFromTheConfiguredPath()
	{
		mkdir($this->base.'/app/config', 0777, true);
		file_put_contents($this->base.'/app/config/app.php', file_get_contents($this->base.'/config/app.php'));
		file_put_contents($this->base.'/app/config/services.php', '<?php return array("from" => "app/config");');

		$app = $this->configure('testing');
		$app->useConfigPath($this->base.'/app/config');
		$app->make(Kernel::class)->bootstrap();

		$this->assertSame('app/config', $app['config']['services.from']);
	}

	#[Test]
	public function theConfiguredTimezoneIsSet()
	{
		$timezone = date_default_timezone_get() === 'Asia/Tokyo' ? 'Europe/Paris' : 'Asia/Tokyo';
		$this->writeConfig('app', array_merge(require $this->base.'/config/app.php', array('timezone' => $timezone)));

		$this->bootstrapped('testing');

		$this->assertSame($timezone, date_default_timezone_get());
	}

	#[Test]
	public function callbacksAfterLoadingTheConfigurationRunBeforeTheProvidersRegister()
	{
		$received = null;
		file_put_contents($this->base.'/storage/meta/services.json', json_encode(array('providers' => array(BuilderTestProvider::class), 'eager' => array(BuilderTestProvider::class), 'deferred' => array())));
		$app = $this->configure('testing');
		$app->afterBootstrapping(LoadConfiguration::class, function ($app) use (&$received) {
			$received = $app;
			$app['config']->set('app.providers', array(BuilderTestProvider::class));
			$app['config']->set('services.word', 'from the callback');
		});

		$app->make(Kernel::class)->bootstrap();

		$this->assertSame($app, $received);
		$this->assertSame('from the callback', BuilderTestProvider::$registeredWith);
	}

	#[Test]
	public function withMiddlewareDefinesTheGlobalMiddleware()
	{
		$app = Application::configure($this->base)
			->withMiddleware(fn (Middleware $middleware) => $middleware->use(array(BuilderTestMiddleware::class)))
			->create();

		$this->assertSame(array(BuilderTestMiddleware::class), $app->getGlobalMiddleware());
		$this->assertSame(array(), Application::configure($this->base)->withMiddleware()->create()->getGlobalMiddleware());
	}

	#[Test]
	public function withExceptionsConfiguresTheHandlerOnceItIsResolved()
	{
		$configured = null;

		$app = Application::configure($this->base)
			->withExceptions(function (Exceptions $exceptions) use (&$configured) { $configured = $exceptions; })
			->create();

		$this->assertNull($configured);
		$this->assertSame($app['exception'], $configured->handler);
	}

	#[Test]
	public function theConsoleKernelBootstrapsTheStartScriptOnce()
	{
		$app = $this->configure('testing');

		$kernel = $app->make(Kernel::class);

		$this->assertInstanceOf(Artisan::class, $kernel);
		$this->assertFalse($app->hasBeenBootstrapped());

		$kernel->bootstrap();
		$config = $app['config'];
		$kernel->bootstrap();

		$this->assertTrue($app->hasBeenBootstrapped());
		$this->assertSame('http://builder.test', $config['app.url']);
		$this->assertSame($config, $app['config']);
		$this->assertSame('testing', $app['env']);
	}

	#[Test]
	public function theEnvironmentDefaultsToProduction()
	{
		$app = Application::configure($this->base)->create();

		$app->bootstrapWithStartScript();
		$this->bootstraps++;

		$this->assertSame('production', $app['env']);
	}

	#[Test]
	public function appEnvFromTheEnvFileNamesTheEnvironmentAndItsPhpFile()
	{
		file_put_contents($this->base.'/.env', "APP_ENV=staging\n");
		file_put_contents($this->base.'/.env.staging.php', '<?php return array("FORK_START_SHARED" => "staging");');
		$app = Application::configure($this->base)->create();

		$app->bootstrapWithStartScript();
		$this->bootstraps++;

		$this->assertSame('staging', $app['env']);
		$this->assertSame('staging', getenv('FORK_START_SHARED'));
	}

	#[Test]
	public function anEnvironmentTheApplicationDetectedWinsOverAppEnv()
	{
		file_put_contents($this->base.'/.env', "APP_ENV=staging\n");
		file_put_contents($this->base.'/.env.local.php', '<?php return array("FORK_START_SHARED" => "local");');
		$app = Application::configure($this->base)->create();
		$app->detectEnvironment(fn () => 'local');

		$app->bootstrapWithStartScript();
		$this->bootstraps++;

		$this->assertSame('local', $app['env']);
		$this->assertSame('local', getenv('FORK_START_SHARED'));
	}

	#[Test]
	public function handleRequestBootstrapsSendsTheResponseAndTerminates()
	{
		$app = $this->configure('production', fn (Middleware $middleware) => $middleware->use(array(BuilderTestMiddleware::class)));
		$terminated = false;
		$app->terminating(function () use (&$terminated) { $terminated = true; });

		ob_start();
		$app->handleRequest(Request::create('/probe'));
		$output = ob_get_clean();

		$this->assertSame('[probed in production]', $output);
		$this->assertTrue($terminated);
	}

	#[Test]
	public function handleCommandRunsTheCommandWithTheConsoleRequestAndReturnsItsStatus()
	{
		$app = $this->configure('testing', commands: array(BuilderTestCommand::class));

		$status = $app->handleCommand(new ArrayInput(array('command' => 'builder:probe')));

		$this->assertSame(3, $status);
		$this->assertSame('http://builder.test', BuilderTestCommand::$requestRoot);
	}

	#[Test]
	public function withCommandsBuildsEveryRegisteredCommandThroughTheContainer()
	{
		$app = $this->configure('testing', commands: array(BuilderTestCommand::class));
		$app->instance(BuilderTestGreeting::class, new BuilderTestGreeting('bound'));

		$kernel = $app->make(Kernel::class);
		$kernel->addCommands(array(BuilderTestOtherCommand::class));

		$this->assertSame(4, $app->handleCommand(new ArrayInput(array('command' => 'builder:other'))));
		$this->assertSame(3, $kernel->call('builder:probe'));
		$this->assertSame('bound', BuilderTestCommand::$greeting);
	}

	#[Test]
	public function theLaravel42EntryPointsAreGone()
	{
		$this->assertFalse(method_exists(Application::class, 'run'));
		$this->assertFalse(method_exists(Application::class, 'bindInstallPaths'));
	}

	private function writeConfig(string $name, array $items): void
	{
		if ( ! is_dir($directory = dirname($this->base.'/config/'.$name))) mkdir($directory, 0777, true);

		file_put_contents($this->base.'/config/'.$name.'.php', '<?php return '.var_export($items, true).';');
	}

	private function bootstrapped(string $env): Application
	{
		$app = $this->configure($env);
		$app->make(Kernel::class)->bootstrap();

		return $app;
	}

	private function configure(string $env, ?callable $middleware = null, array $commands = array()): Application
	{
		$app = Application::configure($this->base)->withMiddleware($middleware)->withCommands($commands)->create();
		$app['env'] = $env;
		$this->bootstraps++;

		return $app;
	}
}

class BuilderTestMiddleware
{
	public function handle($request, Closure $next)
	{
		$response = $next($request);
		$response->setContent('['.$response->getContent().']');

		return $response;
	}
}

class BuilderTestProvider extends ServiceProvider
{
	public static ?string $registeredWith = null;

	public function register()
	{
		static::$registeredWith = $this->app['config']['services.word'];
	}
}

class BuilderTestGreeting
{
	public function __construct(public string $word = 'autowired')
	{
	}
}

class BuilderTestCommand extends Command
{
	public static ?string $requestRoot = null;
	public static ?string $greeting = null;

	protected $signature = 'builder:probe';

	public function __construct(private BuilderTestGreeting $builderGreeting)
	{
		parent::__construct();
	}

	public function handle()
	{
		static::$requestRoot = $this->laravel['request']->root();
		static::$greeting = $this->builderGreeting->word;

		return 3;
	}
}

class BuilderTestOtherCommand extends Command
{
	protected $signature = 'builder:other';

	public function handle()
	{
		return 4;
	}
}
