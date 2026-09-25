<?php
// src/View/Template.php

declare(strict_types=1);

namespace Berserk\View;

class Template
{
    private string $root;
    private string $http;

    public function __construct(string $root, string $http = '/')
    {
        $this->root = rtrim($root, '/\\') . '/';
        $this->http = rtrim($http, '/') . '/';
    }

    /**
     * Прочитать шаблон без подстановок. Заменяет только {{http}}.
     */
    public function get(string $name): string|false
    {
        $file = $this->root . $name;
        if ($name === '' || !is_file($file)) {
            return false;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return false;
        }

        return str_replace('{{http}}', $this->http, $content);
    }

    /**
     * Прочитать шаблон и подставить значения.
     * Массивы в $data пропускаются.
     * Оставшиеся {{...}} вычищаются.
     */
    public function parse(string $name, array $data = []): string|false
    {
        $file = $this->root . $name;
        if ($name === '' || !is_file($file)) {
            return false;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return false;
        }

        $data['rkey'] = time();
        $data['http'] = $this->http;

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $content = str_replace('{{' . $key . '}}', (string) $value, $content);
        }

        return preg_replace('/\{\{(.*?)\}\}/', '', $content);
    }
}