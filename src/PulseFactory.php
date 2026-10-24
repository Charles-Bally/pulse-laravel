<?php

declare(strict_types=1);

namespace Pulse\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Pulse\Config;
use Pulse\Http\Transport;
use Pulse\Laravel\Detection\LaravelProbes;
use Pulse\Laravel\Detection\LaravelSchedule;
use Pulse\Logger;
use Pulse\PackageMeta;
use Pulse\Probe;
use Pulse\Runtime;
use Pulse\Support\Loggers;
use Pulse\Versions;

final class PulseFactory
{
    public const VERSION = '0.0.1';
    private const OPTIONS = [
        'key', 'endpoint', 'send', 'include', 'exclude', 'logs', 'log_level',
        'public_key', 'clear', 'relay_fingerprint', 'app_version', 'disabled',
    ];

    public function __construct(private readonly Application $app)
    {
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        $config = (array) $this->app['config']->get('pulse-app', []);

        return array_intersect_key($config, array_flip(self::OPTIONS));
    }

    public function directory(): string
    {
        $directory = $this->app['config']->get('pulse-app.directory');

        return is_string($directory) && $directory !== '' ? $directory : $this->app->storagePath('pulse');
    }

    // La configuration de Laravel lit déjà les variables : elle passe seule, pour que config:cache reste juste.
    public function config(?Logger $logger = null): ?Config
    {
        return Config::resolve($this->options(), [], $logger ?? Loggers::none());
    }

    public function meta(): PackageMeta
    {
        return new PackageMeta(
            'pulse-laravel',
            Versions::of('pulse-app/pulse-laravel', self::VERSION) ?? self::VERSION,
            'laravel',
            $this->app->version(),
        );
    }

    public function runtime(Logger $logger): ?Runtime
    {
        $transport = $this->app->bound(Transport::class) ? $this->app->make(Transport::class) : null;
        $runtime = Runtime::create($this->meta(), $this->directory(), $this->options(), [], $logger, $transport);
        if ($runtime === null) {
            return null;
        }
        foreach ((array) $this->app['config']->get('pulse-app.http_probes', []) as $name => $url) {
            $runtime->reporter->addProbe(Probe::http((string) $name, (string) $url));
        }
        if ($this->app['config']->get('pulse-app.auto_detect', true) !== false) {
            foreach ((new LaravelProbes($this->app))->detect() as $probe) {
                $runtime->reporter->addProbe($probe);
            }
            $runtime->reporter->addCronSource(fn () => LaravelSchedule::snapshots($this->app->make(Schedule::class)));
        }

        return $runtime;
    }
}
