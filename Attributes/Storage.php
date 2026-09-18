<?php

namespace Voyager\Vessel\Attributes;

use Attribute;
use Voyager\Contracts\Vessel\Vessel;
use Voyager\Contracts\Filesystem\Filesystem;
use Voyager\Contracts\Vessel\ContextualAttribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Storage implements ContextualAttribute
{
    /**
     * Create a new class instance.
     */
    public function __construct(public ?string $disk = null)
    {
    }

    /**
     * Resolve the storage disk.
     *
     * @param  self  $attribute
     * @param  Vessel  $vessel
     * @return Filesystem
     */
    public static function resolve(self $attribute, Vessel $vessel): Filesystem
    {
        return $vessel->make('filesystem')->disk($attribute->disk);
    }
}
