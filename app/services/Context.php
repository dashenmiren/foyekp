<?php

use Phalcon\Di\Injectable;

class Context extends Injectable
{
    private array $cache = [];

    public function __construct()
    {
    }

    public function getPageCache(): ?string
    {
        return $this->getDI()->get('cache')->get();
    }

    public function setPageCache(string $content): void
    {
        $this->getDI()->get('cache')->set($content);
    }

    public function checkAccess(): ?string
    {
        return $this->getDI()->get('access')->check();
    }

    public function getRedis(): Redis
    {
        return $this->getDI()->get('redis')->getRedis();
    }

    public function getConfig(): array
    {
        return $this->getDI()->get('config')->get();
    }

    public function getSiteConfig(string $key): string
    {
        return $this->getDI()->get('site')->get($key);
    }

    // 请求级别缓存方法
    public function hasCache(string $key): bool
    {
        return isset($this->cache[$key]);
    }

    public function getCache(string $key)
    {
        return $this->cache[$key] ?? null;
    }

    public function setCache(string $key, $value): void
    {
        $this->cache[$key] = $value;
    }
}
