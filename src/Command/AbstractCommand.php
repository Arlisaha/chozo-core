<?php

namespace Arlisaha\Chozo\Command;

use DI\Container;
use Symfony\Component\Console\Command\Command;

abstract class AbstractCommand extends Command
{
    protected const NAME = '';

    /**
     * @var Container
     */
    private $container;

    /**
     * AbstractCommand constructor.
     *
     * @param Container $container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;

        parent::__construct(static::NAME ?: static::class);
    }

    /**
     * @return Container
     */
    final protected function getContainer(): Container
    {
        return $this->container;
    }
}