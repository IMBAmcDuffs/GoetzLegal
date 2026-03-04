<?php
/**
 * Template Name: Homepage
 * Template Post Type: page
 *
 * @package GoetzLegal
 */

get_header();
?>

<!-- Hero Section -->
<section class="relative bg-primary text-white py-20 md:py-32">
    <div class="absolute inset-0 bg-gradient-to-r from-primary/95 to-primary/80"></div>
    <div class="relative container mx-auto px-4">
        <div class="max-w-3xl">
            <h1 class="font-heading text-4xl md:text-6xl font-bold leading-tight mb-6">
                <?php esc_html_e('Trusted Legal Counsel in Fort Myers, Florida', 'goetz-legal'); ?>
            </h1>
            <p class="text-lg md:text-xl text-white/90 leading-relaxed mb-8">
                <?php esc_html_e('Goetz & Goetz provides experienced representation in Corporate and Construction law. Protecting your interests with dedication and integrity since our founding.', 'goetz-legal'); ?>
            </p>
            <div class="flex flex-wrap gap-4">
                <a href="<?php echo esc_url(home_url('/contact')); ?>" class="inline-flex rounded-full px-6 py-3 text-base font-semibold bg-secondary text-primary hover:bg-secondary/90 transition !no-underline">
                    <?php esc_html_e('Schedule a Consultation', 'goetz-legal'); ?>
                </a>
                <a href="tel:+12399360066" class="inline-flex rounded-full px-6 py-3 text-base font-semibold border-2 border-white text-white hover:bg-white hover:text-primary transition !no-underline">
                    <?php esc_html_e('Call (239) 936-0066', 'goetz-legal'); ?>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Practice Areas Overview -->
<section class="py-16 md:py-24 bg-white">
    <div class="container mx-auto px-4">
        <div class="text-center mb-12">
            <h2 class="font-heading text-3xl md:text-4xl font-bold text-primary mb-4">
                <?php esc_html_e('Our Practice Areas', 'goetz-legal'); ?>
            </h2>
            <p class="text-gray-600 text-lg max-w-2xl mx-auto">
                <?php esc_html_e('We focus our practice on the areas where we can deliver the most value to our clients.', 'goetz-legal'); ?>
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-4xl mx-auto">
            <!-- Corporate Law Card -->
            <div class="bg-light rounded-2xl p-8 hover:shadow-lg transition-shadow">
                <div class="w-14 h-14 bg-primary/10 rounded-xl flex items-center justify-center mb-6">
                    <svg class="w-7 h-7 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                </div>
                <h3 class="font-heading text-xl font-bold text-primary mb-3"><?php esc_html_e('Corporate Law', 'goetz-legal'); ?></h3>
                <p class="text-gray-600 mb-4">
                    <?php esc_html_e('Business formation, contracts, mergers and acquisitions, corporate governance, and regulatory compliance for businesses of all sizes.', 'goetz-legal'); ?>
                </p>
                <a href="<?php echo esc_url(home_url('/practice-areas/corporate-law')); ?>" class="text-secondary font-semibold hover:text-secondary/80 transition-colors !no-underline">
                    <?php esc_html_e('Learn More →', 'goetz-legal'); ?>
                </a>
            </div>

            <!-- Construction Law Card -->
            <div class="bg-light rounded-2xl p-8 hover:shadow-lg transition-shadow">
                <div class="w-14 h-14 bg-primary/10 rounded-xl flex items-center justify-center mb-6">
                    <svg class="w-7 h-7 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                </div>
                <h3 class="font-heading text-xl font-bold text-primary mb-3"><?php esc_html_e('Construction Law', 'goetz-legal'); ?></h3>
                <p class="text-gray-600 mb-4">
                    <?php esc_html_e('Construction litigation, contract disputes, lien claims, defect claims, and regulatory matters for contractors, developers, and property owners.', 'goetz-legal'); ?>
                </p>
                <a href="<?php echo esc_url(home_url('/practice-areas/construction-law')); ?>" class="text-secondary font-semibold hover:text-secondary/80 transition-colors !no-underline">
                    <?php esc_html_e('Learn More →', 'goetz-legal'); ?>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Attorneys Section -->
