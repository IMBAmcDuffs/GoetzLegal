<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    fwrite(STDERR, "compat-site.php must run through WP-CLI.\n");
    exit(1);
}

$approved_pages = [
    'home'             => 'Home',
    'james-l-goetz'    => 'James L. Goetz',
    'gregory-w-goetz'  => 'Gregory W. Goetz',
    'staff'            => 'Staff',
    'questions'        => 'Questions',
    'links'            => 'Links',
    'contact'          => 'Contact',
];

$page_ids = [];
foreach ($approved_pages as $slug => $title) {
    $page = get_page_by_path($slug, OBJECT, 'page');
    if ($page instanceof WP_Post) {
        $page_ids[$slug] = (int) $page->ID;
        continue;
    }

    $page_id = wp_insert_post(
        [
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_name'    => $slug,
            'post_title'   => $title,
            'post_content' => sprintf('Compatibility fixture for %s.', $title),
        ],
        true
    );

    if (is_wp_error($page_id)) {
        throw new RuntimeException($page_id->get_error_message());
    }

    $page_ids[$slug] = (int) $page_id;
}

update_option('show_on_front', 'page');
update_option('page_on_front', $page_ids['home']);
update_option('page_for_posts', 0);

$published_slugs = get_posts(
    [
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'post_name__in'  => array_keys($approved_pages),
    ]
);

if (count($published_slugs) !== count($approved_pages)) {
    throw new RuntimeException('The compatibility fixture did not create all seven approved pages.');
}

WP_CLI::log(
    wp_json_encode(
        [
            'front_page' => $page_ids['home'],
            'pages'      => $page_ids,
        ],
        JSON_UNESCAPED_SLASHES
    )
);
