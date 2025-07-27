<?php
// @foyeseo
declare(strict_types=1);

/**
 * 模板引擎类 - 处理模板渲染和标签替换 (v2 - Multi-pass Architecture)
 */
class TemplateEngine
{
    // 目录常量
    private const TEMPLATE_DIR = __DIR__ . '/../config/templates/';
    private const DATA_DIR = __DIR__ . '/../config/data/';
    private const ZHDATA_DIR = __DIR__ . '/../config/zhdata/';
    private const WENZHANG_DIR = __DIR__ . '/../config/wenzhang/';
    private const FILES_DIR = __DIR__ . '/../public/static/files/';
    private const FILES_DIR1 = __DIR__ . '/../public/static/files1/';

    // 类属性
    private string $siteId;
    private Context $context;

    // 分阶段的标签处理器
    private array $loopTagProcessor = [];
    private array $combinationTagProcessors = [];
    private array $simpleTagProcessors = [];

    public function __construct(Context $context)
    {
        $this->siteId = hash('crc32b', realpath($_SERVER['DOCUMENT_ROOT']));
        $this->context = $context;
        $this->initializeTagProcessors();
    }

    /**
     * 初始化标签处理器 (重构)
     * 根据三阶段渲染流程，对处理器进行分类
     */
    private function initializeTagProcessors(): void
    {
        $this->loopTagProcessor = [
            'loop' => [ 
                'pattern' => '/{foye\s+num=(\d+)(?:-(\d+))?\s*}(.*?){\/foye}/s',
                'callback' => [$this, 'expandLoopTag']
            ]
        ];

        foreach (glob(self::ZHDATA_DIR . '*.txt') as $filePath) {
            $tag = basename($filePath, '.txt');
            $this->combinationTagProcessors[$tag] = [
                'pattern' => '/{' . preg_quote($tag, '/') . '}/',
                'callback' => fn() => $this->expandCombinationTag($filePath)
            ];
        }

        $this->simpleTagProcessors['rand_file'] = [
            'pattern' => '/{rand_file}/',
            'callback' => [$this, 'processRandFile']
        ];
        $this->simpleTagProcessors['rand_file1'] = [
            'pattern' => '/{rand_file1}/',
            'callback' => [$this, 'processRandFile1']
        ];
        $this->simpleTagProcessors['rand_local_data'] = [
            'pattern' => '/{rand_([a-zA-Z_]+)(\d+)?}/',
            'callback' => [$this, 'processLocalDataTagCallback']
        ];

        $this->simpleTagProcessors['url_djurl'] = [
            'pattern' => '/{djurl(?:=([01]))?}/',
            'callback' => fn($matches) => $this->getDomainUrl(0, (int)($matches[1] ?? 0))
        ];
        $this->simpleTagProcessors['url_siteurl'] = [
            'pattern' => '/{siteurl(?:=([01]))?}/',
            'callback' => fn($matches) => $this->getDomainUrl(1, (int)($matches[1] ?? 0))
        ];
        $this->simpleTagProcessors['url_siteuri'] = [
            'pattern' => '/{siteuri(?:=([01]))?}/',
            'callback' => fn($matches) => $this->getDomainUrl(2, (int)($matches[1] ?? 0))
        ];
        $this->simpleTagProcessors['timestamp'] = [
            'pattern' => '/{timestamp\_(year|month|day|hour|minute|second|datetime|random)}/',
            'callback' => [$this, 'processTimestampTags']
        ];
        $this->simpleTagProcessors['generator'] = [
            'pattern' => '/{(string|alpha_numeric|hex|digits|alpha_upper|alpha_lower)(?:=(\d+)(?:-(\d+))?)?}/',
            'callback' => fn($matches) => $this->processGeneratorCallback($matches)
        ];

        $this->simpleTagProcessors['local_data'] = [
            'pattern' => '/{([a-zA-Z_][a-zA-Z0-9_]*)(\d+)?}/', 
            'callback' => [$this, 'processLocalDataTagCallback']
        ];
    }

