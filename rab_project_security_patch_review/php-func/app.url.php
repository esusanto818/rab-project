<?php
/**
 * Application URL helper.
 * Compatible with PHP 7.x.
 */

if (!function_exists('app_detect_base_url')) {
    function app_detect_base_url()
    {
        $paths = array();

        if (!empty($_SERVER['SCRIPT_NAME'])) {
            $paths[] = parse_url($_SERVER['SCRIPT_NAME'], PHP_URL_PATH);
        }

        if (!empty($_SERVER['REQUEST_URI'])) {
            $paths[] = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        }

        $internalDirs = 'views|php-crud|pdf|includes|php-func|php-class|css|js|js-ext|js-func|tcpdf_new';

        foreach ($paths as $path) {
            if (!$path) {
                continue;
            }

            $path = '/' . ltrim(str_replace('\\', '/', $path), '/');

            // Contoh: /rab-project/dev/views/router
            if (preg_match('~^(.*?)/(' . $internalDirs . ')(?:/|$)~i', $path, $matches)) {
                $root = rtrim($matches[1], '/');
                return ($root === '' ? '/' : $root . '/');
            }

            // Entry point di application root.
            if (preg_match('~^(.*?)/(?:index|router)\.php$~i', $path, $matches)) {
                $root = rtrim($matches[1], '/');
                return ($root === '' ? '/' : $root . '/');
            }
        }

        return '/';
    }
}

if (!defined('BASE_URL')) {
    define('BASE_URL', app_detect_base_url());
}