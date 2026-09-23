<?php

namespace Voyager\Vessel\Concerns;

use Closure;
use ReflectionClass;
use ReflectionAttribute;
use ReflectionException;
use ReflectionParameter;
use Voyager\Vessel\Util;
use Voyager\Contracts\Vessel\Attributes\Bind;
use Voyager\Contracts\Vessel\Attributes\Scoped;
use Voyager\Contracts\Vessel\CircleDepException;
use Voyager\Contracts\Vessel\ContextualAttribute;
use Voyager\Contracts\Vessel\Attributes\Singleton;
use Voyager\Contracts\Vessel\DataBindingException;
use Voyager\NutsAndBolts\Concerns\ReflectsClosures;

trait ContainerBinding
{
    use ReflectsClosures;

    /**
     * An array of the types that have been resolved.
     *
     * @var bool[]
     */
    protected array $resolved = [];

    /**
     * The container's shared instances.
     *
     * @var object[]
     */
    protected array $instances = [];

    /**
     * The container's scoped instances.
     *
     * @var array
     */
    protected array $scoped_instances = [];

    /**
     * The container's bindings.
     *
     * @var array[]
     */
    protected array $bindings = [];

    /**
     * The container's method bindings.
     *
     * @var callable[]
     */
    protected array $method_bindings = [];

    /**
     * The parameter override stack.
     *
     * @var array[]
     */
    protected array $with = [];

    /**
     * The contextual binding map.
     *
     * @var array[]
     */
    public array $contextual = [];

    /**
     * The stack of concretions currently being built.
     *
     * @var array[]
     */
    protected array $build_stack = [];

    /**
     * Every global before resolving callbacks.
     *
     * @var callable[]
     */
    protected array $global_before_resolving_callbacks = [];

    /**
     * Every before-resolving callback by class type.
     *
     * @var array[]
     */
    protected array $before_resolving_callbacks = [];

    /**
     * Every global resolving callback.
     *
     * @var callable[]
     */
    protected array $global_resolving_callbacks = [];

    /**
     * Every registered rebound callback.
     *
     * @var array[]
     */
    protected array $rebound_callbacks = [];

    /**
     * Every resolving callback by class type.
     *
     * @var array[]
     */
    protected array $resolving_callbacks = [];

    /**
     * Every global after resolving callback.
     *
     * @var callable[]
     */
    protected array $global_after_resolving_callbacks = [];

    /**
     * Every after resolving callbacks by class type.
     *
     * @var array[]
     */
    protected array $after_resolving_callbacks = [];

    /**
     * Every post-resolving attribute callback by class type.
     *
     * @var array[]
     */
    protected array $after_resolving_attribute_callbacks = [];

    /**
     * Whether an abstract class has already had its attributes checked for bindings.
     *
     * @var array<class-string, true>
     */
    protected array $checked_for_attribute_bindings = [];

    /**
     * Whether a class has already been checked for Singleton or Scoped attributes.
     *
     * @var array<class-string, "scoped"|"singleton"|null>
     */
    protected array $checked_for_singleton_or_scoped_attributes = [];

    /**
     * The extension closures for services.
     *
     * @var array[]
     */
    protected array $extenders = [];

    /**
     * Determine if a given type is shared.
     *
     * @param string $abstract
     * @return bool
     */
    public function isShared(string $abstract): bool
    {
        if (isset($this->instances[$abstract])) {
            return true;
        }

        if (isset($this->bindings[$abstract]['shared']) && $this->bindings[$abstract]['shared'] === true) {
            return true;
        }

        if (! class_exists($abstract)) {
            return false;
        }

        if (($scopedType = $this->getScopedTyped($abstract)) === null) {
            return false;
        }

        if ($scopedType === 'scoped') {
            if (! in_array($abstract, $this->scoped_instances, true)) {
                $this->scoped_instances[] = $abstract;
            }
        }

        return true;
    }

