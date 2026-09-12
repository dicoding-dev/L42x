<?php

use Illuminate\Container\Container;
use Illuminate\Cookie\CookieJar;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Manager;
use Illuminate\Validation\Factory as ValidationFactory;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class OctaneRepointSettersTest extends TestCase
{
	protected function tearDown(): void
	{
		m::close();
	}

	public function testManagerSetApplicationReplacesAppAndReturnsThis()
	{
		$manager = $this->newConcreteManager(array('env' => 'testing'));
		$other = array('env' => 'sandbox');

		$result = $manager->setApplication($other);

		$this->assertSame($result, $manager);
		$this->assertSame($other, $this->getManagerApp($manager));
	}

	public function testManagerForgetDriversClearsDriversPreservesCustomCreators()
	{
		$manager = $this->newConcreteManager(array('env' => 'testing'));
		$noop = function() {};
		$manager->extend('fake', $noop);

		$this->setPrivate($manager, 'drivers', array('fake' => new \stdClass()));

		$result = $manager->forgetDrivers();

		$this->assertSame($result, $manager);
		$this->assertEmpty($this->getManagerDrivers($manager));
		$this->assertArrayHasKey('fake', $this->getManagerCustomCreators($manager));
	}

	public function testQueueManagerSetApplicationReturnsThis()
	{
		$app = array('config' => array('queue.default' => 'sync'));
		$manager = new QueueManager($app);
		$other = array('config' => array('queue.default' => 'sync'));

		$result = $manager->setApplication($other);

		$this->assertSame($result, $manager);
		$this->assertSame($other, $this->getPrivate($manager, 'app'));
	}

	public function testQueueManagerForgetConnectionsClearsConnectionsPreservesConnectors()
	{
		$app = array('config' => array('queue.default' => 'sync'));
		$manager = new QueueManager($app);

		$this->setPrivate($manager, 'connectors', array('fake' => function() {}));
		$this->setPrivate($manager, 'connections', array('fake' => new \stdClass()));

		$result = $manager->forgetConnections();

		$this->assertSame($result, $manager);
		$this->assertEmpty($this->getPrivate($manager, 'connections'));
		$this->assertNotEmpty($this->getPrivate($manager, 'connectors'));
	}

	public function testDatabaseManagerSetApplicationReturnsThis()
	{
		$app = m::mock('Illuminate\Foundation\Application');
		$factory = m::mock('Illuminate\Database\Connectors\ConnectionFactory');
		$manager = new DatabaseManager($app, $factory);
		$other = m::mock('Illuminate\Foundation\Application');

		$result = $manager->setApplication($other);

		$this->assertSame($result, $manager);
		$this->assertSame($other, $this->getPrivate($manager, 'app'));
	}

	public function testDatabaseManagerForgetConnectionsPreservesExtensionsAndFactory()
	{
		$app = m::mock('Illuminate\Foundation\Application');
		$factory = m::mock('Illuminate\Database\Connectors\ConnectionFactory');
		$manager = new DatabaseManager($app, $factory);

		$this->setPrivate($manager, 'extensions', array('foo' => function() {}));

		$result = $manager->forgetConnections();

		$this->assertSame($result, $manager);
		$this->assertNotEmpty($this->getPrivate($manager, 'extensions'));
		$this->assertSame($factory, $this->getPrivate($manager, 'factory'));
	}

	public function testCookieJarFlushQueuedCookiesClearsQueueAndReturnsThis()
	{
		$jar = new CookieJar();
		$jar->queue($jar->make('foo', 'bar'));
		$this->assertNotEmpty($jar->getQueuedCookies());

		$result = $jar->flushQueuedCookies();

		$this->assertSame($result, $jar);
		$this->assertEmpty($jar->getQueuedCookies());
	}

	public function testValidationFactorySetContainerReplacesContainerAndReturnsThis()
	{
		$translator = m::mock(TranslatorInterface::class);
		$factory = new ValidationFactory($translator);
		$container = new Container();

		$result = $factory->setContainer($container);

		$this->assertSame($result, $factory);
		$this->assertSame($container, $this->getPrivate($factory, 'container'));
	}

	private function newConcreteManager(array $app): Manager
	{
		return new class($app) extends Manager {
			public function getDefaultDriver(): string
			{
				return 'default';
			}

			protected function createDefaultDriver()
			{
				return new \stdClass();
			}
		};
	}

	private function getManagerApp(Manager $manager)
	{
		return $this->getPrivate($manager, 'app');
	}

	private function getManagerDrivers(Manager $manager): array
	{
		return $this->getPrivate($manager, 'drivers');
	}

	private function getManagerCustomCreators(Manager $manager): array
	{
		return $this->getPrivate($manager, 'customCreators');
	}

	private function getPrivate(object $object, string $property)
	{
		$reflection = new \ReflectionProperty($object, $property);
		$reflection->setAccessible(true);

		return $reflection->getValue($object);
	}

	private function setPrivate(object $object, string $property, $value): void
	{
		$reflection = new \ReflectionProperty($object, $property);
		$reflection->setAccessible(true);
		$reflection->setValue($object, $value);
	}
}
