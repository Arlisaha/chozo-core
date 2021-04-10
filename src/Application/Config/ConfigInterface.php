<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Application\Config;

interface ConfigInterface
{
    /**
     * @param string $key
     *
     * @return mixed
     */
    public function getSetting(string $key);

    /**
     * @param string $key
     *
     * @return bool
     */
    public function hasSetting(string $key): bool;
    /**
     * @param string $key
     *
     * @return mixed
     */
    public function getParameter(string $key);

    /**
     * @param string $key
     *
     * @return bool
     */
    public function hasParameter(string $key): bool;
}