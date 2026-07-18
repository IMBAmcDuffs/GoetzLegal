<?php

$attrs = wp_parse_args(
    is_array($attributes) ? $attributes : [],
    [
        'name'          => '',
        'role'          => '',
        'bio'           => '',
        'email'         => '',
        'imageUrl'      => '',
        'imageAlt'      => '',
        'profileUrl'    => '',
        'imageId'       => 0,
        'profileNewTab' => false,
    ]
);

$scalar = static fn(mixed $value): string => is_scalar($value) ? (string) $value : '';
$name = $scalar($attrs['name']);
$role = $scalar($attrs['role']);
$bio = $scalar($attrs['bio']);
$email = sanitize_email($scalar($attrs['email']));
$image_url = $scalar($attrs['imageUrl']);
$image_alt = $scalar($attrs['imageAlt']);
$profile_url = $scalar($attrs['profileUrl']);
$profile_new_tab = \Goetz\Site\normalize_boolean($attrs['profileNewTab']);
$class_name = $scalar($attrs['className'] ?? '');
$is_profile = preg_match('/(?:^|\s)is-style-profile(?:\s|$)/', $class_name) === 1;
$context = isset($block) && $block instanceof WP_Block && is_array($block->context)
    ? $block->context
    : [];
$grid_heading = $context['goetz/attorneyGridHeading'] ?? null;
$is_grid_card = is_scalar($grid_heading) && trim((string) $grid_heading) !== '';
$image_id = \Goetz\Site\valid_image_attachment_id($attrs['imageId']);
$image_html = '';
$name_markup = esc_html($name);

if ($is_profile) {
    $name_parts = preg_split('/\s+/', trim($name)) ?: [];
    if (count($name_parts) > 1) {
        $family_name = (string) array_pop($name_parts);
        $name_markup = sprintf(
            '<span class="goetz-attorney-card__accent">%1$s</span> %2$s',
            esc_html(implode(' ', $name_parts)),
            esc_html($family_name)
        );
    }
}

if ($image_id > 0) {
    $image_html = (string) wp_get_attachment_image(
        $image_id,
        'large',
        false,
        [
            'class'   => 'goetz-attorney-card__image',
            'alt'     => $image_alt !== '' ? $image_alt : $name,
            'loading' => 'lazy',
            'sizes'   => '(min-width: 782px) 50vw, 100vw',
        ]
    );
}

if ($image_html === '' && trim($image_url) !== '') {
    $image_html = sprintf(
        '<img class="goetz-attorney-card__image" src="%1$s" alt="%2$s" loading="lazy">',
        esc_url($image_url),
        esc_attr($image_alt !== '' ? $image_alt : $name)
    );
}
?>
<article <?php echo get_block_wrapper_attributes(['class' => 'goetz-attorney-card' . ($is_profile ? ' goetz-attorney-card--profile' : '')]); ?>>
    <?php echo $image_html; ?>
    <div class="goetz-attorney-card__body">
        <?php if ($is_profile): ?>
            <img class="goetz-attorney-card__mark" src="<?php echo esc_url(GOETZ_SITE_URL . 'assets/seed/law-scale-icon-purple.png'); ?>" alt="" aria-hidden="true" width="40" height="39">
        <?php endif; ?>
        <?php if ($is_grid_card): ?>
            <h3><?php echo $name_markup; ?></h3>
        <?php else: ?>
            <h2><?php echo $name_markup; ?></h2>
        <?php endif; ?>
        <?php if (trim($role) !== ''): ?>
            <p class="goetz-attorney-card__role"><?php echo esc_html($role); ?></p>
        <?php endif; ?>
        <?php if (trim($bio) !== ''): ?>
            <p><?php echo esc_html($bio); ?></p>
        <?php endif; ?>
        <div class="goetz-attorney-card__links">
            <?php if (trim($profile_url) !== ''): ?>
                <a href="<?php echo esc_url($profile_url); ?>"<?php if ($profile_new_tab): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?php esc_html_e('Read Full Bio', 'goetz-site'); ?></a>
            <?php endif; ?>
            <?php if ($email !== '' && is_email($email)): ?>
                <a href="<?php echo esc_url('mailto:' . $email); ?>"><?php echo esc_html($is_profile ? sprintf(__('Email %s', 'goetz-site'), $name) : __('Email', 'goetz-site')); ?></a>
            <?php endif; ?>
        </div>
    </div>
</article>
