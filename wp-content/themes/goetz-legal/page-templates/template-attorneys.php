<?php
/**
 * Template Name: Attorneys
 * Template Post Type: page
 *
 * @package GoetzLegal
 */

get_header();
?>

<!-- Page Header -->
<section class="bg-primary text-white py-16">
    <div class="container mx-auto px-4 text-center">
        <h1 class="font-heading text-4xl md:text-5xl font-bold mb-4"><?php the_title(); ?></h1>
        <p class="text-white/80 text-lg max-w-2xl mx-auto">
            <?php esc_html_e('Our team of dedicated attorneys brings years of experience and a passion for justice to every case.', 'goetz-legal'); ?>
        </p>
    </div>
</section>

<!-- Attorneys Grid -->
<section class="py-16 md:py-24 bg-white">
    <div class="container mx-auto px-4">
        <?php
        $attorneys = new WP_Query([
            'post_type'      => 'attorney',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ]);

        if ($attorneys->have_posts()): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <?php while ($attorneys->have_posts()): $attorneys->the_post(); ?>
                    <div class="bg-light rounded-2xl overflow-hidden hover:shadow-lg transition-shadow">
                        <?php if (has_post_thumbnail()): ?>
                            <div class="aspect-4/3 overflow-hidden">
                                <?php the_post_thumbnail('medium_large', ['class' => 'w-full h-full object-cover']); ?>
                            </div>
                        <?php else: ?>
                            <div class="aspect-4/3 bg-primary/10 flex items-center justify-center">
                                <svg class="w-20 h-20 text-primary/30" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                            </div>
                        <?php endif; ?>
                        <div class="p-6">
                            <h2 class="font-heading text-xl font-bold text-primary mb-1"><?php the_title(); ?></h2>
                            <?php if ($position = get_post_meta(get_the_ID(), 'attorney_position', true)): ?>
                                <p class="text-secondary font-semibold text-sm mb-3"><?php echo esc_html($position); ?></p>
                            <?php endif; ?>
                            <div class="text-gray-600 text-sm leading-relaxed">
                                <?php the_excerpt(); ?>
                            </div>
                            <a href="<?php the_permalink(); ?>" class="inline-flex mt-4 text-secondary font-semibold hover:text-secondary/80 transition-colors !no-underline text-sm">
                                <?php esc_html_e('View Full Profile →', 'goetz-legal'); ?>
                            </a>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <!-- Fallback Static Content -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-5xl mx-auto">
                <?php
                $static_attorneys = [
                    [
                        'name'  => __('James L. Goetz', 'goetz-legal'),
                        'title' => __('Senior Partner', 'goetz-legal'),
                        'bio'   => __('With decades of experience in corporate and business law, James provides strategic counsel to businesses throughout Southwest Florida. He has been instrumental in guiding companies through complex transactions, mergers, and regulatory compliance matters.', 'goetz-legal'),
                    ],
                    [
                        'name'  => __('Gregory W. Goetz', 'goetz-legal'),
                        'title' => __('Partner', 'goetz-legal'),
                        'bio'   => __('Gregory focuses his practice on construction law and commercial litigation. He represents contractors, developers, and property owners in disputes involving construction defects, lien claims, contract breaches, and regulatory matters.', 'goetz-legal'),
                    ],
                    [
                        'name'  => __('Dawn Heitl', 'goetz-legal'),
                        'title' => __('Associate Attorney', 'goetz-legal'),
                        'bio'   => __('Dawn brings a thorough and detail-oriented approach to corporate and construction law matters. Her commitment to client service and comprehensive legal research ensures that clients receive well-rounded legal solutions.', 'goetz-legal'),
                    ],
                ];

                foreach ($static_attorneys as $attorney): ?>
                    <div class="bg-light rounded-2xl overflow-hidden hover:shadow-lg transition-shadow">
                        <div class="aspect-4/3 bg-primary/10 flex items-center justify-center">
                            <svg class="w-20 h-20 text-primary/30" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                        </div>
                        <div class="p-6">
                            <h2 class="font-heading text-xl font-bold text-primary mb-1"><?php echo esc_html($attorney['name']); ?></h2>
                            <p class="text-secondary font-semibold text-sm mb-3"><?php echo esc_html($attorney['title']); ?></p>
                            <p class="text-gray-600 text-sm leading-relaxed"><?php echo esc_html($attorney['bio']); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif;
        wp_reset_postdata();
        ?>
    </div>
</section>

<!-- Page Content -->
<?php if (have_posts()): ?>
    <section class="py-12 bg-light">
        <div class="container mx-auto px-4">
            <div class="entry-content mx-auto max-w-3xl">
                <?php while (have_posts()): the_post(); ?>
                    <?php the_content(); ?>
                <?php endwhile; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php
get_footer();
