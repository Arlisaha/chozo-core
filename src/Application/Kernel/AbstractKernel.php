<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Application\Kernel;

use Arlisaha\Chozo\Application\Config\Config;
use Arlisaha\Chozo\Application\Config\ConfigInterface;
use Arlisaha\Chozo\Application\Config\Parameters\Parameters;
use Arlisaha\Chozo\Application\Config\Parameters\ParametersInterface;
use Arlisaha\Chozo\Application\Config\Settings\Settings;
use Arlisaha\Chozo\Application\Config\Settings\SettingsInterface;
use Arlisaha\Chozo\Application\Equipment\EquipmentInterface;
use Arlisaha\Chozo\Application\Handlers\ShutdownHandler;
use Arlisaha\Chozo\Application\PathBuilder\PathBuilder;
use Arlisaha\Chozo\Application\PathBuilder\PathBuilderInterface;
use Arlisaha\Chozo\ClassFinder\ClassFinder;
use Arlisaha\Chozo\ClassFinder\ClassFinderInterface;
use Arlisaha\Chozo\Controller\ControllerInterface;
use Arlisaha\Chozo\Exception\ConfigFileException;
use Arlisaha\Chozo\Exception\InvalidEquipmentException;
use Arlisaha\Chozo\Exception\InvalidPathException;
use Arlisaha\Chozo\Exception\KernelNotCreatedException;
use Arlisaha\Chozo\Exception\MissingConfigKeyException;
use DI\Bridge\Slim\Bridge;
use DI\Container;
use DI\ContainerBuilder;
use DI\DependencyException;
use DI\NotFoundException;
use Exception;
use Psr\Cache\InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
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
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;
use function array_fill_keys;
use function array_key_exists;
use function array_merge;
use function array_reduce;
use function DI\autowire;
use function error_reporting;
use function file_exists;
use function file_get_contents;
use function ini_set;
use function is_array;
use function is_string;
use function register_shutdown_function;
use const E_ALL;
use const E_NOTICE;
use const PHP_SAPI;

abstract class AbstractKernel implements KernelInterface
{
    public const SETTINGS_KEY = 'kernel';

    protected const DEBUG_ERROR_REPORTING      = E_ALL & ~E_NOTICE;
    protected const DEFAULT_CACHE_LIFETIME     = 0;
    protected const CACHE_DIRECTORY_PERMISSION = 0744;
    protected const SERVICES_CONFIG_PARAM_NAME = 'config';
    protected const CACHE_DIR                  = '/var/cache/';
    protected const SETTINGS_PATH              = '/config/settings.yml';
    protected const PARAMETERS_PATH            = '/config/parameters.yml';
    protected const CONTROLLERS                = ['App\\Controller']; // namespace
    protected const COMMANDS                   = ['App\\Command'];    // namespace
    protected const SERVICES                   = [];                  // config_key => [FQCN(s)]
    protected const MIDDLEWARES                = [];                  // FQCN
    protected const EQUIPMENTS                 = [];                  // FQCN

    /**
     * @var static
     */
    protected static $instance;

    /**
     * @var Container
     */
    private $container;

    /**
     * @var bool
     */
    private $isDebug;

    /**
     * @var string[]
     */
    private $commandFullyQualifiedClassNames;

    /**
     * @var string[]
     */
    private $controllerFullyQualifiedClassNames;

    /**
     * @var array<string[]|array<string[]>>
     */
    private $routes;

    /**
     * @var EquipmentInterface[]
     */
    private $equipments;

    /**
     * @var bool
     */
    private $running = false;

    /**
     * @param string $rootDir
     *
     * @throws KernelNotCreatedException
     * @throws InvalidArgumentException
     *
     * @return static
     */
    final public static function create(string $rootDir): KernelInterface
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
    final public static function get(): KernelInterface
    {
        if (!static::$instance) {
            throw new KernelNotCreatedException();
        }

        return static::$instance;
    }

    /**
     * @throws KernelNotCreatedException
     * @throws Exception
     *
     * @return int
     */
    final public static function run(): int
    {
        return static::get()->runApp();
    }

