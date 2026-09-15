<?php namespace Illuminate\Routing;

use Illuminate\Support\Str;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Routing\ResponseFactory as FactoryContract;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ResponseFactory implements FactoryContract {

	use Macroable;

	/**
	 * The container / application instance.
	 *
	 * ponytail: hold the container and resolve 'view' lazily (like the old
	 * static facade) so make()/json() work when 'view' isn't bound. Swap to
	 * constructor-injected Contracts\View\Factory once View implements it.
	 *
	 * @var \ArrayAccess
	 */
	protected $container;

	/**
	 * Create a new response factory instance.
	 *
	 * @param  \ArrayAccess  $container
	 * @return void
	 */
	public function __construct($container)
	{
		$this->container = $container;
	}

	public function make($content = '', $status = 200, array $headers = [])
	{
		return new Response($content, $status, $headers);
	}

	public function view($view, $data = [], $status = 200, array $headers = [])
	{
		return $this->make($this->container['view']->make($view, $data), $status, $headers);
	}

	public function json($data = [], $status = 200, array $headers = [], $options = 0)
	{
		if ($data instanceof Arrayable)
		{
			$data = $data->toArray();
		}

		return new JsonResponse($data, $status, $headers, $options);
	}

	public function jsonp($callback, $data = [], $status = 200, array $headers = [], $options = 0)
	{
		return $this->json($data, $status, $headers, $options)->setCallback($callback);
	}

	public function stream($callback, $status = 200, array $headers = [])
	{
		return new StreamedResponse($callback, $status, $headers);
	}

	public function download($file, $name = null, array $headers = [], $disposition = 'attachment')
	{
		$response = new BinaryFileResponse($file, 200, $headers, true, $disposition);

		if ( ! is_null($name))
		{
			return $response->setContentDisposition($disposition, $name, str_replace('%', '', Str::ascii($name)));
		}

		return $response;
	}

}
