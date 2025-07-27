<?php

use Phalcon\Di\Injectable;

class ConfigService extends Injectable
{
    private const REQUIRED = [
        'redis.host', 'redis.port', 'redis.databases.page_cache',
        'page_cache.enabled', 'page_cache.ttl'
    ];

    private array $config;

    public function __construct()
    {
        $this->load();
    }

    public function get(): array
    {
        return $this->config;
    }

    private function load(): void
    {
        $file = APP_PATH . '/../config/config.php';
        if (!file_exists($file)) {
            throw new RuntimeException("Config not found");
        }

        $config = require $file;
        if (!is_array($config)) {
            throw new RuntimeException("Invalid config");
        }

        $this->validate($config);
        $this->config = $config;
    }

    private function validate(array $config): void
    {
        foreach (self::REQUIRED as $key) {
            $value = $config;
            foreach (explode('.', $key) as $part) {
                if (!isset($value[$part])) {
                    throw new RuntimeException("Missing: {$key}");
                }
                $value = $value[$part];
            }
        }
    }
}
