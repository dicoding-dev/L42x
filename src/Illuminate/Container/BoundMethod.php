<?php

namespace Illuminate\Container;

use Closure;
use Illuminate\Support\Reflector;
use Illuminate\Support\Util;
use InvalidArgumentException;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;

class BoundMethod
{
    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param Container       $container
     * @param callable|string $callback
     * @param array           $parameters
     * @param null            $defaultMethod
     *
     * @return mixed
     *
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    public static function call(
        Container $container,
        callable|string $callback,
        array $parameters = [],
        $defaultMethod = null
    ) {
        if (is_string($callback) && ! $defaultMethod && method_exists($callback, '__invoke')) {
            $defaultMethod = '__invoke';
        }

        if (static::isCallableWithAtSign($callback) || $defaultMethod) {
            return static::callClass($container, $callback, $parameters, $defaultMethod);
        }

        return static::callBoundMethod($container, $callback, function () use ($container, $callback, $parameters) {
            return $callback(...array_values(static::getMethodDependencies($container, $callback, $parameters)));
        });
    }

    /**
     * Call a string reference to a class using Class@method syntax.
     *
     * @param Container $container
     * @param string    $target
     * @param array     $parameters
     * @param null      $defaultMethod
     *
     * @return mixed
     *
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    protected static function callClass(
        Container $container,
        string $target,
        array $parameters = [],
        $defaultMethod = null
    ) {
        $segments = explode('@', $target);

        // We will assume an @ sign is used to delimit the class name from the method
        // name. We will split on this @ sign and then build a callable array that
        // we can pass right back into the "call" method for dependency binding.
        $method = count($segments) === 2
            ? $segments[1] : $defaultMethod;

        if (is_null($method)) {
            throw new InvalidArgumentException('Method not provided.');
        }

        // Diqo:
        // So whats being called here are:
        // 1. The container
        // 2. Array which contains the resolved instance of the class being called
        //      and the method to call on that instance
        // 3. Parameters that are given to the method
        //
        // The above parameters will be used by static::call to resolve the
        // dependencies of the call before passing them
        // to static::callBoundedMethod.
        return static::call(
            $container, [$container->make($segments[0]), $method], $parameters
        );
    }

    /**
     * Call a method that has been bound to the container.
     *
     * @param Container $container
     * @param  callable|string $callback
     * @param  mixed    $default
     *
     * @return mixed
     */
    protected static function callBoundMethod(Container $container, callable|string $callback, $default)
    {
        if (! is_array($callback)) {
            return Util::unwrapIfClosure($default);
        }

        // Here we need to turn the array callable into a Class@method string we can use to
        // examine the container and see if there are any method bindings for this given
        // method. If there are, we can call this method binding callback immediately.
        $method = static::normalizeMethod($callback);

        if ($container->hasMethodBinding($method)) {
            return $container->callMethodBinding($method, $callback[0]);
        }

        // Diqo:
        // This is where the array callback is executed. Note that the
        // value that is used for execution is only $default, which is
        // the closure that should haved "closure" in the callback and the
        // required parameters.
        //
        // To further understand the mechanisme, see `call` on how it contrstruct
        // the closure before calling this `callBoundMethod`.
        //
        // So, unwrapIfClosure just executed the $default closure.
        return Util::unwrapIfClosure($default);
    }

    /**
     * Normalize the given callback into a Class@method string.
     *
     * @param  callable  $callback
     * @return string
     */
    protected static function normalizeMethod(callable $callback): string
    {
        $class = is_string($callback[0]) ? $callback[0] : get_class($callback[0]);

        return "{$class}@{$callback[1]}";
    }

    /**
     * Get all dependencies for a given method.
     *
     * @param Container       $container
     * @param callable|string $callback
     * @param array           $parameters
     *
     * @return array
     *
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    protected static function getMethodDependencies(
        Container $container,
        callable|string $callback,
        array $parameters = []
    ): array {
        $dependencies = [];

        foreach (static::getCallReflector($callback)->getParameters() as $parameter) {
            static::addDependencyForCallParameter($container, $parameter, $parameters, $dependencies);
        }

        return array_merge($dependencies, array_values($parameters));
    }

    /**
     * Get the proper reflection instance for the given callback.
     *
     * @param  callable|string  $callback
     * @return \ReflectionFunctionAbstract
     *
     * @throws ReflectionException
     */
    protected static function getCallReflector(callable|string $callback)
    {
        if (is_string($callback) && str_contains($callback, '::')) {
            $callback = explode('::', $callback);
        } elseif (is_object($callback) && ! $callback instanceof Closure) {
            $callback = [$callback, '__invoke'];
        }

        return is_array($callback)
            ? new ReflectionMethod($callback[0], $callback[1])
            : new ReflectionFunction($callback);
    }

    /**
     * Get the dependency for the given call parameter.
     *
     * @param Container           $container
     * @param ReflectionParameter $parameter
     * @param array               $parameters
     * @param array               $dependencies
     *
     * @return void
     *
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    protected static function addDependencyForCallParameter(
        Container $container,
        ReflectionParameter $parameter,
        array &$parameters,
        array &$dependencies
    ): void {
        if (array_key_exists($paramName = $parameter->getName(), $parameters)) {
            $dependencies[] = $parameters[$paramName];

            unset($parameters[$paramName]);
        } elseif (! is_null($className = Reflector::getParameterClassName($parameter))) {
            if (array_key_exists($className, $parameters)) {
                $dependencies[] = $parameters[$className];

                unset($parameters[$className]);
            } elseif ($parameter->isVariadic()) {
                $variadicDependencies = $container->make($className);

                $dependencies = array_merge($dependencies, is_array($variadicDependencies)
                    ? $variadicDependencies
                    : [$variadicDependencies]);
            } else {
                $dependencies[] = $container->make($className);
            }
        } elseif ($parameter->isDefaultValueAvailable()) {
            $dependencies[] = $parameter->getDefaultValue();
        } elseif (! $parameter->isOptional() && ! array_key_exists($paramName, $parameters)) {
            $message = "Unable to resolve dependency [{$parameter}] in class {$parameter->getDeclaringClass()->getName()}";

            throw new BindingResolutionException($message);
        }
    }

    /**
     * Determine if the given string is in Class@method syntax.
     *
     * @param  mixed  $callback
     * @return bool
     */
    protected static function isCallableWithAtSign($callback): bool
    {
        return is_string($callback) && str_contains($callback, '@');
    }
}
