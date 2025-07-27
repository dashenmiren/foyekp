<?php

declare(strict_types=1);
const support = '技术支持';
const telegram = '@foyeseo';
// 基础设置
ini_set('memory_limit', '128M');
set_time_limit(30);
header("Content-type: text/html; charset=utf-8");
date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ERROR | E_PARSE);
ini_set("display_errors", "Off");
// 启用输出缓冲
ob_start();

try {
    // 自动加载类
    spl_autoload_register(function ($class) {
        // 处理命名空间路径
        $parts = explode('\\', str_replace('\\', '/', $class));
        $className = array_pop($parts);
        
        // 检查是否是服务类
        if (strpos($class, 'Service') !== false) {
            $file = __DIR__ . "/../core/Services/{$className}.php";
        } else {
            $file = __DIR__ . "/../core/{$className}.php";
        }
        
        if (!file_exists($file)) {
            throw new RuntimeException("Class not found: {$class} (tried: {$file})");
        }
        
        require_once $file;
    });

    $context = new Context();
    
    // 检查访问权限
    if (($page = $context->checkAccess()) !== null) {
        echo $page;
        exit;
    }
    
    // 检查页面缓存
    if ($cached = $context->getPageCache()) {
        echo $cached;
        exit;
    }
    
    // 渲染模板
    $content = (new TemplateEngine($context))->render();
    $context->setPageCache($content);
    echo $content;
    
} catch (Throwable $e) {
    // 错误日志记录
    error_log(sprintf(
        "Error: %s\nFile: %s\nLine: %d\nTrace: %s",
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));
    
    // 清理任何已有输出
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    // 错误响应
    http_response_code(500);
    echo '错误: ' . $e->getMessage() . "<br>";
    echo '文件: ' . $e->getFile() . "<br>";
    echo '行号: ' . $e->getLine() . "<br>";
    echo '堆栈: ' . $e->getTraceAsString() . "<br>";
    echo '系统繁忙,请稍后再试';
} finally {
    // 确保所有输出缓冲都被清理
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}