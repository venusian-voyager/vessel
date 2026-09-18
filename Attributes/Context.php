<?php

namespace Voyager\Vessel\Attributes;

use Attribute;
use Voyager\Contracts\Vessel\Vessel;
use Voyager\Contracts\Vessel\ContextualAttribute;
use Voyager\Log\Context\Repository;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Context implements ContextualAttribute
{
    /**
     * Create a new attribute instance.
     */
    public function __construct(public string $key, public mixed $default = null, public bool $hidden = false)
    {
    }

    /**
     * Resolve the context value.
     *
     * @param  self  $attribute
     * @param  \Voyager\Contracts\Vessel\Vessel  $vessel
     * @return mixed
     */
    public static function resolve(self $attribute, Vessel $vessel): mixed
    {
        $repository = $vessel->make(Repository::class);

        return match ($attribute->hidden) {
            true => $repository->getHidden($attribute->key, $attribute->default),
            false => $repository->get($attribute->key, $attribute->default),
        };
    }
}
