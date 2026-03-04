<?php
/**
 * Template Name: Contact
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
            <?php esc_html_e('We are here to help. Contact us today for a free initial consultation about your legal matter.', 'goetz-legal'); ?>
        </p>
    </div>
</section>

<!-- Contact Content -->
<section class="py-16 md:py-24 bg-white">
    <div class="container mx-auto px-4">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 max-w-6xl mx-auto">
            <!-- Contact Info -->
            <div>
                <h2 class="font-heading text-2xl md:text-3xl font-bold text-primary mb-6"><?php esc_html_e('Get In Touch', 'goetz-legal'); ?></h2>

                <div class="space-y-6">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center shrink-0">
                            <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-900"><?php esc_html_e('Office Address', 'goetz-legal'); ?></h3>
                            <p class="text-gray-600">
                                <?php esc_html_e('1534 Broadway, Suite 201', 'goetz-legal'); ?><br>
                                <?php esc_html_e('Fort Myers, FL 33901', 'goetz-legal'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center shrink-0">
                            <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-900"><?php esc_html_e('Phone', 'goetz-legal'); ?></h3>
                            <p class="text-gray-600">
                                <a href="tel:+12399360066" class="hover:text-secondary transition-colors">(239) 936-0066</a>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center shrink-0">
                            <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-900"><?php esc_html_e('Email', 'goetz-legal'); ?></h3>
                            <p class="text-gray-600">
                                <a href="mailto:info@goetzlegal.com" class="hover:text-secondary transition-colors">info@goetzlegal.com</a>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center shrink-0">
                            <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-900"><?php esc_html_e('Office Hours', 'goetz-legal'); ?></h3>
                            <p class="text-gray-600">
                                <?php esc_html_e('Monday – Friday: 8:30 AM – 5:00 PM', 'goetz-legal'); ?><br>
                                <?php esc_html_e('Saturday – Sunday: Closed', 'goetz-legal'); ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Map Embed Placeholder -->
                <div class="mt-8 rounded-2xl overflow-hidden bg-light aspect-video flex items-center justify-center">
                    <p class="text-gray-400 text-sm"><?php esc_html_e('Google Maps embed will be configured here', 'goetz-legal'); ?></p>
                </div>
            </div>

            <!-- Contact Form -->
            <div>
                <h2 class="font-heading text-2xl md:text-3xl font-bold text-primary mb-6"><?php esc_html_e('Send Us a Message', 'goetz-legal'); ?></h2>

                <div class="entry-content">
                    <?php if (have_posts()): ?>
                        <?php while (have_posts()): the_post(); ?>
                            <?php the_content(); ?>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <!-- Fallback Contact Form (replace with WPForms/Gravity Forms shortcode) -->
                        <div class="bg-light rounded-2xl p-8">
                            <p class="text-gray-600 mb-6"><?php esc_html_e('Please install and configure a form plugin (such as WPForms or Gravity Forms) and add the form shortcode to this page.', 'goetz-legal'); ?></p>
                            <div class="space-y-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1" for="contact-name"><?php esc_html_e('Full Name *', 'goetz-legal'); ?></label>
                                        <input type="text" id="contact-name" class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-primary focus:border-primary" disabled>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1" for="contact-email"><?php esc_html_e('Email *', 'goetz-legal'); ?></label>
                                        <input type="email" id="contact-email" class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-primary focus:border-primary" disabled>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1" for="contact-phone"><?php esc_html_e('Phone', 'goetz-legal'); ?></label>
                                        <input type="tel" id="contact-phone" class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-primary focus:border-primary" disabled>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1" for="contact-case"><?php esc_html_e('Case Type', 'goetz-legal'); ?></label>
                                        <select id="contact-case" class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-primary focus:border-primary" disabled>
                                            <option><?php esc_html_e('Select...', 'goetz-legal'); ?></option>
                                            <option><?php esc_html_e('Corporate Law', 'goetz-legal'); ?></option>
                                            <option><?php esc_html_e('Construction Law', 'goetz-legal'); ?></option>
                                            <option><?php esc_html_e('Other', 'goetz-legal'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1" for="contact-message"><?php esc_html_e('Message *', 'goetz-legal'); ?></label>
                                    <textarea id="contact-message" rows="5" class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-primary focus:border-primary" disabled></textarea>
                                </div>
                                <button type="button" class="inline-flex rounded-full px-6 py-3 text-base font-semibold bg-primary text-white hover:bg-primary/90 transition cursor-not-allowed opacity-50" disabled>
                                    <?php esc_html_e('Send Message', 'goetz-legal'); ?>
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
get_footer();