<section class="py-16 md:py-24 bg-light">
    <div class="container mx-auto px-4">
        <div class="text-center mb-12">
            <h2 class="font-heading text-3xl md:text-4xl font-bold text-primary mb-4">
                <?php esc_html_e('Meet Our Attorneys', 'goetz-legal'); ?>
            </h2>
            <p class="text-gray-600 text-lg max-w-2xl mx-auto">
                <?php esc_html_e('Our team of experienced legal professionals is dedicated to providing the highest quality representation.', 'goetz-legal'); ?>
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-5xl mx-auto">
            <?php
            $attorneys = [
                [
                    'name'  => __('James L. Goetz', 'goetz-legal'),
                    'title' => __('Senior Partner', 'goetz-legal'),
                    'desc'  => __('With decades of experience in corporate and business law, James provides strategic counsel to businesses throughout Southwest Florida.', 'goetz-legal'),
                ],
                [
                    'name'  => __('Gregory W. Goetz', 'goetz-legal'),
                    'title' => __('Partner', 'goetz-legal'),
                    'desc'  => __('Gregory focuses his practice on construction law and commercial litigation, representing contractors, developers, and property owners.', 'goetz-legal'),
                ],
                [
                    'name'  => __('Dawn Heitl', 'goetz-legal'),
                    'title' => __('Associate Attorney', 'goetz-legal'),
                    'desc'  => __('Dawn brings a thorough and detail-oriented approach to corporate and construction law matters, ensuring comprehensive legal solutions.', 'goetz-legal'),
                ],
            ];

            foreach ($attorneys as $attorney): ?>
                <div class="bg-white rounded-2xl p-8 text-center hover:shadow-lg transition-shadow">
                    <div class="w-24 h-24 bg-primary/10 rounded-full mx-auto mb-6 flex items-center justify-center">
                        <svg class="w-12 h-12 text-primary/50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                    </div>
                    <h3 class="font-heading text-xl font-bold text-primary mb-1"><?php echo esc_html($attorney['name']); ?></h3>
                    <p class="text-secondary font-semibold text-sm mb-3"><?php echo esc_html($attorney['title']); ?></p>
                    <p class="text-gray-600 text-sm leading-relaxed">
                        <?php echo esc_html($attorney['desc']); ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="text-center mt-10">
            <a href="<?php echo esc_url(home_url('/attorneys')); ?>" class="inline-flex rounded-full px-6 py-3 text-base font-semibold bg-primary text-white hover:bg-primary/90 transition !no-underline">
                <?php esc_html_e('View All Attorneys', 'goetz-legal'); ?>
            </a>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="py-16 md:py-24 bg-secondary">
    <div class="container mx-auto px-4 text-center">
        <h2 class="font-heading text-3xl md:text-4xl font-bold text-primary mb-4">
            <?php esc_html_e('Need A Lawyer?', 'goetz-legal'); ?>
        </h2>
        <p class="text-primary/80 text-lg max-w-2xl mx-auto mb-8">
            <?php esc_html_e('Contact us today for a free initial consultation. Let us help you navigate your legal challenges with confidence.', 'goetz-legal'); ?>
        </p>
        <div class="flex flex-wrap justify-center gap-4">
            <a href="<?php echo esc_url(home_url('/contact')); ?>" class="inline-flex rounded-full px-6 py-3 text-base font-semibold bg-primary text-white hover:bg-primary/90 transition !no-underline">
                <?php esc_html_e('Contact Us Today', 'goetz-legal'); ?>
            </a>
            <a href="tel:+12399360066" class="inline-flex rounded-full px-6 py-3 text-base font-semibold border-2 border-primary text-primary hover:bg-primary hover:text-white transition !no-underline">
                <?php esc_html_e('(239) 936-0066', 'goetz-legal'); ?>
            </a>
        </div>
    </div>
</section>

<?php if (have_posts()): ?>
    <section class="py-16 md:py-24 bg-white">
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
