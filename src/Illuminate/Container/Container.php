<?php namespace Illuminate\Container;

use Closure;
use ArrayAccess;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Reflector;
use Illuminate\Support\Util;
use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;

class Container implements ArrayAccess, ContainerInterface {

    private static ?Container $instance;

    /**
	 * An array of the types that have been resolved.
	 */
	protected array $resolved = [];

	/**
	 * The container's bindings.
	 */
	protected array $bindings = [];

    /**
     * The container's method bindings.
     *
     * @var \Closure[]
     */
    protected array $methodBindings = [];

	/**
	 * The container's shared instances.
	 */
	protected array $instances = [];

	/**
	 * The registered type aliases.
	 */
	protected array $aliases = [];

    /**
     * The registered aliases keyed by the abstract name.
     */
    protected array $abstractAliases = [];

	/**
	 * All of the registered rebound callbacks.
	 */
	protected array $reboundCallbacks = [];

    /**
     * All of the before resolving callbacks by class type.
     */
    protected array $beforeResolvingCallbacks = [];

	/**
	 * All of the registered resolving callbacks.
	 */
	protected array $resolvingCallbacks = [];

    /**
     * All of the after resolving callbacks by class type.
     */
    protected array $afterResolvingCallbacks = [];

    /**
     * All of the global before resolving callbacks.
     *
     * @var \Closure[]
     */
    private array $globalBeforeResolvingCallbacks = [];

	/**
	 * All of the global resolving callbacks.
	 */
	protected array $globalResolvingCallbacks = [];

    /**
     * All of the global after resolving callbacks.
     *
     * @var \Closure[]
     */
    protected array $globalAfterResolvingCallbacks = [];

    /**
     * The contextual binding map.
     */
    public array $contextual = [];

    /**
     * The extension closures for services.
     */
    protected array $extenders = [];

    /**
     * The stack of concretions currently being built.
     */
    protected array $buildStack = [];

    /**
     * All of the registered tags.
     */
    private array $tags;

    /**
     * Get the globally available instance of the container.
     *
     * @return Container
     */
    public static function getInstance(): Container
    {
        if (is_null(static::$instance)) {
            static::$instance = new static;
        }

        return static::$instance;
    }

    /**
     * Set the shared instance of the container.
     *
     * @param  Container|null  $container
     */
    public static function setInstance(Container $container = null): ?Container
    {
        return static::$instance = $container;
    }

    /**
     * Define a contextual binding.
     *
     * @param  array|string  $concrete
     * @return ContextualBindingBuilder
     */
    public function when($concrete): ContextualBindingBuilder
    {
        $aliases = [];

        foreach (Arr::wrap($concrete) as $c) {
            $aliases[] = $this->getAlias($c);
        }

        return new ContextualBindingBuilder($this, $aliases);
    }

    /**
     * Add a contextual binding to the container.
     *
     * @param string           $concrete
     * @param string           $abstract
     * @param  \Closure|string $implementation
     *
     * @return void
     */
    public function addContextualBinding($concrete, $abstract, $implementation): void
    {
        $this->contextual[$concrete][$this->getAlias($abstract)] = $implementation;
    }

	/**
	 * Determine if the given abstract type has been bound.
	 *
	 * @param  string  $abstract
	 * @return bool
	 */
	public function bound($abstract): bool
    {
        return isset($this->bindings[$abstract]) ||
            isset($this->instances[$abstract]) ||
            $this->isAlias($abstract);
	}

    /**
     * Determine if the container has a method binding.
     *
     * @param  string  $method
     * @return bool
     */
    public function hasMethodBinding($method): bool
    {
        return isset($this->methodBindings[$method]);
    }

    /**
     * Bind a callback to resolve with Container::call.
     *
     * @param  array|string  $method
     * @param  \Closure  $callback
     * @return void
     */
    public function bindMethod($method, $callback): void
    {
        $this->methodBindings[$this->parseBindMethod($method)] = $callback;
    }

    /**
     * Get the method to be bound in class@method format.
     *
     * @param  array|string  $method
     * @return string
     */
    protected function parseBindMethod($method): string
    {
        if (is_array($method)) {
            return $method[0].'@'.$method[1];
        }

        return $method;
    }

