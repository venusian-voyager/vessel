<?php

declare(strict_types=1);

namespace Voyager\Vessel\Attributes;

use Attribute;
use Voyager\Contracts\Vessel\Vessel;
use Voyager\Contracts\Vessel\ContextualAttribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Tag implements ContextualAttribute
{
    public function __construct(
        public string $tag,
    ) {
    }

    /**
     * Resolve the tag.
     *
     * @param  self  $attribute
     * @param  Vessel $vessel
     * @return iterable
     */
    public static function resolve(self $attribute, Vessel $vessel): iterable
    {
        return $vessel->tagged($attribute->tag);
    }
}