    /**
     * 修改：不再直接处理标签，而是获取随机文章的数据（标题和内容）
     * @return array ['title' => string, 'content' => string]
     */
    private function getRandomArticleData(): array
    {
        try {
            $cacheKey = 'wenzhang_files_list';

            // 检查缓存中是否有文件列表
            if (!$this->context->hasCache($cacheKey)) {
                $wenzhangDir = realpath(self::WENZHANG_DIR);
                if (!$wenzhangDir) {
                    throw new RuntimeException('WENZHANG_DIR not found: ' . self::WENZHANG_DIR);
                }
                
                $files = glob($wenzhangDir . '/*.txt');
                if (empty($files)) {
                    throw new RuntimeException('No .txt files found in WENZHANG_DIR: ' . $wenzhangDir);
                }
                
                // 将文件列表存入缓存
                $this->context->setCache($cacheKey, $files);
            }

            $files = $this->context->getCache($cacheKey);
            if (empty($files)) {
                throw new RuntimeException('Article file list from cache is empty.');
            }

            // 随机选择一个文件
            $randomFile = $files[array_rand($files)];
            
            // 返回包含标题和内容的数组
            return [
                'title'   => basename($randomFile, '.txt'),
                'content' => file_get_contents($randomFile) ?: ''
            ];

        } catch (Throwable $e) {
            error_log("Error in getRandomArticleData: " . $e->getMessage());
            // 发生错误时返回空数组，防止后续代码出错
            return ['title' => '', 'content' => ''];
        }
    }

