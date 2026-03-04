<?php
/**
 * Template Name: About Us
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
            <?php esc_html_e('Learn about the history, values, and mission of our firm.', 'goetz-legal'); ?>
        </p>
    </div>
</section>

<!-- About Content -->
<section class="py-16 md:py-24 bg-white">
    <div class="container mx-auto px-4">
        <div class="max-w-4xl mx-auto">
            <?php if (have_posts()): ?>
                <?php while (have_posts()): the_post(); ?>
                    <div class="entry-content prose prose-lg max-w-none">
                        <?php the_content(); ?>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="space-y-8">
                    <div>
                        <h2 class="font-heading text-2xl md:text-3xl font-bold text-primary mb-4"><?php esc_html_e('Our Firm', 'goetz-legal'); ?></h2>
                        <p class="text-gray-600 leading-relaxed">
                            <?php esc_html_e('Goetz & Goetz is a Fort Myers, Florida law firm dedicated to providing exceptional legal services in Corporate Law and Construction Law. Our attorneys bring decades of combined experience to every client engagement, ensuring thorough and strategic representation.', 'goetz-legal'); ?>
                        </p>
                    </div>

                    <div>
                        <h2 class="font-heading text-2xl md:text-3xl font-bold text-primary mb-4"><?php esc_html_e('Our Approach', 'goetz-legal'); ?></h2>
                        <p class="text-gray-600 leading-relaxed">
                            <?php esc_html_e('We believe in building lasting relationships with our clients through trust, communication, and results. Every case receives personalized attention from experienced attorneys who understand the complexities of Florida law.', 'goetz-legal'); ?>
                        </p>
                    </div>

                    <div>
                        <h2 class="font-heading text-2xl md:text-3xl font-bold text-primary mb-4"><?php esc_html_e('Community Commitment', 'goetz-legal'); ?></h2>
                        <p class="text-gray-600 leading-relaxed">
                            <?php esc_html_e('As a Fort Myers-based firm, we are deeply committed to our community. We actively participate in local bar associations, community organizations, and pro bono work to give back to the community we serve.', 'goetz-legal'); ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php
get_footer();
