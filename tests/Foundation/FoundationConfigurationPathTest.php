<?php

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\KeyGenerateCommand;
use Illuminate\Foundation\Providers\PublisherServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class FoundationConfigurationPathTest extends TestCase
{
	private string $base;

	protected function setUp(): void
	{
		$this->base = sys_get_temp_dir().'/fork-config-path-'.uniqid();
		mkdir($this->base.'/settings', 0777, true);
	}

	protected function tearDown(): void
	{
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		rmdir($this->base);
	}

	#[Test]
	public function keyGenerateReplacesTheKeyInTheConfiguredAppFile()
	{
		$old = str_repeat('o', 32);
		file_put_contents($this->base.'/settings/app.php', "<?php return array('key' => '{$old}', 'cipher' => 'AES-256-CBC');");
		$app = $this->application();
		$app->instance('config', new Repository(array('app' => array('key' => $old))));

		$command = new KeyGenerateCommand(new Filesystem);
		$command->setLaravel($app);
		$status = $command->run(new ArrayInput(array()), $output = new BufferedOutput);

		$key = $app['config']['app.key'];
		$this->assertSame(0, $status);
		$this->assertSame(32, strlen($key));
		$this->assertNotSame($old, $key);
		$this->assertSame(array('key' => $key, 'cipher' => 'AES-256-CBC'), require $this->base.'/settings/app.php');
		$this->assertStringContainsString("Application key [{$key}] set successfully.", $output->fetch());
	}

	#[Test]
	public function configPublishPublishesUnderTheConfiguredPath()
	{
		$app = $this->application();
		$app->instance('files', new Filesystem);
		(new PublisherServiceProvider($app))->register();

		$this->assertSame($this->base.'/settings/packages/foo/bar', $app['config.publisher']->getDestinationPath('foo/bar'));
	}

	private function application(): Application
	{
		$app = new Application;
		$app->setBasePath($this->base);
		$app->useConfigPath($this->base.'/settings');
		$app['env'] = 'testing';

		return $app;
	}
}
