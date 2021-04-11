<?php

namespace Arlisaha\Chozo\Route;

use Arlisaha\Chozo\Exception\InvalidRouteElementException;
use ArrayAccess;
use function array_key_exists;
use function array_merge;
use function is_a;

class Group implements RouteElementInterface, ArrayAccess
{
    /**
     * @var Group
     */
    private $parent;

    /**
     * @var string
     */
    private $label;

    /**
     * @var RouteElementInterface[]
     */
    private $children;

    /**
     * @param string                  $label
     * @param RouteElementInterface[] $children
     *
     * @return Group
     */
    public static function create(string $label, ...$children): Group
    {
        return new self($label, $children);
    }

    /**
     * Group constructor.
     *
     * @param string                  $label
     * @param RouteElementInterface[] $children
     */
    public function __construct(string $label, array $children)
    {
        $this->label = $label;
        foreach ($children as $child) {
            if (!is_a($child, RouteElementInterface::class, true)) {
                throw new InvalidRouteElementException($child);
            }

            $child->setParent($this);


        }

        $this->children = $children;
    }

    /**
     * @inheritDoc
     */
    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->children);
    }

    /**
     * @inheritDoc
     */
    public function offsetGet($offset)
    {
        return $this->children[$offset];
    }

    /**
     * @inheritDoc
     */
    public function offsetSet($offset, $value)
    {
    }

    /**
     * @inheritDoc
     */
    public function offsetUnset($offset)
    {
    }

    /**
     * @param Group $routeElement
     *
     * @return $this
     */
    public function setParent(Group $routeElement): RouteElementInterface
    {
        $this->parent = $routeElement;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getParent(): ?Group
    {
        return $this->parent;
    }

    /**
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return RouteElementInterface[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * @inheritDoc
     */
    public function getPrefix(): string
    {
        return ($this->getParent() ? $this->getParent()->getPrefix() . $this->getParent()->getLabel() : '');
    }

    /**
     * @return Action[]
     */
    public function getFlattenedChildren(): array
    {
        $flattened = [];
        foreach ($this->getChildren() as $child) {
            if($child instanceof Group) {
                $flattened = array_merge($flattened, $child->getFlattenedChildren());
                continue;
            }

            $flattened[] = $child;
        }

        return $flattened;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->getLabel();
    }
}