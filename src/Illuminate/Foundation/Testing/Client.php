<?php namespace Illuminate\Foundation\Testing;

use Illuminate\Foundation\Application;
use Symfony\Component\HttpKernel\HttpKernelBrowser;
use Symfony\Component\BrowserKit\Request as DomRequest;

class Client extends HttpKernelBrowser {

	/**
	 * The cookies to send with the next request only, on top of the cookie jar's.
	 *
	 * @var array
	 */
	protected $requestCookies = array();

	/**
	 * Send the given cookies with the next request only.
	 *
	 * @param  array  $cookies
	 * @return $this
	 */
	public function withRequestCookies(array $cookies)
	{
		$this->requestCookies = $cookies;

		return $this;
	}

	/**
	 * Convert a BrowserKit request into a Illuminate request.
	 *
	 * @param  \Symfony\Component\BrowserKit\Request  $request
	 */
	#[\Override]
    protected function filterRequest(DomRequest $request): \Symfony\Component\HttpFoundation\Request
    {
		$parameters = $this->getRequestParameters($request);
		$parameters[3] = array_replace($parameters[3], $this->requestCookies);
		$this->requestCookies = array();

		$httpRequest = Application::onRequest('create', $parameters);

		$httpRequest->files->replace($this->filterFiles($httpRequest->files->all()));

		return $httpRequest;
	}

	/**
	 * v13 Request::convertUploadedFiles() re-wraps each file via UploadedFile::createFromBase()
	 * with test=false, so a plain Symfony upload fails isValid()/mimes under tests. Handing back
	 * Illuminate\Http\UploadedFile instances lets that instanceof check preserve the test flag.
	 */
	#[\Override]
	protected function filterFiles(array $files): array
	{
		return $this->toTestUploadedFiles(parent::filterFiles($files));
	}

	private function toTestUploadedFiles(array $files): array
	{
		foreach ($files as $key => $file) {
			if (is_array($file)) {
				$files[$key] = $this->toTestUploadedFiles($file);
			} elseif ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
				$files[$key] = \Illuminate\Http\UploadedFile::createFromBase($file, true);
			}
		}

		return $files;
	}

	/**
	 * Get the request parameters from a BrowserKit request.
	 *
	 * @param  \Symfony\Component\BrowserKit\Request  $request
	 * @return array
	 */
	protected function getRequestParameters(DomRequest $request)
	{
		return array(
			$request->getUri(), $request->getMethod(), $request->getParameters(), $request->getCookies(),
			$request->getFiles(), $request->getServer(), $request->getContent()
		);
	}

}
