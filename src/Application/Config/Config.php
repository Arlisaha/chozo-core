<?php

namespace Arlisaha\Chozo\Application\Config;

use Arlisaha\Chozo\Application\Config\Parameters\ParametersInterface;
use Arlisaha\Chozo\Application\Config\Settings\SettingsInterface;

class Config implements ConfigInterface
{
    /**
     * @var ParametersInterface
     */
    private $parameters;

    /**
     * @var SettingsInterface
     */
    private $settings;

    /**
     * Config constructor.
     *
     * @param SettingsInterface   $settings
     * @param ParametersInterface $parameters
     */
    public function __construct(SettingsInterface $settings, ParametersInterface $parameters)
    {
        $this->parameters = $parameters;
        $this->settings   = $settings;
    }

    /**
     * @inheritDoc
     */
    public function getSetting(string $key)
    {
        return $this->settings->get($key);
    }

    /**
     * @inheritDoc
     */
    public function hasSetting(string $key): bool
    {
        return $this->settings->has($key);
    }

    /**
     * @inheritDoc
     */
    public function getParameter(string $key)
    {
        return $this->parameters->get($key);
    }

    /**
     * @inheritDoc
     */
    public function hasParameter(string $key): bool
    {
        return $this->parameters->has($key);
    }
}