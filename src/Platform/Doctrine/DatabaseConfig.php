<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

/**
 * Connection settings taken from the running WordPress install.
 */
final readonly class DatabaseConfig
{
    public const string TABLE_PREFIX_SUFFIX = 'orillacart_';

    /**
     * @param non-empty-string $tablePrefix
     */
    public function __construct(
        public string $host,
        public ?int $port,
        public ?string $socket,
        public string $user,
        public string $password,
        public string $name,
        public string $charset,
        public string $collation,
        /** WordPress table prefix plus TABLE_PREFIX_SUFFIX, e.g. "wp_orillacart_"; never empty. */
        public string $tablePrefix,
        /** As the server reports it, e.g. "8.4.3" or "11.4.2-MariaDB"; null makes DBAL ask the server. */
        public ?string $serverVersion = null,
    ) {}

    public static function fromWordPress(): self
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if (!$wpdb instanceof \wpdb) {
            throw new \LogicException('WordPress database is not loaded.');
        }

        $dbHost = self::constant('DB_HOST');
        // parse_db_host() understands every DB_HOST form WordPress accepts: host:port, host:/socket, [ipv6]:port
        $parsed = $wpdb->parse_db_host($dbHost);
        [$host, $port, $socket] = \is_array($parsed) ? $parsed : [$dbHost, null, null];

        $charset = \defined('DB_CHARSET') ? self::constant('DB_CHARSET') : '';

        return new self(
            host: \is_string($host) && $host !== '' ? $host : 'localhost',
            port: is_numeric($port) ? (int) $port : null,
            socket: \is_string($socket) && $socket !== '' ? $socket : null,
            user: self::constant('DB_USER'),
            password: self::constant('DB_PASSWORD'),
            name: self::constant('DB_NAME'),
            charset: $charset !== '' ? $charset : 'utf8mb4',
            collation: \defined('DB_COLLATE') ? self::constant('DB_COLLATE') : '',
            tablePrefix: $wpdb->prefix . self::TABLE_PREFIX_SUFFIX,
            // wpdb already asked when it connected; DBAL would ask again on every request.
            serverVersion: \is_string($version = $wpdb->db_server_info()) && $version !== '' ? $version : null,
        );
    }

    /**
     * DBAL connection parameters for the mysqli driver, which WordPress requires anyway.
     *
     * @return array{driver: 'mysqli', host: string, user: string, password: string, dbname: string, charset: string, port?: int, unix_socket?: string, serverVersion?: string, defaultTableOptions?: array<string, mixed>}
     */
    public function connectionParams(): array
    {
        $params = [
            'driver' => 'mysqli',
            'host' => $this->host,
            'user' => $this->user,
            'password' => $this->password,
            'dbname' => $this->name,
            'charset' => $this->charset,
        ];

        if ($this->port !== null) {
            $params['port'] = $this->port;
        }
        if ($this->socket !== null) {
            $params['unix_socket'] = $this->socket;
        }
        if ($this->serverVersion !== null) {
            $params['serverVersion'] = $this->serverVersion;
        }
        if ($this->collation !== '') {
            $params['defaultTableOptions'] = ['charset' => $this->charset, 'collation' => $this->collation];
        }

        return $params;
    }

    private static function constant(string $name): string
    {
        $value = \defined($name) ? \constant($name) : null;
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('WordPress constant %s is not defined as a string.', $name));
        }

        return $value;
    }
}
