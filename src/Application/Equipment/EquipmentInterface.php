<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Application\Equipment;

use Symfony\Component\Cache\Adapter\AdapterInterface;

interface EquipmentInterface
{
    public const SETTINGS_KEY = '';

    /**
     * EquipmentInterface constructor.
     *
     * @param array|null       $settings
     * @param AdapterInterface $cacheHandler
     * @param bool             $isDebug
     */
    public function __construct(?array $settings, AdapterInterface $cacheHandler, bool $isDebug);

    /**
     * @return array<string,callable>
     */
    public function getServices(): array;

    /**
     * @return string[]
     */
    public function getMiddlewareClassnames(): array;

    /**
     * @return string[]
     */
    public function getCommandNamespaces(): array;

    /**
     * @return string[]
     */
    public function getControllerNamespaces(): array;
}