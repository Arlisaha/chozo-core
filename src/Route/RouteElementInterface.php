<?php

namespace Arlisaha\Chozo\Route;

interface RouteElementInterface
{
    /**
     * @param Group $routeElement
     *
     * @return RouteElementInterface
     */
    public function setParent(Group $routeElement): RouteElementInterface;

    /**
     * @return Group|null
     */
    public function getParent(): ?Group;

    /**
     * @return string
     */
    public function getPrefix(): string;
}