    /**
     * 渲染模板 (主入口)
     */
    public function render(): string
    {
        try {
            $content = $this->loadTemplate();
            return $this->processTags($content);
        } catch (Throwable $e) {
            error_log("Render error: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 标签处理总控制器 (重构)
     * @param string $content 初始模板内容
     * @return string 完全渲染后的内容
     */
    private function processTags(string $content): string
    {
        $this->syncLocalDataToRedis();
        $content = $this->executeSinglePass($content, $this->loopTagProcessor);

        $content = $this->executeRecursivePass($content, $this->combinationTagProcessors);
        // --- 修改：在第二阶段后，同时处理 {wenzhang} 和 {wenzhang_title} ---
        if (strpos($content, '{wenzhang}') !== false || strpos($content, '{wenzhang_title}') !== false) {
            // 1. 获取一次文章数据（标题和内容）
            $articleData = $this->getRandomArticleData();
            
            // 2. 定义要查找和替换的标签
            $search = ['{wenzhang_title}', '{wenzhang}'];
            $replace = [$articleData['title'], $articleData['content']];
            
            // 3. 一次性替换所有相关标签
            $content = str_replace($search, $replace, $content);
        }
        // --- 处理结束 ---
        
        $content = $this->executeSinglePass($content, $this->simpleTagProcessors);

        return $content;
    }

    /**
     * 执行单次遍历处理
     * @param string $content
     * @param array $processors
     * @return string
     */
    private function executeSinglePass(string $content, array $processors): string
    {
        foreach ($processors as $processor) {
            if (strpos($content, '{') === false) break;
            $content = preg_replace_callback($processor['pattern'], $processor['callback'], $content);
        }
        return $content;
    }

    /**
     * 执行递归遍历处理，直到内容稳定
     * @param string $content
     * @param array $processors
     * @return string
     */
    private function executeRecursivePass(string $content, array $processors): string
    {
        $maxLoops = 10;
        $loopCount = 0;
        do {
            $previousContent = $content;
            $content = $this->executeSinglePass($content, $processors);
            $loopCount++;
        } while ($content !== $previousContent && $loopCount < $maxLoops);
        return $content;
    }

    /**
     * 展开 {foye...} 循环标签
     */
    private function expandLoopTag(array $matches): string
    {
        $min = (int)$matches[1];
        $max = isset($matches[2]) ? (int)$matches[2] : $min;
        $innerContent = $matches[3];

        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        $repeatCount = mt_rand($min, $max);
        $output = '';

        for ($i = 0; $i < $repeatCount; $i++) {
            $output .= str_replace('{vo.i}', (string)$i, $innerContent);
        }
        return $output;
    }

    /**
     * 展开组合标签
     */
    private function expandCombinationTag(string $filePath): string
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return empty($lines) ? '' : $lines[array_rand($lines)];
    }
    
    /**
     * 处理本地数据标签 (包括普通和rand_)
     */
    private function processLocalDataTagCallback(array $matches): string
    {
        try {
            $isRandom = str_starts_with($matches[0], '{rand_');
            
            $baseTag = $isRandom ? $matches[1] : $matches[1];
            $suffix = $isRandom ? ($matches[2] ?? '') : ($matches[2] ?? '');

            if (isset($this->combinationTagProcessors[$baseTag])) {
                return $matches[0];
            }

            $config = $this->context->getConfig();
            $redis = $this->context->getRedis();
            $redis->select($config['redis']['databases']['local_data']);

            $redisKey = "site:{$this->siteId}:local_data:{$baseTag}";
            $needEncode = $this->checkNeedEncode($baseTag);
            $value = '';

            $keywordGdMode = $config['access']['yumingguding'] ?? 0;
            if (!$isRandom && $keywordGdMode === 1) {
                // 模式1：yumingguding=1
                $cacheKey = "{$baseTag}:" . md5(($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '')) . ':' . $suffix;
                if ($this->context->hasCache($cacheKey)) {
                    $value = $this->context->getCache($cacheKey);
                } else {
                    $keywords = $redis->sMembers($redisKey);
                    if (!empty($keywords)) {
                        $seedSource = ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '') . $suffix;
                        $seed = md5($seedSource);
                        $index = hexdec(substr($seed, 0, 8)) % count($keywords);
                        $value = $keywords[$index];
                    }
                    $this->context->setCache($cacheKey, $value ?: '');
                }
            } else {
                // 模式0：yumingguding=0 或 rand_ 标签
                if (!$isRandom) { // {tag} - 同一页面内固定
                    $cacheKey = "local_data:{$baseTag}{$suffix}";
                    if (!$this->context->hasCache($cacheKey)) {
                        $value = $redis->sRandMember($redisKey) ?: '';
                        $this->context->setCache($cacheKey, $value);
                    }
                    $value = $this->context->getCache($cacheKey);
                } else { // {rand_tag} - 每次都随机
                    $value = $redis->sRandMember($redisKey) ?: '';
                }
            }

            if ($value && $needEncode) {
                return mb_convert_encoding($value, 'HTML-ENTITIES', 'UTF-8');
            }
            return $value ?: '';
        } catch (Throwable $e) {
            error_log("Tag error in processLocalDataTagCallback: {$e->getMessage()}");
            return '';
        }
    }

    private function getDomainUrl(int $type = 0, int $xieyi = 0): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $result = '';
        switch ($type) {
            case 1: $result = $host; break;
            case 2: $result = $host . $uri; break;
            case 0:
            default:
                $parts = explode('.', $host);
                $count = count($parts);
                if ($count > 2) {
                    $lastTwo = $parts[$count - 2] . '.' . $parts[$count - 1];
                    $specialSuffixes = ['com.cn', 'net.cn', 'org.cn', 'gov.cn', 'edu.cn'];
                    if (in_array($lastTwo, $specialSuffixes) && $count > 2) {
                        $result = $parts[$count - 3] . '.' . $lastTwo;
                    } else {
                        $result = $lastTwo;
                    }
                } elseif ($count > 1) {
                    $result = $parts[$count - 2] . '.' . $parts[$count - 1];
                } else {
                    $result = $host;
                }
                break;
        }
        if ($xieyi === 1) {
            return "{$scheme}://{$result}";
        }
        return $result;
    }
    private function processGeneratorCallback(array $matches): string
    {
        $type = $matches[1];
        $min_length = 8;
        $max_length = 8;
        if (isset($matches[2])) {
            $min_length = (int)$matches[2];
            $max_length = $min_length;
        }
        if (isset($matches[3])) {
            $max_length = (int)$matches[3];
        }
        if ($min_length > $max_length) {
            [$min_length, $max_length] = [$max_length, $min_length];
        }
        $length = mt_rand($min_length, $max_length);
        return $this->processGeneratorTags($type, $length);
    }
    private function processGeneratorTags(string $type, int $length): string
    {
        $charSets = [
            'alpha_numeric' => 'abcdefghijklmnopqrstuvwxyz0123456789',
            'string' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
            'digits' => '123456789',
            'hex' => 'abcdef0123456789',
            'alpha_upper' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'alpha_lower' => 'abcdefghijklmnopqrstuvwxyz'
        ];
        if (!isset($charSets[$type])) return '';

        $characterSet = $charSets[$type];
        if (strlen($characterSet) === 0) {
            return ''; // 防止除以零的错误
        }
        return substr(str_shuffle(str_repeat($characterSet, (int)ceil($length / strlen($characterSet)))), 0, $length);
    }

