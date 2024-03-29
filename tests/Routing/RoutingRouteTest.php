<?php

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use L4\Tests\BackwardCompatibleTestCase;

class RoutingRouteTest extends BackwardCompatibleTestCase
{

    public function testBasicDispatchingOfRoutes(): void
    {
        $router = $this->getRouter();
        $router->get(
            'foo/bar',
            fn() => 'hello'
        );
        $this->assertEquals('hello', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());

        $router = $this->getRouter();
        $route = $router->get(
            'foo/bar',
            [
                'domain' => 'api.{name}.bar',
                fn($name) => $name
            ]
        );
        $route = $router->get(
            'foo/bar',
            [
                'domain' => 'api.{name}.baz',
                fn($name) => $name
            ]
        );
		$this->assertEquals('taylor', $router->dispatch(Request::create('http://api.taylor.bar/foo/bar', 'GET'))->getContent());
		$this->assertEquals('dayle', $router->dispatch(Request::create('http://api.dayle.baz/foo/bar', 'GET'))->getContent());

		$router = $this->getRouter();
		$route = $router->get('foo/{age}', ['domain' => 'api.{name}.bar', fn($name, $age) => $name.$age]);
		$this->assertEquals('taylor25', $router->dispatch(Request::create('http://api.taylor.bar/foo/25', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('foo/bar', fn() => 'hello');
		$router->post('foo/bar', fn() => 'post hello');
		$this->assertEquals('hello', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());
		$this->assertEquals('post hello', $router->dispatch(Request::create('foo/bar', 'POST'))->getContent());

		$router = $this->getRouter();
		$router->get('foo/{bar}', fn($name) => $name);
		$this->assertEquals('taylor', $router->dispatch(Request::create('foo/taylor', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('foo/{bar}/{baz?}', fn($name, $age = 25) => $name.$age);
		$this->assertEquals('taylor25', $router->dispatch(Request::create('foo/taylor', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('foo/{name}/boom/{age?}/{location?}', fn($name, $age = 25, $location = 'AR') => $name.$age.$location);
		$this->assertEquals('taylor30AR', $router->dispatch(Request::create('foo/taylor/boom/30', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('{bar}/{baz?}', fn($name, $age = 25) => $name.$age);
		$this->assertEquals('taylor25', $router->dispatch(Request::create('taylor', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('{baz?}', fn($age = 25) => $age);
		$this->assertEquals('25', $router->dispatch(Request::create('/', 'GET'))->getContent());
		$this->assertEquals('30', $router->dispatch(Request::create('30', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('{foo?}/{baz?}', ['as' => 'foo', fn($name = 'taylor', $age = 25) => $name.$age]);
		$this->assertEquals('taylor25', $router->dispatch(Request::create('/', 'GET'))->getContent());
		$this->assertEquals('fred25', $router->dispatch(Request::create('fred', 'GET'))->getContent());
		$this->assertEquals('fred30', $router->dispatch(Request::create('fred/30', 'GET'))->getContent());
		$this->assertTrue($router->currentRouteNamed('foo'));
		$this->assertTrue($router->is('foo'));
		$this->assertFalse($router->is('bar'));

		$router = $this->getRouter();
		$router->get('foo/bar', fn() => 'hello');
		$this->assertEquals('', $router->dispatch(Request::create('foo/bar', 'HEAD'))->getContent());

		$router = $this->getRouter();
		$router->any('foo/bar', fn() => 'hello');
		$this->assertEquals('', $router->dispatch(Request::create('foo/bar', 'HEAD'))->getContent());

		$router = $this->getRouter();
		$router->get('foo/bar', fn() => 'first');
		$router->get('foo/bar', fn() => 'second');
		$this->assertEquals('second', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('foo/bar/åαф', function() { return 'hello'; });
		$this->assertEquals('hello', $router->dispatch(Request::create('foo/bar/%C3%A5%CE%B1%D1%84', 'GET'))->getContent());
	}


	public function testOptionsResponsesAreGeneratedByDefault(): void
    {
		$router = $this->getRouter();
		$router->get('foo/bar', fn() => 'hello');
		$router->post('foo/bar', fn() => 'hello');
		$response = $router->dispatch(Request::create('foo/bar', 'OPTIONS'));

		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('GET,HEAD,POST', $response->headers->get('Allow'));
	}


	public function testHeadDispatcher(): void
    {
		$router = $this->getRouter();
		$router->match(['GET', 'POST'], 'foo', fn() => 'bar');

		$response = $router->dispatch(Request::create('foo', 'OPTIONS'));
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('GET,HEAD,POST', $response->headers->get('Allow'));

		$response = $router->dispatch(Request::create('foo', 'HEAD'));
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('', $response->getContent());

		$router = $this->getRouter();
		$router->match(['GET'], 'foo', fn() => 'bar');

		$response = $router->dispatch(Request::create('foo', 'OPTIONS'));
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('GET,HEAD', $response->headers->get('Allow'));

		$router = $this->getRouter();
		$router->match(['POST'], 'foo', fn() => 'bar');

		$response = $router->dispatch(Request::create('foo', 'OPTIONS'));
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('POST', $response->headers->get('Allow'));
	}


	public function testNonGreedyMatches(): void
    {
		$route = new Route('GET', 'images/{id}.{ext}', function() {});

		$request1 = Request::create('images/1.png', 'GET');
		$this->assertTrue($route->matches($request1));
		$route->bind($request1);
		$this->assertEquals('1', $route->parameter('id'));
		$this->assertEquals('png', $route->parameter('ext'));

		$request2 = Request::create('images/12.png', 'GET');
		$this->assertTrue($route->matches($request2));
		$route->bind($request2);
		$this->assertEquals('12', $route->parameter('id'));
		$this->assertEquals('png', $route->parameter('ext'));

		// Test parameter() default value
		$route = new Route('GET', 'foo/{foo?}', function() {});

		$request3 = Request::create('foo', 'GET');
		$this->assertTrue($route->matches($request3));
		$route->bind($request3);
		$this->assertEquals('bar', $route->parameter('foo', 'bar'));
	}


    public function testRoutesDontMatchNonMatchingPathsWithLeadingOptionals(): void
    {
        $this->expectException(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $router = $this->getRouter();
        $router->get(
            '{baz?}',
            fn($age = 25) => $age
        );
        $this->assertEquals('25', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());
    }


    public function testRoutesDontMatchNonMatchingDomain(): void
    {
        $this->expectException(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $router = $this->getRouter();
        $route = $router->get(
            'foo/bar',
            [
                'domain' => 'api.foo.bar',
                fn() => 'hello'
            ]
        );
        $this->assertEquals(
            'hello',
            $router->dispatch(Request::create('http://api.baz.boom/foo/bar', 'GET'))->getContent()
        );
    }


	public function testDispatchingOfControllers(): void
    {
		$router = $this->getRouter();
		$router->get('foo', 'RouteTestControllerDispatchStub@foo');
		$this->assertEquals('bar', $router->dispatch(Request::create('foo', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->filter('foo', fn() => 'filter');
		$router->get('bar', 'RouteTestControllerDispatchStub@bar');
		$this->assertEquals('filter', $router->dispatch(Request::create('bar', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('baz', 'RouteTestControllerDispatchStub@baz');
		$this->assertEquals('filtered', $router->dispatch(Request::create('baz', 'GET'))->getContent());


		unset($_SERVER['__test.after.filter']);
		$router = $this->getRouter();
		$router->filter('qux', function()
		{
			$_SERVER['__test.after.filter'] = true;
		});
		$router->get('qux', 'RouteTestControllerDispatchStub@qux');
		$this->assertEquals('qux', $router->dispatch(Request::create('qux', 'GET'))->getContent());
		$this->assertTrue($_SERVER['__test.after.filter']);

		/**
		 * Test filter removal.
		 */
		$router = $this->getRouter();
		$router->filter('removeBefore', function() {
			$_SERVER['__test.before.removeBeforeFilter'] = true;
		});
		$router->get('beforeRoute', 'RouteTestControllerRemoveFilterStub@beforeRoute');
		$this->assertEquals('beforeRoute', $router->dispatch(Request::create('beforeRoute', 'GET'))->getContent());
		$this->assertTrue(!isset($_SERVER['__test.after.removeBeforeFilter']) || is_null(isset($_SERVER['__test.after.removeBeforeFilter'])));

		$router = $this->getRouter();
		$router->filter('removeAfter', function() {
			$_SERVER['__test.after.removeAfterFilter'] = true;
		});
		$router->get('afterRoute', 'RouteTestControllerRemoveFilterStub@afterRoute');
		$this->assertEquals('afterRoute', $router->dispatch(Request::create('afterRoute', 'GET'))->getContent());
		$this->assertTrue(!isset($_SERVER['__test.after.removeAfterFilter']) || is_null(isset($_SERVER['__test.after.removeAfterFilter'])));

		/**
		 * Test filters disabled...
		 */
		$router = $this->getRouter();
		$router->filter('foo', fn() => 'filter');
		$router->disableFilters();
		$router->get('bar', 'RouteTestControllerDispatchStub@bar');
		$this->assertEquals('baz', $router->dispatch(Request::create('bar', 'GET'))->getContent());

		$this->assertTrue($router->currentRouteUses('RouteTestControllerDispatchStub@bar'));
		$this->assertTrue($router->uses('RouteTestControllerDispatchStub@bar'));
		$this->assertFalse($router->uses('RouteTestControllerDispatchStub@baz'));
	}


	public function testBasicBeforeFilters(): void
    {
		$router = $this->getRouter();
		$router->get('foo/bar', fn() => 'hello');
		$router->before(fn() => 'foo!');
		$this->assertEquals('foo!', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('foo/bar', fn() => 'hello');
		$router->before('RouteTestFilterStub');
		$this->assertEquals('foo!', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('foo/bar', fn() => 'hello');
		$router->before('RouteTestFilterStub@handle');
		$this->assertEquals('handling!', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('foo/bar', ['before' => 'foo', fn() => 'hello']);
		$router->filter('foo', fn() => 'foo!');
		$this->assertEquals('foo!', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('foo/bar', ['before' => 'foo:25', fn() => 'hello']);
		$router->filter('foo', fn($route, $request, $age) => $age);
		$this->assertEquals('25', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('foo/bar', ['before' => 'foo:0,taylor', fn() => 'hello']);
		$router->filter('foo', fn($route, $request, $age, $name) => $age.$name);
		$this->assertEquals('0taylor', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('foo/bar', ['before' => 'foo:bar,baz', fn() => 'hello']);
		$router->filter('foo', fn($route, $request, $bar, $baz) => $bar.$baz);
		$this->assertEquals('barbaz', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('foo/bar', ['before' => 'foo:bar,baz|bar:boom', fn() => 'hello']);
		$router->filter('foo', fn($route, $request, $bar, $baz) => null);
		$router->filter('bar', fn($route, $request, $boom) => $boom);
		$this->assertEquals('boom', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());

		/**
		 * Basic filter parameter
		 */
		unset($_SERVER['__route.filter']);
		$router = $this->getRouter();
		$router->get('foo/bar', ['before' => 'foo:bar', fn() => 'hello']);
		$router->filter('foo', function($route, $request, $value = null) { $_SERVER['__route.filter'] = $value; });
		$router->dispatch(Request::create('foo/bar', 'GET'));
		$this->assertEquals('bar', $_SERVER['__route.filter']);

		/**
		 * Optional filter parameter
		 */
		unset($_SERVER['__route.filter']);
		$router = $this->getRouter();
		$router->get('foo/bar', ['before' => 'foo', fn() => 'hello']);
		$router->filter('foo', function($route, $request, $value = null) { $_SERVER['__route.filter'] = $value; });
		$router->dispatch(Request::create('foo/bar', 'GET'));
		$this->assertNull($_SERVER['__route.filter']);
	}


	public function testFiltersCanBeDisabled(): void
    {
		$router = $this->getRouter();
		$router->disableFilters();
		$router->get('foo/bar', fn() => 'hello');
		$router->before(fn() => 'foo!');
		$this->assertEquals('hello', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->disableFilters();
		$router->get('foo/bar', ['before' => 'foo', fn() => 'hello']);
		$router->filter('foo', fn() => 'foo!');
		$this->assertEquals('hello', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());
	}


	public function testGlobalAfterFilters(): void
    {
		unset($_SERVER['__filter.after']);
		$router = $this->getRouter();
		$router->get('foo/bar', fn() => 'hello');
		$router->after(function() { $_SERVER['__filter.after'] = true; return 'foo!'; });

		$this->assertEquals('hello', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());
		$this->assertTrue($_SERVER['__filter.after']);
	}


	public function testBasicAfterFilters(): void
    {
		unset($_SERVER['__filter.after']);
		$router = $this->getRouter();
		$router->get('foo/bar', ['after' => 'foo', fn() => 'hello']);
		$router->filter('foo', function() { $_SERVER['__filter.after'] = true; return 'foo!'; });

		$this->assertEquals('hello', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());
		$this->assertTrue($_SERVER['__filter.after']);
	}


	public function testPatternBasedFilters(): void
    {
		$router = $this->getRouter();
		$router->get('foo/bar', fn() => 'hello');
		$router->filter('foo', fn($route, $request, $bar) => 'foo'.$bar);
		$router->when('foo/*', 'foo:bar');
		$this->assertEquals('foobar', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('foo/bar', fn() => 'hello');
		$router->filter('foo', fn($route, $request, $bar) => 'foo'.$bar);
		$router->when('bar/*', 'foo:bar');
		$this->assertEquals('hello', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('foo/bar', fn() => 'hello');
		$router->filter('foo', fn($route, $request, $bar) => 'foo'.$bar);
		$router->when('foo/*', 'foo:bar', ['post']);
		$this->assertEquals('hello', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('foo/bar', fn() => 'hello');
		$router->filter('foo', fn($route, $request, $bar) => 'foo'.$bar);
		$router->when('foo/*', 'foo:bar', ['get']);
		$this->assertEquals('foobar', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());

		$router = $this->getRouter();
		$router->get('foo/bar', fn() => 'hello');
		$router->filter('foo', function($route, $request) {});
		$router->filter('bar', fn($route, $request) => 'bar');
		$router->when('foo/*', 'foo|bar', ['get']);
		$this->assertEquals('bar', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());
	}


	public function testRegexBasedFilters(): void
    {
		$router = $this->getRouter();
		$router->get('foo/bar', fn() => 'hello');
		$router->get('bar/foo', fn() => 'hello');
		$router->get('baz/foo', fn() => 'hello');
		$router->filter('foo', fn($route, $request, $bar) => 'foo'.$bar);
		$router->whenRegex('/^(foo|bar).*/', 'foo:bar');
		$this->assertEquals('foobar', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());
		$this->assertEquals('foobar', $router->dispatch(Request::create('bar/foo', 'GET'))->getContent());
		$this->assertEquals('hello', $router->dispatch(Request::create('baz/foo', 'GET'))->getContent());
	}


	public function testRegexBasedFiltersWithVariables(): void
    {
		$router = $this->getRouter();
		$router->get('{var}/bar', fn($var) => 'hello');
		$router->filter('foo', fn($route, $request, $bar) => 'foo'.$bar);
		$router->whenRegex('/^(foo|bar).*/', 'foo:bar');
		$this->assertEquals('foobar', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());
		$this->assertEquals('foobar', $router->dispatch(Request::create('bar/bar', 'GET'))->getContent());
		$this->assertEquals('hello', $router->dispatch(Request::create('baz/bar', 'GET'))->getContent());
	}


	public function testGroupFiltersAndRouteFilters(): void
    {
		$router = $this->getRouter();
		$router->group(['before' => ['foo']], function() use ($router)
		{
			$router->get('foo/bar', fn() => 'hello')->before('bar');
		});
		$router->filter('foo', fn($route, $request) => 'foo');
		$router->filter('bar', fn($route, $request) => 'bar');
		$this->assertEquals('foo', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());
	}


	public function testMatchesMethodAgainstRequests(): void
    {
		/**
		 * Basic
		 */
		$request = Request::create('foo/bar', 'GET');
		$route = new Route('GET', 'foo/{bar}', function() {});
		$this->assertTrue($route->matches($request));

		$request = Request::create('foo/bar', 'GET');
		$route = new Route('GET', 'foo', function() {});
		$this->assertFalse($route->matches($request));

		/**
		 * Method checks
		 */
		$request = Request::create('foo/bar', 'GET');
		$route = new Route('GET', 'foo/{bar}', function() {});
		$this->assertTrue($route->matches($request));

		$request = Request::create('foo/bar', 'POST');
		$route = new Route('GET', 'foo', function() {});
		$this->assertFalse($route->matches($request));

		/**
		 * Domain checks
		 */
		$request = Request::create('http://something.foo.com/foo/bar', 'GET');
		$route = new Route('GET', 'foo/{bar}', ['domain' => '{foo}.foo.com', function() {}]);
		$this->assertTrue($route->matches($request));

		$request = Request::create('http://something.bar.com/foo/bar', 'GET');
		$route = new Route('GET', 'foo/{bar}', ['domain' => '{foo}.foo.com', function() {}]);
		$this->assertFalse($route->matches($request));

		/**
		 * HTTPS checks
		 */
		$request = Request::create('https://foo.com/foo/bar', 'GET');
		$route = new Route('GET', 'foo/{bar}', ['https', function() {}]);
		$this->assertTrue($route->matches($request));

		$request = Request::create('https://foo.com/foo/bar', 'GET');
		$route = new Route('GET', 'foo/{bar}', ['https', 'baz' => true, function() {}]);
		$this->assertTrue($route->matches($request));

		$request = Request::create('http://foo.com/foo/bar', 'GET');
		$route = new Route('GET', 'foo/{bar}', ['https', function() {}]);
		$this->assertFalse($route->matches($request));

		/**
		 * HTTP checks
		 */
		$request = Request::create('https://foo.com/foo/bar', 'GET');
		$route = new Route('GET', 'foo/{bar}', ['http', function() {}]);
		$this->assertFalse($route->matches($request));

		$request = Request::create('http://foo.com/foo/bar', 'GET');
		$route = new Route('GET', 'foo/{bar}', ['http', function() {}]);
		$this->assertTrue($route->matches($request));

		$request = Request::create('http://foo.com/foo/bar', 'GET');
		$route = new Route('GET', 'foo/{bar}', ['baz' => true, function() {}]);
		$this->assertTrue($route->matches($request));
	}


	public function testWherePatternsProperlyFilter(): void
    {
		$request = Request::create('foo/123', 'GET');
		$route = new Route('GET', 'foo/{bar}', function() {});
		$route->where('bar', '[0-9]+');
		$this->assertTrue($route->matches($request));

		$request = Request::create('foo/123abc', 'GET');
		$route = new Route('GET', 'foo/{bar}', function() {});
		$route->where('bar', '[0-9]+');
		$this->assertFalse($route->matches($request));

		$request = Request::create('foo/123abc', 'GET');
		$route = new Route('GET', 'foo/{bar}', ['where' => ['bar' => '[0-9]+'], function() {}]);
		$route->where('bar', '[0-9]+');
		$this->assertFalse($route->matches($request));

		/**
		 * Optional
		 */
		$request = Request::create('foo/123', 'GET');
		$route = new Route('GET', 'foo/{bar?}', function() {});
		$route->where('bar', '[0-9]+');
		$this->assertTrue($route->matches($request));

		$request = Request::create('foo/123', 'GET');
		$route = new Route('GET', 'foo/{bar?}', ['where' => ['bar' => '[0-9]+'], function() {}]);
		$route->where('bar', '[0-9]+');
		$this->assertTrue($route->matches($request));

		$request = Request::create('foo/123', 'GET');
		$route = new Route('GET', 'foo/{bar?}/{baz?}', function() {});
		$route->where('bar', '[0-9]+');
		$this->assertTrue($route->matches($request));

		$request = Request::create('foo/123/foo', 'GET');
		$route = new Route('GET', 'foo/{bar?}/{baz?}', function() {});
		$route->where('bar', '[0-9]+');
		$this->assertTrue($route->matches($request));

		$request = Request::create('foo/123abc', 'GET');
		$route = new Route('GET', 'foo/{bar?}', function() {});
		$route->where('bar', '[0-9]+');
		$this->assertFalse($route->matches($request));
	}


	public function testDotDoesNotMatchEverything(): void
    {
		$route = new Route('GET', 'images/{id}.{ext}', function() {});

		$request1 = Request::create('images/1.png', 'GET');
		$this->assertTrue($route->matches($request1));
		$route->bind($request1);
		$this->assertEquals('1', $route->parameter('id'));
		$this->assertEquals('png', $route->parameter('ext'));

		$request2 = Request::create('images/12.png', 'GET');
		$this->assertTrue($route->matches($request2));
		$route->bind($request2);
		$this->assertEquals('12', $route->parameter('id'));
		$this->assertEquals('png', $route->parameter('ext'));

	}


	public function testRouteBinding(): void
    {
		$router = $this->getRouter();
		$router->get('foo/{bar}', fn($name) => $name);
		$router->bind('bar', fn($value) => strtoupper($value));
		$this->assertEquals('TAYLOR', $router->dispatch(Request::create('foo/taylor', 'GET'))->getContent());
	}


	public function testRouteClassBinding(): void
    {
		$router = $this->getRouter();
		$router->get('foo/{bar}', fn($name) => $name);
		$router->bind('bar', 'RouteBindingStub');
		$this->assertEquals('TAYLOR', $router->dispatch(Request::create('foo/taylor', 'GET'))->getContent());
	}


	public function testRouteClassMethodBinding(): void
    {
		$router = $this->getRouter();
		$router->get('foo/{bar}', fn($name) => $name);
		$router->bind('bar', 'RouteBindingStub@find');
		$this->assertEquals('dragon', $router->dispatch(Request::create('foo/Dragon', 'GET'))->getContent());
	}


	public function testModelBinding(): void
    {
		$router = $this->getRouter();
		$router->get('foo/{bar}', fn($name) => $name);
		$router->model('bar', 'RouteModelBindingStub');
		$this->assertEquals('TAYLOR', $router->dispatch(Request::create('foo/taylor', 'GET'))->getContent());
	}


    public function testModelBindingWithNullReturn(): void
    {
        $this->expectException(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $router = $this->getRouter();
        $router->get(
            'foo/{bar}',
            fn($name) => $name
        );
        $router->model('bar', 'RouteModelBindingNullStub');
        $router->dispatch(Request::create('foo/taylor', 'GET'))->getContent();
    }


	public function testModelBindingWithCustomNullReturn(): void
    {
		$router = $this->getRouter();
		$router->get('foo/{bar}', fn($name) => $name);
		$router->model('bar', 'RouteModelBindingNullStub', fn() => 'missing');
		$this->assertEquals('missing', $router->dispatch(Request::create('foo/taylor', 'GET'))->getContent());
	}


	public function testGroupMerging(): void
    {
		$old = ['prefix' => 'foo/bar/'];
		$this->assertEquals(
            ['prefix' => 'foo/bar/baz', 'namespace' => null, 'where' => []], Router::mergeGroup(
            ['prefix' => 'baz'], $old));

		$old = ['domain' => 'foo'];
		$this->assertEquals(
            ['domain' => 'baz', 'prefix' => null, 'namespace' => null, 'where' => []], Router::mergeGroup(
            ['domain' => 'baz'], $old));

		$old = ['where' => ['var1' => 'foo', 'var2' => 'bar']];
		$this->assertEquals([
            'prefix' => null, 'namespace' => null, 'where' => [
			'var1' => 'foo', 'var2' => 'baz', 'var3' => 'qux',
		]
        ], Router::mergeGroup(['where' => ['var2' => 'baz', 'var3' => 'qux']], $old));

		$old = [];
		$this->assertEquals([
            'prefix' => null, 'namespace' => null, 'where' => [
			'var1' => 'foo', 'var2' => 'bar',
		]
        ], Router::mergeGroup(['where' => ['var1' => 'foo', 'var2' => 'bar']], $old));
	}


	public function testRouteGrouping(): void
    {
		/**
		 * Inhereting Filters
		 */
		$router = $this->getRouter();
		$router->group(['before' => 'foo'], function() use ($router)
		{
			$router->get('foo/bar', fn() => 'hello');
		});
		$router->filter('foo', fn() => 'foo!');
		$this->assertEquals('foo!', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());


		/**
		 * Merging Filters
		 */
		$router = $this->getRouter();
		$router->group(['before' => 'foo'], function() use ($router)
		{
			$router->get('foo/bar', ['before' => 'bar', fn() => 'hello']);
		});
		$router->filter('foo', function() {});
		$router->filter('bar', fn() => 'foo!');
		$this->assertEquals('foo!', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());


		/**
		 * Merging Filters
		 */
		$router = $this->getRouter();
		$router->group(['before' => 'foo|bar'], function() use ($router)
		{
			$router->get('foo/bar', ['before' => 'baz', fn() => 'hello']);
		});
		$router->filter('foo', function() {});
		$router->filter('bar', function() {});
		$router->filter('baz', fn() => 'foo!');
		$this->assertEquals('foo!', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());

		/**
		 * getPrefix() method
		 */
		$router = $this->getRouter();
		$router->group(['prefix' => 'foo'], function() use ($router)
		{
			$router->get('bar', fn() => 'hello');
		});
		$routes = $router->getRoutes();
		$routes = $routes->getRoutes();
		$this->assertEquals('foo', $routes[0]->getPrefix());
	}


	public function testMergingControllerUses(): void
    {
		$router = $this->getRouter();
		$router->group(['namespace' => 'Namespace'], function() use ($router)
		{
			$router->get('foo/bar', 'Controller');
		});
		$routes = $router->getRoutes()->getRoutes();
		$action = $routes[0]->getAction();

		$this->assertEquals('Namespace\\Controller', $action['controller']);


		$router = $this->getRouter();
		$router->group(['namespace' => 'Namespace'], function() use ($router)
		{
			$router->group(['namespace' => 'Nested'], function() use ($router)
			{
				$router->get('foo/bar', 'Controller');
			});
		});
		$routes = $router->getRoutes()->getRoutes();
		$action = $routes[0]->getAction();

		$this->assertEquals('Namespace\\Nested\\Controller', $action['controller']);


		$router = $this->getRouter();
		$router->group(['prefix' => 'baz'], function() use ($router)
		{
			$router->group(['namespace' => 'Namespace'], function() use ($router)
			{
				$router->get('foo/bar', 'Controller');
			});
		});
		$routes = $router->getRoutes()->getRoutes();
		$action = $routes[0]->getAction();

		$this->assertEquals('Namespace\\Controller', $action['controller']);
	}


	public function testResourceRouting(): void
    {
		$router = $this->getRouter();
		$router->resource('foo', 'FooController');
		$routes = $router->getRoutes();
		$this->assertCount(8, $routes);

		$router = $this->getRouter();
		$router->resource('foo', 'FooController', ['only' => ['show', 'destroy']]);
		$routes = $router->getRoutes();

		$this->assertCount(2, $routes);

		$router = $this->getRouter();
		$router->resource('foo', 'FooController', ['except' => ['show', 'destroy']]);
		$routes = $router->getRoutes();

		$this->assertCount(6, $routes);

		$router = $this->getRouter();
		$router->resource('foo-bars', 'FooController', ['only' => ['show']]);
		$routes = $router->getRoutes();
		$routes = $routes->getRoutes();

		$this->assertEquals('foo-bars/{foo_bars}', $routes[0]->getUri());

		$router = $this->getRouter();
		$router->resource('foo-bars.foo-bazs', 'FooController', ['only' => ['show']]);
		$routes = $router->getRoutes();
		$routes = $routes->getRoutes();

		$this->assertEquals('foo-bars/{foo_bars}/foo-bazs/{foo_bazs}', $routes[0]->getUri());

		$router = $this->getRouter();
		$router->resource('foo-bars', 'FooController', ['only' => ['show'], 'as' => 'prefix']);
		$routes = $router->getRoutes();
		$routes = $routes->getRoutes();

		$this->assertEquals('foo-bars/{foo_bars}', $routes[0]->getUri());
		$this->assertEquals('prefix.foo-bars.show', $routes[0]->getName());
	}


	public function testResourceRouteNaming(): void
    {
		$router = $this->getRouter();
		$router->resource('foo', 'FooController');

		$this->assertTrue($router->getRoutes()->hasNamedRoute('foo.index'));
		$this->assertTrue($router->getRoutes()->hasNamedRoute('foo.show'));
		$this->assertTrue($router->getRoutes()->hasNamedRoute('foo.create'));
		$this->assertTrue($router->getRoutes()->hasNamedRoute('foo.store'));
		$this->assertTrue($router->getRoutes()->hasNamedRoute('foo.edit'));
		$this->assertTrue($router->getRoutes()->hasNamedRoute('foo.update'));
		$this->assertTrue($router->getRoutes()->hasNamedRoute('foo.destroy'));

		$router = $this->getRouter();
		$router->resource('foo.bar', 'FooController');

		$this->assertTrue($router->getRoutes()->hasNamedRoute('foo.bar.index'));
		$this->assertTrue($router->getRoutes()->hasNamedRoute('foo.bar.show'));
		$this->assertTrue($router->getRoutes()->hasNamedRoute('foo.bar.create'));
		$this->assertTrue($router->getRoutes()->hasNamedRoute('foo.bar.store'));
		$this->assertTrue($router->getRoutes()->hasNamedRoute('foo.bar.edit'));
		$this->assertTrue($router->getRoutes()->hasNamedRoute('foo.bar.update'));
		$this->assertTrue($router->getRoutes()->hasNamedRoute('foo.bar.destroy'));

		$router = $this->getRouter();
		$router->resource('foo', 'FooController', [
            'names' => [
			'index' => 'foo',
			'show' => 'bar',
            ]
        ]);

		$this->assertTrue($router->getRoutes()->hasNamedRoute('foo'));
		$this->assertTrue($router->getRoutes()->hasNamedRoute('bar'));
	}


	public function testRouterFiresRoutedEvent(): void
    {
		$events = new Illuminate\Events\Dispatcher();
		$router = new Router($events);
		$router->get('foo/bar', fn() => '');

		$request = Request::create('http://foo.com/foo/bar', 'GET');
		$route   = new Route('GET', 'foo/bar', ['http', function() {}]);

		$_SERVER['__router.request'] = null;
		$_SERVER['__router.route']   = null;

		$router->matched(function($route, $request){
			$_SERVER['__router.request'] = $request;
			$_SERVER['__router.route']   = $route;
		});

		$router->dispatchToRoute($request);

		$this->assertInstanceOf(Request::class, $_SERVER['__router.request']);
		$this->assertEquals($_SERVER['__router.request'], $request);
		unset($_SERVER['__router.request']);

		$this->assertInstanceOf(Route::class, $_SERVER['__router.route']);
		$this->assertEquals($_SERVER['__router.route']->getUri(), $route->getUri());
		unset($_SERVER['__router.route']);
	}


	public function testRouterPatternSetting(): void
    {
		$router = $this->getRouter();
		$router->pattern('test', 'pattern');
		$this->assertEquals(['test' => 'pattern'], $router->getPatterns());

		$router = $this->getRouter();
		$router->patterns(['test' => 'pattern', 'test2' => 'pattern2']);
		$this->assertEquals(['test' => 'pattern', 'test2' => 'pattern2'], $router->getPatterns());
	}


    public function testRouteParametersDefaultValue()
    {
        $router = $this->getRouter();

        $router->get('foo/{bar?}', function ($bar = '') {
            return $bar;
        })->defaults('bar', 'foo');
        $this->assertEquals('foo', $router->dispatch(Request::create('foo', 'GET'))->getContent());


        $router->get('foo/{bar?}', function ($bar = '') {
            return $bar;
        })->defaults('bar', 'foo');
        $this->assertEquals('bar', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());
    }


    public function testRouteRedirect()
    {
        $router = $this->getRouter();
        $router->get('contact_us', function () {
            throw new \Exception('Route should not be reachable.');
        });
        $router->redirect('contact_us', 'contact', 302);

        $response = $router->dispatch(Request::create('contact_us', 'GET'));
        $this->assertTrue($response->isRedirect('contact'));
        $this->assertEquals(302, $response->getStatusCode());
    }


    public function testDispatchingCallableActionClasses()
    {
        $router = $this->getRouter();
        $router->get('foo/bar', 'ActionStub');

        $this->assertEquals('hello', $router->dispatch(Request::create('foo/bar', 'GET'))->getContent());

        $router->get('foo/bar2', [
            'uses' => 'ActionStub',
        ]);

        $this->assertEquals('hello', $router->dispatch(Request::create('foo/bar2', 'GET'))->getContent());
    }


	protected function getRouter(): Router
    {
		return new Router(new Illuminate\Events\Dispatcher);
	}

}


class RouteTestControllerDispatchStub extends Illuminate\Routing\Controller {
	public function __construct()
	{
		$this->beforeFilter('foo', ['only' => 'bar']);
		$this->beforeFilter('@filter', ['only' => 'baz']);
		$this->afterFilter('qux', ['only' => 'qux']);
	}
	public function foo(): string
    {
		return 'bar';
	}
	public function bar(): string
    {
		return 'baz';
	}
	public function filter(): string
    {
		return 'filtered';
	}
	public function baz(): string
    {
		return 'baz';
	}
	public function qux(): string
    {
		return 'qux';
	}
}

class RouteTestControllerRemoveFilterStub extends Controller
{
    public function __construct()
    {
        $this->beforeFilter('removeBefore', ['only' => 'beforeRoute']);
        $this->beforeFilter('@inlineBeforeFilter', ['only' => 'beforeRoute']);
        $this->afterFilter('removeAfter', ['only' => 'afterRoute']);
        $this->afterFilter('@inlineAfterFilter', ['only' => 'afterRoute']);

        $this->forgetBeforeFilter('removeBefore');
        $this->forgetBeforeFilter('@inlineBeforeFilter');
        $this->forgetAfterFilter('removeAfter');
		$this->forgetAfterFilter('@inlineAfterFilter');
	}
	public function beforeRoute(): string
    {
		return __FUNCTION__;
	}
	public function afterRoute(): string
    {
		return __FUNCTION__;
	}
	public function inlineBeforeFilter(): string
    {
		return __FUNCTION__;
	}
	public function inlineAfterFilter(): string
    {
		return __FUNCTION__;
	}
}

class RouteBindingStub {
	public function bind($value, $route): string
    { return strtoupper((string) $value); }
	public function find($value, $route): string
    { return strtolower((string) $value); }
}

class RouteModelBindingStub {
	public function find($value): string
    { return strtoupper((string) $value); }
}

class RouteModelBindingNullStub {
	public function find($value): void
    {}
}

class RouteTestFilterStub {
	public function filter(): string
    {
		return 'foo!';
	}
	public function handle(): string
    {
		return 'handling!';
	}
}

class ActionStub extends Controller
{
    public function __invoke(): string
    {
        return 'hello';
    }
}
