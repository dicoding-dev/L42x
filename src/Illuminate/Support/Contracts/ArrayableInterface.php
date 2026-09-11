<?php namespace Illuminate\Support\Contracts;

use Illuminate\Contracts\Support\Arrayable;

interface ArrayableInterface extends Arrayable {

	/**
	 * Get the instance as an array.
	 *
	 * @return array
	 */
	public function toArray();

}
