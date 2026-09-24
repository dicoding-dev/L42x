<?php namespace Illuminate\Console;

/**
 * ponytail: BC shim for v13's Illuminate\Console\MigrationGeneratorCommand. The fork
 * console is not swapped yet (task 4.2), but v13 component commands (make:session-table,
 * make:cache-table) extend this base — without it their class declaration fatals and
 * breaks artisan boot. This lets them load; actual migration generation needs the v13
 * console and is out of scope until the console swap. Remove then.
 */
abstract class MigrationGeneratorCommand extends Command {

	/**
	 * Get the migration table name.
	 *
	 * @return string
	 */
	abstract protected function migrationTableName();

	/**
	 * Get the path to the migration stub file.
	 *
	 * @return string
	 */
	abstract protected function migrationStubFile();

	/**
	 * Execute the console command.
	 *
	 * @return int
	 */
	public function handle()
	{
		if (method_exists($this, 'error')) {
			$this->error('make:*-table generators require the v13 console (L13 migration task 4.2).');
		}

		return 1;
	}
}
