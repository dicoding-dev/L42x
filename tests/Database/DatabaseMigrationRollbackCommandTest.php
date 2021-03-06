<?php

use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Migrations\Migrator;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class DatabaseMigrationRollbackCommandTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testRollbackCommandCallsMigratorWithProperArguments()
    {
        $command = new RollbackCommand($migrator = m::mock(Migrator::class));
        $command->setLaravel(new AppDatabaseMigrationRollbackStub());
        $migrator->shouldReceive('setConnection')->once()->with(null);
		$migrator->shouldReceive('rollback')->once()->with(false);
		$migrator->shouldReceive('getNotes')->andReturn([]);

		$this->runCommand($command);
	}


	public function testRollbackCommandCanBePretended()
	{
		$command = new RollbackCommand($migrator = m::mock(Migrator::class));
		$command->setLaravel(new AppDatabaseMigrationRollbackStub());
		$migrator->shouldReceive('setConnection')->once()->with('foo');
		$migrator->shouldReceive('rollback')->once()->with(true);
		$migrator->shouldReceive('getNotes')->andReturn([]);

		$this->runCommand($command, ['--pretend' => true, '--database' => 'foo']);
	}


	protected function runCommand($command, $input = [])
	{
		return $command->run(new Symfony\Component\Console\Input\ArrayInput($input), new Symfony\Component\Console\Output\NullOutput);
	}

}

class AppDatabaseMigrationRollbackStub {
	public $env = 'development';
	public function environment() { return $this->env; }
}
