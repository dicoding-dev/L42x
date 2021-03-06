<?php

use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class DatabaseMigrationInstallCommandTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testFireCallsRepositoryToInstall()
    {
        $command = new Illuminate\Database\Console\Migrations\InstallCommand(
            $repo = m::mock(MigrationRepositoryInterface::class)
        );
        $repo->shouldReceive('setSource')->once()->with('foo');
        $repo->shouldReceive('createRepository')->once();

		$this->runCommand($command, ['--database' => 'foo']);
	}


	protected function runCommand($command, $options = [])
	{
		return $command->run(new Symfony\Component\Console\Input\ArrayInput($options), new Symfony\Component\Console\Output\NullOutput);
	}

}