    /**
     * AbstractKernel constructor.
     *
     * @param string $rootDir
     *
     * @throws Exception
     * @throws InvalidArgumentException
     */
    private function __construct(string $rootDir)
    {
        $debugKey     = 'debug';
        $pathBuilder  = new PathBuilder($rootDir);
        $classFinder  = new ClassFinder($pathBuilder->getRootDir());
        $cacheDir     = $this->getCacheDirectory($pathBuilder);
        $cacheHandler = new ChainAdapter([
            new PhpFilesAdapter('', static::DEFAULT_CACHE_LIFETIME, $cacheDir),
            new FilesystemAdapter('', static::DEFAULT_CACHE_LIFETIME, $cacheDir),
        ], static::DEFAULT_CACHE_LIFETIME);
        [SettingsInterface::class => $settings, ParametersInterface::class => $parameters] = $this->handleConfig($pathBuilder, $cacheHandler);
        if (!array_key_exists(static::SETTINGS_KEY, $settings)) {
            throw new MissingConfigKeyException(static::SETTINGS_KEY);
        }
        if (!array_key_exists($debugKey, $settings[static::SETTINGS_KEY])) {
            throw new MissingConfigKeyException($debugKey, static::SETTINGS_KEY);
        }

        $containerBuilder    = new ContainerBuilder();
        $this->isDebug       = $settings[static::SETTINGS_KEY][$debugKey];
        $errorReportingLevel = 0;
        $displayErrorLevel   = '0';
        if ($this->isDebug) {
            $errorReportingLevel = static::DEBUG_ERROR_REPORTING;
            $displayErrorLevel   = '1';
            $cacheHandler->clear();
            [SettingsInterface::class => $settings, ParametersInterface::class => $parameters] = $this->handleConfig($pathBuilder, $cacheHandler);
        } else {
            $cacheHandler->prune();
            $containerBuilder->enableCompilation($cacheDir);
        }
        error_reporting($errorReportingLevel);
        ini_set('display_errors', $displayErrorLevel);

        $this->equipments = [];
        foreach (static::EQUIPMENTS as $equipment) {
            if (!is_a($equipment, EquipmentInterface::class, true)) {
                if ($this->isDebug) {
                    throw new InvalidEquipmentException($equipment);
                }

                continue;
            }

            $this->equipments[$equipment] = new $equipment(
                ($equipment::SETTINGS_KEY && array_key_exists($equipment::SETTINGS_KEY, $settings) ?
                    $settings[$equipment::SETTINGS_KEY] : null),
                $cacheHandler,
                $this->isDebug
            );
        }

        $this->commandFullyQualifiedClassNames    = $cacheHandler->get('commands.fqcn', function (ItemInterface $item) use ($classFinder) {
            return $this->getConsoleCommands($item, $classFinder);
        });
        $this->controllerFullyQualifiedClassNames = $cacheHandler->get('controllers.fqcn', function (ItemInterface $item) use ($classFinder) {
            return $this->getControllers($item, $classFinder);
        });
        $this->routes                             = $cacheHandler->get('routes', function () {
            $routes = [];
            foreach ($this->getControllerFullyQualifiedClassNames() as $class) {
                $actions = $class::getRoutes()->getFlattenedChildren();
                foreach ($actions as $action) {
                    $routes[] = [
                        'methods' => $action->getMethods(),
                        'pattern' => $action->getPrefixedPattern(),
                        'action'  => [$class, $action->getAction()],
                    ];
                }
            }

            return $routes;
        });

        $containerBuilder->addDefinitions(array_merge(
            $this->getContainerConfigDefinitions($settings, $parameters),
            $this->getPathUtilsDefinition($pathBuilder),
            [
                KernelInterface::class               => $this,
                ClassFinderInterface::class          => $classFinder,
                AdapterInterface::class              => $cacheHandler,
                ResponseFactoryInterface::class      => $this->getResponseFactory(),
                CallableResolverInterface::class     => $this->getCallableResolver(),
                RouteCollectorInterface::class       => $this->getRouteCollector(),
                RouteResolverInterface::class        => $this->getRouteResolver(),
                MiddlewareDispatcherInterface::class => $this->getMiddlewareDispatcher(),
            ],
            array_fill_keys($this->getCommandFullyQualifiedClassNames(), autowire()),
            array_fill_keys($this->getControllerFullyQualifiedClassNames(), autowire()),
            array_reduce($this->getEquipments(), static function (array $carry, EquipmentInterface $equipment) {
                return array_merge($carry, $equipment->getServices());
            }, []),
            $this->getConfiguredServicesDefinitions($settings),
            $this->getServicesDefinitions()
        ));

        $this->container = $containerBuilder->build();
    }

    private function __clone()
    {
    }

