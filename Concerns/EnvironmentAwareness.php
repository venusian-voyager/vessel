<?php

namespace Voyager\Vessel\Concerns;

trait EnvironmentAwareness
{
    /**
     * The callback used to determine the container's environment.
     *
     * @var (callable(array<int, string>|string): bool|string)|null
     */
    protected $environment_resolver = null;

    /**
     * Determine the environment for the container.
     *
     * @param string|array<int, string> $environments
     * @return bool
     */
    public function currentEnvironmentIs(array|string $environments): bool
    {
        return $this->environment_resolver === null
            ? false
            : call_user_func($this->environment_resolver, $environments);
    }
}