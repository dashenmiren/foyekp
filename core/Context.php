<?php
// @foyeseo
declare(strict_types=1);

/**
 * 上下文类 - 管理全局依赖和状态
 */
class Context {
    private array $cache = [];
    private string $siteId;
    private ConfigService $config;
    private RedisService $redis;
    private CacheService $pageCache;
    private AccessService $access;
    private SiteService $site;
    
    /**
     * 构造函数 - 初始化上下文
     */
    public function __construct() {
        $this->siteId = hash('crc32b', realpath($_SERVER['DOCUMENT_ROOT']));
        $this->initializeServices();
    }
    
    private function initializeServices(): void {
        $this->config = new ConfigService();
        $this->redis = new RedisService($this->config->get());
        
        // 获取专用的Redis连接用于页面缓存
        $this->pageCache = new CacheService(
            $this->redis->getRedis(),
            $this->config->get(),
            $this->siteId
        );
        
        $this->access = new AccessService($this->config->get());
        $this->site = new SiteService($this->config->get());
    }
    
    /**
     * 获取页面缓存
     */
    public function getPageCache(): ?string {
        return $this->pageCache->get();
    }
    
    /**
     * 设置页面缓存
     */
    public function setPageCache(string $content): void {
        $this->pageCache->set($content);
    }
    
    /**
     * 检查访问权限
     * @return string|null 返回null表示允许访问，否则返回要显示的页面内容
     */
    public function checkAccess(): ?string {
        return $this->access->check();
    }
    
    /**
     * 获取Redis实例
     */
    public function getRedis(): Redis {
        return $this->redis->getRedis();
    }
    
    /**
     * 获取配置项
     */
    public function getConfig(): array {
        return $this->config->get();
    }
    
    public function getSiteConfig(string $key): string {
        return $this->site->get($key);
    }
    
    // 请求级别缓存方法
    public function hasCache(string $key): bool {
        return isset($this->cache[$key]);
    }
    
    public function getCache(string $key) {
        return $this->cache[$key] ?? null;
    }
    
    public function setCache(string $key, $value): void {
        $this->cache[$key] = $value;
    }
} 