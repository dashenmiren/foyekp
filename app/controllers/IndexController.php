<?php

class IndexController extends ControllerBase
{
    public function indexAction()
    {
        $context = $this->getDI()->get('context');

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
        $content = $this->getDI()->get('templateEngine')->render();
        $context->setPageCache($content);
        echo $content;
        exit;
    }
}
