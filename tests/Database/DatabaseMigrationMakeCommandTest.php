<?php

use Illuminate\Database\Console\Migrations\MigrateMakeCommand;
use Illuminate\Database\Migrations\MigrationCreator;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class DatabaseMigrationMakeCommandTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testBasicCreateGivesCreatorProperArguments()
    {
        $command = new DatabaseMigrationMakeCommandTestStub(
            $creator = m::mock(MigrationCreator::class), __DIR__ . '/vendor'
        );
        $app = ['path' => __DIR__];
        $command->setLaravel($app);
		$creator->allows()->create()
            ->once()
            ->with('create_foo', __DIR__.'/database/migrations', null, false)
            ->andReturn($app['path']);

		$this->runCommand($command, ['name' => 'create_foo']);
	}


	public function testBasicCreateGivesCreatorProperArgumentsWhenTableIsSet()
	{
		$command = new DatabaseMigrationMakeCommandTestStub($creator = m::mock(
            MigrationCreator::class
        ), __DIR__.'/vendor');
		$app = ['path' => __DIR__];
		$command->setLaravel($app);
		$creator->allows()->create()
            ->once()
            ->with('create_foo', __DIR__.'/database/migrations', 'users', true)
            ->andReturn($app['path']);

		$this->runCommand($command, ['name' => 'create_foo', '--create' => 'users']);
	}


	public function testPackagePathsMayBeUsed()
	{
		$command = new DatabaseMigrationMakeCommandTestStub($creator = m::mock(
            MigrationCreator::class
        ), __DIR__.'/vendor');
		$app = ['path' => __DIR__];
		$command->setLaravel($app);
		$creator->allows()->create()
            ->once()
            ->with('create_foo', __DIR__.'/vendor/bar/src/migrations', null, false)
            ->andReturn($app['path']);

		$this->runCommand($command, ['name' => 'create_foo', '--package' => 'bar']);
	}


	public function testPackageFallsBackToVendorDirWhenNotExplicit()
	{
		$command = new DatabaseMigrationMakeCommandTestStub($creator = m::mock(
            MigrationCreator::class
        ), __DIR__.'/vendor');
		$creator->allows()->create()
            ->once()
            ->with('create_foo', __DIR__.'/vendor/foo/bar/src/migrations', null, false)
            ->andReturn(__DIR__);

		$this->runCommand($command, ['name' => 'create_foo', '--package' => 'foo/bar']);
	}


	protected function runCommand($command, $input = [])
	{
		return $command->run(
            new Symfony\Component\Console\Input\ArrayInput($input),
            new Symfony\Component\Console\Output\NullOutput
        );
	}

}



class DatabaseMigrationMakeCommandTestStub extends MigrateMakeCommand
{
	public function call($command, array $arguments = [])
	{
		//
	}
}
