<?php

require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/../sikelan/Core/constants.php';
require_once __DIR__ . '/../sikelan/Core/common.php';

if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        return $value;
    }
}
