<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function assert_true_ignore_rules(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
if ($schema === false) {
    throw new RuntimeException('schema.sql okunamadi');
}
$pdo->exec($schema);

$repo = new BrokenLinkIgnoreRuleRepository($pdo);
$repo->create([
    'pattern' => '/wp-json/',
    'match_type' => 'contains',
    'scope' => 'target',
    'note' => 'WordPress API blocks HEAD',
]);
$repo->create([
    'pattern' => '/private/',
    'match_type' => 'contains',
    'scope' => 'source',
    'note' => 'Private pages are ignored',
]);

assert_true_ignore_rules(
    $repo->shouldIgnore('https://example.com/wp-json/test', 'https://example.com/page'),
    'Target contains rule should ignore matching target URL'
);
assert_true_ignore_rules(
    $repo->shouldIgnore('https://example.com/missing.css', 'https://example.com/private/page'),
    'Source contains rule should ignore matching source URL'
);
assert_true_ignore_rules(
    !$repo->shouldIgnore('https://example.com/missing.css', 'https://example.com/page'),
    'Non-matching URLs should not be ignored'
);

$rules = $repo->all();
assert_true_ignore_rules(count($rules) === 2, 'Two ignore rules should be listed');
assert_true_ignore_rules($repo->deleteById((int) $rules[0]['id']), 'Ignore rule should be deletable');
assert_true_ignore_rules(count($repo->all()) === 1, 'Deleting one rule should leave one rule');

echo "BrokenLinkIgnoreRulesTest OK\n";