    /**
     * @param PathBuilder      $pathBuilder
     * @param AdapterInterface $cacheHandler
     *
     * @throws InvalidPathException
     *
     * @return array
     */
    private function handleConfig(PathBuilder $pathBuilder, AdapterInterface $cacheHandler): array
    {
        return $cacheHandler->get('config', function () use ($pathBuilder) {
            $parametersPath = $this->getParametersFilePath($pathBuilder);
            $settingsPath   = $this->getSettingsFilePath($pathBuilder);

            if (!file_exists($parametersPath) || !file_exists($settingsPath)) {
                throw new ConfigFileException();
            }
            $parameters      = Yaml::parseFile($parametersPath);
            $replaceCallback = static function (array $match) use ($parameters) {
                if (!array_key_exists($match[1], $parameters)) {
                    return $match[0];
                }

                $val = $parameters[$match[1]];
                return (is_string($val) ? $val : Yaml::dump($val));
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

            return [SettingsInterface::class => $settings, ParametersInterface::class => $parameters];
        });
    }

    /**
     * @param ItemInterface        $item
     * @param ClassFinderInterface $classFinder
     *
     * @return array
     */
    private function getControllers(ItemInterface $item, ClassFinderInterface $classFinder): array
    {
        $controllers = $this->getControllerNamespaces();

        $classes = $this->getClassesFromNamespaces($classFinder, $controllers);
        foreach ($this->getEquipments() as $equipment) {
            $classes = array_merge($equipment->getControllerNamespaces(), $classes);
        }

        $defs = [];
        foreach ($classes as $class) {
            if (!is_a($class, ControllerInterface::class, true)) {
                continue;
            }

            $defs[] = $class;
        }

        return $defs;
    }

    /**
     * @param ItemInterface        $item
     * @param ClassFinderInterface $classFinder
     * @throws Exception
     *
     * @return array
     */
    private function getConsoleCommands(ItemInterface $item, ClassFinderInterface $classFinder): array
    {
        $commands = $this->getCommandNamespaces();

        $classes = $this->getClassesFromNamespaces($classFinder, $commands);
        foreach ($this->getEquipments() as $equipment) {
            $classes = array_merge($equipment->getCommandNamespaces(), $classes);
        }

        $defs = [];
        foreach ($classes as $class) {
            if (!is_a($class, Command::class, true)) {
                continue;
            }

            $defs[] = $class;
        }

        return $defs;
    }

    /**
     * @return static
     */
    private function toggleRunning(): KernelInterface
    {
        $this->running = true;

        return $this;
    }

    /**
     * @return Container
     */
    final protected function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * @return EquipmentInterface[]
     */
    final protected function getEquipments(): array
    {
        return $this->equipments;
    }

    /**
     * @return string[]
     */
    final protected function getCommandFullyQualifiedClassNames(): array
    {
        return $this->commandFullyQualifiedClassNames;
    }

    /**
     * @return string[]|ControllerInterface[]
     */
    final protected function getControllerFullyQualifiedClassNames(): array
    {
        return $this->controllerFullyQualifiedClassNames;
    }

    /**
     * @return array
     */
    final protected function getRouteDefinitions(): array
    {
        return $this->routes;
    }

    /**
     * @param ClassFinderInterface $classFinder
     * @param string[]             $namespaces
     *
     * @return array
     */
    final protected function getClassesFromNamespaces(ClassFinderInterface $classFinder, array $namespaces): array
    {
        $classes = [];
        foreach ($namespaces as $namespace) {
            $classes = array_merge($classFinder->getClassesInNamespace($namespace));
        }

        return $classes;
    }

    /**
     * @throws Exception
     *
     * @return int
     */
    final protected function runAsCliApp(): int
    {
        $app = new Application();

        $this->registerConsoleHelperSet($app);
        $this->registerConsoleCommands($app);

        return $app->run();
    }

    /**
     * Run web application
     *
     * @throws DependencyException
     * @throws NotFoundException
     *
     * @return int
     */
    final protected function runAsWebApp(): int
    {
        $c                   = $this->getContainer();
        $settings            = $c->get(SettingsInterface::class);
        $displayErrorDetails = $settings->get(static::SETTINGS_KEY . '.display_error_details');
        $logError            = $settings->get(static::SETTINGS_KEY . '.log_error');
        $logErrorDetails     = $settings->get(static::SETTINGS_KEY . '.log_error_details');
        $basePath            = $settings->get(static::SETTINGS_KEY . '.base_path');

        $app = Bridge::create($this->getContainer());
        $app->setBasePath($basePath);

        $this->registerControllers($app);

        $this->registerMiddlewares($app);

        $serverRequestCreator = ServerRequestCreatorFactory::create();
        $request              = $serverRequestCreator->createServerRequestFromGlobals();

        $c->set(ResponseEmitter::class, $this->getResponseEmitter());
        $c->set(CallableResolverInterface::class, $app->getCallableResolver());
        $c->set(ResponseFactoryInterface::class, $app->getResponseFactory());
        $c->set(ServerRequestInterface::class, $request);
        $c->set(ErrorHandlerInterface::class, $this->getErrorHandler());

        register_shutdown_function($this->getShutdownHandler());

        $errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, $logError, $logErrorDetails);
        $errorMiddleware->setDefaultErrorHandler($c->get(ErrorHandlerInterface::class));

        $c->get(ResponseEmitter::class)->emit($app->handle($request));

        return 1;
    }

