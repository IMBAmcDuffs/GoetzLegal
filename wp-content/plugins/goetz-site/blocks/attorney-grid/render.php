<?php

$attrs = wp_parse_args(
    is_array($attributes) ? $attributes : [],
    ['heading' => 'Attorneys']
);

$heading = is_scalar($attrs['heading']) ? (string) $attrs['heading'] : 'Attorneys';
?>
<section <?php echo get_block_wrapper_attributes(['class' => 'goetz-attorney-grid goetz-section--attorneys']); ?>>
    <?php if (trim($heading) !== ''): ?>
        <h2 class="goetz-attorney-grid__heading"><?php echo esc_html($heading); ?></h2>
        <img class="goetz-attorney-grid__mark" src="<?php echo esc_url(GOETZ_SITE_URL . 'assets/seed/law-scale-icon-purple.png'); ?>" alt="" aria-hidden="true" width="40" height="39">
    <?php endif; ?>
    <div class="goetz-attorney-grid__cards">
        <?php echo $content; ?>
    </div>
</section>
