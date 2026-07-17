<?php

$attrs = wp_parse_args(
    is_array($attributes) ? $attributes : [],
    [
        'heading'            => 'Providing <strong>Legal Advice</strong> in:',
        'backgroundImageId'  => 0,
        'backgroundImageUrl' => '',
        'backgroundImageAlt' => '',
        'scaleImageId'       => 0,
        'scaleImageUrl'      => '',
        'scaleImageAlt'      => '',
    ]
);

$scalar = static fn(mixed $value): string => is_scalar($value) ? (string) $value : '';
$heading = \Goetz\Site\heading_markup($scalar($attrs['heading']));
$background_url = $scalar($attrs['backgroundImageUrl']);
$background_alt = $scalar($attrs['backgroundImageAlt']);
$background_id = \Goetz\Site\valid_image_attachment_id($attrs['backgroundImageId']);
$background_image = '';

if ($background_id > 0) {
    $background_image = (string) wp_get_attachment_image(
        $background_id,
        'full',
        false,
        [
            'class'    => 'goetz-practice-band__background',
            'alt'      => $background_alt,
            'loading'  => 'lazy',
            'decoding' => 'async',
            'sizes'    => '(min-width: 990px) 50vw, 100vw',
        ]
    );
}

if ($background_image === '' && trim($background_url) !== '') {
    $background_image = sprintf(
        '<img class="goetz-practice-band__background" src="%1$s" alt="%2$s" loading="lazy" decoding="async">',
        esc_url($background_url),
        esc_attr($background_alt)
    );
}
?>
<section <?php echo get_block_wrapper_attributes(['class' => 'goetz-practice-areas goetz-practice-band']); ?>>
    <figure class="goetz-practice-band__image">
        <?php echo $background_image; ?>
    </figure>
    <div class="goetz-practice-band__content">
        <h2 class="goetz-practice-areas__heading"><?php echo $heading; ?></h2>
        <ul class="goetz-practice-list">
            <?php echo $content; ?>
        </ul>
    </div>
</section>
