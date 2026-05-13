<?php

declare(strict_types=1);

const CONFIG_ENV_FILE = __DIR__ . '/../.env';
const CONFIG_DIR = __DIR__ . '/../config';

/**
 * @return array<string, string>
 */
function config_all(): array
{
    static $values = null;

    if ($values !== null) {
        return $values;
    }

    $values = [];

    if (!is_file(CONFIG_ENV_FILE)) {
        return $values;
    }

    $lines = file(CONFIG_ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $values;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        $value = trim($value, "\"'");
        $values[$key] = $value;
    }

    return $values;
}

/**
 * @return array<string, array<string, mixed>>
 */
function config_file_groups(): array
{
    static $groups = null;

    if ($groups !== null) {
        return $groups;
    }

    $groups = [];
    if (!is_dir(CONFIG_DIR)) {
        return $groups;
    }

    $files = glob(CONFIG_DIR . '/*.php');
    if ($files === false) {
        return $groups;
    }

    foreach ($files as $file) {
        $name = pathinfo($file, PATHINFO_FILENAME);
        $data = require $file;
        if (is_array($data)) {
            $groups[$name] = $data;
        }
    }

    return $groups;
}

/**
 * @param mixed $default
 * @return mixed
 */
function config(string $key, $default = null)
{
    $values = config_all();
    if (array_key_exists($key, $values)) {
        return $values[$key];
    }

    $groups = config_file_groups();
    $parts = explode('.', $key);
    if (count($parts) < 2) {
        return $default;
    }

    $group = array_shift($parts);
    if (!isset($groups[$group]) || !is_array($groups[$group])) {
        return $default;
    }

    $cursor = $groups[$group];
    foreach ($parts as $part) {
        if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
            return $default;
        }
        $cursor = $cursor[$part];
    }

    return $cursor;
}
