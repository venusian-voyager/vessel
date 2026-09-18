<?php

namespace Voyager\Vessel\Attributes;

use Attribute;
use Voyager\Contracts\Vessel\Vessel;
use Voyager\Contracts\Vessel\ContextualAttribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Config implements ContextualAttribute
{
    /**
     * Create a new class instance.
     */
    public function __construct(public string $key, public mixed $default = null)
    {
    }

    /**
     * Resolve the configuration value.
     *
     * @param  self  $attribute
     * @param  Vessel $vessel
     * @return mixed
     */
    public static function resolve(self $attribute, Vessel $vessel): mixed
    {
        return $vessel->make('config')->get($attribute->key, $attribute->default);
    }
}
