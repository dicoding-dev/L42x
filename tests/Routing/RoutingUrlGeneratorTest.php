<?php

use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\Store;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class RoutingUrlGeneratorTest extends BackwardCompatibleTestCase {

	public function testBasicGeneration(): void
    {
		$url = new UrlGenerator(
			$routes = new Illuminate\Routing\RouteCollection,
			$request = Illuminate\Http\Request::create('http://www.foo.com/')
		);

		$this->assertEquals('http://www.foo.com/foo/bar', $url->to('foo/bar'));
		$this->assertEquals('https://www.foo.com/foo/bar', $url->to('foo/bar', [], true));
		$this->assertEquals('https://www.foo.com/foo/bar/baz/boom', $url->to('foo/bar', ['baz', 'boom'], true));

		/**
		 * Test HTTPS request URL generation...
		 */
		$url = new UrlGenerator(
			$routes = new Illuminate\Routing\RouteCollection,
			$request = Illuminate\Http\Request::create('https://www.foo.com/')
		);

		$this->assertEquals('https://www.foo.com/foo/bar', $url->to('foo/bar'));

		/**
		 * Test asset URL generation...
		 */
		$url = new UrlGenerator(
			$routes = new Illuminate\Routing\RouteCollection,
			$request = Illuminate\Http\Request::create('http://www.foo.com/index.php/')
		);

		$this->assertEquals('http://www.foo.com/foo/bar', $url->asset('foo/bar'));
		$this->assertEquals('https://www.foo.com/foo/bar', $url->asset('foo/bar', true));
	}


	public function testBasicRouteGeneration(): void
    {
		$url = new UrlGenerator(
			$routes = new Illuminate\Routing\RouteCollection,
			$request = Illuminate\Http\Request::create('http://www.foo.com/')
		);

		/**
		 * Empty Named Route
		 */
		$route = new Illuminate\Routing\Route(['GET'], '/', ['as' => 'plain']);
		$routes->add($route);

		/**
		 * Named Routes
		 */
		$route = new Illuminate\Routing\Route(['GET'], 'foo/bar', ['as' => 'foo']);
		$routes->add($route);

		/**
		 * Parameters...
		 */
		$route = new Illuminate\Routing\Route(['GET'], 'foo/bar/{baz}/breeze/{boom}', ['as' => 'bar']);
		$routes->add($route);

		/**
		 * HTTPS...
		 */
		$route = new Illuminate\Routing\Route(['GET'], 'foo/bar', ['as' => 'baz', 'https']);
		$routes->add($route);

		/**
		 * Controller Route Route
		 */
		$route = new Illuminate\Routing\Route(['GET'], 'foo/bar', ['controller' => 'foo@bar']);
		$routes->add($route);

		/**
		 * Non ASCII routes
		 */
		$route = new Illuminate\Routing\Route(['GET'], 'foo/bar/åαф/{baz}', ['as' => 'foobarbaz']);
		$routes->add($route);

		$this->assertEquals('/', $url->route('plain', [], false));
		$this->assertEquals('/?foo=bar', $url->route('plain', ['foo' => 'bar'], false));
		$this->assertEquals('http://www.foo.com/foo/bar', $url->route('foo'));
		$this->assertEquals('/foo/bar', $url->route('foo', [], false));
		$this->assertEquals('/foo/bar?foo=bar', $url->route('foo', ['foo' => 'bar'], false));
		$this->assertEquals('http://www.foo.com/foo/bar/taylor/breeze/otwell?fly=wall', $url->route('bar', ['taylor', 'otwell', 'fly' => 'wall']
        ));
		$this->assertEquals('http://www.foo.com/foo/bar/otwell/breeze/taylor?fly=wall', $url->route('bar', ['boom' => 'taylor', 'baz' => 'otwell', 'fly' => 'wall']
        ));
		$this->assertEquals('/foo/bar/taylor/breeze/otwell?fly=wall', $url->route('bar', ['taylor', 'otwell', 'fly' => 'wall'], false));
		$this->assertEquals('https://www.foo.com/foo/bar', $url->route('baz'));
		$this->assertEquals('http://www.foo.com/foo/bar', $url->action('foo@bar'));
		$this->assertEquals('http://www.foo.com/foo/bar/taylor/breeze/otwell?wall&woz', $url->route('bar', ['wall', 'woz', 'boom' => 'otwell', 'baz' => 'taylor']
        ));
		$this->assertEquals('http://www.foo.com/foo/bar/taylor/breeze/otwell?wall&woz', $url->route('bar', ['taylor', 'otwell', 'wall', 'woz']
        ));
		$this->assertEquals('http://www.foo.com/foo/bar/%C3%A5%CE%B1%D1%84/%C3%A5%CE%B1%D1%84', $url->route('foobarbaz', ['baz' => 'åαф']
        ));

	}


	public function testRoutesMaintainRequestScheme(): void
    {
		$url = new UrlGenerator(
			$routes = new Illuminate\Routing\RouteCollection,
			$request = Illuminate\Http\Request::create('https://www.foo.com/')
		);

		/**
		 * Named Routes
		 */
		$route = new Illuminate\Routing\Route(['GET'], 'foo/bar', ['as' => 'foo']);
		$routes->add($route);

		$this->assertEquals('https://www.foo.com/foo/bar', $url->route('foo'));
	}


	public function testHttpOnlyRoutes(): void
    {
		$url = new UrlGenerator(
			$routes = new Illuminate\Routing\RouteCollection,
			$request = Illuminate\Http\Request::create('https://www.foo.com/')
		);

		/**
		 * Named Routes
		 */
		$route = new Illuminate\Routing\Route(['GET'], 'foo/bar', ['as' => 'foo', 'http']);
		$routes->add($route);

		$this->assertEquals('http://www.foo.com/foo/bar', $url->route('foo'));
	}


	public function testRoutesWithDomains(): void
    {
		$url = new UrlGenerator(
			$routes = new Illuminate\Routing\RouteCollection,
			$request = Illuminate\Http\Request::create('http://www.foo.com/')
		);

		$route = new Illuminate\Routing\Route(['GET'], 'foo/bar', ['as' => 'foo', 'domain' => 'sub.foo.com']);
		$routes->add($route);

		/**
		 * Wildcards & Domains...
		 */
		$route = new Illuminate\Routing\Route(['GET'], 'foo/bar/{baz}', ['as' => 'bar', 'domain' => 'sub.{foo}.com']);
		$routes->add($route);

		$this->assertEquals('http://sub.foo.com/foo/bar', $url->route('foo'));
		$this->assertEquals('http://sub.taylor.com/foo/bar/otwell', $url->route('bar', ['taylor', 'otwell']));
		$this->assertEquals('/foo/bar/otwell', $url->route('bar', ['taylor', 'otwell'], false));
	}


	public function testRoutesWithDomainsAndPorts(): void
    {
		$url = new UrlGenerator(
			$routes = new Illuminate\Routing\RouteCollection,
			$request = Illuminate\Http\Request::create('http://www.foo.com:8080/')
		);

		$route = new Illuminate\Routing\Route(['GET'], 'foo/bar', ['as' => 'foo', 'domain' => 'sub.foo.com']);
		$routes->add($route);

		/**
		 * Wildcards & Domains...
		 */
		$route = new Illuminate\Routing\Route(['GET'], 'foo/bar/{baz}', ['as' => 'bar', 'domain' => 'sub.{foo}.com']);
		$routes->add($route);

		$this->assertEquals('http://sub.foo.com:8080/foo/bar', $url->route('foo'));
		$this->assertEquals('http://sub.taylor.com:8080/foo/bar/otwell', $url->route('bar', ['taylor', 'otwell']));
	}


	public function testHttpsRoutesWithDomains(): void
    {
		$url = new UrlGenerator(
			$routes = new Illuminate\Routing\RouteCollection,
			$request = Illuminate\Http\Request::create('https://foo.com/')
		);

		/**
		 * When on HTTPS, no need to specify 443
		 */
		$route = new Illuminate\Routing\Route(['GET'], 'foo/bar', ['as' => 'baz', 'domain' => 'sub.foo.com']);
		$routes->add($route);

		$this->assertEquals('https://sub.foo.com/foo/bar', $url->route('baz'));
	}


	public function testUrlGenerationForControllers(): void
    {
		$url = new UrlGenerator(
			$routes = new Illuminate\Routing\RouteCollection,
			$request = Illuminate\Http\Request::create('http://www.foo.com:8080/')
		);

		$route = new Illuminate\Routing\Route(['GET'], 'foo/{one}/{two?}/{three?}', ['as' => 'foo', function() {}]);
		$routes->add($route);

		$this->assertEquals('http://www.foo.com:8080/foo', $url->route('foo'));
	}


	public function testForceRootUrl(): void
    {
		$url = new UrlGenerator(
			$routes = new Illuminate\Routing\RouteCollection,
			$request = Illuminate\Http\Request::create('http://www.foo.com/')
		);

		$url->forceRootUrl('https://www.bar.com');
		$this->assertEquals('http://www.bar.com/foo/bar', $url->to('foo/bar'));


		/**
		 * Route Based...
		 */
		$url = new UrlGenerator(
			$routes = new Illuminate\Routing\RouteCollection,
			$request = Illuminate\Http\Request::create('http://www.foo.com/')
		);

		$url->forceSchema('https');
		$route = new Illuminate\Routing\Route(['GET'], '/foo', ['as' => 'plain']);
		$routes->add($route);

		$this->assertEquals('https://www.foo.com/foo', $url->route('plain'));

		$url->forceRootUrl('https://www.bar.com');
		$this->assertEquals('https://www.bar.com/foo', $url->route('plain'));
	}


	public function testPrevious(): void
    {
		$url = new UrlGenerator(
			$routes = new Illuminate\Routing\RouteCollection,
			$request = Illuminate\Http\Request::create('http://www.foo.com/')
		);

		$url->getRequest()->headers->set('referer', 'http://www.bar.com/');
		$this->assertEquals('http://www.bar.com/', $url->previous());

		$url->getRequest()->headers->remove('referer');
		$this->assertEquals($url->to('/'), $url->previous());
	}


    public function testPreviousUrlFromSession(): void
    {
        $session = m::mock(Store::class);
        $request = Illuminate\Http\Request::create('http://www.foo.com/some');

        $session->shouldReceive('previousUrl')->andReturn('http://www.foo.com/previous-page');
        $request->setSession($session);

        $url = new UrlGenerator(
			new Illuminate\Routing\RouteCollection,
            $request
		);

		$this->assertEquals('http://www.foo.com/previous-page', $url->previous());
	}


    public function testPreviousWithFallback(): void
    {
		$url = new UrlGenerator(
			$routes = new Illuminate\Routing\RouteCollection,
			$request = Illuminate\Http\Request::create('http://www.foo.com/')
		);

		$url->getRequest()->headers->set('referer', 'http://www.bar.com/');
		$this->assertEquals('http://www.bar.com/', $url->previous('/some-page'));

		$url->getRequest()->headers->remove('referer');
		$this->assertEquals($url->to('/some-page'), $url->previous('/some-page'));
	}

}
