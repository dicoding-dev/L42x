<?php

use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Monitor as QueueMonitor;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Encryption\Encrypter;
use Illuminate\Exception\ExceptionHandlerAdapter;
use Illuminate\Exception\Handler;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Console\ListenCommand;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Queue\FailConsoleServiceProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Queue\Worker;
use Illuminate\Support\SerializableClosure;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;

class QueueForkBridgeTest extends TestCase
{
	use ProphecyTrait;

	protected function setUp(): void
	{
		QueueForkBridgeState::$calls = array();
		ConsoleApplication::forgetBootstrappers();
	}

	protected function tearDown(): void
	{
		ConsoleApplication::forgetBootstrappers();
	}

	public function testQueueContractsResolveToTheQueueBindings()
	{
		$app = $this->makeApplication();

		$this->assertSame($app['queue'], $app->make(QueueFactory::class));
		$this->assertSame($app['queue'], $app->make(QueueMonitor::class));
		$this->assertSame($app['queue.connection'], $app->make(QueueContract::class));
		$this->assertSame($app['queue.failer'], $app->make(FailedJobProviderInterface::class));
	}

	public function testBusDispatcherIsRegisteredByDefault()
	{
		$app = new Application;

		$this->assertTrue($app->bound(BusDispatcher::class));
	}

	public function testExceptionHandlerContractIsNotBoundOutsideTheWorker()
	{
		$app = $this->makeApplication();
		$app->register(new CacheServiceProvider($app));
		$app->register(new FailConsoleServiceProvider($app));

		$this->assertFalse($app->bound(ExceptionHandler::class));
	}

	public function testWorkerCommandReportsFailedJobsThroughTheForkHandler()
	{
		$exception = new RuntimeException('job failed');
		$handler = $this->prophesize(Handler::class);
		$handler->report($exception)->shouldBeCalledOnce();

		$app = $this->makeApplication();
		$app->instance('exception', $handler->reveal());
		$app->register(new CacheServiceProvider($app));
		$app->register(new FailConsoleServiceProvider($app));

		$this->assertInstanceOf(WorkCommand::class, $app->make(WorkCommand::class));
		$this->assertInstanceOf(Worker::class, $app->make('queue.worker'));

		$adapter = $app->make(ExceptionHandler::class);
		$adapter->report($exception);

		$this->assertInstanceOf(ExceptionHandlerAdapter::class, $adapter);
	}

	public function testStringJobRunsItsFireMethod()
	{
		$this->makeApplication()->make('queue')->push(QueueForkBridgeJob::class, array('foo' => 'bar'));

		$this->assertSame(array(array('fire', array('foo' => 'bar'))), QueueForkBridgeState::$calls);
	}

	public function testClosureJobRunsThroughTheBusDispatcher()
	{
		$this->makeApplication()->make('queue')->push(function ($job)
		{
			QueueForkBridgeState::$calls[] = array('closure');

			$job->delete();
		});

		$this->assertSame(array(array('closure')), QueueForkBridgeState::$calls);
	}

	public function testStringJobQueuedBeforeTheSwapStillFires()
	{
		$app = $this->makeApplication();
		$payload = json_encode(array('job' => QueueForkBridgeJob::class, 'data' => array('foo' => 'bar')));

		(new SyncJob($app, $payload, 'sync', 'default'))->fire();

		$this->assertSame(array(array('fire', array('foo' => 'bar'))), QueueForkBridgeState::$calls);
	}

	public function testClosureJobQueuedBeforeTheSwapStillFires()
	{
		$app = $this->makeApplication();
		$closure = $app['encrypter']->encrypt(serialize(new SerializableClosure(function ($job)
		{
			QueueForkBridgeState::$calls[] = array('legacy closure');
		})));
		$payload = json_encode(array('job' => 'IlluminateQueueClosure', 'data' => compact('closure')));

		(new SyncJob($app, $payload, 'sync', 'default'))->fire();

		$this->assertSame(array(array('legacy closure')), QueueForkBridgeState::$calls);
	}

	public function testQueueCommandsAreRegisteredWithTheConsole()
	{
		$app = $this->makeApplication();
		$app->register(new CacheServiceProvider($app));
		$app->register(new FailConsoleServiceProvider($app));

		$console = new ConsoleApplication($app, $app['events'], 'test');
		$console->setContainerCommandLoader();

		foreach (array('queue:work', 'queue:listen', 'queue:restart', 'queue:failed', 'queue:retry', 'queue:forget', 'queue:flush', 'make:queue-failed-table') as $name)
		{
			$this->assertTrue($console->has($name), "{$name} is not registered");
		}

		$this->assertInstanceOf(WorkCommand::class, $console->get('queue:work'));
		$this->assertInstanceOf(ListenCommand::class, $console->get('queue:listen'));
	}

	protected function makeApplication()
	{
		$app = new Application;
		$app->instance('app', $app);
		$app->registerCoreContainerAliases();
		$app->instance('path.base', __DIR__);
		$app->instance('files', new Filesystem);
		$app->instance('config', new Repository(array(
			'cache' => array(
				'default' => 'array',
				'stores' => array('array' => array('driver' => 'array')),
			),
			'queue' => array(
				'default' => 'sync',
				'connections' => array('sync' => array('driver' => 'sync')),
				'failed' => array('driver' => null),
			),
		)));
		$app->instance('encrypter', new Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
		$app->register(new QueueServiceProvider($app));

		return $app;
	}
}

class QueueForkBridgeState
{
	public static $calls = array();
}

class QueueForkBridgeJob
{
	public function fire($job, $data)
	{
		QueueForkBridgeState::$calls[] = array('fire', $data);

		$job->delete();
	}
}
