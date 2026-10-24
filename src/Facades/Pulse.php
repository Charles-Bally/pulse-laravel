<?php

declare(strict_types=1);

namespace Pulse\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed track(string $name, callable $task)
 * @method static void recordCronRun(string $name, bool $ok, mixed $error = null)
 */
final class Pulse extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Pulse\Pulse::class;
    }
}
