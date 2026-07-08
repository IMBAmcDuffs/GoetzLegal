<?php
$attrs = wp_parse_args(
    $attributes,
    array(
        'name'       => '',
        'role'       => '',
        'bio'        => '',
        'email'      => '',
        'imageUrl'   => '',
        'imageAlt'   => '',
        'profileUrl' => '',
    )
);
?>
<article <?php echo get_block_wrapper_attributes(array('class' => 'goetz-attorney-card')); ?>>
    <?php if (!empty($attrs['imageUrl'])): ?>
        <img class="goetz-attorney-card__image" src="<?php echo esc_url($attrs['imageUrl']); ?>" alt="<?php echo esc_attr($attrs['imageAlt'] ?: $attrs['name']); ?>" loading="lazy">
    <?php endif; ?>
    <div class="goetz-attorney-card__body">
        <h2><?php echo esc_html($attrs['name']); ?></h2>
        <?php if (!empty($attrs['role'])): ?>
            <p class="goetz-attorney-card__role"><?php echo esc_html($attrs['role']); ?></p>
        <?php endif; ?>
        <?php if (!empty($attrs['bio'])): ?>
            <p><?php echo esc_html($attrs['bio']); ?></p>
        <?php endif; ?>
        <div class="goetz-attorney-card__links">
            <?php if (!empty($attrs['profileUrl'])): ?>
                <a href="<?php echo esc_url($attrs['profileUrl']); ?>"><?php esc_html_e('Read Full Bio', 'goetz-legal'); ?></a>
            <?php endif; ?>
            <?php if (!empty($attrs['email'])): ?>
                <a href="mailto:<?php echo esc_attr($attrs['email']); ?>"><?php esc_html_e('Email', 'goetz-legal'); ?></a>
            <?php endif; ?>
        </div>
    </div>
</article>

