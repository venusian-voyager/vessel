<?php

namespace Voyager\Vessel\Attributes;

use Attribute;
use Voyager\Contracts\Vessel\Vessel;
use Voyager\Contracts\Vessel\ContextualAttribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Give implements ContextualAttribute
{
    /**
     * Provide a concrete class implementation for dependency injection.
     *
     * @param  string  $class
     * @param  array|null  $params
     */
    public function __construct(
        public string $class,
        public array $params = [],
    ) {
    }

    /**
     * Resolve the dependency.
     *
     * @param  self  $attribute
     * @param  \Voyager\Contracts\Vessel\Vessel  $vessel
     * @return mixed
     */
    public static function resolve(self $attribute, Vessel $vessel): mixed
    {
        return $vessel->make($attribute->class, $attribute->params);
    }
}
