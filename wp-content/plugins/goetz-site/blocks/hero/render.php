<?php

$attrs = wp_parse_args(
    is_array($attributes) ? $attributes : [],
    [
        'eyebrow'     => 'GoetzLegal.com',
        'heading'     => 'A law firm with seasoned trial attorneys in Fort Myers, Florida.',
        'content'     => '',
        'imageUrl'    => '',
        'imageAlt'    => '',
        'buttonText'  => 'Learn More About Us',
        'buttonUrl'   => '/james-l-goetz/',
        'imageId'     => 0,
        'buttonNewTab'=> false,
    ]
);

$scalar = static fn(mixed $value): string => is_scalar($value) ? (string) $value : '';
$eyebrow = $scalar($attrs['eyebrow']);
$heading = \Goetz\Site\heading_markup($scalar($attrs['heading']));
$content = \Goetz\Site\rich_text_markup($scalar($attrs['content']));
$image_url = $scalar($attrs['imageUrl']);
$image_alt = $scalar($attrs['imageAlt']);
$button_text = $scalar($attrs['buttonText']);
$button_url = $scalar($attrs['buttonUrl']);
$button_new_tab = \Goetz\Site\normalize_boolean($attrs['buttonNewTab']);
$image_id = \Goetz\Site\valid_image_attachment_id($attrs['imageId']);
$image_html = '';

if ($image_id > 0) {
    $image_html = (string) wp_get_attachment_image(
        $image_id,
        'full',
        false,
        [
            'class'         => 'goetz-hero__image',
            'alt'           => $image_alt,
            'loading'       => 'eager',
            'fetchpriority' => 'high',
            'sizes'         => '(min-width: 1180px) 508px, (min-width: 782px) calc(47vw - 47px), 85vw',
        ]
    );
}

if ($image_html === '' && trim($image_url) !== '') {
    $image_html = sprintf(
        '<img class="goetz-hero__image" src="%1$s" alt="%2$s" loading="eager" fetchpriority="high">',
        esc_url($image_url),
        esc_attr($image_alt)
    );
}
?>
<section <?php echo get_block_wrapper_attributes(['class' => 'goetz-hero']); ?>>
    <div class="goetz-hero__content">
        <?php if (trim($eyebrow) !== ''): ?>
            <p class="goetz-hero__eyebrow"><?php echo esc_html($eyebrow); ?></p>
        <?php endif; ?>
        <h1><?php echo $heading; ?></h1>
        <?php if (trim($content) !== ''): ?>
            <p><?php echo $content; ?></p>
        <?php endif; ?>
        <?php if (trim($button_text) !== '' && trim($button_url) !== ''): ?>
            <a class="goetz-button" href="<?php echo esc_url($button_url); ?>"<?php if ($button_new_tab): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?php echo esc_html($button_text); ?></a>
        <?php endif; ?>
    </div>
    <?php if ($image_html !== ''): ?>
        <figure class="goetz-hero__media">
            <?php echo $image_html; ?>
        </figure>
    <?php endif; ?>
</section>
