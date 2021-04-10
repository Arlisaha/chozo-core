<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Application\Kernel;

use Arlisaha\Chozo\Application\Config\Config;
use Arlisaha\Chozo\Application\Config\ConfigInterface;
use Arlisaha\Chozo\Application\Config\Parameters\Parameters;
use Arlisaha\Chozo\Application\Config\Parameters\ParametersInterface;
use Arlisaha\Chozo\Application\Config\Settings\Settings;
use Arlisaha\Chozo\Application\Config\Settings\SettingsInterface;
use Arlisaha\Chozo\Exception\CacheDirectoryException;
use Arlisaha\Chozo\Exception\ConfigFileException;
use Arlisaha\Chozo\Exception\KernelNotCreatedException;
use Arlisaha\Chozo\Exception\MissingConfigKeyException;
use DI\ContainerBuilder;
use Exception;
use Psr\Container\ContainerInterface;
use Symfony\Component\Yaml\Yaml;
use function array_key_exists;
use function array_merge;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function md5_file;
use function rtrim;
use function serialize;
use function unserialize;
use const DIRECTORY_SEPARATOR;

abstract class AbstractKernel
{
    public const SETTINGS_KEY = 'kernel';
    public const SETTINGS_DEBUG_KEY = 'debug';
    public const ROOT_DIR = 'root_dir';

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
    public static function create(string $rootDir): self
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
    public static function get(): self
    {
        if (!static::$instance) {
            throw new KernelNotCreatedException();
        }

        return static::$instance;
    }

    /**
     * AbstractKernel constructor.
     * @param string $rootDir
     *
     * @throws CacheDirectoryException
     * @throws Exception
     */
    private function __construct(string $rootDir)
    {
        $cacheDirectory = $this->handleCacheDirectory($rootDir);
        [SettingsInterface::class => $settings, ParametersInterface::class => $parameters] = $this->handleConfig($rootDir);
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
            $this->getPathUtilsDefinition($rootDir),
            $this->getContainerDefinitions()
        ));

        $this->container = $containerBuilder->build();
    }

    private function __clone()
    {
    }

    /**
     * @param string $rootDir
     *
     * @throws CacheDirectoryException
     *
     * @return string
     */
    private function handleCacheDirectory(string $rootDir): string
    {
        $cacheDirectory = $this->getCacheDirectory($rootDir);
        if (!file_exists($cacheDirectory) && !mkdir($cacheDirectory, 0744, true)) {
            throw new CacheDirectoryException();
        }

        return $cacheDirectory;
    }

    /**
     * @param string $rootDir
     *
     * @throws ConfigFileException
     *
     * @return array
     */
    private function handleConfig(string $rootDir): array
    {
        $cacheDir       = rtrim($this->getCacheDirectory($rootDir), '/\\' . DIRECTORY_SEPARATOR);
        $parametersPath = $this->getParametersFilePath($rootDir);
        $settingsPath   = $this->getSettingsFilePath($rootDir);

        if (!file_exists($parametersPath) || !file_exists($settingsPath)) {
            throw new ConfigFileException();
        }

        $paramsSum            = md5_file($parametersPath);
        $settingsSum          = md5_file($settingsPath);
        $cachedConfigFilePath = "$cacheDir/config.$paramsSum.$settingsSum";

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

            file_put_contents($cachedConfigFilePath, serialize([SettingsInterface::class => $settings, ParametersInterface::class => $parameters]));
        }

        return unserialize($cachedConfigFilePath);
    }

    /**
     * @param string $rootDir
     *
     * @return string
     */
    protected function getCacheDirectory(string $rootDir): string
    {
        return $rootDir . '/var/cache';
    }

    /**
     * @param string $rootDir
     *
     * @return string
     */
    protected function getSettingsFilePath(string $rootDir): string
    {
        return $rootDir . '/config/settings.yml';
    }

    /**
     * @param string $rootDir
     *
     * @return string
     */
    protected function getParametersFilePath(string $rootDir): string
    {
        return $rootDir . '/config/parameters.yml';
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
     * @param string $rootDir
     *
     * @return array
     */
    protected function getPathUtilsDefinition(string $rootDir): array
    {
        return [];//TODO
    }

    /**
     * @return array
     */
    protected function getContainerDefinitions(): array
    {
        return [
            //TODO - logger, mailer, orm
        ];
    }

    /**
     * @return ContainerInterface
     */
    final protected function getContainer(): ContainerInterface
    {
        return $this->container;
    }
}