<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Application\Kernel;

use Arlisaha\Chozo\Application\Config\Config;
use Arlisaha\Chozo\Application\Config\ConfigInterface;
use Arlisaha\Chozo\Application\Config\Parameters\Parameters;
use Arlisaha\Chozo\Application\Config\Parameters\ParametersInterface;
use Arlisaha\Chozo\Application\Config\Settings\Settings;
use Arlisaha\Chozo\Application\Config\Settings\SettingsInterface;
use Arlisaha\Chozo\Application\PathBuilder\PathBuilder;
use Arlisaha\Chozo\Application\PathBuilder\PathBuilderInterface;
use Arlisaha\Chozo\Exception\CacheDirectoryException;
use Arlisaha\Chozo\Exception\ConfigFileException;
use Arlisaha\Chozo\Exception\KernelNotCreatedException;
use Arlisaha\Chozo\Exception\MissingConfigKeyException;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Symfony\Component\Yaml\Yaml;
use function array_key_exists;
use function array_merge;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_string;
use function md5_file;
use function serialize;
use function unserialize;

abstract class AbstractKernel
{
    public const SETTINGS_KEY = 'kernel';
    public const SETTINGS_DEBUG_KEY = 'debug';

    protected const CACHE_DIRECTORY_PERMISSION = 0744;
    protected const SERVICES = [];

    /**
     * @var self
     */
    private static $instance;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @param string $rootDir
     *
     * @throws KernelNotCreatedException|CacheDirectoryException
     *
     * @return static
     */
    public static function create(string $rootDir)
    {
        if (!static::$instance) {
            static::$instance = new static($rootDir);
        }

        return static::get();
    }

    /**
     * @throws KernelNotCreatedException
     *
     * @return static
     */
    public static function get()
    {
        if (!static::$instance) {
            throw new KernelNotCreatedException();
        }

        return static::$instance;
    }

    /**
     * AbstractKernel constructor.
     *
     * @param string $rootDir
     *
     * @throws CacheDirectoryException
     */
    private function __construct(string $rootDir)
    {
        $pathBuilder    = new PathBuilder($rootDir);
        $cacheDirectory = $this->handleCacheDirectory($pathBuilder);
        [SettingsInterface::class => $settings, ParametersInterface::class => $parameters] = $this->handleConfig($pathBuilder);
        if (!array_key_exists(static::SETTINGS_KEY, $settings)) {
            throw new MissingConfigKeyException(static::SETTINGS_KEY);
        }
        if (!array_key_exists(static::SETTINGS_DEBUG_KEY, $settings[static::SETTINGS_KEY])) {
            throw new MissingConfigKeyException(static::SETTINGS_DEBUG_KEY, static::SETTINGS_KEY);
        }

        $containerBuilder = new ContainerBuilder();
        if ($settings[static::SETTINGS_KEY][static::SETTINGS_DEBUG_KEY]) {
            $containerBuilder->enableCompilation($cacheDirectory);
        }

        $containerBuilder->addDefinitions(array_merge(
            $this->getContainerConfigDefinitions($settings, $parameters),
            $this->getPathUtilsDefinition($pathBuilder),
            $this->getConfiguredServicesDefinitions(),
            $this->getServicesDefinitions()
        ));

        $this->container = $containerBuilder->build();
    }

    private function __clone()
    {
    }

    /**
     * @param PathBuilder $pathBuilder
     * @throws CacheDirectoryException
     * @return string
     */
    private function handleCacheDirectory(PathBuilder $pathBuilder): string
    {
        $cacheDirectory = $this->getCacheDirectory($pathBuilder);
        if (!file_exists($cacheDirectory) && !mkdir($cacheDirectory, static::CACHE_DIRECTORY_PERMISSION, true)) {
            throw new CacheDirectoryException();
        }

        return $cacheDirectory;
    }

