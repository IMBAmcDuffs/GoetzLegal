<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
    <?php if (is_page() && !is_front_page()): ?>
        <?php
        $page_hero_url = function_exists('goetz_legal_asset_url')
            ? goetz_legal_asset_url('bann-img.jpg', 'https://goetzlegal.com/wp-content/uploads/2022/08/bann-img.jpg')
            : 'https://goetzlegal.com/wp-content/uploads/2022/08/bann-img.jpg';
        $page_hero_style = 'background-image: linear-gradient(rgb(0 0 0 / 50%), rgb(0 0 0 / 50%)), url(' . esc_url($page_hero_url) . ');';
        ?>
        <header class="goetz-page-hero" style="<?php echo esc_attr($page_hero_style); ?>">
            <h1><?php the_title(); ?></h1>
        </header>
    <?php endif; ?>

    <?php if (!is_page()): ?>
        <header class="mx-auto flex max-w-5xl flex-col text-center">
            <h1 class="mt-6 font-heading text-4xl md:text-5xl font-bold tracking-tight text-primary"><?php the_title(); ?></h1>
            <time datetime="<?php echo esc_attr(get_the_date('c')); ?>" itemprop="datePublished" class="order-first text-sm text-gray-500"><?php echo esc_html(get_the_date()); ?></time>
            <p class="mt-6 text-sm font-semibold text-gray-700"><?php printf(esc_html__('by %s', 'goetz-legal'), esc_html(get_the_author())); ?></p>
        </header>
    <?php endif; ?>

    <?php if (has_post_thumbnail()): ?>
        <div class="mt-10 sm:mt-20 mx-auto max-w-4xl rounded-2xl bg-light overflow-hidden">
            <?php the_post_thumbnail('large', ['class' => 'aspect-16/10 w-full object-cover']); ?>
        </div>
    <?php endif; ?>

    <div class="<?php echo esc_attr(is_page() ? 'entry-content goetz-page-content' : 'entry-content mx-auto max-w-5xl px-4 py-12 md:py-16'); ?>">
        <?php the_content(); ?>
    </div>
</article>
