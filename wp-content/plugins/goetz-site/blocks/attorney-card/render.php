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
$image_id = \Goetz\Site\valid_image_attachment_id($attrs['imageId']);
$image_html = '';

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
<article <?php echo get_block_wrapper_attributes(['class' => 'goetz-attorney-card']); ?>>
    <?php echo $image_html; ?>
    <div class="goetz-attorney-card__body">
        <h2><?php echo esc_html($name); ?></h2>
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
                <a href="<?php echo esc_url('mailto:' . $email); ?>"><?php esc_html_e('Email', 'goetz-site'); ?></a>
            <?php endif; ?>
        </div>
    </div>
</article>
