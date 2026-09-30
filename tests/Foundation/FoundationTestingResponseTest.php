<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\ApplicationTrait;
use Illuminate\Foundation\Testing\AssertionsTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FoundationTestingResponseTest extends TestCase
{
	use ApplicationTrait, AssertionsTrait;

	protected function setUp(): void
	{
		$this->app = new Application;
		$this->app->instance('url', new UrlGenerator(new RouteCollection, Request::create('http://localhost')));
		$this->app->instance('session.store', new Store('test', new ArraySessionHandler(10)));
		Facade::setFacadeApplication($this->app);

		$this->client = new FoundationTestingResponseClient;
	}

	protected function tearDown(): void
	{
		Facade::clearResolvedInstances();
		Facade::setFacadeApplication(null);
	}

	public function testCallReturnsATestResponseAroundTheClientResponse()
	{
		$this->client->response = new Response('hello', 200, array('X-Foo' => 'bar'));

		$response = $this->call('GET', '/hello', array('q' => 'x'));

		$this->assertInstanceOf(TestResponse::class, $response);
		$this->assertSame($this->client->response, $response->baseResponse);
		$this->assertSame(array('GET', '/hello', array('q' => 'x'), array(), array(), null, true), $this->client->requests[0]);
		$this->assertSame('hello', $response->getContent());
		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('bar', $response->headers->get('X-Foo'));
		$response->assertOk()->assertSee('hello')->assertHeader('X-Foo', 'bar');
	}

	public function testLegacyAssertionsStillReadTheClientResponse()
	{
		$this->client->response = new Response('created', 201);

		$this->call('POST', '/things');

		$this->assertResponseStatus(201);
	}

	public function testFailedFluentAssertionIsAPhpunitFailure()
	{
		$this->client->response = new Response('missing', 404);

		$this->expectException(ExpectationFailedException::class);

		$this->call('GET', '/missing')->assertOk();
	}

	public function testJsonAssertions()
	{
		$this->client->response = new JsonResponse(array('data' => array('id' => 1, 'tags' => array('a', 'b'))));

		$response = $this->call('GET', '/things/1');

		$response->assertOk()
			->assertJsonPath('data.id', 1)
			->assertJson(array('data' => array('id' => 1)))
			->assertJsonStructure(array('data' => array('id', 'tags')))
			->assertJsonCount(2, 'data.tags')
			->assertExactJson(array('data' => array('id' => 1, 'tags' => array('a', 'b'))));
		$this->assertSame(1, $response['data']['id']);
	}

	public function testRedirectAndSessionAssertions()
	{
		$this->client->response = new RedirectResponse('http://localhost/login');
		$this->app['session.store']->put('status', 'saved');

		$this->call('POST', '/save')
			->assertRedirect('/login')
			->assertSessionHas('status', 'saved');
	}

	public function testStreamedResponseContent()
	{
		$this->client->response = new StreamedResponse(function ()
		{
			echo 'chunk';
		});

		$response = $this->call('GET', '/download');

		$this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);
		$this->assertSame('chunk', $response->streamedContent());
	}
}

class FoundationTestingResponseClient
{
	public $response;

	public $request;

	public $requests = array();

	public function request($method, $uri, $parameters = array(), $files = array(), $server = array(), $content = null, $changeHistory = true)
	{
		$this->requests[] = func_get_args();
		$this->request = Request::create($uri, $method, $parameters);
	}

	public function getResponse()
	{
		return $this->response;
	}

	public function getRequest()
	{
		return $this->request;
	}
}
