<?php namespace Illuminate\Queue\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class FailedTableCommand extends Command {

	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $name = 'queue:failed-table';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Create a migration for the failed queue jobs database table';

	/**
	 * The filesystem instance.
	 *
	 * @var \Illuminate\Filesystem\Filesystem
	 */
	protected $files;

	/**
	 * Create a new session table command instance.
	 *
	 * @param  \Illuminate\Filesystem\Filesystem  $files
	 * @return void
	 */
	public function __construct(Filesystem $files)
	{
		parent::__construct();

		$this->files = $files;
	}

	/**
	 * Execute the console command.
	 *
	 * @return int
     */
	public function fire()
	{
		$fullPath = $this->createBaseMigration();

		$this->files->put($fullPath, $this->files->get(__DIR__.'/stubs/failed_jobs.stub'));

		$this->info('Migration created successfully!');

        return 0;
	}

	/**
	 * Create a base migration file for the table.
	 *
	 * @return string
	 */
	protected function createBaseMigration()
	{
		$name = 'create_failed_jobs_table';

		$path = $this->laravel['path'].'/database/migrations';

		return $this->laravel['migration.creator']->create($name, $path);
	}

}
