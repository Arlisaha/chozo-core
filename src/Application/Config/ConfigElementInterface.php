<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Application\Config;

interface ConfigElementInterface
{
    /**
     * @param string $key
     *
     * @return mixed
     */
    public function get(string $key);

    /**
     * @param string $key
     *
     * @return bool
     */
    public function has(string $key): bool;
}