<?php
// @foyeseo
declare(strict_types=1);

class CacheService {
    private const PREFIX = 'v1:';
    private Redis $redis;
    private string $siteId;
    private array $config;
    private bool $enabled;
    
    public function __construct(Redis $redis, array $config, string $siteId) {
        $this->redis = $redis;
        $this->config = $config;
        $this->siteId = $siteId;
        $this->enabled = $config['page_cache']['enabled'];
    }
    
    public function get(): ?string {
        if (!$this->enabled) return null;
        
        try {
            $this->redis->select($this->config['redis']['databases']['page_cache']);
            return $this->redis->get($this->getKey()) ?: null;
        } catch (Throwable $e) {
            error_log("Cache get error: {$e->getMessage()}");
            return null;
        }
    }
    
    public function set(string $content): void {
        if (!$this->enabled) return;
        
        try {
            $this->redis->select($this->config['redis']['databases']['page_cache']);
            $this->redis->setex(
                $this->getKey(),
                $this->config['page_cache']['ttl'],
                $content
            );
        } catch (Throwable $e) {
            error_log("Cache set error: {$e->getMessage()}");
        }
    }
    
    private function getKey(): string {
        return self::PREFIX . "page_cache:{$this->siteId}:" . 
               md5($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ?? '/');
    }
} 