<?php
$attrs = wp_parse_args(
    $attributes,
    array(
        'eyebrow'    => 'GoetzLegal.com',
        'heading'    => 'A law firm with seasoned trial attorneys in Fort Myers, Florida.',
        'content'    => '',
        'imageUrl'   => '',
        'imageAlt'   => '',
        'buttonText' => 'Learn More About Us',
        'buttonUrl'  => '/james-l-goetz/',
    )
);
?>
<section <?php echo get_block_wrapper_attributes(array('class' => 'goetz-hero')); ?>>
    <div class="goetz-hero__content">
        <?php if (!empty($attrs['eyebrow'])): ?>
            <p class="goetz-hero__eyebrow"><?php echo esc_html($attrs['eyebrow']); ?></p>
        <?php endif; ?>
        <h1><?php echo wp_kses_post($attrs['heading']); ?></h1>
        <?php if (!empty($attrs['content'])): ?>
            <p><?php echo esc_html($attrs['content']); ?></p>
        <?php endif; ?>
        <?php if (!empty($attrs['buttonText']) && !empty($attrs['buttonUrl'])): ?>
            <a class="goetz-button" href="<?php echo esc_url($attrs['buttonUrl']); ?>"><?php echo esc_html($attrs['buttonText']); ?></a>
        <?php endif; ?>
    </div>
    <?php if (!empty($attrs['imageUrl'])): ?>
        <figure class="goetz-hero__media">
            <img src="<?php echo esc_url($attrs['imageUrl']); ?>" alt="<?php echo esc_attr($attrs['imageAlt']); ?>" loading="eager">
        </figure>
    <?php endif; ?>
</section>

