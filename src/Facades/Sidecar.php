<?php

namespace Statamic\Sidecar\Facades;

use Illuminate\Support\Facades\Facade;
use Statamic\Sidecar\Manager;

/**
 * @method static \Statamic\Sidecar\Manager extend(string $driver, \Closure $callback)
 * @method static \Statamic\Sidecar\Manager pair(string $compatiblePackage, string $driver)
 * @method static bool hasDriver(string $driver)
 * @method static array registeredDrivers()
 * @method static \Illuminate\Support\Collection packages()
 * @method static \Illuminate\Support\Collection sources()
 * @method static \Illuminate\Support\Collection handles()
 * @method static \Statamic\Sidecar\Source|null source(string $handle)
 * @method static bool manages(string $handle)
 * @method static \Statamic\Sidecar\Contracts\Driver driver(string $handle)
 *
 * @see Manager
 */
class Sidecar extends Facade
{
    protected static function getFacadeAccessor()
    {
        return Manager::class;
    }
}
