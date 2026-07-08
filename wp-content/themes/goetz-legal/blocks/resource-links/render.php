<?php
$groups = isset($attributes['groups']) && is_array($attributes['groups']) ? $attributes['groups'] : array();
$image_url = isset($attributes['imageUrl']) ? (string) $attributes['imageUrl'] : '';
$image_alt = isset($attributes['imageAlt']) ? (string) $attributes['imageAlt'] : '';

if (!$image_url) {
    $image_url = function_exists('goetz_legal_asset_url')
        ? goetz_legal_asset_url('law-firm-img.jpg', 'https://goetzlegal.com/wp-content/uploads/2022/08/law-firm-img.jpg')
        : 'https://goetzlegal.com/wp-content/uploads/2022/08/law-firm-img.jpg';
}

?>
<section <?php echo get_block_wrapper_attributes(array('class' => 'goetz-resource-links')); ?>>
    <div class="goetz-resource-links__media" aria-hidden="true">
        <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($image_alt); ?>" loading="lazy">
    </div>

    <div class="goetz-resource-links__content">
        <?php foreach ($groups as $group): ?>
            <?php
            $heading = isset($group['heading']) ? (string) $group['heading'] : '';
            $links   = isset($group['links']) && is_array($group['links']) ? array_values($group['links']) : array();
            $split_at = max(1, (int) ceil(count($links) / 2));
            $columns = array_chunk($links, $split_at);
            $heading_parts = preg_split('/\s+/', trim($heading), 2);
            ?>
            <section class="goetz-resource-links__group">
                <h2>
                    <?php if (!empty($heading_parts[0])): ?>
                        <strong><?php echo esc_html($heading_parts[0]); ?></strong><?php echo isset($heading_parts[1]) ? ' ' . esc_html($heading_parts[1]) : ''; ?>
                    <?php endif; ?>
                </h2>
                <div class="goetz-resource-links__columns">
                    <?php foreach ($columns as $column): ?>
                        <ul>
                            <?php foreach ($column as $link): ?>
                                <?php
                                $label = isset($link['label']) ? (string) $link['label'] : '';
                                $url   = isset($link['url']) ? (string) $link['url'] : '';
                                ?>
                                <li>
                                    <span class="goetz-resource-links__icon" aria-hidden="true"></span>
                                    <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($label); ?></a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</section>
