<?php

/**
 * Copyright (c) 2015 by Maarten Staa.
 *
 * Some rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are
 * met:
 *
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *
 *     * Redistributions in binary form must reproduce the above
 *       copyright notice, this list of conditions and the following
 *       disclaimer in the documentation and/or other materials provided
 *       with the distribution.
 *
 *     * The names of the contributors may not be used to endorse or
 *       promote products derived from this software without specific
 *       prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

use Illuminate\Cache\CacheManager;
use Illuminate\CachedRouting\Router;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelBrowser;

class RoutingIntegrationTest extends TestCase
{
    /**
     * The Illuminate application instance.
     */
    protected ?Application $app = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        if ($this->app === null) {
            $this->refreshApplication();
        }
    }

    /**
     * Refresh the application instance.
     */
    protected function refreshApplication(): void
    {
        $this->app = $this->createApplication();

        Facade::setFacadeApplication($this->app);

        $this->app['env'] = 'testing';

        $this->app['path.storage'] = __DIR__;

        $loader = $this->createMock('Illuminate\Config\LoaderInterface');

        $loader->method('load')->willReturn([]);
        $loader->method('exists')->willReturn(true);
        $loader->method('getNamespaces')->willReturn([]);
        $loader->method('cascadePackage')->willReturn([]);

        $this->app['config'] = new Repository($loader, $this->app['env']);

        $this->app['cache'] = new CacheManager($this->app);
        $this->app['config']['cache.driver'] = 'array';

        $this->app['session'] = new SessionManager($this->app);
        $this->app['config']['session.driver'] = 'array';
        $this->app['session.store'] = $this->app['session']->driver();

        $this->app->boot();
    }

    /**
     * Creates the application.
     */
    protected function createApplication(): Application
    {
        return new Application();
    }

    /**
     * Create a router.
     */
    protected function getRouter(): Router
    {
        return new Router($this->app['events'], $this->app);
    }

    /**
     * Create a new HttpKernel client instance.
     */
    protected function createClient(array $server = array()): HttpKernelBrowser
    {
        return new HttpKernelBrowser($this->app, $server);
    }

    public function testCacheRoutes(): void
    {
        $router = $this->getRouter();

        $key = $router->cache(__FILE__, function () use ($router) {
            $router->get('/', 'HomeController@actionIndex');
        });

        static::assertTrue($this->app->cache->has($key), 'Routes must be in cache');
        static::assertEquals(1, $router->getRoutes()->count(), 'Routes must be in collection');

        $cachedRoutes = unserialize($this->app->cache->get($key));
        static::assertArrayHasKey('routes', $cachedRoutes);
        static::assertArrayHasKey('GET', $cachedRoutes['routes']);
        static::assertCount(1, $cachedRoutes['routes']['GET']);

        // Next request should not call the callback.
        $router = $this->getRouter();
        $router->cache(__FILE__, function () use ($router) {
            throw new RuntimeException('This should not be called');
        });
        static::assertEquals(1, $router->getRoutes()->count(), 'Routes must be obtained from cache');
    }

    public function testCacheRoutesNoTtl(): void
    {
        $router = $this->getRouter();

        $key = $router->cache(__FILE__, function () use ($router) {
            $router->get('/', 'HomeController@actionIndex');
        }, 0);

        static::assertNull($key, 'Cache key should be null with TTL=0');
        static::assertFalse($this->app->cache->has($key), 'Key should not be stored in cache');
        static::assertEquals(1, $router->getRoutes()->count(), 'Route must be added to router');
    }

    public function testAllMethodsWorks(): void
    {
        $methods = ['get', 'post', 'put', 'patch', 'delete'];

        $router = $this->getRouter();

        $key = $router->cache(__FILE__, function () use ($router, $methods) {
            foreach ($methods as $method) {
                $router->$method('/', 'HomeController@action' . ucfirst($method));
            }
        });

        static::assertTrue($this->app->cache->has($key), 'Routes must be in cache');
        static::assertEquals(count($methods), $router->getRoutes()->count(), 'Routes must be in collection');

        $cachedRoutes = unserialize($this->app->cache->get($key));
        static::assertArrayHasKey('routes', $cachedRoutes);

        foreach ($methods as $method) {
            static::assertArrayHasKey(strtoupper($method), $cachedRoutes['routes']);
            static::assertCount(1, $cachedRoutes['routes'][strtoupper($method)]);
        }

        // Next request should not call the callback.
        $router = $this->getRouter();
        $router->cache(__FILE__, function () use ($router) {
            throw new RuntimeException('This should not be called');
        });
        static::assertEquals(count($methods), $router->getRoutes()->count(), 'Routes must be obtained from cache');
    }

    public function testControllerRouting(): void
    {
        $router = $this->getRouter();

        $controllerName = str_shuffle('abcdefghijklmnopqrstuvwxyz');

        // Create a controller class.
        eval('class ' . $controllerName . ' extends Illuminate\Routing\Controller { public function getHomePage() {} }');

        $key = $router->cache(__FILE__, function () use ($router, $controllerName) {
            $router->controller('/', $controllerName);
        });

        static::assertTrue($this->app->cache->has($key), 'Routes must be in cache');
        // 2 because controller adds missingMethod
        static::assertEquals(2, $router->getRoutes()->count(), 'Routes must be in collection');

        // Next request should not call the callback.
        $router = $this->getRouter();
        $router->cache(__FILE__, function () use ($router) {
            throw new Exception('This should not be called');
        });
        static::assertEquals(2, $router->getRoutes()->count(), 'Routes must be obtained from cache');
    }

    public function testCanDispatchRequest(): void
    {
        // Create a controller class.
        $controllerName = str_shuffle('abcdefghijklmnopqrstuvwxyz');
        eval('class ' . $controllerName . ' extends Illuminate\Routing\Controller {
            public function getIndex() {
                return Illuminate\Support\Facades\Response::make(1);
            }
        }');

        // First, define a route.
        $router = $this->getRouter();
        $router->cache(__FILE__, function () use ($router, $controllerName) {
            $router->get('/', $controllerName . '@getIndex');
        });

        // Create a new router, set it on the app, and simulate a request.
        $this->app['router'] = $this->getRouter();
        $this->app['router']->cache(__FILE__, function () use ($router) {
            throw new RuntimeException('This should not be called');
        });

        $client = $this->createClient();
        $client->request('get', '/');

        $response = $client->getResponse();
        static::assertInstanceOf('Symfony\Component\HttpFoundation\Response', $response);
        static::assertEquals(1, $response->getContent());
    }

    public function testCanRouteToClosure(): void
    {
        // Create a new router, set it on the app, and simulate a request.
        $this->app['router'] = $this->getRouter();
        $this->app['router']->get('/', fn() => 1);

        $client = $this->createClient();
        $client->request('get', '/');

        $response = $client->getResponse();
        static::assertInstanceOf('Symfony\Component\HttpFoundation\Response', $response);
        static::assertEquals(1, $response->getContent());
    }

    public function testCanGroupRoutes(): void
    {
        $router = $this->getRouter();

        $controllerName = str_shuffle('abcdefghijklmnopqrstuvwxyz');

        // Create a controller class.
        eval('class ' . $controllerName . ' extends Illuminate\Routing\Controller {
            public function getHomePage() {
                return Illuminate\Support\Facades\Response::make(1);
            }
        }');

        $router->cache(__FILE__, function () use ($controllerName, $router) {
            $router->group(
                ['prefix' => 'grouped'],
                function () use ($router, $controllerName) {
                    $router->get('/', $controllerName . '@getHomePage');
                    $router->get('/dashboard', $controllerName . '@getHomePage');
                }
            );
        });

        // 2 routes originating from group closure
        static::assertEquals(2, $router->getRoutes()->count(), 'Routes must be in collection');

        // Create a new router, set it on the app, and simulate a request.
        $this->app['router'] = $this->getRouter();
        $this->app['router']->cache(__FILE__, callback: function () use ($router) {
            throw new RuntimeException('This should not be called');
        });

        $client = $this->createClient();
        $client->request('GET', '/grouped/dashboard');

        $response = $client->getResponse();
        static::assertInstanceOf('Symfony\Component\HttpFoundation\Response', $response);
        static::assertEquals(1, $response->getContent());
    }

    public function testCanChainWheres(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->app['router'] = $this->getRouter();
        $this->app['router']->get('/{foo}/{bar}', fn() => 'baz')
            ->where('foo', '\w+')
            ->where('bar', '\d+');

        // /herp/derp should not match above route.
        $client = $this->createClient();
        $client->catchExceptions(false);
        $client->request('GET', '/herp/derp');
    }

    public function testWheresRetainedInCache(): void
    {
        $this->expectException(NotFoundHttpException::class);

        // Create a controller class.
        $controllerName = str_shuffle('abcdefghijklmnopqrstuvwxyz');
        eval('class ' . $controllerName . ' extends Illuminate\Routing\Controller {
            public function getIndex() {
                return Illuminate\Support\Facades\Response::make(1);
            }
        }');

        // First, define a route.
        $router = $this->getRouter();
        $router->cache(__FILE__, function () use ($router, $controllerName) {
            $router->get('/{foo}/{bar}', $controllerName . '@getIndex')->where('foo', '\w+')->where('bar', '\d+');
        });

        // Create a new router, set it on the app, and simulate a request.
        $this->app['router'] = $this->getRouter();
        $this->app['router']->cache(__FILE__, callback: function () use ($router) {
            throw new RuntimeException('This should not be called');
        });

        // /herp/derp should not match above route.
        $client = $this->createClient();
        $client->catchExceptions(false);
        $client->request('GET', '/herp/derp');
    }

    public function testCanUseResource(): void
    {
        // Create a controller class.
        $controllerName = str_shuffle('abcdefghijklmnopqrstuvwxyz');
        eval('class ' . $controllerName . ' extends Illuminate\Routing\Controller { }');

        // First, define a resource.
        $router = $this->getRouter();
        $key = $router->cache(__FILE__, function () use ($router, $controllerName) {
            $router->resource('item', $controllerName);
        });

        static::assertTrue($this->app->cache->has($key), 'Routes must be in cache');
        static::assertEquals(8, $router->getRoutes()->count(), 'Routes must be in collection');

        // Next request should not call the callback.
        $router = $this->getRouter();
        $router->cache(__FILE__, function () use ($router) {
            throw new RuntimeException('This should not be called');
        });
        static::assertEquals(8, $router->getRoutes()->count(), 'Routes must be obtained from cache');
    }

    public function testCanClearCache(): void
    {
        // Create a controller class.
        $controllerName = str_shuffle('abcdefghijklmnopqrstuvwxyz');
        eval('class ' . $controllerName . ' extends Illuminate\Routing\Controller { }');

        // First, define a route.
        $router = $this->getRouter();
        $key = $router->cache(__FILE__, function () use ($router, $controllerName) {
            $router->get('/{foo}/{bar}', $controllerName . '@getIndex')->where('foo', '\w+')->where('bar', '\d+');
        });
        static::assertTrue($this->app->cache->has($key), 'Routes must be in cache');

        // Next, clear it.
        $router->clearCache(__FILE__);
        static::assertFalse($this->app->cache->has($key), 'Routes must no longer be cached');
    }
}