    /**
     * Get the method binding for the given method.
     *
     * @param  string  $method
     * @param  mixed  $instance
     * @return mixed
     */
    public function callMethodBinding($method, $instance)
    {
        return call_user_func($this->methodBindings[$method], $instance, $this);
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function has(string $id): bool
    {
        return $this->bound($id);
    }

	/**
	 * Determine if the given abstract type has been resolved.
	 *
	 * @param  string  $abstract
	 * @return bool
	 */
	public function resolved($abstract): bool
    {
        if ($this->isAlias($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

		return isset($this->resolved[$abstract]) || isset($this->instances[$abstract]);
	}

	/**
	 * Determine if a given string is an alias.
	 *
	 * @param  string  $name
	 * @return bool
	 */
	public function isAlias($name): bool
    {
		return isset($this->aliases[$name]);
	}

    /**
     * Register a binding with the container.
     *
     * @param string|array $abstract
     * @param null         $concrete
     * @param bool         $shared
     *
     * @return void
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
	public function bind($abstract, $concrete = null, $shared = false): void
    {
		// If the given types are actually an array, we will assume an alias is being
		// defined and will grab this "real" abstract class name and register this
		// alias with the container so that it can be used as a shortcut for it.
		if (is_array($abstract)) {
			[$abstract, $alias] = $this->extractAlias($abstract);

			$this->alias($abstract, $alias);
		}

        $this->dropStaleInstances($abstract);

		// If no concrete type was given, we will simply set the concrete type to the
		// abstract type. This will allow concrete type to be registered as shared
		// without being forced to state their classes in both of the parameter.
		if (is_null($concrete)) {
			$concrete = $abstract;
		}

		// If the factory is not a Closure, it means it is just a class name which is
		// is bound into this container to the abstract type and we will just wrap
		// it up inside a Closure to make things more convenient when extending.
		if ( ! $concrete instanceof Closure) {
            if (! is_string($concrete)) {
                throw new \TypeError(self::class.'::bind(): Argument #2 ($concrete) must be of type Closure|string|null');
            }

			$concrete = $this->getClosure($abstract, $concrete);
		}

		$this->bindings[$abstract] = compact('concrete', 'shared');

		// If the abstract type was already resolved in this container we'll fire the
		// rebound listener so that any objects which have already gotten resolved
		// can have their copy of the object updated via the listener callbacks.
		if ($this->resolved($abstract))
		{
			$this->rebound($abstract);
		}
	}

	/**
	 * Get the Closure to be used when building a type.
	 *
	 * @param string $abstract
	 * @param string $concrete
	 *
	 * @return \Closure
	 */
	protected function getClosure(string $abstract, string $concrete): Closure
    {
		return function($c, $parameters = array()) use ($abstract, $concrete) {
			$method = ($abstract === $concrete) ? 'build' : 'make';

			return $c->$method($concrete, $parameters, false);
		};
	}

	/**
	 * Register a binding if it hasn't already been registered.
	 *
	 * @param  string  $abstract
	 * @param  \Closure|string|null  $concrete
	 * @param  bool  $shared
	 * @return void
	 */
	public function bindIf($abstract, $concrete = null, $shared = false): void
    {
		if ( ! $this->bound($abstract)) {
			$this->bind($abstract, $concrete, $shared);
		}
	}

    /**
     * Register a shared binding in the container.
     *
     * @param string $abstract
     * @param null   $concrete
     *
     * @return void
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
	public function singleton($abstract, $concrete = null): void
    {
		$this->bind($abstract, $concrete, true);
	}

    /**
     * Register a shared binding if it hasn't already been registered.
     *
     * @param  string  $abstract
     * @param  \Closure|string|null  $concrete
     * @return void
     */
    public function singletonIf($abstract, $concrete = null): void
    {
        if (! $this->bound($abstract)) {
            $this->singleton($abstract, $concrete);
        }
    }

	/**
	 * Wrap a Closure such that it is shared.
	 *
	 * @param  \Closure  $closure
	 * @return \Closure
	 */
	public function share(Closure $closure): Closure
    {
		return function($container) use ($closure)
		{
			// We'll simply declare a static variable within the Closures and if it has
			// not been set we will execute the given Closures to resolve this value
			// and return it back to these consumers of the method as an instance.
			static $object;

			if (is_null($object))
			{
				$object = $closure($container);
			}

			return $object;
		};
	}

	/**
	 * Bind a shared Closure into the container.
	 *
	 * @param  string    $abstract
	 * @param  \Closure  $closure
	 * @return void
	 */
	public function bindShared($abstract, Closure $closure): void
    {
		$this->bind($abstract, $this->share($closure), true);
	}

    /**
     * "Extend" an abstract type in the container.
     *
     * @param string   $abstract
     * @param \Closure $closure
     *
     * @return void
     *
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
	public function extend($abstract, Closure $closure): void
    {
        $abstract = $this->getAlias($abstract);

		if (isset($this->instances[$abstract])) {
			$this->instances[$abstract] = $closure($this->instances[$abstract], $this);

			$this->rebound($abstract);
		} else {
            $this->extenders[$abstract][] = $closure;

            if ($this->resolved($abstract)) {
                $this->rebound($abstract);
            }
		}
	}

	/**
	 * Get an extender Closure for resolving a type.
     *
	 * @deprecated
	 * @param  string    $abstract
	 * @param  \Closure  $closure
	 * @return \Closure
	 */
	protected function getExtender($abstract, Closure $closure): Closure
    {
		// To "extend" a binding, we will grab the old "resolver" Closure and pass it
		// into a new one. The old resolver will be called first and the result is
		// handed off to the "new" resolver, along with this container instance.
		$resolver = $this->bindings[$abstract]['concrete'];

		return function($container) use ($resolver, $closure)
		{
			return $closure($resolver($container), $container);
		};
	}

    /**
     * Register an existing instance as shared in the container.
     *
     * @param string|array $abstract
     * @param mixed  $instance
     *
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
	public function instance($abstract, $instance)
    {
        // First, we will extract the alias from the abstract if it is an array so we
		// are using the correct name when binding the type. If we get an alias it
		// will be registered with the container so we can resolve it out later.
		if (is_array($abstract))
		{
			[$abstract, $alias] = $this->extractAlias($abstract);

			$this->alias($abstract, $alias);
		}

        $this->removeAbstractAlias($abstract);

		unset($this->aliases[$abstract]);

		// We'll check to determine if this type has been bound before, and if it has
		// we will fire the rebound callbacks registered with the container and it
		// can be updated with consuming classes that have gotten resolved here.
		$isBound = $this->bound($abstract);

		$this->instances[$abstract] = $instance;

		if ($isBound) {
			$this->rebound($abstract);
		}

        return $instance;
	}

    /**
     * Remove an alias from the contextual binding alias cache.
     *
     * @param  string  $searched
     * @return void
     */
    protected function removeAbstractAlias($searched): void
    {
        if (! isset($this->aliases[$searched])) {
            return;
        }

        foreach ($this->abstractAliases as $abstract => $aliases) {
            foreach ($aliases as $index => $alias) {
                if ($alias == $searched) {
                    unset($this->abstractAliases[$abstract][$index]);
                }
            }
        }
    }

    /**
     * Assign a set of tags to a given binding.
     *
     * @param  array|string  $abstracts
     * @param  array|mixed  ...$tags
     * @return void
     */
    public function tag($abstracts, $tags): void
    {
        $tags = is_array($tags) ? $tags : array_slice(func_get_args(), 1);

        foreach ($tags as $tag) {
            if (! isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }

            foreach ((array) $abstracts as $abstract) {
                $this->tags[$tag][] = $abstract;
            }
        }
    }

    /**
     * Resolve all of the bindings for a given tag.
     *
     * @param  string  $tag
     */
    public function tagged($tag)
    {
        if (! isset($this->tags[$tag])) {
            return [];
        }

        return new RewindableGenerator(function () use ($tag) {
            foreach ($this->tags[$tag] as $abstract) {
                yield $this->make($abstract);
            }
        }, count($this->tags[$tag]));
    }

	/**
	 * Alias a type to a shorter name.
	 *
	 * @param  string  $abstract
	 * @param  string  $alias
	 * @return void
	 */
	public function alias($abstract, $alias): void
    {
        if ($alias === $abstract) {
            throw new LogicException("[{$abstract}] is aliased to itself.");
        }

        $this->aliases[$alias] = $abstract;

        $this->abstractAliases[$abstract][] = $alias;
	}

	/**
	 * Extract the type and alias from a given definition.
	 *
	 * @param  array  $definition
	 * @return array
	 */
	protected function extractAlias(array $definition): array
    {
		return array(key($definition), current($definition));
	}

    /**
     * Bind a new callback to an abstract's rebind event.
     *
     * @param string   $abstract
     * @param \Closure $callback
     *
     * @return mixed
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
	public function rebinding($abstract, Closure $callback)
	{
		$this->reboundCallbacks[$abstract][] = $callback;

		if ($this->bound($abstract)) {
            return $this->make($abstract);
        }
	}

    /**
     * Refresh an instance on the given target and method.
     *
     * @param string $abstract
     * @param mixed  $target
     * @param string $method
     *
     * @return mixed
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
	public function refresh($abstract, $target, $method)
	{
		return $this->rebinding($abstract, function($app, $instance) use ($target, $method)
		{
			$target->{$method}($instance);
		});
	}

    /**
     * Fire the "rebound" callbacks for the given abstract type.
     *
     * @param string $abstract
     *
     * @return void
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
	protected function rebound($abstract): void
    {
		$instance = $this->make($abstract);

		foreach ($this->getReboundCallbacks($abstract) as $callback) {
			$callback($this, $instance);
		}
	}

	/**
	 * Get the rebound callbacks for a given type.
	 *
	 * @param  string  $abstract
	 * @return array
	 */
	protected function getReboundCallbacks($abstract): array
    {
		if (isset($this->reboundCallbacks[$abstract]))
		{
			return $this->reboundCallbacks[$abstract];
		}

		return array();
	}

    /**
     * Wrap the given closure such that its dependencies will be injected when executed.
     *
     * @param  \Closure  $callback
     * @param  array  $parameters
     * @return \Closure
     */
    public function wrap(Closure $callback, array $parameters = []): Closure
    {
        return function () use ($callback, $parameters) {
            return $this->call($callback, $parameters);
        };
    }

    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param callable|string      $callback
     * @param array<string, mixed> $parameters
     * @param null                 $defaultMethod
     *
     * @return mixed
     *
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    public function call($callback, array $parameters = [], $defaultMethod = null)
    {
        return BoundMethod::call($this, $callback, $parameters, $defaultMethod);
    }

    /**
     * Get a closure to resolve the given type from the container.
     *
     * @param string $abstract
     *
     * @return \Closure
     */
    public function factory(string $abstract): Closure
    {
        return function () use ($abstract) {
            return $this->make($abstract);
        };
    }

    /**
     * An alias function name for make().
     *
     * @param string|callable $abstract
     * @param array           $parameters
     *
     * @return mixed
     *
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    public function makeWith($abstract, array $parameters = [])
    {
        return $this->make($abstract, $parameters);
    }

    /**
     * {@inheritdoc}
     *
     * @param string $id
     *
     * @return mixed
     * @throws CircularDependencyException
     * @throws EntryNotFoundException
     */
    public function get(string $id)
    {
        try {
            return $this->make($id);
        } catch (Exception $e) {
            if ($this->has($id) || $e instanceof CircularDependencyException) {
                throw $e;
            }

            throw new EntryNotFoundException($id, is_int($e->getCode()) ? $e->getCode() : 0, $e);
        }
    }

    /**
     * Resolve the given type from the container.
     *
     * @param string|callable $abstract
     * @param array  $parameters
     * @param bool   $raiseEvents
     *
     * @return mixed
     * @throws BindingResolutionException
     * @throws \ReflectionException
     */
	public function make($abstract, $parameters = array(), $raiseEvents = true)
	{
		$abstract = $this->getAlias($abstract);

        // First we'll fire any event handlers which handle the "before" resolving of
        // specific types. This gives some hooks the chance to add various extends
        // calls to change the resolution of objects that they're interested in.
        if ($raiseEvents) {
            $this->fireBeforeResolvingCallbacks($abstract, $parameters);
        }

        $concrete = $this->getContextualConcrete($abstract);

        $needsContextualBuild = ! empty($parameters) || ! is_null($concrete);

        // If an instance of the type is currently being managed as a singleton we'll
        // just return an existing instance instead of instantiating new instances
        // so the developer can keep using the same objects instance every time.
        if (isset($this->instances[$abstract]) && ! $needsContextualBuild) {
            return $this->instances[$abstract];
        }

        if (is_null($concrete)) {
            $concrete = $this->getConcrete($abstract);
        }

		// We're ready to instantiate an instance of the concrete type registered for
		// the binding. This will instantiate the types, as well as resolve any of
		// its "nested" dependencies recursively until all have gotten resolved.
		if ($this->isBuildable($concrete, $abstract))   {
			$object = $this->build($concrete, $parameters);
		} else {
			$object = $this->make($concrete, $parameters);
		}

        // If we defined any extenders for this type, we'll need to spin through them
        // and apply them to the object being built. This allows for the extension
        // of services, such as changing configuration or decorating the object.
        foreach ($this->getExtenders($abstract) as $extender) {
            $object = $extender($object, $this);
        }

        // If the requested type is registered as a singleton we'll want to cache off
        // the instances in "memory" so we can return it later without creating an
        // entirely new instance of an object on each subsequent request for it.
        if ($this->isShared($abstract) && ! $needsContextualBuild) {
            $this->instances[$abstract] = $object;
        }

        if ($raiseEvents) {
            $this->fireResolvingCallbacks($abstract, $object);
        }

        // Before returning, we will also set the resolved flag to "true".
        // After that we will be ready to return back the fully constructed class instance.
        $this->resolved[$abstract] = true;

        return $object;
	}

    /**
     * Get the contextual concrete binding for the given abstract.
     *
     * @param  string|callable  $abstract
     * @return \Closure|string|array|null
     */
    protected function getContextualConcrete($abstract)
    {
        if (! is_null($binding = $this->findInContextualBindings($abstract))) {
            return $binding;
        }

        // Next we need to see if a contextual binding might be bound under an alias of the
        // given abstract type. So, we will need to check if any aliases exist with this
        // type and then spin through them and check for contextual bindings on these.
        if (empty($this->abstractAliases[$abstract])) {
            return null;
        }

        foreach ($this->abstractAliases[$abstract] as $alias) {
            if (! is_null($binding = $this->findInContextualBindings($alias))) {
                return $binding;
            }
        }

        return null;
    }

    /**
     * Find the concrete binding for the given abstract in the contextual binding array.
     *
     * @param  string|callable  $abstract
     * @return \Closure|string|null
     */
    protected function findInContextualBindings($abstract)
    {
        return $this->contextual[end($this->buildStack)][$abstract] ?? null;
    }

    /**
     * Get the extender callbacks for a given type.
     *
     * @param  string  $abstract
     * @return array
     */
    protected function getExtenders($abstract): array
    {
        return $this->extenders[$this->getAlias($abstract)] ?? [];
    }

    /**
     * Remove all of the extender callbacks for a given type.
     *
     * @param  string  $abstract
     * @return void
     */
    public function forgetExtenders($abstract): void
    {
        unset($this->extenders[$this->getAlias($abstract)]);
    }

	/**
	 * Get the concrete type for a given abstract.
	 *
	 * @param string $abstract
	 *
	 * @return mixed   $concrete
	 */
	protected function getConcrete(string $abstract)
	{
		// If we don't have a registered resolver or concrete for the type, we'll just
		// assume each type is a concrete name and will attempt to resolve it as is
		// since the container should be able to resolve concretes automatically.
		if ( ! isset($this->bindings[$abstract]))
		{
			if ($this->missingLeadingSlash($abstract) && isset($this->bindings['\\'.$abstract]))
			{
				$abstract = '\\'.$abstract;
			}

			return $abstract;
		}

		return $this->bindings[$abstract]['concrete'];
	}

	/**
	 * Determine if the given abstract has a leading slash.
	 *
	 * @param string $abstract
	 *
	 * @return bool
	 */
	protected function missingLeadingSlash(string $abstract): bool
    {
		return is_string($abstract) && strpos($abstract, '\\') !== 0;
	}

    /**
     * Instantiate a concrete instance of the given type.
     *
     * @param string|Closure $concrete
     * @param array          $parameters
     *
     * @return mixed
     *
     * @throws BindingResolutionException
     * @throws \ReflectionException
     */
	public function build($concrete, $parameters = array())
	{
        // If the concrete type is actually a Closure, we will just execute it and
		// hand back the results of the functions, which allows functions to be
		// used as resolvers for more fine-tuned resolution of these objects.
		if ($concrete instanceof Closure) {
			return $concrete($this, $parameters);
		}

        try {
            $reflector = new ReflectionClass($concrete);
        } catch (ReflectionException $e) {
            throw new BindingResolutionException("Target class [$concrete] does not exist.", 0, $e);
        }

		// If the type is not instantiable, the developer is attempting to resolve
		// an abstract type such as an Interface of Abstract Class and there is
		// no binding registered for the abstractions so we need to bail out.
		if ( ! $reflector->isInstantiable()) {
			$this->notInstantiable($concrete);
		}

        // Diqo:
        // Why do we need to hold build stack?
        // This is to suport variadic binding resolution. Please check
        // \Illuminate\Container\Container::resolveVariadicClass
        //
        // The $concrete will be popped after it has been built or the container
        // faced error in building it.
        $this->buildStack[] = $concrete;

		$constructor = $reflector->getConstructor();

		// If there are no constructors, that means there are no dependencies then
		// we can just resolve the instances of the objects right away, without
		// resolving any other types or dependencies out of these containers.
		if (is_null($constructor)) {
            array_pop($this->buildStack);

			return new $concrete;
		}

		try {
            $dependencies = $constructor->getParameters();

            // Once we have all the constructor's parameters we can create each of the
            // dependency instances and then use the reflection instances to make a
            // new instance of this class, injecting the created dependencies in.

            $parameters = $this->keyParametersByArgument(
                $dependencies, $parameters
            );

            $instances = $this->getDependencies(
                $dependencies, $parameters
            );
        } catch (BindingResolutionException $exception) {
            array_pop($this->buildStack);

            throw $exception;
        }

        array_pop($this->buildStack);

		return $reflector->newInstanceArgs($instances);
	}

    /**
     * Throw an exception that the concrete is not instantiable.
     *
     * @param  string  $concrete
     * @return void
     *
     * @throws BindingResolutionException
     */
    protected function notInstantiable($concrete): void
    {
        if (! empty($this->buildStack)) {
            $previous = implode(', ', $this->buildStack);

            $message = "Target [$concrete] is not instantiable while building [$previous].";
        } else {
            $message = "Target [$concrete] is not instantiable.";
        }

        throw new BindingResolutionException($message);
    }

    /**
     * Resolve all of the dependencies from the ReflectionParameters.
     *
     * @param array $parameters
     * @param array $primitives
     *
     * @return array
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
	protected function getDependencies(array $parameters, array $primitives = array()): array
    {
		$dependencies = [];

		foreach ($parameters as $parameter)
		{
            $dependency = Reflector::getParameterClassName($parameter);

			if (array_key_exists($parameter->name, $primitives)) {
                // If the dependency has an override for this particular build we will use
                // that instead as the value. Otherwise, we will continue with this run
                // of resolutions and let reflection attempt to determine the result.
				$dependencies[] = $primitives[$parameter->name];
                continue;
			}

            if (is_null($dependency)) {
                // If the class is null, it means the dependency is a string or some other
                // primitive type which we can not resolve since it is not a class and
                // we will just bomb out with an error since we have no-where to go.
				$result = $this->resolveNonClass($parameter);
			} else {
				$result = $this->resolveClass($parameter);
			}

            if ($parameter->isVariadic()) {
                $dependencies = array_merge($dependencies, $result);
            } else {
                $dependencies[] = $result;
            }
		}

		return $dependencies;
	}

	/**
	 * Resolve a non-class hinted dependency.
	 *
	 * @param  \ReflectionParameter  $parameter
	 * @return mixed
	 *
	 * @throws BindingResolutionException
	 */
	protected function resolveNonClass(ReflectionParameter $parameter)
	{
        if (!is_null($concrete = $this->getContextualConcrete('$' . $parameter->getName()))) {
            return Util::unwrapIfClosure($concrete, $this);
        }

		if ($parameter->isDefaultValueAvailable()) {
			return $parameter->getDefaultValue();
		}

		$message = "Unresolvable dependency resolving [$parameter] in class {$parameter->getDeclaringClass()->getName()}";

		throw new BindingResolutionException($message);
	}

    /**
     * Resolve a class based dependency from the container.
     *
     * @param \ReflectionParameter $parameter
     *
     * @return mixed
     *
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
	protected function resolveClass(ReflectionParameter $parameter)
	{
        try {
            return $parameter->isVariadic()
                ? $this->resolveVariadicClass($parameter)
                : $this->make(Reflector::getParameterClassName($parameter));
        }

		// If we can not resolve the class instance, we will check to see if the value
		// is optional, and if it is we will return the optional parameter value as
		// the value of the dependency, similarly to how we do this with scalars.
		catch (BindingResolutionException $e)
		{
			if ($parameter->isDefaultValueAvailable())
			{
				return $parameter->getDefaultValue();
			}

            if ($parameter->isVariadic()) {
                return [];
            }

			throw $e;
		}
	}

    /**
     * Resolve a class based variadic dependency from the container.
     *
     * @param \ReflectionParameter $parameter
     *
     * @return mixed
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    protected function resolveVariadicClass(ReflectionParameter $parameter)
    {
        $className = Reflector::getParameterClassName($parameter);

        $abstract = $this->getAlias($className);

        if (!is_array($concrete = $this->getContextualConcrete($abstract))) {
            return $this->make($className);
        }

        return array_map(function ($abstract) {
            return $this->make($abstract);
        }, $concrete);
    }

	/**
	 * If extra parameters are passed by numeric ID, rekey them by argument name.
	 *
	 * @param  array  $dependencies
	 * @param  array  $parameters
	 * @return array
	 */
	protected function keyParametersByArgument(array $dependencies, array $parameters): array
    {
		foreach ($parameters as $key => $value)
		{
			if (is_numeric($key))
			{
				unset($parameters[$key]);

				$parameters[$dependencies[$key]->name] = $value;
			}
		}

		return $parameters;
	}

    /**
     * Register a new before resolving callback for all types.
     *
     * @param  \Closure|string  $abstract
     * @param  \Closure|null  $callback
     * @return void
     */
    public function beforeResolving($abstract, Closure $callback = null)
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        if ($abstract instanceof Closure && is_null($callback)) {
            $this->globalBeforeResolvingCallbacks[] = $abstract;
        } else {
            $this->beforeResolvingCallbacks[$abstract][] = $callback;
        }
    }

	/**
	 * Register a new resolving callback.
	 *
	 * @param  string|callable    $abstract
	 * @param  \Closure  $callback
	 * @return void
	 */
	public function resolving($abstract, Closure $callback = null): void
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        if (is_null($callback) && $abstract instanceof Closure) {
            // This is global callback, where it will be called
            // when the container resolves any type
            $this->globalResolvingCallbacks[] = $abstract;
        } else {
            // Call the callback when the container resolves $abstract
            $this->resolvingCallbacks[$abstract][] = $callback;
        }
	}

    /**
     * Register a new after resolving callback for all types.
     *
     * @param  \Closure|string  $abstract
     * @param  \Closure|null  $callback
     * @return void
     */
    public function afterResolving($abstract, Closure $callback = null)
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        if ($abstract instanceof Closure && is_null($callback)) {
            $this->globalAfterResolvingCallbacks[] = $abstract;
        } else {
            $this->afterResolvingCallbacks[$abstract][] = $callback;
        }
    }

