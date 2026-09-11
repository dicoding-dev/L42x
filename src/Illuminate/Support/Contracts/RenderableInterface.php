<?php namespace Illuminate\Support\Contracts;

use Illuminate\Contracts\Support\Renderable;

interface RenderableInterface extends Renderable {

	/**
	 * Get the evaluated contents of the object.
	 *
	 * @return string
	 */
	public function render();

}