    private function checkNeedEncode(string $tag): bool
    {
        $files = glob(self::DATA_DIR . "#{$tag}#[01].txt");
        if (!empty($files)) {
            if (preg_match('/#.+#([01])\.txt/', basename($files[0]), $matches)) {
                return isset($matches[1]) && $matches[1] === '1';
            }
        }
        return false;
    }
    private function syncLocalDataToRedis(): self
    {
        try {
            $redis = $this->context->getRedis();
            $redis->select($this->context->getConfig()['redis']['databases']['local_data']);
            foreach (glob(self::DATA_DIR . '#*#[01].txt') as $file) {
                if (!preg_match('/#(.+)#[01]\.txt/', basename($file), $matches)) continue;
                $tag = $matches[1];
                $key = "site:{$this->siteId}:local_data:{$tag}";
                $timeKey = "{$key}:updated_at";
                $fileTime = filemtime($file);
                if (!$redis->exists($key) || !($redisTime = $redis->get($timeKey)) || $fileTime > (int)$redisTime) {
                    $lines = array_filter(explode("\n", file_get_contents($file) ?: ''));
                    if (empty($lines)) continue;
                    $redis->exists($key) && $redis->del($key);
                    $redis->sAddArray($key, $lines);
                    $redis->set($timeKey, $fileTime);
                }
            }
        } catch (Throwable $e) {
            error_log("Sync error: {$e->getMessage()}");
        }
        return $this;
    }
    private function loadTemplate(): string
    {
        $config = $this->context->getConfig();
        $neiyeMode = $config['nykg']['neiye'] ?? 0;
        $selectionMode = $config['access']['yumingguding'] ?? 0;
        $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $isRoot = ($requestPath === '/');
        $templateSubDir = 'shouye';
        if ($neiyeMode === 1 && !$isRoot) {
            $templateSubDir = 'neiye';
        }
        $targetDir = self::TEMPLATE_DIR . $templateSubDir . '/';
        $templateFiles = glob($targetDir . '*.html');
        if (empty($templateFiles)) {
            if ($templateSubDir === 'neiye') {
                $fallbackDir = self::TEMPLATE_DIR . 'shouye/';
                $templateFiles = glob($fallbackDir . '*.html');
                if (empty($templateFiles)) {
                     throw new RuntimeException("No template files found in 'neiye' or fallback 'shouye' directory.");
                }
            } else {
                throw new RuntimeException("No template files found in directory: " . $targetDir);
            }
        }
        $templatePath = '';
        switch ($selectionMode) {
            case 1:
                $seed = md5($_SERVER['HTTP_HOST'] ?? '');
                $index = hexdec(substr($seed, 0, 8)) % count($templateFiles);
                $templatePath = $templateFiles[$index];
                break;
            case 0:
            default:
                $templatePath = $templateFiles[array_rand($templateFiles)];
                break;
        }
        if (!file_exists($templatePath)) {
            throw new RuntimeException("Selected template file does not exist: " . $templatePath);
        }
        return file_get_contents($templatePath) ?: '';
    }

    private function processRandFile(): string
    {
        try {
            $cacheKey = 'files_list';
            $docRoot = realpath($_SERVER['DOCUMENT_ROOT']);
            if (!$this->context->hasCache($cacheKey)) {
                $filesDir = realpath(self::FILES_DIR);
                if (!$filesDir) return '';
                $files = glob($filesDir . '/*.*');
                if (empty($files)) return '';
                $this->context->setCache($cacheKey, $files);
            }
            $files = $this->context->getCache($cacheKey);
            if (empty($files)) return '';
            $file = $files[array_rand($files)];
            return str_replace($docRoot, '', $file);
        } catch (Throwable $e) {
            return '';
        }
    }
    private function processRandFile1(): string
    {
        try {
            $cacheKey = 'files_list1';
            $docRoot = realpath($_SERVER['DOCUMENT_ROOT']);
            if (!$this->context->hasCache($cacheKey)) {
                $filesDir = realpath(self::FILES_DIR1);
                if (!$filesDir) return '';
                $files = glob($filesDir . '/*.*');
                if (empty($files)) return '';
                $this->context->setCache($cacheKey, $files);
            }
            $files = $this->context->getCache($cacheKey);
            if (empty($files)) return '';
            $file = $files[array_rand($files)];
            return str_replace($docRoot, '', $file);
        } catch (Throwable $e) {
            return '';
        }
    }
    private function processTimestampTags(array $matches): string
    {
        $type = $matches[1];
        switch ($type) {
            case 'year': return date('Y');
            case 'month': return date('m');
            case 'day': return date('d');
            case 'hour': return date('H');
            case 'minute': return date('i');
            case 'second': return date('s');
            case 'random':
                $randomTime = time() - mt_rand(0, 86400);
                return date('Y-m-d H:i:s', $randomTime);
            case 'datetime':
            default:
                return date('Y-m-d H:i:s');
        }
    }
}