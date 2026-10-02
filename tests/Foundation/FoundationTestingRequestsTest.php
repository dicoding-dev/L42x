<?php

use Illuminate\Foundation\Testing\ApplicationTrait;
use Illuminate\Foundation\Testing\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class FoundationTestingRequestsTest extends TestCase
{
	use ApplicationTrait;

	protected function setUp(): void
	{
		$this->client = new FoundationTestingRequestsClient;
	}

	#[Test]
	public function callTakesCookiesBeforeFilesServerAndContent()
	{
		$file = $this->uploadedFile();

		$this->call('POST', '/save', array('a' => 1), array('c' => 'v'), array('f' => $file), array('HTTP_X' => 'y'), 'body');

		$this->assertSame(array('c' => 'v'), $this->client->cookies[0]);
		$this->assertSame(array('POST', '/save', array('a' => 1), array('f' => $file), array('HTTP_X' => 'y'), 'body'), $this->client->requests[0]);
	}

	#[Test]
	public function callMovesUploadedFilesOutOfTheParameters()
	{
		$avatar = $this->uploadedFile();
		$document = $this->uploadedFile();

		$this->call('POST', '/save', array('name' => 'n', 'avatar' => $avatar, 'docs' => array('main' => $document, 'note' => 'x')));

		list(, , $parameters, $files) = $this->client->requests[0];
		$this->assertSame(array('name' => 'n', 'docs' => array('note' => 'x')), $parameters);
		$this->assertSame(array('avatar' => $avatar, 'docs' => array('main' => $document)), $files);
	}

	#[Test]
	public function getSendsItsSecondArgumentAsHeaders()
	{
		$this->get('/search', array('X-Requested-With' => 'XMLHttpRequest', 'Content-Type' => 'application/json', 'HTTP_ACCEPT' => 'text/html'));

		$this->assertSame(array('GET', '/search', array(), array(), array(
			'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
			'CONTENT_TYPE' => 'application/json',
			'HTTP_ACCEPT' => 'text/html',
		), null), $this->client->requests[0]);
		$this->assertSame(array(), $this->client->cookies[0]);
	}

	#[Test]
	public function dataVerbsSendDataAsParametersAndHeadersAsServerVariables()
	{
		foreach (array('post', 'put', 'patch', 'delete') as $verb)
		{
			$this->{$verb}('/things', array('a' => 1), array('Accept' => 'text/html'));
		}

		foreach (array('POST', 'PUT', 'PATCH', 'DELETE') as $index => $method)
		{
			$this->assertSame(
				array($method, '/things', array('a' => 1), array(), array('HTTP_ACCEPT' => 'text/html'), null),
				$this->client->requests[$index]
			);
		}
	}

	#[Test]
	public function clientSendsRequestCookiesWithTheNextRequestOnly()
	{
		$client = new Client(new FoundationTestingRequestsCookieEchoKernel);

		$client->withRequestCookies(array('a' => '1'))->request('GET', '/');
		$this->assertSame('{"a":"1"}', $client->getResponse()->getContent());

		$client->request('GET', '/');
		$this->assertSame('[]', $client->getResponse()->getContent());
	}

	protected function uploadedFile()
	{
		return new UploadedFile(__FILE__, 'test.php', null, null, true);
	}
}

class FoundationTestingRequestsClient
{
	public $requests = array();

	public $cookies = array();

	public function withRequestCookies(array $cookies)
	{
		$this->cookies[] = $cookies;

		return $this;
	}

	public function request($method, $uri, $parameters = array(), $files = array(), $server = array(), $content = null)
	{
		$this->requests[] = func_get_args();
	}

	public function getResponse()
	{
		return new Response;
	}

	public function getRequest()
	{
		return Request::create('/');
	}
}

class FoundationTestingRequestsCookieEchoKernel implements HttpKernelInterface
{
	public function handle(SymfonyRequest $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
	{
		return new Response(json_encode($request->cookies->all()));
	}
}
