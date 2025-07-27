<?php

use Phalcon\Di\Injectable;

class AccessService extends Injectable
{
    private array $config;

    public function __construct()
    {
        $this->config = $this->getDI()->get('config')->get();
        if (md5('技术支持') !== "f0fbeece7f9ba32879f8871adec84931") {
            throw new RuntimeException("502 bad gateway");
        }
        if (md5('@foyeseo') !== "b082146cef8d1e8e34b0bf04fb673219") {
            throw new RuntimeException("502 bad gateway");
        }
        if (md5($this->config["support"]) !== "f0fbeece7f9ba32879f8871adec84931") {
            throw new RuntimeException("502 bad gateway");
        }
        if (md5($this->config["telegram"]) !== "b082146cef8d1e8e34b0bf04fb673219") {
            throw new RuntimeException("502 bad gateway");
        }
    }

    public function check(): ?string
    {
        try {
            if ($this->isPreviewMode()) {
                return null;
            }
            if ($this->isBlacklistModeEnabled() && $this->isBlacklistedIp()) {
                return $this->getBlacklistPage();
            }
            if ($this->isSpiderModeEnabled()) {
                return $this->isBaiduSpider() ? null : $this->getDefaultPage();
            }
            return null;
        } catch (Throwable $exception) {
            error_log("Access error: {$exception->getMessage()}");
            return '';
        }
    }

    private function isPreviewMode(): bool
    {
        $previewParam = $this->config["access"]["preview_param"];
        return isset($_GET[$previewParam]) && $_GET[$previewParam] == 1;
    }

    private function isSpiderModeEnabled(): bool
    {
        return $this->config["access"]["spider_mode"] === 1;
    }

    private function isBaiduSpider(): bool
    {
        $userAgent = $_SERVER["HTTP_USER_AGENT"] ?? '';
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!preg_match("/baidu/i", $userAgent)) {
            return false;
        }
        foreach ($this->config["baidu_spider_ips"] as $spiderIpPattern) {
            if (preg_match($spiderIpPattern, $remoteAddr)) {
                return true;
            }
        }
        return (bool)preg_match("/baidu/i", gethostbyaddr($remoteAddr));
    }

    private function getDefaultPage(): string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $isMobile = preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $userAgent);

        // 根据设备类型选择页面
        $defaultPageKey = $isMobile ? 'mob_page' : 'pc_page';
        $defaultPage = $this->config["access"][$defaultPageKey] ?? '';
        if (strpos($defaultPage, 'http') === 0) {
            // 如果是URL，直接请求
            $response = file_get_contents($defaultPage);
            return $response !== false ? $response : '';
            // 如果是URL， 直接302到URL
            // header("Location: $defaultPage",true,302);
            // exit;
        } else {
            $filePath = APP_PATH . "/../config/" . $defaultPage;
            // 如果是本地文件路径，读取文件内容
            $content = file_get_contents($filePath);
            return $content !== false ? $content : '';
        }

        return '';
    }

    private function isBlacklistModeEnabled(): bool
    {
        return $this->config["access"]["blacklist_mode"] === 1;
    }

    private function isBlacklistedIp(): bool
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        foreach ($this->config["blacklist_ips"] as $blacklistIpPattern) {
            if (preg_match($blacklistIpPattern, $remoteAddr)) {
                return true;
            }
        }
        return false;
    }

    private function getClientIP()
    {
        $possibleHeaders = [
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CF_CONNECTING_IP',
            'HTTP_TRUE_CLIENT_IP',
        ];

        foreach ($possibleHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // 处理逗号分隔的IP列表
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }

                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'];
    }

    private function getBlacklistPage(): string
    {
        $blacklistPagePath = APP_PATH . "/../config/" . $this->config["access"]["blacklist_page"];
        if (file_exists($blacklistPagePath)) {
            $content = file_get_contents($blacklistPagePath);
            return $content !== false ? $content : '';
        }
        return '';
    }
}
