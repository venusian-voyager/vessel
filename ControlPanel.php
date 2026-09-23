<?php

namespace Voyager\Vessel;

use Closure;
use Exception;
use TypeError;
use ArrayAccess;
use LogicException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionException;
use InvalidArgumentException;
use Voyager\Contracts\Vessel\SelfBuilding;
use Voyager\Vessel\Concerns\AliasManagement;
use Voyager\Vessel\Concerns\ContainerBinding;
use Voyager\Contracts\Vessel\CircleDepException;
use Voyager\Contracts\Vessel\TheServiceContainer;
use Voyager\Vessel\Concerns\EnvironmentAwareness;
use Voyager\Contracts\Vessel\DataBindingException;

class ControlPanel implements TheServiceContainer, ArrayAccess
{
    use AliasManagement, ContainerBinding, EnvironmentAwareness;

    /**
     * The current globally available container (if any).
     */
    protected static ?ControlPanel $instance = null;

    // SERVICE CONTAINER METHODS
    public function get(string $id): mixed
    {
        try
        {
            return $this->resolve($id);
        }
        catch (Exception $e)
        {
            if ($this->has($id) || $e instanceof CircleDepException)
            {
                throw $e;
            }

            throw new EntryNotFoundException($id, is_int($e->getCode()) ? $e->getCode() : 0, $e);
        }
    }

    public function has(string $id): bool
    {
        return $this->offsetExists($id);
    }

    /**
     * Determine if a given offset exists.
     *
     * @param  string  $offset
     */
    public function offsetExists($offset): bool
    {
        return $this->isBound($offset);
    }

    /**
     * Get the value at a given offset.
     *
     * @param string $offset
     * @throws ReflectionException
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->make($offset);
    }

    /**
     * Set the value at a given offset.
     *
     * @param mixed $offset
     * @param mixed $value
     * @throws ReflectionException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->bind($offset, $value instanceof Closure ? $value : fn () => $value);
    }

    /**
     * Unset the value at a given offset.
     *
     * @param  string  $offset
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->bindings[$offset], $this->instances[$offset], $this->resolved[$offset]);
    }

    /**
     * Alias a type to a different name.
     *
     * @param string $abstract
     * @param string $alias
     * @return void
     *
     * @throws LogicException
     */
    public function alias(string $abstract, string $alias): void
    {
        if ($alias === $abstract) {
            throw new LogicException("[{$abstract}] is aliased to itself.");
        }

        $this->removeAbstractAlias($alias);

        $this->aliases[$alias] = $abstract;

        $this->abstract_aliases[$abstract][] = $alias;
    }