    /**
     * @param array $settings
     *
     * @return array
     */
    final protected function getConfiguredServicesDefinitions(array $settings): array
    {
        $servicesDefinitions = static::SERVICES;

        $definitions = [];
        foreach ($servicesDefinitions as $configKey => $className) {
            if (!is_array($className)) {
                $className = [$className];
            }

            if (array_key_exists($configKey, $settings)) {
                $config = $settings[$configKey];
                foreach ($className as $fqcn) {
                    $definitions[$fqcn] = autowire($fqcn)->constructorParameter(static::SERVICES_CONFIG_PARAM_NAME, $config);
                }

                continue;
            }

            foreach ($className as $fqcn) {
                $definitions[$fqcn] = autowire($fqcn);
            }
        }

        return $definitions;
    }

    /**
     * @param PathBuilder $pathBuilder
     *
     * @throws InvalidPathException
     *
     * @return string
     */
    protected function getCacheDirectory(PathBuilder $pathBuilder): string
    {
        return $pathBuilder->getAbsolutePath(static::CACHE_DIR, true);
    }

    /**
     * @param PathBuilder $pathBuilder
     *
     * @throws InvalidPathException
     *
     * @return string
     */
    protected function getSettingsFilePath(PathBuilder $pathBuilder): string
    {
        return $pathBuilder->getAbsolutePath(static::SETTINGS_PATH, true);
    }

    /**
     * @param PathBuilder $pathBuilder
     *
     * @throws InvalidPathException
     *
     * @return string
     */
    protected function getParametersFilePath(PathBuilder $pathBuilder): string
    {
        return $pathBuilder->getAbsolutePath(static::PARAMETERS_PATH, true);
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
            SettingsInterface::class   => autowire(Settings::class)->constructorParameter('configElement', $settings),
            ParametersInterface::class => autowire(Parameters::class)->constructorParameter('configElement', $parameters),
            ConfigInterface::class     => autowire(Config::class),
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
     * @param App $app
     */
    protected function registerMiddlewares(App $app): void
    {
        $doRegister = static function (array $middlewares) use ($app): void {
            foreach ($middlewares as $middleware) {
                $app->add($middleware);
            }
        };

        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        foreach ($this->getEquipments() as $equipment) {
            $doRegister($equipment->getMiddlewareClassnames());
        }
        $doRegister(static::MIDDLEWARES);
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
        $c = $this->getContainer();
        return new ErrorHandler(
            $c->get(CallableResolverInterface::class),
            $c->get(ResponseFactoryInterface::class),
            $this->getLogger()
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
     * @param App $app
     */
    protected function registerControllers(App $app): void
    {
        foreach ($this->getRouteDefinitions() as ['methods' => $methods, 'pattern' => $pattern, 'action' => $action]) {
            $app->map($methods, $pattern, $action);
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
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    protected function registerConsoleCommands(Application $application): void
    {
        $c = $this->getContainer();
        foreach ($this->getCommandFullyQualifiedClassNames() as $class) {
            $application->add($c->get($class));
        }
    }

    /**
     * @return bool
     */
    final public function isCli(): bool
    {
        return 'cli' === PHP_SAPI;
    }

    /**
     * @throws Exception
     *
     * @return int
     */
    final public function runApp(): int
    {
        $this->toggleRunning();
        return ($this->isCli() ? $this->runAsCliApp() : $this->runAsWebApp());
    }

    /**
     * @return bool
     */
    final public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     *
     * @return LoggerInterface|null
     */
    public function getLogger(): ?LoggerInterface
    {
        $c = $this->getContainer();
        return ($c->has(LoggerInterface::class) ? $c->get(LoggerInterface::class) : null);
    }

    /**
     * @param Throwable $exception
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function handleErrorOutsideApplication(Throwable $exception): void
    {
        if ($this->getContainer()) {
            if (($logger = $this->getLogger())) {
                $logger->error(
                    $exception->getMessage(),
                    [
                        'code'  => $exception->getCode(),
                        'file'  => $exception->getFile(),
                        'line'  => $exception->getLine(),
                        'trace' => $exception->getTrace(),
                    ]
                );
            }

            if ($this->isDebug) {
                dump($exception);
            }
        }
    }
}