    /**
     * Fire every post-resolving attribute callback.
     *
     * @param  ReflectionAttribute[]  $attributes
     * @param  mixed  $object
     * @return void
     */
    public function firePostResolveAttributeCallbacks(array $attributes, mixed $object): void
    {
        foreach ($attributes as $attribute) {
            if (is_a($attribute->getName(), ContextualAttribute::class, true)) {
                $instance = $attribute->newInstance();

                if (method_exists($instance, 'after')) {
                    $instance->after($instance, $object, $this);
                }
            }

            $callbacks = $this->getHooksForType(
                $attribute->getName(), $object, $this->after_resolving_attribute_callbacks
            );

            foreach ($callbacks as $callback) {
                $callback($attribute->newInstance(), $object, $this);
            }
        }
    }

    /**
     * Resolve a dependency based on an attribute.
     *
     * @param ReflectionAttribute $attribute
     * @return mixed
     */
    public function resolveFromAttribute(ReflectionAttribute $attribute): mixed
    {
        $handler = $this->contextualAttributes[$attribute->getName()] ?? null;

        $instance = $attribute->newInstance();

        if (is_null($handler) && method_exists($instance, 'resolve')) {
            $handler = $instance->resolve(...);
        }

        if (is_null($handler)) {
            throw new DataBindingException("Contextual binding attribute [{$attribute->getName()}] has no registered handler.");
        }

        return $handler($instance, $this);
    }

    /**
     * Determine if the container has a method binding.
     *
     * @param string $method
     * @return bool
     */
    public function hasMethodBinding(string $method): bool
    {
        return isset($this->method_bindings[$method]);
    }

    /**
     * Get the method binding for the given method.
     *
     * @param string $method
     * @param  mixed  $instance
     * @return mixed
     */
    public function callMethodBinding(string $method, mixed $instance): mixed
    {
        return call_user_func($this->method_bindings[$method], $instance, $this);
    }

    /**
     * Register a new after resolving attribute callback for all types.
     *
     * @param string $attribute
     * @param Closure $callback
     * @return void
     */
    public function afterResolvingAttribute(string $attribute, \Closure $callback): void
    {
        $this->after_resolving_attribute_callbacks[$attribute][] = $callback;
    }

    /**
     * Add a contextual binding to the container.
     *
     * @param string $concrete
     * @param callable|string $abstract
     * @param array|callable|string $implementation
     * @return void
     */
    public function addContextualBinding(string $concrete, callable|string $abstract, array|callable|string $implementation): void
    {
        $this->contextual[$concrete][$this->getAlias($abstract)] = $implementation;
    }

