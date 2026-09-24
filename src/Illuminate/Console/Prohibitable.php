<?php namespace Illuminate\Console;

/**
 * ponytail: BC shim for v13's Illuminate\Console\Prohibitable trait. The fork console
 * is not swapped yet (task 4.2), but v13 component commands (migrate:fresh/refresh/reset)
 * `use` this trait — without it their class declaration fatals and breaks artisan boot.
 * Minimal but functional: gates command execution when prohibited. Remove at the console swap.
 */
trait Prohibitable
{
	/**
	 * @var bool
	 */
	protected static $prohibitedFromRunning = false;

	/**
	 * Indicate whether the command should be prohibited from running.
	 *
	 * @param  bool  $prohibit
	 * @return void
	 */
	public static function prohibit($prohibit = true)
	{
		static::$prohibitedFromRunning = $prohibit;
	}

	/**
	 * Determine if the command is prohibited from running and display a warning if so.
	 *
	 * @param  bool  $prohibit
	 * @return bool
	 */
	protected function isProhibited($prohibit = false)
	{
		if (! static::$prohibitedFromRunning && ! $prohibit) {
			return false;
		}

		if (method_exists($this, 'components')) {
			$this->components->error('This command is prohibited from running in this environment.');
		}

		return true;
	}
}
