<?php

declare(strict_types=1);

namespace Pulse\Laravel\Detection;

use Illuminate\Contracts\Foundation\Application;
use Pulse\Probe;

final class LaravelProbes
{
    private const ENGINE_NAMES = [
        'pgsql' => 'PostgreSQL',
        'mysql' => 'MySQL',
        'mariadb' => 'MariaDB',
        'sqlite' => 'SQLite',
        'sqlsrv' => 'SQL Server',
        'mongodb' => 'MongoDB',
        'oracle' => 'Oracle',
        'oci8' => 'Oracle',
    ];
    private const IDLE_QUEUE_DRIVERS = ['sync', 'null', 'deferred', 'background'];

    public function __construct(private readonly Application $app)
    {
    }

    /** @return list<Probe> */
    public function detect(): array
    {
        $probes = array_filter([$this->database(), ...$this->redis(), $this->queue()]);
        $names = [];
        $out = [];
        foreach ($probes as $probe) {
            $count = ($names[$probe->name] ?? 0) + 1;
            $names[$probe->name] = $count;
            $out[] = $count > 1 ? $probe->named("{$probe->name} ({$count})") : $probe;
        }

        return $out;
    }

    // Lectures sans effet de bord seulement : une sonde ne doit rien écrire chez le client.
    private function database(): ?Probe
    {
        $name = $this->config('database.default');
        $driver = $name === null ? null : $this->config("database.connections.{$name}.driver");
        if ($name === null || $driver === null) {
            return null;
        }
        $db = $this->app['db'];

        return new Probe(
            self::ENGINE_NAMES[strtolower($driver)] ?? 'Base de données',
            'database',
            static function () use ($db, $name, $driver): void {
                $connection = $db->connection($name);
                if ($driver === 'mongodb') {
                    $database = method_exists($connection, 'getDatabase') ? $connection->getDatabase() : $connection->getMongoDB();
                    $database->command(['ping' => 1]);

                    return;
                }
                $connection->select(in_array($driver, ['oracle', 'oci8'], true) ? 'SELECT 1 FROM DUAL' : 'SELECT 1');
            },
            $driver,
            static fn () => $db->purge($name),
        );
    }

    /** @return list<Probe> */
    private function redis(): array
    {
        $connections = [];
        $cache = $this->config('cache.default');
        if ($cache !== null && $this->config("cache.stores.{$cache}.driver") === 'redis') {
            $connections[] = $this->config("cache.stores.{$cache}.connection") ?? 'default';
        }
        $queue = $this->config('queue.default');
        if ($queue !== null && $this->config("queue.connections.{$queue}.driver") === 'redis') {
            $connections[] = $this->config("queue.connections.{$queue}.connection") ?? 'default';
        }
        if ($this->config('session.driver') === 'redis') {
            $connections[] = $this->config('session.connection') ?? 'default';
        }
        $broadcast = $this->config('broadcasting.default');
        if ($broadcast !== null && $this->config("broadcasting.connections.{$broadcast}.driver") === 'redis') {
            $connections[] = $this->config("broadcasting.connections.{$broadcast}.connection") ?? 'default';
        }
        $servers = [];
        foreach (array_unique($connections) as $connection) {
            $servers[$this->server($connection)] ??= $connection;
        }
        $redis = $this->app->bound('redis') ? $this->app['redis'] : null;
        if ($redis === null) {
            return [];
        }

        return array_values(array_map(static fn (string $connection) => new Probe(
            'Redis',
            'redis',
            static fn () => $redis->connection($connection)->ping(),
            'redis',
            static fn () => $redis->purge($connection),
        ), $servers));
    }

    private function queue(): ?Probe
    {
        $name = $this->config('queue.default');
        $driver = $name === null ? null : $this->config("queue.connections.{$name}.driver");
        if ($name === null || $driver === null || in_array($driver, self::IDLE_QUEUE_DRIVERS, true)) {
            return null;
        }
        $queueName = $this->config("queue.connections.{$name}.queue") ?? 'default';
        $manager = $this->app['queue'];

        return new Probe(
            "File {$queueName}",
            'queue',
            static fn () => $manager->connection($name)->size($queueName),
            $driver,
        );
    }

    private function server(string $connection): string
    {
        $settings = (array) $this->app['config']->get("database.redis.{$connection}", []);
        if (is_string($settings['url'] ?? null) && $settings['url'] !== '') {
            $parts = parse_url($settings['url']);

            return ($parts['host'] ?? '') . ':' . ($parts['port'] ?? 6379);
        }

        return ($settings['host'] ?? '127.0.0.1') . ':' . ($settings['port'] ?? 6379);
    }

    private function config(string $key): ?string
    {
        $value = $this->app['config']->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
