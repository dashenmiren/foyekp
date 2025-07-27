<?php

use Phalcon\Di\Injectable;

class RedisService extends Injectable
{
    private Redis $redis;

    public function __construct()
    {
        $this->initConnection();
    }

    public function getRedis(): Redis
    {
        try {
            $this->redis->ping();
        } catch (Throwable) {
            $this->initConnection();
        }
        return $this->redis;
    }

    private function initConnection(): void
    {
        $config = $this->getDI()->get('config')->get();
        $this->redis = new Redis();
        $this->redis->connect(
            $config['redis']['host'],
            $config['redis']['port'],
            $config['redis']['timeout'] ?? 2.0
        );
    }

    public function __destruct()
    {
        try {
            $this->redis->close();
        } catch (Throwable) {
        }
    }
}
