<?php

$attrs = wp_parse_args(
    is_array($attributes) ? $attributes : [],
    [
        'eyebrow'           => 'WE ARE AN EXPERIENCED TEAM',
        'heading'           => 'NEED A LAWYER?',
        'buttonText'        => 'Get Consultation',
        'buttonUrl'         => '/contact/',
        'backgroundImageId' => 0,
        'backgroundImageUrl'=> '',
        'buttonNewTab'      => false,
    ]
);

$scalar = static fn(mixed $value): string => is_scalar($value) ? (string) $value : '';
$eyebrow = $scalar($attrs['eyebrow']);
$heading = $scalar($attrs['heading']);
$button_text = $scalar($attrs['buttonText']);
$button_url = $scalar($attrs['buttonUrl']);
$button_new_tab = \Goetz\Site\normalize_boolean($attrs['buttonNewTab']);
$background_image_id = \Goetz\Site\valid_image_attachment_id($attrs['backgroundImageId']);
$background_url = $background_image_id > 0
    ? wp_get_attachment_image_url($background_image_id, 'full')
    : false;

if (! is_string($background_url) || $background_url === '') {
    $background_url = $scalar($attrs['backgroundImageUrl']);
}
if (trim($background_url) === '') {
    $background_url = function_exists('goetz_legal_asset_url')
        ? goetz_legal_asset_url('law-updates-bg.jpg', 'https://goetzlegal.com/wp-content/uploads/2022/08/law-updates-bg.jpg')
        : 'https://goetzlegal.com/wp-content/uploads/2022/08/law-updates-bg.jpg';
}

$style = sprintf(
    'background-image: linear-gradient(rgb(45 45 45 / 90%%), rgb(45 45 45 / 90%%)), url("%s");',
    esc_url($background_url)
);

if (trim($button_text) === '') {
    $button_text = function_exists('goetz_site_get_setting')
        ? (string) goetz_site_get_setting('cta_label', 'Get Consultation')
        : 'Get Consultation';
}
if (trim($button_url) === '') {
    $button_url = function_exists('goetz_site_get_setting')
        ? (string) goetz_site_get_setting('cta_url', '/contact/')
        : '/contact/';
}
if ($heading === 'NEED A LAWYER?') {
    $heading = 'NEED A <b>LAWYER?</b>';
}
$heading = \Goetz\Site\heading_markup($heading);
?>
<section <?php echo get_block_wrapper_attributes(['class' => 'goetz-cta', 'style' => $style]); ?>>
    <div>
        <p><?php echo esc_html($eyebrow); ?></p>
        <h2><?php echo $heading; ?></h2>
    </div>
    <a class="goetz-button" href="<?php echo esc_url($button_url); ?>"<?php if ($button_new_tab): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?php echo esc_html($button_text); ?></a>
</section>
