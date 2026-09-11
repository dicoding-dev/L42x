<?php namespace Illuminate\Support\Contracts;

use Illuminate\Contracts\Support\MessageProvider;

interface MessageProviderInterface extends MessageProvider {

	/**
	 * Get the messages for the instance.
	 *
	 * @return \Illuminate\Support\MessageBag
	 */
	public function getMessageBag();

}
