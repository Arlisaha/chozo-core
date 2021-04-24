<?php

namespace Arlisaha\Chozo\Application\Kernel;

use Arlisaha\Chozo\Exception\KernelNotCreatedException;
use Psr\Log\LoggerInterface;
use Throwable;

interface KernelInterface
{
    /**
     * @param string $rootDir
     *
     * @return static
     */
    public static function create(string $rootDir): KernelInterface;

    /**
     * @throws KernelNotCreatedException
     *
     * @return static
     */
    public static function get(): KernelInterface;

    /**
     * @return int
     */
    public static function run(): int;

    /**
     * @return bool
     */
    public function isCli(): bool;

    /**
     * @return bool
     */
    public function isRunning(): bool;

    /**
     * @return int
     */
    public function runApp(): int;

    /**
     * @return LoggerInterface|null
     */
    public function getLogger(): ?LoggerInterface;

    /**
     * @param Throwable $exception
     */
    public function handleErrorOutsideApplication(Throwable $exception): void;
}