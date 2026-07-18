<?php

$groups = isset($attributes['groups']) && is_array($attributes['groups'])
    ? array_values($attributes['groups'])
    : [];
$image_url = isset($attributes['imageUrl']) && is_scalar($attributes['imageUrl'])
    ? (string) $attributes['imageUrl']
    : '';
$image_alt = isset($attributes['imageAlt']) && is_scalar($attributes['imageAlt'])
    ? (string) $attributes['imageAlt']
    : '';
$image_id = \Goetz\Site\valid_image_attachment_id($attributes['imageId'] ?? 0);
$image_html = '';

if ($image_id > 0) {
    $image_html = (string) wp_get_attachment_image(
        $image_id,
        'full',
        false,
        [
            'alt'     => $image_alt,
            'loading' => 'lazy',
            'sizes'   => '(min-width: 782px) 50vw, 100vw',
        ]
    );
}

if ($image_html === '') {
    if (trim($image_url) === '') {
        $image_url = function_exists('goetz_legal_asset_url')
            ? goetz_legal_asset_url('law-firm-img.jpg', 'https://goetzlegal.com/wp-content/uploads/2022/08/law-firm-img.jpg')
            : 'https://goetzlegal.com/wp-content/uploads/2022/08/law-firm-img.jpg';
    }
    $image_html = sprintf(
        '<img src="%1$s" alt="%2$s" loading="lazy">',
        esc_url($image_url),
        esc_attr($image_alt)
    );
}
?>
<section <?php echo get_block_wrapper_attributes(['class' => 'goetz-resource-links']); ?>>
    <div class="goetz-resource-links__media">
        <?php echo $image_html; ?>
    </div>

    <div class="goetz-resource-links__content">
        <?php foreach ($groups as $group): ?>
            <?php
            if (! is_array($group)) {
                continue;
            }
            $heading = isset($group['heading']) && is_scalar($group['heading'])
                ? (string) $group['heading']
                : '';
            $raw_links = isset($group['links']) && is_array($group['links'])
                ? array_values($group['links'])
                : [];
            $links = array_values(array_filter($raw_links, 'is_array'));
            $split_at = max(1, (int) ceil(count($links) / 2));
            $columns = array_chunk($links, $split_at);
            $heading_parts = preg_split('/\s+/', trim($heading), 2);
            ?>
            <section class="goetz-resource-links__group">
                <h2>
                    <?php if (! empty($heading_parts[0])): ?>
                        <strong><?php echo esc_html($heading_parts[0]); ?></strong><?php echo isset($heading_parts[1]) ? ' ' . esc_html($heading_parts[1]) : ''; ?>
                    <?php endif; ?>
                </h2>
                <div class="goetz-resource-links__columns">
                    <?php foreach ($columns as $column): ?>
                        <ul>
                            <?php foreach ($column as $link): ?>
                                <?php
                                $label = isset($link['label']) && is_scalar($link['label'])
                                    ? (string) $link['label']
                                    : '';
                                $url = isset($link['url']) && is_scalar($link['url'])
                                    ? (string) $link['url']
                                    : '';
                                $new_tab = array_key_exists('newTab', $link)
                                    ? \Goetz\Site\normalize_boolean($link['newTab'])
                                    : false;
                                ?>
                                <li>
                                    <span class="goetz-resource-links__icon" aria-hidden="true"></span>
                                    <a href="<?php echo esc_url($url); ?>"<?php if ($new_tab): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?php echo esc_html($label); ?></a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</section>