    /**
     * Determine if the given abstract type has been bound.
     *
     * @param string $abstract
     * @return bool
     */
    public function isBound(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) ||
            isset($this->instances[$abstract]) ||
            $this->isAlias($abstract);
    }

    /**
     * Determine if the given abstract type has been resolved.
     *
     * @param string $abstract
     * @return bool
     */
    public function isResolved(string $abstract): bool
    {
        if ($this->isAlias($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        return isset($this->resolved[$abstract]) ||
            isset($this->instances[$abstract]);
    }

    /**
     * Instantiate a concrete instance of the given type.
     *
     * @template TClass of mixed
     *
     * @param callable(static, array): TClass|class-string<TClass> $concrete
     * @return TClass
     *
     * @throws DataBindingException
     * @throws CircleDepException
     * @throws ReflectionException
     */
    public function build(callable|string $concrete): mixed
    {
        // If the concrete type is actually a Closure, we will just execute it and
        // hand back the results of the functions, which allows functions to be
        // used as resolvers for more fine-tuned resolution of these objects.
        if ($concrete instanceof Closure) {
            $this->build_stack[] = spl_object_hash($concrete);

            try {
                return $concrete($this, $this->getLastParameterOverride());
            } finally {
                array_pop($this->build_stack);
            }
        }

        try
        {
            $reflector = new ReflectionClass($concrete);
        }
        catch (ReflectionException $e)
        {
            throw new DataBindingException("Target class [$concrete] does not exist.", 0, $e);
        }

        // If the type is not instantiable, the developer is attempting to resolve
        // an abstract type such as an Interface or Abstract Class and there is
        // no binding registered for the abstractions so we need to bail out.
        if (! $reflector->isInstantiable()) {
            $this->notInstantiable($concrete);
        }
        else
        {
            if (is_a($concrete, SelfBuilding::class, true) &&
                ! in_array($concrete, $this->build_stack, true)) {
                return $this->buildSelfBuildingInstance($concrete, $reflector);
            }

            $this->build_stack[] = $concrete;

            $constructor = $reflector->getConstructor();

            // If there are no constructors, that means there are no dependencies then
            // we can just resolve the instances of the objects right away, without
            // resolving any other types or dependencies out of these containers.
            if (is_null($constructor)) {
                array_pop($this->build_stack);

                $this->firePostResolveAttributeCallbacks(
                    $reflector->getAttributes(), $instance = new $concrete
                );

                return $instance;
            }

            $dependencies = $constructor->getParameters();

            // Once we have all the constructor's parameters we can create each of the
            // dependency instances and then use the reflection instances to make a
            // new instance of this class, injecting the created dependencies in.
            try {
                $instances = $this->resolveDependencies($dependencies);
            }
            finally {
                array_pop($this->build_stack);
            }

            $this->firePostResolveAttributeCallbacks(
                $reflector->getAttributes(), $instance = new $concrete(...$instances)
            );

            return $instance;
        }
    }

    /**
     * Resolve the given type from the container.
     *
     * @template TClass of object
     *
     * @param string|class-string<TClass> $abstract
     * @return ($abstract is class-string<TClass> ? TClass : mixed)
     *
     * @throws DataBindingException
     * @throws ReflectionException
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        return $this->resolve($abstract, $parameters);
    }

    /**
     * Register an existing instance as shared in the container.
     *
     * @template TInstance of mixed
     *
     * @param string $abstract
     * @param TInstance $instance
     * @return void
     * @throws ReflectionException
     */
    public function registerInstance(string $abstract, mixed $instance): void
    {
        $this->removeAbstractAlias($abstract);

        $is_bound = $this->isBound($abstract);

        unset($this->aliases[$abstract]);

        // We'll check to determine if this type has been bound before, and if it has
        // we will fire the rebound callbacks registered with the container.
        // Then, it can be updated with consuming classes that have gotten resolved here.
        $this->instances[$abstract] = $instance;

        if ($is_bound) {
            $this->fireReboundHooks($abstract);
        }
    }

    /**
     * Register a scoped binding in the container.
     *
     * @param callable|string $abstract
     * @param callable|string|null $concrete
     * @return void
     * @throws ReflectionException
     */
    public function registerScoped(callable|string $abstract, callable|string|null $concrete = null): void
    {
        $this->scoped_instances[] = $abstract;

        $this->registerSingleton($abstract, $concrete);
    }

    /**
     * Register a shared binding in the container.
     *
     * @param callable|string $abstract
     * @param callable|string|null $concrete
     * @return void
     * @throws ReflectionException
     */
    public function registerSingleton(callable|string $abstract, callable|string|null $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param callable|string $callback
     * @param  array<string, mixed>  $parameters
     * @param string|null $default_method
     * @return mixed
     *
     * @throws InvalidArgumentException|ReflectionException
     */
    public function call(callable|string $callback, array $parameters = [], ?string $default_method = null): mixed
    {

        $pushedToBuildStack = false;

        if (($className = $this->getClassForCallable($callback)) && ! in_array(
                $className,
                $this->build_stack,
                true
            )) {
            $this->build_stack[] = $className;

            $pushedToBuildStack = true;
        }

        $result = BoundMethod::call($this, $callback, $parameters, $default_method);

        if ($pushedToBuildStack) {
            array_pop($this->build_stack);
        }

        return $result;
    }

    /**
     * Register a binding with the container.
     *
     * @param callable|string $abstract
     * @param callable|string|null $concrete
     * @param bool $shared
     * @return void
     *
     * @throws TypeError
     * @throws ReflectionException
     */
    public function bind(callable|string $abstract, callable|string|null $concrete = null, bool $shared = false): void
    {
        if ($abstract instanceof Closure)
        {
            $this->bindBasedOnClosureReturnTypes($abstract, $concrete, $shared);
        }
        else
        {
            $this->dropStaleInstances($abstract);

            // If no concrete type was given, we will simply set the concrete type to the
            // abstract type. After that, the concrete type to be registered as shared
            // without being forced to state their classes in both of the parameters.
            if (is_null($concrete)) {
                $concrete = $abstract;
            }

            // If the factory is not a Closure, it means it is just a class name which is
            // bound into this container to the abstract type, and we will just wrap it
            // up inside its own Closure to give us more convenience when extending.
            if (! $concrete instanceof Closure)
            {
                if (! is_string($concrete))
                {
                    throw new TypeError(self::class.'::bind(): Argument #2 ($concrete) must be of type Closure|string|null');
                }

                $concrete = $this->getClosure($abstract, $concrete);
            }

            $this->bindings[$abstract] = ['concrete' => $concrete, 'shared' => $shared];

            // If the abstract type was already resolved in this container we'll fire the
            // rebound listener so that any objects which have already gotten resolved
            // can have their copy of the object updated via the listener callbacks.
            if ($this->isResolved($abstract))
            {
                $this->fireReboundHooks($abstract);
            }
        }
    }

    /**
     * Define a contextual binding.
     *
     * @param array|string $concrete
     * @return ContextualBindingBuilder
     */
    public function when(array|string $concrete): ContextualBindingBuilder
    {
        $aliases = [];

        foreach (Util::arrayWrap($concrete) as $c) {
            $aliases[] = $this->getAlias($c);
        }

        return new ContextualBindingBuilder($this, $aliases);
    }

    /**
     * Get the class name for the given callback, if one can be determined.
     *
     * @param callable|string $callback
     * @return string|false
     * @throws ReflectionException
     */
    protected function getClassForCallable(callable|string $callback): false|string
    {
        if (is_callable($callback) &&
            ! ($reflector = new ReflectionFunction($callback(...)))->isAnonymous()) {
            return $reflector->getClosureScopeClass()->name ?? false;
        }

        return false;
    }

    /**
     * Set the shared instance of the container.
     *
     * @param TheServiceContainer|null $container
     * @return TheServiceContainer|null
     */
    public static function setInstance(?TheServiceContainer $container = null): ?TheServiceContainer
    {
        return static::$instance = $container;
    }

    /**
     * Get the globally available instance of the container.
     *
     * @return static
     */
    public static function getInstance(): static
    {
        return static::$instance ??= new static;
    }

    /**
     * Register a scoped binding in the vessel.
     *
     * @param callable|string $abstract
     * @param callable|string|null $concrete
     * @return void
     * @throws ReflectionException
     */
    public function scoped(callable|string $abstract, callable|string|null $concrete = null): void
    {
        $this->scoped_instances[] = $abstract;

        $this->registerSingleton($abstract, $concrete);
    }

    /**
     * Register a new resolving callback.
     *
     * @param callable|string $abstract
     * @param callable|null $callback
     * @return void
     */
    public function resolving(callable|string $abstract, ?callable $callback = null): void
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        if (is_null($callback) && $abstract instanceof Closure) {
            $this->global_resolving_callbacks[] = $abstract;
        } else {
            $this->resolving_callbacks[$abstract][] = $callback;
        }
    }

    /**
     * Set the callback which determines the current vessel environment.
     *
     * @param callable|null $callback
     * @return void
     */
    public function resolveEnvironmentUsing(?callable $callback): void
    {
        $this->environment_resolver = $callback;
    }

}