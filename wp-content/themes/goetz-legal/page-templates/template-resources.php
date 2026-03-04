<?php
/**
 * Template Name: Resources
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
            <?php esc_html_e('Helpful legal resources and links for Florida and federal matters.', 'goetz-legal'); ?>
        </p>
    </div>
</section>

<!-- Resources Content -->
<section class="py-16 md:py-24 bg-white">
    <div class="container mx-auto px-4">
        <div class="max-w-4xl mx-auto">
            <?php if (have_posts()): ?>
                <?php while (have_posts()): the_post(); ?>
                    <div class="entry-content">
                        <?php the_content(); ?>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Florida Resources -->
                    <div class="bg-light rounded-2xl p-8">
                        <h2 class="font-heading text-xl font-bold text-primary mb-4"><?php esc_html_e('Florida Legal Resources', 'goetz-legal'); ?></h2>
                        <ul class="space-y-3">
                            <li><a href="https://www.flcourts.org/" target="_blank" rel="noopener noreferrer" class="text-primary hover:text-secondary transition-colors"><?php esc_html_e('Florida Courts', 'goetz-legal'); ?></a></li>
                            <li><a href="https://www.floridabar.org/" target="_blank" rel="noopener noreferrer" class="text-primary hover:text-secondary transition-colors"><?php esc_html_e('The Florida Bar', 'goetz-legal'); ?></a></li>
                            <li><a href="http://www.leg.state.fl.us/statutes/" target="_blank" rel="noopener noreferrer" class="text-primary hover:text-secondary transition-colors"><?php esc_html_e('Florida Statutes', 'goetz-legal'); ?></a></li>
                            <li><a href="https://dos.myflorida.com/sunbiz/" target="_blank" rel="noopener noreferrer" class="text-primary hover:text-secondary transition-colors"><?php esc_html_e('Florida Division of Corporations', 'goetz-legal'); ?></a></li>
                        </ul>
                    </div>

                    <!-- Federal Resources -->
                    <div class="bg-light rounded-2xl p-8">
                        <h2 class="font-heading text-xl font-bold text-primary mb-4"><?php esc_html_e('Federal Legal Resources', 'goetz-legal'); ?></h2>
                        <ul class="space-y-3">
                            <li><a href="https://www.uscourts.gov/" target="_blank" rel="noopener noreferrer" class="text-primary hover:text-secondary transition-colors"><?php esc_html_e('United States Courts', 'goetz-legal'); ?></a></li>
                            <li><a href="https://www.law.cornell.edu/" target="_blank" rel="noopener noreferrer" class="text-primary hover:text-secondary transition-colors"><?php esc_html_e('Cornell Law Institute', 'goetz-legal'); ?></a></li>
                            <li><a href="https://www.sba.gov/" target="_blank" rel="noopener noreferrer" class="text-primary hover:text-secondary transition-colors"><?php esc_html_e('Small Business Administration', 'goetz-legal'); ?></a></li>
                            <li><a href="https://www.sec.gov/" target="_blank" rel="noopener noreferrer" class="text-primary hover:text-secondary transition-colors"><?php esc_html_e('Securities and Exchange Commission', 'goetz-legal'); ?></a></li>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php
get_footer();
