<?php

$attrs = wp_parse_args(
    is_array($attributes) ? $attributes : [],
    [
        'leftImageId'  => 0,
        'leftImageUrl' => '',
        'leftImageAlt' => '',
        'rightImageId' => 0,
        'rightImageUrl'=> '',
        'rightImageAlt'=> '',
        'heading'      => '<strong>Mr. Goetz welcomes</strong> you to browse this site to learn more about his firm and get information.',
        'contentPrefix'=> 'If you would like to speak with Mr. Goetz, please call',
        'phoneLabel'   => '',
        'phoneUrl'     => '',
        'contentJoin'  => 'or contact the firm',
        'onlineLabel'  => 'online',
        'onlineUrl'    => '',
    ]
);

$scalar = static fn(mixed $value): string => is_scalar($value) ? (string) $value : '';
$render_image = static function (
    mixed $raw_id,
    string $url,
    string $alt,
    string $side
): string {
    $attachment_id = \Goetz\Site\valid_image_attachment_id($raw_id);
    if ($attachment_id > 0) {
        $image = wp_get_attachment_image(
            $attachment_id,
            'full',
            false,
            [
                'class'    => 'goetz-intro__image',
                'alt'      => $alt,
                'loading'  => 'lazy',
                'decoding' => 'async',
                'sizes'    => '(min-width: 601px) 20vw, 85vw',
            ]
        );
        if (is_string($image) && $image !== '') {
            return $image;
        }
    }

    if (trim($url) === '') {
        return '';
    }

    return sprintf(
        '<img class="goetz-intro__image" src="%1$s" alt="%2$s" loading="lazy" decoding="async" data-goetz-welcome-image="%3$s">',
        esc_url($url),
        esc_attr($alt),
        esc_attr($side)
    );
};

$normalize_phone_url = static function (string $value, string $fallback_e164): string {
    $candidate = trim($value);
    if (str_starts_with(strtolower($candidate), 'tel:')) {
        $candidate = trim(substr($candidate, 4));
    }
    if (preg_match('/^\+[1-9]\d{7,14}$/', $candidate) === 1) {
        return 'tel:' . $candidate;
    }

    return preg_match('/^\+[1-9]\d{7,14}$/', $fallback_e164) === 1
        ? 'tel:' . $fallback_e164
        : 'tel:+12399362841';
};

$normalize_online_url = static function (string $value, string $fallback): string {
    $candidate = trim($value) !== '' ? trim($value) : trim($fallback);
    $candidate = esc_url_raw($candidate, ['http', 'https']);
    if ($candidate !== '' && str_starts_with($candidate, '/') && ! str_starts_with($candidate, '//')) {
        return $candidate;
    }

    $parts = $candidate !== '' ? wp_parse_url($candidate) : false;
    if (is_array($parts)
        && isset($parts['scheme'], $parts['host'])
        && in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
        && (string) $parts['host'] !== '') {
        return $candidate;
    }

    if ($candidate !== trim($fallback)) {
        return $fallback;
    }

    return '/contact/';
};

$left_image = $render_image(
    $attrs['leftImageId'],
    $scalar($attrs['leftImageUrl']),
    $scalar($attrs['leftImageAlt']),
    'left'
);
$right_image = $render_image(
    $attrs['rightImageId'],
    $scalar($attrs['rightImageUrl']),
    $scalar($attrs['rightImageAlt']),
    'right'
);
$heading = \Goetz\Site\heading_markup($scalar($attrs['heading']));
$content_prefix = $scalar($attrs['contentPrefix']);
$phone_label = trim($scalar($attrs['phoneLabel']));
$phone_label = $phone_label !== ''
    ? $phone_label
    : (function_exists('goetz_site_get_setting')
        ? (string) goetz_site_get_setting('phone_display', '(239) 936-2841')
        : '(239) 936-2841');
$phone_e164 = function_exists('goetz_site_get_setting')
    ? (string) goetz_site_get_setting('phone_e164', '+12399362841')
    : '+12399362841';
$phone_url = $normalize_phone_url($scalar($attrs['phoneUrl']), $phone_e164);
$content_join = $scalar($attrs['contentJoin']);
$online_label = trim($scalar($attrs['onlineLabel']));
$online_label = $online_label !== '' ? $online_label : 'online';
$online_url = $normalize_online_url($scalar($attrs['onlineUrl']), '/contact/');
$scale_icon_url = defined('GOETZ_SITE_URL')
    ? GOETZ_SITE_URL . 'assets/seed/law-scale-icon-purple.png'
    : plugins_url('../../assets/seed/law-scale-icon-purple.png', __FILE__);
?>
<section <?php echo get_block_wrapper_attributes(['class' => 'goetz-welcome goetz-intro-section']); ?>>
    <div class="goetz-intro">
        <figure class="goetz-intro__media goetz-intro__media--left">
            <?php echo $left_image; ?>
        </figure>
        <div class="goetz-intro__content">
            <h2 class="goetz-intro__heading"><?php echo $heading; ?></h2>
            <img class="goetz-intro__icon" src="<?php echo esc_url($scale_icon_url); ?>" alt="" width="40" height="39" decoding="async" aria-hidden="true">
            <p class="goetz-intro__copy">
                <?php echo esc_html($content_prefix); ?>
                <a href="<?php echo esc_url($phone_url); ?>"><?php echo esc_html($phone_label); ?></a>
                <?php echo esc_html($content_join); ?>
                <a href="<?php echo esc_url($online_url); ?>"><?php echo esc_html($online_label); ?></a>.
            </p>
        </div>
        <figure class="goetz-intro__media goetz-intro__media--right">
            <?php echo $right_image; ?>
        </figure>
    </div>
</section>