	/**
	 * Register a new resolving callback for all types.
	 *
	 * @param  \Closure  $callback
	 * @return void
	 */
	public function resolvingAny(Closure $callback): void
    {
		$this->globalResolvingCallbacks[] = $callback;
	}

    /**
     * Fire all of the before resolving callbacks.
     *
     * @param  string  $abstract
     * @param  array  $parameters
     * @return void
     */
    protected function fireBeforeResolvingCallbacks($abstract, $parameters = []): void
    {
        $this->fireBeforeCallbackArray($abstract, $parameters, $this->globalBeforeResolvingCallbacks);

        foreach ($this->beforeResolvingCallbacks as $type => $callbacks) {
            if ($type === $abstract || is_subclass_of($abstract, $type)) {
                $this->fireBeforeCallbackArray($abstract, $parameters, $callbacks);
            }
        }
    }

    /**
     * Fire an array of callbacks with an object.
     *
     * @param  string  $abstract
     * @param  array  $parameters
     * @param  array  $callbacks
     * @return void
     */
    protected function fireBeforeCallbackArray($abstract, $parameters, array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            $callback($abstract, $parameters, $this);
        }
    }

	/**
	 * Fire all of the resolving callbacks.
	 *
	 * @param  string  $abstract
	 * @param  mixed   $object
	 * @return void
	 */
	protected function fireResolvingCallbacks($abstract, $object): void
    {
        $this->fireCallbackArray($object, $this->globalResolvingCallbacks);

        $this->fireCallbackArray(
            $object, $this->getCallbacksForType($abstract, $object, $this->resolvingCallbacks)
        );

        $this->fireAfterResolvingCallbacks($abstract, $object);
	}

    /**
     * Fire all of the after resolving callbacks.
     *
     * @param  string  $abstract
     * @param  mixed  $object
     * @return void
     */
    protected function fireAfterResolvingCallbacks($abstract, $object): void
    {
        $this->fireCallbackArray($object, $this->globalAfterResolvingCallbacks);

        $this->fireCallbackArray(
            $object, $this->getCallbacksForType($abstract, $object, $this->afterResolvingCallbacks)
        );
    }

    /**
     * Get all callbacks for a given type.
     *
     * @param  string  $abstract
     * @param  object  $object
     * @param  array  $callbacksPerType
     * @return array
     */
    protected function getCallbacksForType($abstract, $object, array $callbacksPerType): array
    {
        $results = [];

        foreach ($callbacksPerType as $type => $callbacks) {
            if ($type === $abstract || $object instanceof $type) {
                $results = array_merge($results, $callbacks);
            }
        }

        return $results;
    }

	/**
	 * Fire an array of callbacks with an object.
	 *
	 * @param  mixed  $object
	 * @param  array  $callbacks
	 */
	protected function fireCallbackArray($object, array $callbacks): void
    {
		foreach ($callbacks as $callback) {
			$callback($object, $this);
		}
	}

	/**
	 * Determine if a given type is shared.
	 *
	 * @param string $abstract
	 *
	 * @return bool
	 */
	public function isShared(string $abstract): bool
    {
        return isset($this->instances[$abstract]) ||
            (isset($this->bindings[$abstract]['shared']) &&
            $this->bindings[$abstract]['shared'] === true);
	}

	/**
	 * Determine if the given concrete is buildable.
	 *
	 * @param  mixed   $concrete
	 * @param  string  $abstract
	 * @return bool
	 */
	protected function isBuildable($concrete, $abstract): bool
    {
		return $concrete === $abstract || $concrete instanceof Closure;
	}

	/**
	 * Get the alias for an abstract if available.
	 *
	 * @param string $abstract
	 *
	 * @return string
	 */
	public function getAlias(string $abstract): string
    {
        return isset($this->aliases[$abstract])
            ? $this->getAlias($this->aliases[$abstract])
            : $abstract;
	}

	/**
	 * Get the container's bindings.
	 *
	 * @return array
	 */
	public function getBindings(): array
    {
		return $this->bindings;
	}

	/**
	 * Drop all of the stale instances and aliases.
	 *
	 * @param  string  $abstract
	 * @return void
	 */
	protected function dropStaleInstances($abstract): void
    {
		unset($this->instances[$abstract], $this->aliases[$abstract]);
	}

	/**
	 * Remove a resolved instance from the instance cache.
	 *
	 * @param  string  $abstract
	 * @return void
	 */
	public function forgetInstance($abstract): void
    {
		unset($this->instances[$abstract]);
	}

	/**
	 * Clear all of the instances from the container.
	 *
	 * @return void
	 */
	public function forgetInstances(): void
    {
		$this->instances = array();
	}

    /**
     * Flush the container of all bindings and resolved instances.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->aliases = [];
        $this->resolved = [];
        $this->bindings = [];
        $this->instances = [];
        $this->abstractAliases = [];
    }

	/**
	 * Determine if a given offset exists.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function offsetExists($key): bool
    {
        return $this->bound($key);
	}

    /**
     * Get the value at a given offset.
     *
     * @param string $key
     *
     * @return mixed
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
	public function offsetGet($key): mixed
    {
		return $this->make($key);
	}

    /**
     * Set the value at a given offset.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return void
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
	public function offsetSet($key, $value): void
    {
		// If the value is not a Closure, we will make it one. This simply gives
		// more "drop-in" replacement functionality for the Pimple which this
		// container's simplest functions are base modeled and built after.
		if ( ! $value instanceof Closure)
		{
			$value = function() use ($value)
			{
				return $value;
			};
		}

		$this->bind($key, $value);
	}

	/**
	 * Unset the value at a given offset.
	 *
	 * @param  string  $key
	 * @return void
	 */
	public function offsetUnset($key): void
    {
		unset($this->bindings[$key], $this->instances[$key], $this->resolved[$key]);
	}

	/**
	 * Dynamically access container services.
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	public function __get($key)
	{
		return $this[$key];
	}

	/**
	 * Dynamically set container services.
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return void
	 */
	public function __set($key, $value)
	{
		$this[$key] = $value;
	}

}
