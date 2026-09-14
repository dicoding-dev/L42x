<?php namespace Illuminate\Support\Contracts;

use Illuminate\Contracts\Support\Jsonable;

interface JsonableInterface extends Jsonable {

	/**
	 * Convert the object to its JSON representation.
	 *
	 * @param  int  $options
	 * @return string
	 */
	public function toJson($options = 0);

}
