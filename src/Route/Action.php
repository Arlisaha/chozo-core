<?php

namespace Arlisaha\Chozo\Route;

use function array_map;
use function is_array;

class Action implements RouteElementInterface
{
    /**
     * @var string[]
     */
    private $methods;

    /**
     * @var string
     */
    private $pattern;

    /**
     * @var string
     */
    private $action;

    /**
     * @var Group
     */
    private $parent;

    /**
     * @param string          $pattern
     * @param string|string[] $methods
     * @param string          $action
     *
     * @return Action
     */
    public static function create(string $pattern, $methods, string $action): Action
    {
        return new self($pattern, $methods, $action);
    }

    /**
     * Action constructor.
     *
     * @param string          $pattern
     * @param string|string[] $methods
     * @param string          $action
     */
    public function __construct(string $pattern, $methods, string $action)
    {
        if (!is_array($methods)) {
            $methods = [$methods];
        }

        $this->methods = array_map('strtoupper', $methods);
        $this->pattern = $pattern;
        $this->action  = $action;
    }

    /**
     * @return string[]
     */
    public function getMethods()
    {
        return $this->methods;
    }

    /**
     * @return string
     */
    public function getPattern(): string
    {
        return $this->pattern;
    }

    /**
     * @return string
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * @inheritDoc
     */
    public function setParent(Group $routeElement): RouteElementInterface
    {
        $this->parent = $routeElement;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getParent(): Group
    {
        return $this->parent;
    }

    /**
     * @inheritDoc
     */
    public function getPrefix(): string
    {
        return $this->getParent()->getPrefix() . $this->getParent()->getLabel();
    }

    /**
     * @return string
     */
    public function getPrefixedPattern(): string
    {
        return $this->getPrefix() . $this->getPattern();
    }
}