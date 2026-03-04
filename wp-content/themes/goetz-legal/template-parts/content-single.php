<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
    <header class="mx-auto flex max-w-5xl flex-col text-center">
        <h1 class="mt-6 font-heading text-4xl md:text-5xl font-bold tracking-tight text-primary"><?php the_title(); ?></h1>

        <?php if (!is_page()): ?>
            <time datetime="<?php echo esc_attr(get_the_date('c')); ?>" itemprop="datePublished" class="order-first text-sm text-gray-500"><?php echo esc_html(get_the_date()); ?></time>
            <p class="mt-6 text-sm font-semibold text-gray-700"><?php printf(__('by %s', 'goetz-legal'), get_the_author()); ?></p>
        <?php endif; ?>
    </header>

    <?php if (has_post_thumbnail()): ?>
        <div class="mt-10 sm:mt-20 mx-auto max-w-4xl rounded-2xl bg-light overflow-hidden">
            <?php the_post_thumbnail('large', ['class' => 'aspect-16/10 w-full object-cover']); ?>
        </div>
    <?php endif; ?>

    <div class="entry-content mx-auto max-w-3xl mt-10 sm:mt-20">
        <?php the_content(); ?>
    </div>
</article>
