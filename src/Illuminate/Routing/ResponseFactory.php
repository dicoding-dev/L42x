<?php namespace Illuminate\Routing;

use Closure;

use Illuminate\Support\Str;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Routing\ResponseFactory as FactoryContract;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
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

	// ponytail: throwaway glue satisfying Contracts\Routing\ResponseFactory (v13.32.0).
	// Fork RF is deleted when illuminate/routing v13 swaps in — keep these minimal,
	// inline missing deps (Js/report/StreamedEvent/StreamedResponseException), widen
	// eventStream's 3rd param to untyped (LSP-compatible; StreamedEvent absent).

	public function noContent($status = 204, array $headers = [])
	{
		return $this->make('', $status, $headers);
	}

	public function file($file, array $headers = [])
	{
		return new BinaryFileResponse($file, 200, $headers);
	}

	public function streamJson($data, $status = 200, $headers = [], $encodingOptions = JsonResponse::DEFAULT_ENCODING_OPTIONS)
	{
		return new StreamedJsonResponse($data, $status, $headers, $encodingOptions);
	}

	public function streamDownload($callback, $name = null, array $headers = [], $disposition = 'attachment')
	{
		$response = new StreamedResponse($callback, 200, $headers);

		if ( ! is_null($name))
		{
			$response->headers->set('Content-Disposition', $response->headers->makeDisposition(
				$disposition, $name, str_replace('%', '', Str::ascii($name))
			));
		}

		return $response;
	}

	public function eventStream(Closure $callback, array $headers = [], $endStreamWith = '</stream>')
	{
		return $this->stream(function () use ($callback, $endStreamWith) {
			foreach ($callback() as $message)
			{
				if (connection_aborted()) break;

				if ( ! is_string($message) && ! is_numeric($message))
				{
					$message = json_encode($message);
				}

				echo "event: update\n";
				echo 'data: '.$message;
				echo "\n\n";

				if (ob_get_level() > 0) ob_flush();
				flush();
			}

			if ($endStreamWith !== null && $endStreamWith !== '')
			{
				echo "event: update\n";
				echo 'data: '.$endStreamWith;
				echo "\n\n";

				if (ob_get_level() > 0) ob_flush();
				flush();
			}
		}, 200, array_merge($headers, [
			'Content-Type' => 'text/event-stream',
			'Cache-Control' => 'no-cache',
			'X-Accel-Buffering' => 'no',
		]));
	}

	public function redirectTo($path, $status = 302, $headers = [], $secure = null)
	{
		return $this->container['redirect']->to($path, $status, $headers, $secure);
	}

	public function redirectToRoute($route, $parameters = [], $status = 302, $headers = [])
	{
		return $this->container['redirect']->route($route, $parameters, $status, $headers);
	}

	public function redirectToAction($action, $parameters = [], $status = 302, $headers = [])
	{
		return $this->container['redirect']->action($action, $parameters, $status, $headers);
	}

	public function redirectGuest($path, $status = 302, $headers = [], $secure = null)
	{
		return $this->container['redirect']->guest($path, $status, $headers, $secure);
	}

	public function redirectToIntended($default = '/', $status = 302, $headers = [], $secure = null)
	{
		return $this->container['redirect']->intended($default, $status, $headers, $secure);
	}

}
