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
 * @param array<string, string> $updates
 */
function config_write_env_file(string $path, array $updates): void
{
    $updates = array_map(static function ($value): string {
        return (string) $value;
    }, $updates);

    $lines = [];
    if (is_file($path)) {
        $existing = file($path, FILE_IGNORE_NEW_LINES);
        if ($existing === false) {
            throw new RuntimeException('Env dosyasi okunamadi: ' . $path);
        }
        $lines = $existing;
    }

    $seen = [];
    foreach (array_keys($updates) as $key) {
        $seen[$key] = false;
    }

    $output = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0 || strpos($line, '=') === false) {
            $output[] = $line;
            continue;
        }

        $parts = explode('=', $line, 2);
        $key = trim((string) $parts[0]);
        if ($key !== '' && array_key_exists($key, $updates)) {
            $output[] = $key . '=' . $updates[$key];
            $seen[$key] = true;
            continue;
        }

        $output[] = $line;
    }

    foreach ($updates as $key => $value) {
        if (($seen[$key] ?? false) === false) {
            $output[] = $key . '=' . $value;
        }
    }

    $content = implode(PHP_EOL, $output);
    if ($content !== '') {
        $content .= PHP_EOL;
    }

    $written = @file_put_contents($path, $content, LOCK_EX);
    if ($written === false) {
        throw new RuntimeException('Env dosyasi yazilamadi: ' . $path);
    }
}

/**
 * @param array<string, string> $updates
 */
function config_update_env_values(array $updates): void
{
    config_write_env_file(CONFIG_ENV_FILE, $updates);
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
