<?php
// @foyeseo
declare(strict_types=1);

class RedisService {
    private Redis $redis;
    private array $config;
    
    public function __construct(array $config) {
        $this->config = $config;
        $this->initConnection();
    }
    
    public function getRedis(): Redis {
        try {
            $this->redis->ping();
        } catch (Throwable) {
            $this->initConnection();
        }
        return $this->redis;
    }
    
    private function initConnection(): void {
        $this->redis = new Redis();
        $this->redis->connect(
            $this->config['redis']['host'],
            $this->config['redis']['port'],
            $this->config['redis']['timeout'] ?? 2.0
        );
    }
    
    public function __destruct() {
        try {
            $this->redis->close();
        } catch (Throwable) {}
    }
} 