<?php

namespace Voyager\Vessel;

use Voyager\Contracts\Vessel\TheServiceContainer;
use Voyager\Contracts\Vessel\ContextualBindingBuilder as ContextualBindingBuilderContract;

class ContextualBindingBuilder implements ContextualBindingBuilderContract
{
    /**
     * The underlying vessel instance.
     *
     * @var TheServiceContainer
     */
    protected TheServiceContainer $vessel;

    /**
     * The concrete instance.
     *
     * @var string|array
     */
    protected array|string $concrete;

    /**
     * The abstract target.
     *
     * @var string|null
     */
    protected ?string $needs = null;

    /**
     * Create a new contextual binding builder.
     *
     * @param TheServiceContainer $vessel
     * @param  string|array  $concrete
     */
    public function __construct(TheServiceContainer $vessel, array|string $concrete)
    {
        $this->concrete = $concrete;
        $this->vessel = $vessel;
    }

    /**
     * Define the abstract target that depends on the context.
     *
     * @param  string  $abstract
     * @return $this
     */
    public function needs(string $abstract): static
    {
        $this->needs = $abstract;

        return $this;
    }

    /**
     * Define the implementation for the contextual binding.
     *
     * @param  callable|string|array  $implementation
     * @return $this
     */
    public function give(array|callable|string $implementation): static
    {
        foreach (Util::arrayWrap($this->concrete) as $concrete) {
            $this->vessel->addContextualBinding($concrete, $this->needs, $implementation);
        }

        return $this;
    }

    /**
     * Define tagged services to be used as the implementation for the contextual binding.
     *
     * @param  string  $tag
     * @return $this
     */
    public function giveTagged(string $tag): static
    {
        return $this->give(function ($vessel) use ($tag) {
            $taggedServices = $vessel->tagged($tag);

            return is_array($taggedServices) ? $taggedServices : iterator_to_array($taggedServices);
        });
    }

    /**
     * Specify the configuration item to bind as a primitive.
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return $this
     */
    public function giveConfig(string $key, mixed $default = null): static
    {
        return $this->give(fn ($vessel) => $vessel->get('config')->get($key, $default));
    }
}