    /**
     * Register a new after resolving callback for all types.
     *
     * @param callable|string $abstract
     * @param callable|null $callback
     * @return void
     */
    public function afterResolving(callable|string $abstract, ?callable $callback = null): void
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        if ($abstract instanceof Closure && is_null($callback)) {
            $this->global_after_resolving_callbacks[] = $abstract;
        } else {
            $this->after_resolving_callbacks[$abstract][] = $callback;
        }
    }

    /**
     * Flush the vessel of all bindings and resolved instances.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->aliases = [];
        $this->resolved = [];
        $this->bindings = [];
        $this->instances = [];
        $this->abstract_aliases = [];
        $this->scoped_instances = [];
        $this->checked_for_attribute_bindings = [];
        $this->checked_for_singleton_or_scoped_attributes = [];
    }

    /**
     * Fire the "rebound" callbacks for the given abstract type.
     *
     * @param string $abstract
     * @return void
     * @throws ReflectionException
     */
    protected function fireReboundHooks(string $abstract): void
    {
        if ($callbacks = $this->getReboundCallbacks($abstract)) {
            $instance = $this->make($abstract);

            foreach ($callbacks as $callback) {
                $callback($this, $instance);
            }
        }
    }

    /**
     * Get the rebound callbacks for a given type.
     *
     * @param string $abstract
     * @return array
     */
    protected function getReboundCallbacks(string $abstract): array
    {
        return $this->rebound_callbacks[$abstract] ?? [];
    }

    /**
     * Resolve the given type from the container.
     *
     * @template TClass of object
     *
     * @param callable|string|class-string<TClass> $abstract
     * @param array $parameters
     * @param bool $fire_hooks
     * @return ($abstract is class-string<TClass> ? TClass : mixed)
     *
     * @throws DataBindingException
     * @throws CircleDepException
     * @throws ReflectionException
     */
    protected function resolve(callable|string $abstract, array $parameters = [], bool $fire_hooks = true): mixed
    {
        $abstract = $this->getAlias($abstract);

        // First we'll fire any event handlers which handle the "before" resolving of
        // specific types. This gives some hooks the chance to add various extends
        // calls to change the resolution of objects that they're interested in.
        if ($fire_hooks) {
            $this->firePreResolveHooks($abstract, $parameters);
        }

        $concrete = $this->getContextualConcrete($abstract);

        $needs_contextual_build = ! empty($parameters) || ! is_null($concrete);

        // If an instance of the type is currently being managed as a singleton we'll
        // just return an existing instance instead of instantiating new instances
        // so the developer can keep using the same objects instance every time.
        if (isset($this->instances[$abstract]) && ! $needs_contextual_build) {
            return $this->instances[$abstract];
        }

        $this->with[] = $parameters;

        if (is_null($concrete)) {
            $concrete = $this->getConcrete($abstract);
        }

        // We're ready to instantiate an instance of the concrete type registered for
        // the binding. This will instantiate the types, as well as resolve any of
        // its "nested" dependencies recursively until all have gotten resolved.
        $object = $this->isBuildable($concrete, $abstract)
            ? $this->build($concrete)
            : $this->make($concrete);

        // If we defined any extenders for this type, we'll need to spin through them
        // and apply them to the object being built. This allows for the extension
        // of services, such as changing configuration or decorating the object.
        foreach ($this->getExtenders($abstract) as $extender) {
            $object = $extender($object, $this);
        }

        // If the requested type is registered as a singleton we'll want to cache off
        // the instances in "memory" so we can return it later without creating an
        // entirely new instance of an object on each subsequent request for it.
        if ($this->isShared($abstract) && ! $needs_contextual_build) {
            $this->instances[$abstract] = $object;
        }

        if ($fire_hooks) {
            $this->fireResolvingHooks($abstract, $object);
        }

        // Before returning, we will also set the resolved flag to "true" and pop off
        // the parameter overrides for this build. After those two things are done
        // we will be ready to return back the fully constructed class instance.
        if (! $needs_contextual_build) {
            $this->resolved[$abstract] = true;
        }

        array_pop($this->with);

        return $object;
    }

    /**
     * Fire every resolving callback.
     *
     * @param string $abstract
     * @param  mixed  $object
     * @return void
     */
    protected function fireResolvingHooks(string $abstract, mixed $object): void
    {
        $this->fireHookArray($object, $this->global_resolving_callbacks);

        $this->fireHookArray(
            $object, $this->getHooksForType($abstract, $object, $this->resolving_callbacks)
        );

        $this->firePostResolvingHooks($abstract, $object);
    }

    /**
     * Fire every after-resolving callbacks.
     *
     * @param string $abstract
     * @param  mixed  $object
     * @return void
     */
    protected function firePostResolvingHooks(string $abstract, mixed $object): void
    {
        $this->fireHookArray($object, $this->global_after_resolving_callbacks);

        $this->fireHookArray(
            $object, $this->getHooksForType($abstract, $object, $this->after_resolving_callbacks)
        );
    }

    /**
     * Get all callbacks for a given type.
     *
     * @param string $abstract
     * @param mixed $object
     * @param array $callbacks_per_type
     * @return array
     */
    protected function getHooksForType(string $abstract, mixed $object, array $callbacks_per_type): array
    {
        $results = [];

        foreach ($callbacks_per_type as $type => $callbacks) {
            if ($type === $abstract || $object instanceof $type) {
                $results = array_merge($results, $callbacks);
            }
        }

        return $results;
    }

    /**
     * Fire an array of callbacks with an object.
     *
     * @param mixed $object
     * @param array $callbacks
     * @return void
     */
    protected function fireHookArray(mixed $object, array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            $callback($object, $this);
        }
    }

    /**
     * Determine if the given concrete is buildable.
     *
     * @param  mixed  $concrete
     * @param string $abstract
     * @return bool
     */
    protected function isBuildable(mixed $concrete, string $abstract): bool
    {
        return $concrete === $abstract || $concrete instanceof Closure;
    }

    /**
     * Fire every before-resolving callback.
     *
     * @param string $abstract
     * @param array $parameters
     * @return void
     */
    protected function firePreResolveHooks(string $abstract, array $parameters = []): void
    {
        $this->firePreResolveHookArray($abstract, $parameters, $this->global_before_resolving_callbacks);

        foreach ($this->before_resolving_callbacks as $type => $callbacks)
        {
            if ($type === $abstract || is_subclass_of($abstract, $type))
            {
                $this->firePreResolveHookArray($abstract, $parameters, $callbacks);
            }
        }
    }

    /**
     * Fire an array of callbacks with an object.
     *
     * @param string $abstract
     * @param array $parameters
     * @param array $callbacks
     * @return void
     */
    protected function firePreResolveHookArray(string $abstract, array $parameters, array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            if(is_callable($callback)) {
                $callback($abstract, $parameters, $this);
            }
        }
    }

    /**
     * Get the contextual concrete binding for the given abstract.
     *
     * @param callable|string $abstract
     * @return callable|string|array|null
     */
    protected function getContextualConcrete(callable|string $abstract): callable|array|string|null
    {
        if (! is_null($binding = $this->findInContextualBindings($abstract))) {
            return $binding;
        }

        // Next we need to see if a contextual binding might be bound under an alias of the
        // given abstract type. So, we will need to check if any aliases exist with this
        // type and then spin through them and check for contextual bindings on these.
        if (!empty($this->abstract_aliases[$abstract]))
        {
            foreach ($this->abstract_aliases[$abstract] as $alias)
            {
                if (! is_null($binding = $this->findInContextualBindings($alias))) {
                    return $binding;
                }
            }
        }

        return null;
    }

    /**
     * Find the concrete binding for the given abstract in the contextual binding array.
     *
     * @param callable|string $abstract
     * @return array|callable|string|null
     */
    protected function findInContextualBindings(callable|string $abstract): array|callable|string|null
    {
        return $this->contextual[end($this->build_stack)][$abstract] ?? null;
    }

    /**
     * Get the concrete type for a given abstract.
     *
     * @param callable|string $abstract
     * @return mixed
     * @throws ReflectionException
     */
    protected function getConcrete(callable|string $abstract): mixed
    {
        // If we don't have a registered resolver or concrete for the type, we'll just
        // assume each type is a concrete name and will attempt to resolve it as is
        // since the container should be able to resolve concretes automatically.
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        if ($this->environment_resolver === null ||
            ($this->checked_for_attribute_bindings[$abstract] ?? false) || ! is_string($abstract)) {
            return $abstract;
        }

        return $this->getConcreteBindingFromAttributes($abstract);
    }

    /**
     * Get the concrete binding for an abstract from the Bind attribute.
     *
     * @param string $abstract
     * @return mixed
     * @throws ReflectionException
     */
    protected function getConcreteBindingFromAttributes(string $abstract): mixed
    {
        $this->checked_for_attribute_bindings[$abstract] = true;

        try {
            $reflected = new ReflectionClass($abstract);
        } catch (ReflectionException) {
            return $abstract;
        }

        $bind_attributes = $reflected->getAttributes(Bind::class);

        if ($bind_attributes === []) {
            return $abstract;
        }

        $concrete = $maybe_concrete = null;

        foreach ($bind_attributes as $reflected_attribute) {
            $instance = $reflected_attribute->newInstance();

            if ($instance->environments === ['*']) {
                $maybe_concrete = $instance->concrete;

                continue;
            }

            if ($this->currentEnvironmentIs($instance->environments)) {
                $concrete = $instance->concrete;
                break;
            }
        }

        if ($maybe_concrete !== null && $concrete === null) {
            $concrete = $maybe_concrete;
        }

        if ($concrete === null) {
            return $abstract;
        }

        match ($this->getScopedTyped($reflected)) {
            'scoped' => $this->registerScoped($abstract, $concrete),
            'singleton' => $this->registerSingleton($abstract, $concrete),
            null => $this->bind($abstract, $concrete),
        };

        return $this->bindings[$abstract]['concrete'];
    }

    /**
     * Determine if a ReflectionClass has scoping attributes applied.
     *
     * @param  ReflectionClass<object>|class-string  $reflection
     * @return "singleton"|"scoped"|null
     */
    protected function getScopedTyped(ReflectionClass|string $reflection): ?string
    {
        $className = $reflection instanceof ReflectionClass
            ? $reflection->getName()
            : $reflection;

        if (array_key_exists($className, $this->checked_for_singleton_or_scoped_attributes)) {
            return $this->checked_for_singleton_or_scoped_attributes[$className];
        }

        try {
            $reflection = $reflection instanceof ReflectionClass
                ? $reflection
                : new ReflectionClass($reflection);
        } catch (ReflectionException) {
            return $this->checked_for_singleton_or_scoped_attributes[$className] = null;
        }

        $type = null;

        if (! empty($reflection->getAttributes(Singleton::class))) {
            $type = 'singleton';
        } elseif (! empty($reflection->getAttributes(Scoped::class))) {
            $type = 'scoped';
        }

        return $this->checked_for_singleton_or_scoped_attributes[$className] = $type;
    }

    /**
     * Get the extender callbacks for a given type.
     *
     * @param string $abstract
     * @return array
     */
    protected function getExtenders(string $abstract): array
    {
        return $this->extenders[$this->getAlias($abstract)] ?? [];
    }

    /**
     * Register a binding with the container based on the given Closure's return types.
     *
     * @param callable|string $abstract
     * @param callable|string|null $concrete
     * @param bool $shared
     * @return void
     * @throws ReflectionException
     */
    protected function bindBasedOnClosureReturnTypes(callable|string $abstract, callable|string|null $concrete = null, bool $shared = false): void
    {
        $abstracts = $this->closureReturnTypes($abstract);

        $concrete = $abstract;

        foreach ($abstracts as $abstract) {
            $this->bind($abstract, $concrete, $shared);
        }
    }

    /**
     * Drop every stale instance and alias.
     *
     * @param string $abstract
     * @return void
     */
    protected function dropStaleInstances(string $abstract): void
    {
        unset($this->instances[$abstract], $this->aliases[$abstract]);
    }

    /**
     * Get the Closure to be used when building a type.
     *
     * @param string $abstract
     * @param string $concrete
     * @return callable
     */
    protected function getClosure(string $abstract, string $concrete): callable
    {
        return function ($container, $parameters = []) use ($abstract, $concrete) {
            if ($abstract == $concrete) {
                return $container->build($concrete);
            }

            return $container->resolve(
                $concrete, $parameters, fire_hooks: false
            );
        };
    }

    /**
     * Get the last parameter override.
     *
     * @return array
     */
    protected function getLastParameterOverride(): array
    {
        return count($this->with) ? array_last($this->with) : [];
    }

    /**
     * Throw an exception that the concrete is not instantiable.
     *
     * @param string $concrete
     * @return never
     *
     * @throws DataBindingException
     */
    protected function notInstantiable(string $concrete): never
    {
        if (! empty($this->build_stack)) {
            $previous = implode(', ', $this->build_stack);

            $message = "Target [$concrete] is not instantiable while building [$previous].";
        } else {
            $message = "Target [$concrete] is not instantiable.";
        }

        throw new DataBindingException($message);
    }

    /**
     * Instantiate a concrete instance of the given self building type.
     *
     * @template TClass of object
     *
     * @param object{'newInstance': \Closure(static, array): TClass|class-string<TClass>} $concrete
     * @param ReflectionClass $reflector
     * @return TClass
     *
     * @throws DataBindingException
     */
    protected function buildSelfBuildingInstance(object $concrete, ReflectionClass $reflector): object
    {
        if (! method_exists($concrete, 'newInstance')) {
            throw new DataBindingException("No newInstance method exists for [$concrete].");
        }

        $this->build_stack[] = $concrete;

        $instance = $this->call([$concrete, 'newInstance']);

        array_pop($this->build_stack);

        $this->firePostResolveAttributeCallbacks(
            $reflector->getAttributes(), $instance
        );

        return $instance;
    }

    /**
     * Resolve every dependency from the ReflectionParameters.
     *
     * @param  ReflectionParameter[]  $dependencies
     * @return array
     *
     * @throws DataBindingException|ReflectionException
     */
    protected function resolveDependencies(array $dependencies): array
    {
        $results = [];

        foreach ($dependencies as $dependency) {
            // If the dependency has an override for this particular build we will use
            // that instead as the value. Otherwise, we will continue with this run
            // of resolutions and let reflection attempt to determine the result.
            if ($this->hasParameterOverride($dependency)) {
                $results[] = $this->getParameterOverride($dependency);

                continue;
            }

            $result = null;

            if (! is_null($attribute = Util::getContextualAttributeFromDependency($dependency))) {
                $result = $this->resolveFromAttribute($attribute);
            }

            // If the class is null, it means the dependency is a string or some other
            // primitive type which we can not resolve since it is not a class and
            // we will just bomb out with an error since we have no-where to go.
            $result ??= is_null($className = Util::getParameterClassName($dependency))
                ? $this->resolvePrimitive($dependency)
                : $this->resolveClass($dependency, $className);

            $this->firePostResolveAttributeCallbacks($dependency->getAttributes(), $result);

            if ($dependency->isVariadic()) {
                $results = array_merge($results, $result);
            } else {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Determine if the given dependency has a parameter override.
     *
     * @param ReflectionParameter $dependency
     * @return bool
     */
    protected function hasParameterOverride(ReflectionParameter $dependency): bool
    {
        return array_key_exists(
            $dependency->name, $this->getLastParameterOverride()
        );
    }

    /**
     * Get a parameter override for a dependency.
     *
     * @param ReflectionParameter $dependency
     * @return mixed
     */
    protected function getParameterOverride(ReflectionParameter $dependency): mixed
    {
        return $this->getLastParameterOverride()[$dependency->name];
    }

    /**
     * Resolve a class based dependency from the container.
     *
     * @param ReflectionParameter $parameter
     * @param string|null $className
     * @return mixed
     *
     * @throws ReflectionException
     */
    protected function resolveClass(ReflectionParameter $parameter, ?string $className = null): mixed
    {
        $className ??= Util::getParameterClassName($parameter);

        // First we will check if a default value has been defined for the parameter.
        // If it has, and no explicit binding exists, we should return it to avoid
        // overriding any of the developer specified defaults for the parameters.
        if ($parameter->isDefaultValueAvailable() &&
            ! $this->isBound($className) &&
            $this->findInContextualBindings($className) === null) {
            return $parameter->getDefaultValue();
        }

        try {
            return $parameter->isVariadic()
                ? $this->resolveVariadicClass($parameter)
                : $this->make($className);
        }
        catch (DataBindingException|ReflectionException $e) {
            // If we can not resolve the class instance, we will check to see if the value
            // is variadic. If it is, we will return an empty array as the value of the
            // dependency similarly to how we handle scalar values in this situation.

            if ($parameter->isVariadic()) {
                array_pop($this->with);

                return [];
            }

            throw $e;
        }

    }

    /**
     * Resolve a class based variadic dependency from the container.
     *
     * @param ReflectionParameter $parameter
     * @return mixed
     * @throws ReflectionException
     */
    protected function resolveVariadicClass(ReflectionParameter $parameter): mixed
    {
        $className = Util::getParameterClassName($parameter);

        $abstract = $this->getAlias($className);

        if (! is_array($concrete = $this->getContextualConcrete($abstract))) {
            return $this->make($className);
        }

        return array_map(fn ($abstract) => $this->resolve($abstract), $concrete);
    }

    /**
     * Resolve a non-class hinted primitive dependency.
     *
     * @param ReflectionParameter $parameter
     * @return mixed
     *
     */
    protected function resolvePrimitive(ReflectionParameter $parameter): mixed
    {
        if (! is_null($concrete = $this->getContextualConcrete('$'.$parameter->getName()))) {
            return Util::unwrapIfClosure($concrete, $this);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->isVariadic()) {
            return [];
        }

        if ($parameter->hasType() && $parameter->allowsNull()) {
            return null;
        }

        $this->unresolvablePrimitive($parameter);
    }

    /**
     * Throw an exception for an unresolvable primitive.
     *
     * @param ReflectionParameter $parameter
     * @return never
     *
     */
    protected function unresolvablePrimitive(ReflectionParameter $parameter): never
    {
        $message = "Unresolvable dependency resolving [$parameter] in class {$parameter->getDeclaringClass()->getName()}";

        throw new DataBindingException($message);
    }


}