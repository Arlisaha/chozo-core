<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Application\Kernel;

use Arlisaha\Chozo\Application\Cache\CacheHandler;
use Arlisaha\Chozo\Application\Config\Config;
use Arlisaha\Chozo\Application\Config\ConfigInterface;
use Arlisaha\Chozo\Application\Config\Parameters\Parameters;
use Arlisaha\Chozo\Application\Config\Parameters\ParametersInterface;
use Arlisaha\Chozo\Application\Config\Settings\Settings;
use Arlisaha\Chozo\Application\Config\Settings\SettingsInterface;
use Arlisaha\Chozo\Application\Handlers\ShutdownHandler;
use Arlisaha\Chozo\Application\PathBuilder\PathBuilder;
use Arlisaha\Chozo\Application\PathBuilder\PathBuilderInterface;
use Arlisaha\Chozo\Command\AbstractCommand;
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
    protected const COMMANDS                   = []; // namespace

    /**
     * @var static
     */
    private static $instance;

    /**
     * @var Container
     */
    private $container;

    /**
     * @var CacheHandler
     */
    private $cacheHandler;

    /**
     * @var bool
     */
    private $isDebug;

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
        $debugKey    = 'debug';
        $pathBuilder = new PathBuilder($rootDir);
        ClassFinder::setAppRoot($pathBuilder->getRootDir());
        $this->cacheHandler = new CacheHandler($this->getCacheDirectory($pathBuilder), $pathBuilder, static::CACHE_DIRECTORY_PERMISSION);
        [SettingsInterface::class => $settings, ParametersInterface::class => $parameters] = $this->handleConfig($pathBuilder);
        if (!array_key_exists(static::SETTINGS_KEY, $settings)) {
            throw new MissingConfigKeyException(static::SETTINGS_KEY);
        }
        if (!array_key_exists($debugKey, $settings[static::SETTINGS_KEY])) {
            throw new MissingConfigKeyException($debugKey, static::SETTINGS_KEY);
        }

        $containerBuilder = new ContainerBuilder();
        $this->isDebug    = $settings[static::SETTINGS_KEY][$debugKey];
        if (!$this->isDebug) {
            $containerBuilder->enableCompilation($this->cacheHandler->getDirectory());
        }

        $containerBuilder->addDefinitions(array_merge(
            $this->getContainerConfigDefinitions($settings, $parameters),
            $this->getPathUtilsDefinition($pathBuilder),
            [
                CacheHandler::class                  => $this->cacheHandler,
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
    }

    private function __clone()
    {
    }

    /**
     * @param PathBuilder $pathBuilder
     *
     * @return array
     */
    private function handleConfig(PathBuilder $pathBuilder): array
    {
        $parametersPath = $this->getParametersFilePath($pathBuilder);
        $settingsPath   = $this->getSettingsFilePath($pathBuilder);

        if (!file_exists($parametersPath) || !file_exists($settingsPath)) {
            throw new ConfigFileException();
        }

        $paramsSum    = md5_file($parametersPath);
        $settingsSum  = md5_file($settingsPath);
        $filename     = "config.$paramsSum.$settingsSum";
        $cachedConfig = $this->cacheHandler->get($filename);

        if (!$cachedConfig) {
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

            $cachedConfig = serialize([SettingsInterface::class => $settings, ParametersInterface::class => $parameters]);
            file_put_contents($filename, $cachedConfig);
        }

        return unserialize($cachedConfig);
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
     * @param string[] $namespaces
     *
     * @return array
     */
    final protected function getClassesFromNamespaces(array $namespaces): array
    {
        return array_map([ClassFinder::class, 'getClassesInNamespace'], $namespaces);
    }

    /**
     * @param string[] $namespaces
     * @param string   $cacheName
     *
     * @return array
     */
    final protected function getClassesFromCache(array $namespaces, string $cacheName): array
    {
        if (!$this->isDebug) {
            $cached = $this->cacheHandler->get($cacheName);

            if (!$cached) {
                $classes = $this->getClassesFromNamespaces($namespaces);
                $cached  = serialize($classes);
                $this->cacheHandler->set($cacheName, $cached);
            }

            return unserialize($cached);
        }

        return $this->getClassesFromNamespaces($namespaces);
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
     *
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

        $this->registerConsoleHelperSet($app);
        $this->registerConsoleCommands($app);

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
     * @return array
     */
    protected function getControllerNamespaces(): array
    {
        return static::CONTROLLERS;
    }

    /**
     * @param App        $app
     * @param array|null $controllers
     */
    protected function registerControllers(App $app, ?array $controllers = null): void
    {
        if (!$controllers) {
            $controllers = $this->getControllerNamespaces();
        }

        $classes = $this->getClassesFromCache($controllers, 'controllers');
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
     * @param Application $application
     */
    protected function registerConsoleHelperSet(Application $application): void
    {
        $application->getHelperSet();
    }

    /**
     * @return array
     */
    protected function getCommandNamespaces(): array
    {
        return static::COMMANDS;
    }

    /**
     * @param Application $application
     * @param array|null  $commands
     */
    protected function registerConsoleCommands(Application $application, ?array $commands = null): void
    {
        if (!$commands) {
            $commands = $this->getCommandNamespaces();
        }

        $classes = $this->getClassesFromCache($commands, 'commands');
        foreach ($classes as $class) {
            if (!is_a($class, AbstractCommand::class, true)) {
                continue;
            }

            $application->add(new $class($this->getContainer()));
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