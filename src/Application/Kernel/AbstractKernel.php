<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Application\Kernel;

use Arlisaha\Chozo\Application\Config\Config;
use Arlisaha\Chozo\Application\Config\ConfigInterface;
use Arlisaha\Chozo\Application\Config\Parameters\Parameters;
use Arlisaha\Chozo\Application\Config\Parameters\ParametersInterface;
use Arlisaha\Chozo\Application\Config\Settings\Settings;
use Arlisaha\Chozo\Application\Config\Settings\SettingsInterface;
use Arlisaha\Chozo\Application\Handlers\ShutdownHandler;
use Arlisaha\Chozo\Application\PathBuilder\PathBuilder;
use Arlisaha\Chozo\Application\PathBuilder\PathBuilderInterface;
use Arlisaha\Chozo\Controller\ControllerInterface;
use Arlisaha\Chozo\Exception\CacheDirectoryException;
use Arlisaha\Chozo\Exception\ConfigFileException;
use Arlisaha\Chozo\Exception\KernelNotCreatedException;
use Arlisaha\Chozo\Exception\MissingConfigKeyException;
use DI\Bridge\Slim\Bridge;
use DI\Container;
use DI\ContainerBuilder;
use DI\DependencyException;
use DI\NotFoundException;
use Exception;
use HaydenPierce\ClassFinder\ClassFinder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\Handlers\ErrorHandler;
use Slim\Interfaces\CallableResolverInterface;
use Slim\Interfaces\ErrorHandlerInterface;
use Slim\Interfaces\MiddlewareDispatcherInterface;
use Slim\Interfaces\RouteCollectorInterface;
use Slim\Interfaces\RouteResolverInterface;
use Slim\ResponseEmitter;
use Symfony\Component\Console\Application;
use Symfony\Component\Yaml\Yaml;
use function array_key_exists;
use function array_map;
use function array_merge;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_string;
use function md5_file;
use function register_shutdown_function;
use function serialize;
use function unserialize;
use const PHP_SAPI;

abstract class AbstractKernel
{
    public const SETTINGS_KEY = 'kernel';

    protected const CACHE_DIRECTORY_PERMISSION = 0744;
    protected const CACHE_DIR                  = '/var/cache';
    protected const SETTINGS_PATH              = '/config/settings.yml';
    protected const PARAMETERS_PATH            = '/config/parameters.yml';
    protected const SERVICES                   = []; //config_key => [services FQCN]
    protected const MIDDLEWARES                = []; // FQCN
    protected const CONTROLLERS                = []; // namespace

    /**
     * @var static
     */
    private static $instance;

