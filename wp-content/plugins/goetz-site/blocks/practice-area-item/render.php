<?php

$label = isset($attributes['label']) && is_scalar($attributes['label'])
    ? (string) $attributes['label']
    : '';
$context = isset($block) && $block instanceof WP_Block && is_array($block->context)
    ? $block->context
    : [];
$scale_id = \Goetz\Site\valid_image_attachment_id($context['goetz/scaleImageId'] ?? 0);
$scale_url = isset($context['goetz/scaleImageUrl']) && is_scalar($context['goetz/scaleImageUrl'])
    ? (string) $context['goetz/scaleImageUrl']
    : '';
$scale_alt = isset($context['goetz/scaleImageAlt']) && is_scalar($context['goetz/scaleImageAlt'])
    ? (string) $context['goetz/scaleImageAlt']
    : '';
$scale_image = '';

if ($scale_id > 0) {
    $scale_image = (string) wp_get_attachment_image(
        $scale_id,
        'thumbnail',
        false,
        [
            'class'    => 'goetz-practice-area-item__scale-image',
            'alt'      => $scale_alt,
            'loading'  => 'lazy',
            'decoding' => 'async',
            'sizes'    => '36px',
        ]
    );
}

if ($scale_image === '' && trim($scale_url) !== '') {
    $scale_image = sprintf(
        '<img class="goetz-practice-area-item__scale-image" src="%1$s" alt="%2$s" loading="lazy" decoding="async">',
        esc_url($scale_url),
        esc_attr($scale_alt)
    );
}
?>
<li <?php echo get_block_wrapper_attributes(['class' => 'goetz-practice-area-item']); ?>>
    <span class="goetz-practice-area-item__scale"<?php if (trim($scale_alt) === ''): ?> aria-hidden="true"<?php endif; ?>><?php echo $scale_image; ?></span>
    <b class="goetz-practice-area-item__label"><?php echo esc_html($label); ?></b>
</li>
