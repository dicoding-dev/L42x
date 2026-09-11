<?php namespace Illuminate\Support\Facades;

use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;

/**
 * @method static \Illuminate\Http\Response make($content = '', $status = 200, array $headers = [])
 * @method static \Illuminate\Http\Response view($view, $data = [], $status = 200, array $headers = [])
 * @method static \Illuminate\Http\JsonResponse json($data = [], $status = 200, array $headers = [], $options = 0)
 * @method static \Illuminate\Http\JsonResponse jsonp($callback, $data = [], $status = 200, array $headers = [], $options = 0)
 * @method static \Symfony\Component\HttpFoundation\StreamedResponse stream($callback, $status = 200, array $headers = [])
 * @method static \Symfony\Component\HttpFoundation\BinaryFileResponse download($file, $name = null, array $headers = [], $disposition = 'attachment')
 *
 * @see \Illuminate\Routing\ResponseFactory
 */
class Response extends Facade {

	/**
	 * Get the registered name of the component.
	 *
	 * @return string
	 */
	protected static function getFacadeAccessor()
	{
		return ResponseFactoryContract::class;
	}

}
