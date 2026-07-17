<?php

if (! defined('ABSPATH')) {
    fwrite(STDERR, "site-settings-option.php must run through WP-CLI.\n");
    exit(1);
}

$action = $args[0] ?? '';
$option_name = 'goetz_site_settings';

if ($action === 'snapshot') {
    $missing = new stdClass();
    $value = get_option($option_name, $missing);
    $snapshot = [
        'exists' => $value !== $missing,
        'value'  => $value !== $missing ? $value : null,
    ];
    echo base64_encode(serialize($snapshot));
    return;
}

if ($action !== 'restore') {
    WP_CLI::error('Expected snapshot or restore action.');
}

$encoded = trim((string) file_get_contents('php://stdin'));
$serialized = base64_decode($encoded, true);
$snapshot = is_string($serialized)
    ? unserialize($serialized, ['allowed_classes' => false])
    : false;
if (! is_array($snapshot)
    || ! array_key_exists('exists', $snapshot)
    || ! is_bool($snapshot['exists'])
    || ! array_key_exists('value', $snapshot)) {
    WP_CLI::error('Invalid Site Settings snapshot.');
}

remove_filter(
    'sanitize_option_' . $option_name,
    ['Goetz\\Site\\Settings\\Site_Settings', 'sanitize']
);
if ($snapshot['exists']) {
    update_option($option_name, $snapshot['value'], false);
} else {
    delete_option($option_name);
}

$missing = new stdClass();
$restored = get_option($option_name, $missing);
$matches = $snapshot['exists'] ? $restored === $snapshot['value'] : $restored === $missing;
if (! $matches) {
    WP_CLI::error('Exact Site Settings restoration failed.');
}

WP_CLI::success('Exact Site Settings option restored.');
