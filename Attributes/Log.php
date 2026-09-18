<?php

namespace Voyager\Vessel\Attributes;

use Attribute;
use Psr\Log\LoggerInterface;
use Voyager\Contracts\Vessel\Vessel;
use Voyager\Contracts\Vessel\ContextualAttribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Log implements ContextualAttribute
{
    /**
     * Create a new class instance.
     */
    public function __construct(public ?string $channel = null)
    {
    }

    /**
     * Resolve the log channel.
     *
     * @param  self  $attribute
     * @param Vessel $vessel
     * @return LoggerInterface
     */
    public static function resolve(self $attribute, Vessel $vessel): LoggerInterface
    {
        return $vessel->make('log')->channel($attribute->channel);
    }
}
