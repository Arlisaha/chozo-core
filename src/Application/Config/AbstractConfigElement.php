<?php

namespace Arlisaha\Chozo\Application\Config;

use Arlisaha\Chozo\Exception\ConfigValueException;
use Throwable;

abstract class AbstractConfigElement implements ConfigElementInterface
{
    protected const DEEP_KEY_SEPARATOR = '.';

    /**
     * @var array
     */
    private $configElement;

    /**
     * Settings constructor.
     * @param array $configElement
     */
    public function __construct(array $configElement)
    {
        $this->configElement = $configElement;
    }

    /**
     * @param string $key
     *
     * @throws Throwable
     *
     * @return mixed
     */
    public function get(string $key)
    {
        return ($this->has($key, false) ? $this->configElement[$key] : $this->getDeep($key));
    }

    /**
     * @param string $key
     * @param bool   $checkDeep
     *
     * @return bool
     */
    public function has(string $key, bool $checkDeep = true): bool
    {
        if (!($key && $this->configElement)) {
            return false;
        }

        if (array_key_exists($key, $this->configElement)) {
            return true;
        }

        if (!$checkDeep) {
            return false;
        }

        try {
            $this->getDeep($key);
        } catch (Throwable $exception) {
            return false;
        }

        return true;
    }

    /**
     * @param string     $key
     * @param array|null $arr
     *
     * @throws Throwable
     *
     * @return mixed
     */
    protected function getDeep(string $key, array $arr = null)
    {
        if (null === $arr) {
            $arr = $this->configElement;
        }

        $parts = explode(static::DEEP_KEY_SEPARATOR, $key);
        $first = array_shift($parts);
        $rest  = implode(static::DEEP_KEY_SEPARATOR, $parts);

        if (array_key_exists($first, $arr)) {
            if (!is_array($arr[$first]) && $rest) {
                throw $this->getDeepException($rest);
            }

            if (array_key_exists($rest, $arr[$first])) {
                return $arr[$first][$rest];
            }

            return $this->getDeep($rest, $arr[$first]);
        }

        throw $this->getDeepException($first);
    }

    /**
     * @param string $key
     *
     * @return Throwable
     */
    protected function getDeepException(string $key): Throwable
    {
        return new ConfigValueException(sprintf('Unknown config deep key "%s".', $key));
    }
}