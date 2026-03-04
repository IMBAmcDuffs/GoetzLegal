<?php
/**
 * Template Name: Practice Areas
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
            <?php esc_html_e('We concentrate our practice in areas where we can deliver the most impactful results for our clients.', 'goetz-legal'); ?>
        </p>
    </div>
</section>

<!-- Practice Areas -->
<section class="py-16 md:py-24 bg-white">
    <div class="container mx-auto px-4">
        <?php
        $practice_areas = new WP_Query([
            'post_type'      => 'practice_area',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ]);

        if ($practice_areas->have_posts()): ?>
            <div class="space-y-16">
                <?php while ($practice_areas->have_posts()): $practice_areas->the_post(); ?>
                    <div class="max-w-4xl mx-auto">
                        <div class="md:flex md:gap-8 items-start">
                            <?php if (has_post_thumbnail()): ?>
                                <div class="md:w-1/3 mb-6 md:mb-0">
                                    <div class="rounded-2xl overflow-hidden">
                                        <?php the_post_thumbnail('medium', ['class' => 'w-full h-auto object-cover']); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="<?php echo has_post_thumbnail() ? 'md:w-2/3' : 'w-full'; ?>">
                                <h2 class="font-heading text-2xl md:text-3xl font-bold text-primary mb-4"><?php the_title(); ?></h2>
                                <div class="text-gray-600 leading-relaxed">
                                    <?php the_excerpt(); ?>
                                </div>
                                <a href="<?php the_permalink(); ?>" class="inline-flex mt-4 rounded-full px-5 py-2 text-sm font-semibold bg-primary text-white hover:bg-primary/90 transition !no-underline">
                                    <?php esc_html_e('Learn More', 'goetz-legal'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <!-- Fallback Static Content -->
            <div class="space-y-16 max-w-4xl mx-auto">
                <!-- Corporate Law -->
                <div class="bg-light rounded-2xl p-8 md:p-12">
                    <div class="w-14 h-14 bg-primary/10 rounded-xl flex items-center justify-center mb-6">
                        <svg class="w-7 h-7 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    </div>
                    <h2 class="font-heading text-2xl md:text-3xl font-bold text-primary mb-4"><?php esc_html_e('Corporate Law', 'goetz-legal'); ?></h2>
                    <p class="text-gray-600 leading-relaxed mb-4">
                        <?php esc_html_e('Our corporate law practice provides comprehensive legal services for businesses at every stage of their lifecycle. From business formation and entity selection to complex mergers and acquisitions, we guide our clients through the legal landscape of business operations.', 'goetz-legal'); ?>
                    </p>
                    <ul class="text-gray-600 space-y-2 mb-6">
                        <li class="flex items-start gap-2"><span class="text-secondary font-bold">✓</span> <?php esc_html_e('Business Formation & Entity Selection', 'goetz-legal'); ?></li>
                        <li class="flex items-start gap-2"><span class="text-secondary font-bold">✓</span> <?php esc_html_e('Contract Drafting & Review', 'goetz-legal'); ?></li>
                        <li class="flex items-start gap-2"><span class="text-secondary font-bold">✓</span> <?php esc_html_e('Mergers & Acquisitions', 'goetz-legal'); ?></li>
                        <li class="flex items-start gap-2"><span class="text-secondary font-bold">✓</span> <?php esc_html_e('Corporate Governance', 'goetz-legal'); ?></li>
                        <li class="flex items-start gap-2"><span class="text-secondary font-bold">✓</span> <?php esc_html_e('Regulatory Compliance', 'goetz-legal'); ?></li>
                    </ul>
                </div>

                <!-- Construction Law -->
                <div class="bg-light rounded-2xl p-8 md:p-12">
                    <div class="w-14 h-14 bg-primary/10 rounded-xl flex items-center justify-center mb-6">
                        <svg class="w-7 h-7 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    </div>
                    <h2 class="font-heading text-2xl md:text-3xl font-bold text-primary mb-4"><?php esc_html_e('Construction Law', 'goetz-legal'); ?></h2>
                    <p class="text-gray-600 leading-relaxed mb-4">
                        <?php esc_html_e('Our construction law attorneys represent contractors, subcontractors, developers, property owners, and suppliers in all aspects of construction-related legal matters. We understand the unique challenges of the construction industry in Florida.', 'goetz-legal'); ?>
                    </p>
                    <ul class="text-gray-600 space-y-2 mb-6">
                        <li class="flex items-start gap-2"><span class="text-secondary font-bold">✓</span> <?php esc_html_e('Construction Litigation', 'goetz-legal'); ?></li>
                        <li class="flex items-start gap-2"><span class="text-secondary font-bold">✓</span> <?php esc_html_e('Contract Disputes', 'goetz-legal'); ?></li>
                        <li class="flex items-start gap-2"><span class="text-secondary font-bold">✓</span> <?php esc_html_e('Lien Claims & Foreclosure', 'goetz-legal'); ?></li>
                        <li class="flex items-start gap-2"><span class="text-secondary font-bold">✓</span> <?php esc_html_e('Construction Defect Claims', 'goetz-legal'); ?></li>
                        <li class="flex items-start gap-2"><span class="text-secondary font-bold">✓</span> <?php esc_html_e('Regulatory & Permitting Issues', 'goetz-legal'); ?></li>
                    </ul>
                </div>
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