    /**
     * @param PathBuilder $pathBuilder
     * @return array
     */
    private function handleConfig(PathBuilder $pathBuilder): array
    {
        $cacheDir       = $this->getCacheDirectory($pathBuilder);
        $parametersPath = $this->getParametersFilePath($pathBuilder);
        $settingsPath   = $this->getSettingsFilePath($pathBuilder);

        if (!file_exists($parametersPath) || !file_exists($settingsPath)) {
            throw new ConfigFileException();
        }

        $paramsSum            = md5_file($parametersPath);
        $settingsSum          = md5_file($settingsPath);
        $cachedConfigFilePath = $pathBuilder->getAbsolutePathFromArray([$cacheDir, "config.$paramsSum.$settingsSum"]);

        if (!file_exists($cachedConfigFilePath)) {
            $parameters      = Yaml::parseFile($parametersPath);
            $replaceCallback = static function (array $match) use ($parameters) {
                if (!array_key_exists($match[1], $parameters)) {
                    return $match[0];
                }

                return $parameters[$match[1]];
            };
            $pattern         = '~%(.+?)%~';
            $rawParameters   = file_get_contents($parametersPath);
            do {
                $rawParameters = preg_replace_callback($pattern, $replaceCallback, $rawParameters);
            } while (preg_match($pattern, $rawParameters));

            $parameters = Yaml::parse($rawParameters, Yaml::PARSE_CONSTANT);
            $settings   = Yaml::parse(
                preg_replace_callback(
                    $pattern,
                    $replaceCallback,
                    file_get_contents($settingsPath)
                ),
                Yaml::PARSE_CONSTANT
            );

            file_put_contents(
                $cachedConfigFilePath,
                serialize([SettingsInterface::class => $settings, ParametersInterface::class => $parameters])
            );
        }

        return unserialize($cachedConfigFilePath);
    }

    /**
     * @param PathBuilder $pathBuilder
     *
     * @return string
     */
    protected function getCacheDirectory(PathBuilder $pathBuilder): string
    {
        return $pathBuilder->getAbsolutePath('/var/cache');
    }

    /**
     * @param PathBuilder $pathBuilder
     *
     * @return string
     */
    protected function getSettingsFilePath(PathBuilder $pathBuilder): string
    {
        return $pathBuilder->getAbsolutePath('/config/settings.yml');
    }

    /**
     * @param PathBuilder $pathBuilder
     *
     * @return string
     */
    protected function getParametersFilePath(PathBuilder $pathBuilder): string
    {
        return $pathBuilder->getAbsolutePath('/config/parameters.yml');
    }

    /**
     * @param array $settings
     * @param array $parameters
     *
     * @return array
     */
    protected function getContainerConfigDefinitions(array $settings, array $parameters): array
    {
        return [
            SettingsInterface::class   => function () use ($settings) {
                return new Settings($settings);
            },
            ParametersInterface::class => function () use ($parameters) {
                return new Parameters($parameters);
            },
            ConfigInterface::class     => function (ContainerInterface $container) {
                return new Config($container);
            },
        ];
    }

    /**
     * @param PathBuilder $pathBuilder
     * @return array
     */
    protected function getPathUtilsDefinition(PathBuilder $pathBuilder): array
    {
        return [
            PathBuilderInterface::class => $pathBuilder,
        ];
    }

    /**
     * @param array|null $servicesDefinitions
     *
     * @return array
     */
    protected function getConfiguredServicesDefinitions(?array $servicesDefinitions = null): array
    {
        if (!$servicesDefinitions) {
            $servicesDefinitions = static::SERVICES;
        }

        $definitions = [];
        foreach ($servicesDefinitions as $configKey => $className) {
            if (!is_array($className)) {
                $className = [$className];
            }

            foreach ($className as $fqcn) {
                $definitions[$fqcn] = function (ContainerInterface $container) use ($fqcn, $configKey) {
                    if (!is_string($configKey)) {
                        return new $fqcn($container);
                    }

                    return new $fqcn($container, $container->get(ConfigInterface::class)->getSetting($configKey));
                };
            }
        }

        return $definitions;
    }

    /**
     * @return array
     */
    protected function getServicesDefinitions(): array
    {
        return [];
    }

    /**
     * @return ContainerInterface
     */
    final protected function getContainer(): ContainerInterface
    {
        return $this->container;
    }
}