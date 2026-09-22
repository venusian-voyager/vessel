<?php

namespace Voyager\Vessel\Concerns;

trait AliasManagement
{
    /**
     * The registered type aliases.
     *
     * @var string[]
     */
    protected array $aliases = [];

    /**
     * The registered aliases keyed by the abstract name.
     *
     * @var array[]
     */
    protected array $abstract_aliases = [];

    /**
     * Determine if a given string is an alias.
     *
     * @param string $name
     * @return bool
     */
    public function isAlias(string $name): bool
    {
        return isset($this->aliases[$name]);
    }

    /**
     * Get the alias for an abstract if available.
     *
     * @param string $abstract
     * @return string
     */
    public function getAlias(string $abstract): string
    {
        return isset($this->aliases[$abstract])
            ? $this->getAlias($this->aliases[$abstract])
            : $abstract;
    }

    /**
     * Remove an alias from the contextual binding alias cache.
     *
     * @param  string  $searched
     * @return void
     */
    protected function removeAbstractAlias(string $searched): void
    {
        if (isset($this->aliases[$searched])) {
            foreach ($this->abstract_aliases as $abstract => $aliases)
            {
                foreach ($aliases as $index => $alias)
                {
                    if ($alias == $searched) {
                        unset($this->abstract_aliases[$abstract][$index]);
                    }
                }
            }
        }
    }
}