<?php

declare(strict_types=1);

namespace Pulse\Laravel;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Monolog\Logger as Monolog;
use Pulse\Config;
use Pulse\Laravel\Console\ReportCommand;
use Pulse\Laravel\Console\WorkCommand;
use Pulse\Laravel\Detection\LaravelSchedule;
use Pulse\Logs\SpoolHandler;
use Pulse\Pulse;

final class PulseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/pulse-app.php', 'pulse-app');
        $this->app->singleton(PulseFactory::class, fn ($app) => new PulseFactory($app));
        $this->app->singleton(Pulse::class, function ($app): Pulse {
            $factory = $app->make(PulseFactory::class);
            $config = $factory->config();

            return $config !== null && $config->selection->wants('crons') ? Pulse::in($factory->directory()) : Pulse::disabled();
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([WorkCommand::class, ReportCommand::class]);
            $this->publishes([__DIR__ . '/../config/pulse-app.php' => $this->app->configPath('pulse-app.php')], 'pulse-app-config');
        }
        $factory = $this->app->make(PulseFactory::class);
        $config = $factory->config();
        if ($config === null) {
            return;
        }
        if ($config->selection->wants('crons')) {
            $this->recordScheduledRuns();
        }
        if ($config->logs()) {
            $this->callAfterResolving('log', fn ($manager) => self::attachSpool($manager, $factory->directory(), $config));
        }
    }

    // Le handler s'ajoute après ceux de l'app sur le canal par défaut : l'app écrit d'abord, rien n'est retiré.
    private static function attachSpool(mixed $manager, string $directory, Config $config): void
    {
        try {
            $monolog = $manager->channel()->getLogger();
            if (!$monolog instanceof Monolog) {
                return;
            }
            foreach ($monolog->getHandlers() as $handler) {
                if ($handler instanceof SpoolHandler) {
                    return;
                }
            }
            $monolog->setHandlers([...$monolog->getHandlers(), SpoolHandler::in($directory, $config->selection->logLevel)]);
        } catch (\Throwable) {
        }
    }

    private function recordScheduledRuns(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $pulse = fn (): Pulse => $this->app->make(Pulse::class);
        $finished = static function (ScheduledTaskFinished|ScheduledBackgroundTaskFinished $event) use ($pulse): void {
            $code = $event->task->exitCode;
            $ok = $code === null || (int) $code === 0;
            $pulse()->recordCronRun(LaravelSchedule::name($event->task), $ok, $ok ? null : "code de sortie {$code}");
        };
        $events->listen(ScheduledTaskFinished::class, $finished);
        $events->listen(ScheduledBackgroundTaskFinished::class, $finished);
        $events->listen(ScheduledTaskFailed::class, static function (ScheduledTaskFailed $event) use ($pulse): void {
            $pulse()->recordCronRun(LaravelSchedule::name($event->task), false, $event->exception);
        });
    }
}
