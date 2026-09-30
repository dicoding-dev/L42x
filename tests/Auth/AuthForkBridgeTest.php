<?php

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Auth\SessionGuard;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Cookie\CookieJar;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\ApplicationTrait;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AuthForkBridgeTest extends TestCase
{
	#[Test]
	public function authContractsResolveToTheAuthBindings()
	{
		$app = $this->makeApplication();

		$this->assertInstanceOf(AuthManager::class, $app->make(AuthFactory::class));
		$this->assertSame($app['auth'], $app->make(AuthFactory::class));
		$this->assertInstanceOf(SessionGuard::class, $app->make(Guard::class));
		$this->assertSame($app['auth']->guard(), $app->make(Guard::class));
	}

	#[Test]
	public function beSetsTheUserOnTheDefaultGuard()
	{
		$app = $this->makeApplication();
		$user = new GenericUser(array('id' => 7));

		$this->testCaseFor($app)->be($user);

		$this->assertSame($user, $app['auth']->user());
		$this->assertSame(7, $app['auth']->id());
	}

	#[Test]
	public function beSetsTheUserOnANamedGuard()
	{
		$app = $this->makeApplication();
		$user = new GenericUser(array('id' => 9));

		$this->testCaseFor($app)->be($user, 'admin');

		$this->assertSame($user, $app['auth']->guard('admin')->user());
		$this->assertNull($app['auth']->guard('web')->user());
	}

	protected function testCaseFor(Application $app)
	{
		return new class($app) {
			use ApplicationTrait;

			public function __construct(Application $app)
			{
				$this->app = $app;
			}
		};
	}

	protected function makeApplication()
	{
		$app = new Application;
		$app->instance('app', $app);
		$app->registerCoreContainerAliases();
		$app->instance('config', new Repository(array(
			'app' => array('key' => str_repeat('k', 32)),
			'auth' => array(
				'defaults' => array('guard' => 'web'),
				'guards' => array(
					'web' => array('driver' => 'session', 'provider' => 'users'),
					'admin' => array('driver' => 'session', 'provider' => 'users'),
				),
				'providers' => array('users' => array('driver' => 'memory')),
			),
		)));
		$app->instance('session.store', new Store('test', new ArraySessionHandler(10)));
		$app->instance('cookie', new CookieJar);
		$app->instance('events', new Dispatcher($app));
		$app->instance('request', Request::create('/'));
		$app->register(new AuthServiceProvider($app));
		$app['auth']->provider('memory', fn () => new AuthForkBridgeUserProvider);

		return $app;
	}
}

class AuthForkBridgeUserProvider implements UserProvider
{
	public function retrieveById($identifier)
	{
		return null;
	}

	public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
	{
		return null;
	}

	public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token)
	{
	}

	public function retrieveByCredentials(#[\SensitiveParameter] array $credentials)
	{
		return null;
	}

	public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials)
	{
		return false;
	}

	public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false)
	{
	}
}
