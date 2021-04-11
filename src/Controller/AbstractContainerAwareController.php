<?php

namespace Arlisaha\Chozo\Controller;

use Arlisaha\Chozo\Route\Group;
use DI\Container;

abstract class AbstractContainerAwareController implements ControllerInterface
{
    /**
     * @var Container
     */
    private $container;

    /**
     * AbstractContainerAwareController constructor.
     *
     * @param Container $container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * @return Container
     */
    final protected function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * @return Group
     */
    abstract public static function getRoutes(): Group;
}