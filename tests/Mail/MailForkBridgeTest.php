<?php

use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Contracts\Mail\MailQueue;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\MailServiceProvider;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Queue\FailConsoleServiceProvider;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Support\SerializableClosure;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

class MailForkBridgeTest extends TestCase
{
	use ProphecyTrait;

	protected function setUp(): void
	{
		Mailer::flushMacros();
		ConsoleApplication::forgetBootstrappers();
	}

	protected function tearDown(): void
	{
		Mailer::flushMacros();
		ConsoleApplication::forgetBootstrappers();
	}

	public function testMailContractsResolveToTheMailBindings()
	{
		$app = $this->makeApplication();

		$this->assertInstanceOf(MailManager::class, $app->make(MailFactory::class));
		$this->assertSame($app['mail.manager'], $app->make(MailFactory::class));
		$this->assertSame($app['mailer'], $app->make(MailerContract::class));
		$this->assertSame($app['mailer'], $app->make(MailQueue::class));
	}

	public function testLegacyMailConfigSendsThroughItsDriver()
	{
		$app = $this->makeApplication();

		$app['mailer']->send('emails.hello', array('name' => 'Taylor'), function ($message)
		{
			$message->to('taylor@example.com', 'Taylor')->subject('Hello');
		});

		$sent = $this->sentMessages($app);
		$this->assertCount(1, $sent);
		$this->assertSame('Hello', $sent[0]->getSubject());
		$this->assertSame('taylor@example.com', $sent[0]->getTo()[0]->getAddress());
		$this->assertSame('app@example.com', $sent[0]->getFrom()[0]->getAddress());
		$this->assertStringContainsString('Hello Taylor', $sent[0]->getHtmlBody());
	}

	public function testMailableQueuedOnTheSyncQueueIsSent()
	{
		$app = $this->makeApplication();

		$app['mailer']->queue(new MailForkBridgeMailable);

		$sent = $this->sentMessages($app);
		$this->assertCount(1, $sent);
		$this->assertSame('Queued', $sent[0]->getSubject());
	}

	public function testLegacyQueuedMailIsNotRegisteredOutsideTheWorker()
	{
		$app = $this->makeApplication();
		$app->register(new CacheServiceProvider($app));
		$app->register(new FailConsoleServiceProvider($app));

		$this->assertFalse(Mailer::hasMacro('handleQueuedMessage'));
	}

	public function testMailQueuedBeforeTheSwapDrainsInTheWorker()
	{
		$app = $this->makeApplication();
		$app->register(new CacheServiceProvider($app));
		$app->register(new FailConsoleServiceProvider($app));
		$app->make(WorkCommand::class);

		$callback = serialize(new SerializableClosure(function ($message)
		{
			$message->to('taylor@example.com', 'Taylor')->subject('Legacy');
		}));
		$payload = json_encode(array(
			'job' => 'mailer@handleQueuedMessage',
			'data' => array('view' => 'emails.hello', 'data' => array('name' => 'Taylor'), 'callback' => $callback),
		));

		$job = new SyncJob($app, $payload, 'sync', 'default');
		$job->fire();

		$sent = $this->sentMessages($app);
		$this->assertCount(1, $sent);
		$this->assertSame('Legacy', $sent[0]->getSubject());
		$this->assertStringContainsString('Hello Taylor', $sent[0]->getHtmlBody());
		$this->assertTrue($job->isDeleted());
	}

	protected function sentMessages(Application $app)
	{
		return $app['mailer']->getSymfonyTransport()->messages()
			->map(fn ($sent) => $sent->getOriginalMessage())
			->values()
			->all();
	}

	protected function makeApplication()
	{
		$view = $this->prophesize(View::class);
		$view->render()->will(fn () => 'Hello Taylor');
		$views = $this->prophesize(ViewFactory::class);
		$views->make('emails.hello', Argument::type('array'))->willReturn($view->reveal());

		$app = new Application;
		$app->instance('app', $app);
		$app->registerCoreContainerAliases();
		$app->instance('path.base', __DIR__);
		$app->instance('files', new Filesystem);
		$app->instance('view', $views->reveal());
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
			'mail' => array(
				'driver' => 'array',
				'from' => array('address' => 'app@example.com', 'name' => 'App'),
				'pretend' => false,
			),
		)));
		$app->instance('encrypter', new Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
		$app->register(new QueueServiceProvider($app));
		$app->register(new MailServiceProvider($app));

		return $app;
	}
}

class MailForkBridgeMailable extends Mailable
{
	public function build()
	{
		return $this->to('taylor@example.com')->subject('Queued')->html('<p>Queued</p>');
	}
}
