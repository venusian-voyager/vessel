<?php

namespace Voyager\Vessel;

use Closure;
use Voyager\Contracts\Vessel\BindingResolutionException;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;

class BoundMethod
{
    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param  \Voyager\Vessel\Vessel  $vessel
     * @param  callable|string  $callback
     * @param  array  $parameters
     * @param  string|null  $defaultMethod
     * @return mixed
     *
     * @throws \ReflectionException
     * @throws \InvalidArgumentException
     */
    public static function call(Vessel $vessel, callable|string|array $callback, array $parameters = [], ?string $defaultMethod = null): mixed
    {
        if (is_string($callback) && ! $defaultMethod && method_exists($callback, '__invoke')) {
            $defaultMethod = '__invoke';
        }

        if (static::isCallableWithAtSign($callback) || $defaultMethod) {
            return static::callClass($vessel, $callback, $parameters, $defaultMethod);
        }

        return static::callBoundMethod($vessel, $callback, function () use ($vessel, $callback, $parameters) {
            return $callback(...array_values(static::getMethodDependencies($vessel, $callback, $parameters)));
        });
    }

    /**
     * Call a string reference to a class using Class@method syntax.
     *
     * @param  \Voyager\Vessel\Vessel  $vessel
     * @param  string  $target
     * @param  array  $parameters
     * @param  string|null  $defaultMethod
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    protected static function callClass(Vessel $vessel, string $target, array $parameters = [], ?string $defaultMethod = null): mixed
    {
        $segments = explode('@', $target);

        // We will assume an @ sign is used to delimit the class name from the method
        // name. We will split on this @ sign and then build a callable array that
        // we can pass right back into the "call" method for dependency binding.
        $method = count($segments) === 2
            ? $segments[1]
            : $defaultMethod;

        if (is_null($method)) {
            throw new InvalidArgumentException('Method not provided.');
        }

        return static::call(
            $vessel,
            [$vessel->make($segments[0]), $method],
            $parameters
        );
    }

    /**
     * Call a method that has been bound to the vessel.
     *
     * @param  \Voyager\Vessel\Vessel  $vessel
     * @param  callable  $callback
     * @param  mixed  $default
     * @return mixed
     */
    protected static function callBoundMethod(Vessel $vessel, callable|string|array $callback, mixed $default): mixed
    {
        if (! is_array($callback)) {
            return Util::unwrapIfClosure($default);
        }

        // Here we need to turn the array callable into a Class@method string we can use to
        // examine the vessel and see if there are any method bindings for this given
        // method. If there are, we can call this method binding callback immediately.
        $method = static::normalizeMethod($callback);

        if ($vessel->hasMethodBinding($method)) {
            return $vessel->callMethodBinding($method, $callback[0]);
        }

        return Util::unwrapIfClosure($default);
    }

    /**
     * Normalize the given callback into a Class@method string.
     *
     * @param  callable  $callback
     * @return string
     */
    protected static function normalizeMethod(array $callback): string
    {
        $class = is_string($callback[0]) ? $callback[0] : get_class($callback[0]);

        return "{$class}@{$callback[1]}";
    }

    /**
     * Get all dependencies for a given method.
     *
     * @param  \Voyager\Vessel\Vessel  $vessel
     * @param  callable|string  $callback
     * @param  array  $parameters
     * @return array
     *
     * @throws \ReflectionException
     */
    protected static function getMethodDependencies(Vessel $vessel, callable|string|array $callback, array $parameters = []): array
    {
        $dependencies = [];

        foreach (static::getCallReflector($callback)->getParameters() as $parameter) {
            static::addDependencyForCallParameter($vessel, $parameter, $parameters, $dependencies);
        }

        return array_merge($dependencies, array_values($parameters));
    }

    /**
     * Get the proper reflection instance for the given callback.
     *
     * @param  callable|string  $callback
     * @return \ReflectionFunctionAbstract
     *
     * @throws \ReflectionException
     */
    protected static function getCallReflector(callable|string|array $callback): ReflectionFunctionAbstract
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
     * @param  \Voyager\Vessel\Vessel  $vessel
     * @param  \ReflectionParameter  $parameter
     * @param  array  $parameters
     * @param  array  $dependencies
     * @return void
     *
     * @throws \Voyager\Contracts\Vessel\BindingResolutionException
     */
    protected static function addDependencyForCallParameter(
        Vessel $vessel,
        ReflectionParameter $parameter,
        array &$parameters,
        array &$dependencies,
    ): void {
        $pendingDependencies = [];

        if (array_key_exists($paramName = $parameter->getName(), $parameters)) {
            $pendingDependencies[] = $parameters[$paramName];

            unset($parameters[$paramName]);
        } elseif ($attribute = Util::getContextualAttributeFromDependency($parameter)) {
            $pendingDependencies[] = $vessel->resolveFromAttribute($attribute);
        } elseif (! is_null($className = Util::getParameterClassName($parameter))) {
            if (array_key_exists($className, $parameters)) {
                $pendingDependencies[] = $parameters[$className];

                unset($parameters[$className]);
            } elseif ($parameter->isVariadic()) {
                $variadicDependencies = $vessel->make($className);

                $pendingDependencies = array_merge($pendingDependencies, is_array($variadicDependencies)
                    ? $variadicDependencies
                    : [$variadicDependencies]);
            } else {
                $pendingDependencies[] = $vessel->make($className);
            }
        } elseif ($parameter->isDefaultValueAvailable()) {
            $pendingDependencies[] = $parameter->getDefaultValue();
        } elseif (! $parameter->isOptional() && ! array_key_exists($paramName, $parameters)) {
            $message = "Unable to resolve dependency [{$parameter}] in class {$parameter->getDeclaringClass()->getName()}";

            throw new BindingResolutionException($message);
        }

        foreach ($pendingDependencies as $dependency) {
            $vessel->fireAfterResolvingAttributeCallbacks($parameter->getAttributes(), $dependency);
        }

        $dependencies = array_merge($dependencies, $pendingDependencies);
    }

    /**
     * Determine if the given string is in Class@method syntax.
     *
     * @param  mixed  $callback
     * @return bool
     */
    protected static function isCallableWithAtSign(mixed $callback): bool
    {
        return is_string($callback) && str_contains($callback, '@');
    }
}
