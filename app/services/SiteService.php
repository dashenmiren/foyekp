<?php

use Phalcon\Di\Injectable;

class SiteService extends Injectable
{
    private array $config;

    public function __construct()
    {
        $this->config = $this->getDI()->get('config')->get();
    }

    public function get(string $key): string
    {
        $values = $this->config['site'][$key] ?? [];
        if (empty($values)) {
            return '';
        }

        return is_array($values) ?
            $values[array_rand($values)] :
            (string)$values;
    }
}
