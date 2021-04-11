<?php

namespace Arlisaha\Chozo\Controller;

use Arlisaha\Chozo\Route\Group;

interface ControllerInterface
{
    /**
     * @return Group
     */
    public static function getRoutes(): Group;
}