    /**
     * @var Container
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
     * @throws Exception
     */
    private function __construct(string $rootDir)
    {
        $debugKey       = 'debug';
        $pathBuilder    = new PathBuilder($rootDir);
        $cacheDirectory = $this->handleCacheDirectory($pathBuilder);
        [SettingsInterface::class => $settings, ParametersInterface::class => $parameters] = $this->handleConfig($pathBuilder);
        if (!array_key_exists(static::SETTINGS_KEY, $settings)) {
            throw new MissingConfigKeyException(static::SETTINGS_KEY);
        }
        if (!array_key_exists($debugKey, $settings[static::SETTINGS_KEY])) {
            throw new MissingConfigKeyException($debugKey, static::SETTINGS_KEY);
        }

        $containerBuilder = new ContainerBuilder();
        if ($settings[static::SETTINGS_KEY][$debugKey]) {
            $containerBuilder->enableCompilation($cacheDirectory);
        }

        $containerBuilder->addDefinitions(array_merge(
            $this->getContainerConfigDefinitions($settings, $parameters),
            $this->getPathUtilsDefinition($pathBuilder),
            [
                ResponseFactoryInterface::class      => $this->getResponseFactory(),
                CallableResolverInterface::class     => $this->getCallableResolver(),
                RouteCollectorInterface::class       => $this->getRouteCollector(),
                RouteResolverInterface::class        => $this->getRouteResolver(),
                MiddlewareDispatcherInterface::class => $this->getMiddlewareDispatcher(),
            ],
            $this->getConfiguredServicesDefinitions(),
            $this->getServicesDefinitions()
        ));

        $this->container = $containerBuilder->build();
        ClassFinder::setAppRoot($pathBuilder->getRootDir());
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
     * @return bool
     */
    final protected function isCli(): bool
    {
        return 'cli' === PHP_SAPI;
    }

    /**
     * @return Container
     */
    final protected function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * @param PathBuilder $pathBuilder
     *
     * @return string
     */
    protected function getCacheDirectory(PathBuilder $pathBuilder): string
    {
        return $pathBuilder->getAbsolutePath(static::CACHE_DIR);
    }

    /**
     * @param PathBuilder $pathBuilder
     *
     * @return string
     */
    protected function getSettingsFilePath(PathBuilder $pathBuilder): string
    {
        return $pathBuilder->getAbsolutePath(static::SETTINGS_PATH);
    }

    /**
     * @param PathBuilder $pathBuilder
     *
     * @return string
     */
    protected function getParametersFilePath(PathBuilder $pathBuilder): string
    {
        return $pathBuilder->getAbsolutePath(static::PARAMETERS_PATH);
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
            ConfigInterface::class     => function (Container $container) {
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
                $definitions[$fqcn] = function (Container $container) use ($fqcn, $configKey) {
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
     * @return ResponseFactoryInterface
     */
    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return AppFactory::determineResponseFactory();
    }

    /**
     * @return CallableResolverInterface|null
     */
    protected function getCallableResolver(): ?CallableResolverInterface
    {
        return null;
    }

    /**
     * @return RouteCollectorInterface|null
     */
    protected function getRouteCollector(): ?RouteCollectorInterface
    {
        return null;
    }

    /**
     * @return RouteResolverInterface|null
     */
    protected function getRouteResolver(): ?RouteResolverInterface
    {
        return null;
    }

    /**
     * @return MiddlewareDispatcherInterface|null
     */
    protected function getMiddlewareDispatcher(): ?MiddlewareDispatcherInterface
    {
        return null;
    }

    /**
     * @param array|null $middlewares
     *
     * @return array
     */
    protected function getMiddlewares(?array $middlewares = null): array
    {
        if (!$middlewares) {
            $middlewares = static::MIDDLEWARES;
        }

        return $middlewares;
    }

    /**
     * @throws Exception
     *
     * @return int
     */
    protected function runAsCliApp(): int
    {
        $app = new Application();

        //TODO

        return $app->run();
    }

    /**
     * @return ResponseEmitter
     */
    protected function getResponseEmitter(): ResponseEmitter
    {
        return new ResponseEmitter();
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     *
     * @return ErrorHandlerInterface
     */
    protected function getErrorHandler(): ErrorHandlerInterface
    {
        return new ErrorHandler(
            $this->getContainer()->get(CallableResolverInterface::class),
            $this->getContainer()->get(ResponseFactoryInterface::class)
        );
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     *
     * @return callable
     */
    protected function getShutdownHandler(): callable
    {
        $settings            = $this->getContainer()->get(SettingsInterface::class);
        $displayErrorDetails = $settings->get(static::SETTINGS_KEY . '.display_error_details');
        $logError            = $settings->get(static::SETTINGS_KEY . '.log_error');
        $logErrorDetails     = $settings->get(static::SETTINGS_KEY . '.log_error_details');

        return new ShutdownHandler(
            $this->getContainer()->get(ServerRequestInterface::class),
            $this->getContainer()->get(ErrorHandlerInterface::class),
            $this->getContainer()->get(ResponseEmitter::class),
            $displayErrorDetails, $logError, $logErrorDetails
        );
    }

    /**
     * @param App        $app
     * @param array|null $controllers
     */
    protected function registerControllers(App $app, ?array $controllers = null): void
    {
        if (!$controllers) {
            $controllers = static::CONTROLLERS;
        }

        $classes = array_map([ClassFinder::class, 'getClassesInNamespace'], $controllers);
        foreach ($classes as $class) {
            if (!is_a($class, ControllerInterface::class, true)) {
                continue;
            }

            $actions = $class::getRoutes()->getFlattenedChildren();
            foreach ($actions as $action) {
                $app->map($action->getMethods(), $action->getPrefixedPattern(), [$class, $action->getAction()]);
            }
        }
    }

    /**
     * Run web application
     *
     * @throws DependencyException
     * @throws NotFoundException
     *
     * @return int
     */
    protected function runAsWebApp(): int
    {
        $settings            = $this->getContainer()->get(SettingsInterface::class);
        $displayErrorDetails = $settings->get(static::SETTINGS_KEY . '.display_error_details');
        $logError            = $settings->get(static::SETTINGS_KEY . '.log_error');
        $logErrorDetails     = $settings->get(static::SETTINGS_KEY . '.log_error_details');
        $basePath            = $settings->get(static::SETTINGS_KEY . '.base_path');

        $app = Bridge::create($this->getContainer());
        $app->setBasePath($basePath);

        $this->registerControllers($app);

        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $middlewares = $this->getMiddlewares();
        foreach ($middlewares as $middleware) {
            $app->add($middleware);
        }

        $serverRequestCreator = ServerRequestCreatorFactory::create();
        $request              = $serverRequestCreator->createServerRequestFromGlobals();

        $this->getContainer()->set(ResponseEmitter::class, $this->getResponseEmitter());
        $this->getContainer()->set(CallableResolverInterface::class, $app->getCallableResolver());
        $this->getContainer()->set(ResponseFactoryInterface::class, $app->getResponseFactory());
        $this->getContainer()->set(ServerRequestInterface::class, $request);
        $this->getContainer()->set(ErrorHandlerInterface::class, $this->getErrorHandler());

        register_shutdown_function($this->getShutdownHandler());

        $errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, $logError, $logErrorDetails);
        $errorMiddleware->setDefaultErrorHandler($this->getContainer()->get(ErrorHandlerInterface::class));

        $this->getContainer()->get(ResponseEmitter::class)->emit($app->handle($request));

        return 1;
    }

    /**
     * @throws Exception
     *
     * @return int
     */
    public function run()
    {
        return ($this->isCli() ? $this->runAsCliApp() : $this->runAsWebApp());
    }
}