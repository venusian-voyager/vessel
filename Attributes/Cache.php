<?php

namespace Voyager\Vessel\Attributes;

use Attribute;
use Voyager\Contracts\Vessel\Vessel;
use Voyager\Contracts\Vessel\ContextualAttribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Cache implements ContextualAttribute
{
    /**
     * Create a new class instance.
     */
    public function __construct(public ?string $store = null)
    {
    }

    /**
     * Resolve the cache store.
     *
     * @param  self  $attribute
     * @param  \Voyager\Contracts\Vessel\Vessel  $vessel
     * @return \Voyager\Contracts\Cache\Repository
     */
    public static function resolve(self $attribute, Vessel $vessel): mixed
    {
        return $vessel->make('cache')->store($attribute->store);
    }
}
