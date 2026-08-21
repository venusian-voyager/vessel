<?php

namespace Voyager\Vessel\Attributes;

use Attribute;
use Voyager\Contracts\Vessel\Vessel;
use Voyager\Contracts\Vessel\ContextualAttribute;
use UnitEnum;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Database implements ContextualAttribute
{
    /**
     * Create a new class instance.
     */
    public function __construct(public UnitEnum|string|null $connection = null)
    {
    }

    /**
     * Resolve the database connection.
     *
     * @param  self  $attribute
     * @param  \Voyager\Contracts\Vessel\Vessel  $vessel
     * @return \Voyager\Database\Connection
     */
    public static function resolve(self $attribute, Vessel $vessel): mixed
    {
        return $vessel->make('db')->connection($attribute->connection);
    }
}
