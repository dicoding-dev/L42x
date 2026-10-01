<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FoundationLoadEnvironmentVariablesTest extends TestCase
{
	private string $base;
	private array $argv;

	protected function setUp(): void
	{
		$this->argv = $_SERVER['argv'];
		$this->base = sys_get_temp_dir().'/fork-dotenv-'.uniqid();
		mkdir($this->base.'/elsewhere', 0777, true);
	}

	protected function tearDown(): void
	{
		$_SERVER['argv'] = $this->argv;
		foreach (array('FORK_DOTENV_VALUE', 'FORK_DOTENV_PROCESS', 'FORK_DOTENV_OTHER', 'APP_ENV') as $key)
		{
			putenv($key);
			unset($_ENV[$key], $_SERVER[$key]);
		}

		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		rmdir($this->base);
	}

	#[Test]
	public function theEnvFileLoadsIntoTheEnvironment()
	{
		file_put_contents($this->base.'/.env', "FORK_DOTENV_VALUE=from-dotenv\n");

		$app = $this->bootstrapped();

		$this->assertSame('.env', $app->environmentFile());
		$this->assertSame($this->base.'/.env', $app->environmentFilePath());
		$this->assertSame('from-dotenv', getenv('FORK_DOTENV_VALUE'));
		$this->assertSame('from-dotenv', $_ENV['FORK_DOTENV_VALUE']);
		$this->assertSame('from-dotenv', $_SERVER['FORK_DOTENV_VALUE']);
		$this->assertSame('from-dotenv', env('FORK_DOTENV_VALUE'));
	}

	#[Test]
	public function theEnvOptionPicksTheMatchingEnvFile()
	{
		file_put_contents($this->base.'/.env', "FORK_DOTENV_VALUE=base\n");
		file_put_contents($this->base.'/.env.staging', "FORK_DOTENV_VALUE=staging\n");
		$_SERVER['argv'] = array('artisan', 'list', '--env=staging');

		$app = $this->bootstrapped();

		$this->assertSame('.env.staging', $app->environmentFile());
		$this->assertSame('staging', getenv('FORK_DOTENV_VALUE'));
	}

	#[Test]
	public function appEnvPicksTheMatchingEnvFile()
	{
		file_put_contents($this->base.'/.env', "FORK_DOTENV_VALUE=base\n");
		file_put_contents($this->base.'/.env.staging', "FORK_DOTENV_VALUE=staging\n");
		putenv('APP_ENV=staging');

		$app = $this->bootstrapped();

		$this->assertSame('.env.staging', $app->environmentFile());
		$this->assertSame('staging', getenv('FORK_DOTENV_VALUE'));
	}

	#[Test]
	public function anEnvironmentWithoutItsOwnFileFallsBackToTheEnvFile()
	{
		file_put_contents($this->base.'/.env', "FORK_DOTENV_VALUE=base\n");
		$_SERVER['argv'] = array('artisan', 'list', '--env=missing');

		$app = $this->bootstrapped();

		$this->assertSame('.env', $app->environmentFile());
		$this->assertSame('base', getenv('FORK_DOTENV_VALUE'));
	}

	#[Test]
	public function variablesAlreadyInTheEnvironmentAreKept()
	{
		file_put_contents($this->base.'/.env', "FORK_DOTENV_PROCESS=from-dotenv\nFORK_DOTENV_OTHER=added\n");
		putenv('FORK_DOTENV_PROCESS=from-the-process');

		$this->bootstrapped();

		$this->assertSame('from-the-process', getenv('FORK_DOTENV_PROCESS'));
		$this->assertSame('added', getenv('FORK_DOTENV_OTHER'));
	}

	#[Test]
	public function aMissingEnvFileLoadsNothing()
	{
		$this->bootstrapped();

		$this->assertFalse(getenv('FORK_DOTENV_VALUE'));
	}

	#[Test]
	public function theEnvFileLoadsFromTheConfiguredEnvironmentPath()
	{
		file_put_contents($this->base.'/.env', "FORK_DOTENV_VALUE=base\n");
		file_put_contents($this->base.'/elsewhere/.env', "FORK_DOTENV_VALUE=elsewhere\n");

		$app = new Application;
		$app->setBasePath($this->base);
		$this->assertSame($app, $app->useEnvironmentPath($this->base.'/elsewhere'));
		(new LoadEnvironmentVariables)->bootstrap($app);

		$this->assertSame($this->base.'/elsewhere', $app->environmentPath());
		$this->assertSame('elsewhere', getenv('FORK_DOTENV_VALUE'));
	}

	private function bootstrapped(): Application
	{
		$app = new Application;
		$app->setBasePath($this->base);
		(new LoadEnvironmentVariables)->bootstrap($app);

		return $app;
	}
}
