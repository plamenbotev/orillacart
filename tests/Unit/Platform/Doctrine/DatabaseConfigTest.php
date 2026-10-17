<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\Doctrine;

use OrillaCart\Platform\Doctrine\DatabaseConfig;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigTest extends TestCase
{
    public function testHostAndPortConnection(): void
    {
        self::assertSame(
            [
                'driver' => 'mysqli',
                'host' => 'db',
                'user' => 'wp',
                'password' => 'secret',
                'dbname' => 'wordpress',
                'charset' => 'utf8mb4',
                'port' => 3307,
            ],
            $this->config(host: 'db', port: 3307, socket: null, collation: '')->connectionParams(),
        );
    }

    public function testSocketConnectionAndCollation(): void
    {
        self::assertSame(
            [
                'driver' => 'mysqli',
                'host' => 'localhost',
                'user' => 'wp',
                'password' => 'secret',
                'dbname' => 'wordpress',
                'charset' => 'utf8mb4',
                'unix_socket' => '/run/mysqld.sock',
                'defaultTableOptions' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_520_ci'],
            ],
            $this->config(host: 'localhost', port: null, socket: '/run/mysqld.sock', collation: 'utf8mb4_unicode_520_ci')
                ->connectionParams(),
        );
    }

    private function config(string $host, ?int $port, ?string $socket, string $collation): DatabaseConfig
    {
        return new DatabaseConfig(
            host: $host,
            port: $port,
            socket: $socket,
            user: 'wp',
            password: 'secret',
            name: 'wordpress',
            charset: 'utf8mb4',
            collation: $collation,
            tablePrefix: 'wp_orillacart_',
        );
    }
}
