<?php

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Artisan;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

class FoundationApplicationBuilderTest extends TestCase
{
	private string $base;
	private int $errorReporting;
	private string $displayErrors;
	private int $bootstraps = 0;
	private ?AliasLoader $aliasLoader = null;

	protected function setUp(): void
	{
		$this->errorReporting = error_reporting();
		$this->displayErrors = (string) ini_get('display_errors');
		$this->aliasLoader = AliasLoader::getInstance();
		AliasLoader::setInstance(new AliasLoader);

		$this->base = sys_get_temp_dir().'/fork-builder-'.uniqid();
		mkdir($this->base.'/app/config', 0777, true);
		mkdir($this->base.'/app/start', 0777, true);
		mkdir($this->base.'/storage/meta', 0777, true);

		file_put_contents($this->base.'/app/config/app.php', '<?php return '.var_export(array(
			'debug' => false,
			'url' => 'http://builder.test',
			'timezone' => date_default_timezone_get(),
			'aliases' => array(),
			'providers' => array(),
			'manifest' => $this->base.'/storage/meta',
		), true).';');
		file_put_contents($this->base.'/storage/meta/services.json', json_encode(array('providers' => array(), 'eager' => array(), 'deferred' => array())));
		file_put_contents($this->base.'/app/routes.php', '<?php $app["router"]->get("/probe", fn () => "probed in ".$app["env"]);');
		file_put_contents($this->base.'/app/start/artisan.php', '<?php throw new RuntimeException("start/artisan.php is no longer loaded");');

		BuilderTestCommand::$requestRoot = null;
		BuilderTestCommand::$greeting = null;
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

		$this->assertSame($app, $app->useStoragePath('/elsewhere/storage'));
		$this->assertSame('/elsewhere/storage', $app['path.storage']);
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
