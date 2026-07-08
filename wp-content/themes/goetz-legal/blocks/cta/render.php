<?php
$attrs = wp_parse_args(
    $attributes,
    array(
        'eyebrow'    => 'WE ARE AN EXPERIENCED TEAM',
        'heading'    => 'NEED A LAWYER?',
        'buttonText' => 'Get Consultation',
        'buttonUrl'  => '/contact/',
    )
);

$background_url = function_exists('goetz_legal_asset_url')
    ? goetz_legal_asset_url('law-updates-bg.jpg', 'https://goetzlegal.com/wp-content/uploads/2022/08/law-updates-bg.jpg')
    : 'https://goetzlegal.com/wp-content/uploads/2022/08/law-updates-bg.jpg';
$style = 'background-image: linear-gradient(rgb(45 45 45 / 90%), rgb(45 45 45 / 90%)), url(' . esc_url($background_url) . ');';
$heading = (string) $attrs['heading'];

if ($heading === 'NEED A LAWYER?') {
    $heading = 'NEED A <b>LAWYER?</b>';
}
?>
<section <?php echo get_block_wrapper_attributes(array('class' => 'goetz-cta', 'style' => $style)); ?>>
    <div>
        <p><?php echo esc_html($attrs['eyebrow']); ?></p>
        <h2><?php echo wp_kses($heading, array('b' => array(), 'strong' => array(), 'br' => array())); ?></h2>
    </div>
    <a class="goetz-button" href="<?php echo esc_url($attrs['buttonUrl']); ?>"><?php echo esc_html($attrs['buttonText']); ?></a>
</section>
