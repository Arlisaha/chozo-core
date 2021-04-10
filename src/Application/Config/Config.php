<?php

namespace Arlisaha\Chozo\Application\Config;

use Arlisaha\Chozo\Application\Config\Parameters\ParametersInterface;
use Arlisaha\Chozo\Application\Config\Settings\SettingsInterface;
use Psr\Container\ContainerInterface;

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
     * @param ContainerInterface $c
     */
    public function __construct(ContainerInterface $c)
    {
        $this->parameters = $c->get(ParametersInterface::class);
        $this->settings   = $c->get(SettingsInterface::class);
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