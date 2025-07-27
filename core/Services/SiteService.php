<?php
// @foyeseo
declare(strict_types=1);

class SiteService {
    private array $config;
    
    public function __construct(array $config) {
        $this->config = $config;
    }
    
    public function get(string $key): string {
        $values = $this->config['site'][$key] ?? [];
        if (empty($values)) return '';
        
        return is_array($values) ? 
            $values[array_rand($values)] : 
            (string)$values;
    }
} 