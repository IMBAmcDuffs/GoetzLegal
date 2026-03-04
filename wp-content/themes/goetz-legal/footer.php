<?php
/**
 * Theme footer template.
 *
 * @package GoetzLegal
 */
?>
        </main>

        <?php do_action('tailpress_content_end'); ?>
    </div>

    <?php do_action('tailpress_content_after'); ?>

    <footer id="colophon" class="bg-primary text-white" role="contentinfo">
        <div class="container mx-auto px-4 py-12">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Column 1: Firm Info -->
                <div>
                    <h3 class="font-heading text-xl font-bold text-secondary mb-4"><?php esc_html_e('Goetz & Goetz', 'goetz-legal'); ?></h3>
                    <p class="text-white/80 text-sm leading-relaxed mb-4">
                        <?php esc_html_e('Experienced attorneys serving Fort Myers, Florida. Focused on Corporate and Construction law with a commitment to client success.', 'goetz-legal'); ?>
                    </p>
                    <p class="text-white/60 text-sm">
                        <?php esc_html_e('1534 Broadway, Suite 201', 'goetz-legal'); ?><br>
                        <?php esc_html_e('Fort Myers, FL 33901', 'goetz-legal'); ?>
                    </p>
                </div>

                <!-- Column 2: Quick Links -->
                <div>
                    <h3 class="font-heading text-xl font-bold text-secondary mb-4"><?php esc_html_e('Quick Links', 'goetz-legal'); ?></h3>
                    <?php if (has_nav_menu('footer')): ?>
                        <?php
                        wp_nav_menu([
                            'container_class' => '',
                            'menu_class'      => 'space-y-2 [&_a]:text-white/80 [&_a]:hover:text-secondary [&_a]:text-sm [&_a]:!no-underline [&_a]:transition-colors',
                            'theme_location'  => 'footer',
                            'fallback_cb'     => false,
                        ]);
                        ?>
                    <?php else: ?>
                        <ul class="space-y-2">
                            <li><a href="<?php echo esc_url(home_url('/')); ?>" class="text-white/80 hover:text-secondary text-sm !no-underline transition-colors"><?php esc_html_e('Home', 'goetz-legal'); ?></a></li>
                            <li><a href="<?php echo esc_url(home_url('/practice-areas')); ?>" class="text-white/80 hover:text-secondary text-sm !no-underline transition-colors"><?php esc_html_e('Practice Areas', 'goetz-legal'); ?></a></li>
                            <li><a href="<?php echo esc_url(home_url('/attorneys')); ?>" class="text-white/80 hover:text-secondary text-sm !no-underline transition-colors"><?php esc_html_e('Attorneys', 'goetz-legal'); ?></a></li>
                            <li><a href="<?php echo esc_url(home_url('/contact')); ?>" class="text-white/80 hover:text-secondary text-sm !no-underline transition-colors"><?php esc_html_e('Contact', 'goetz-legal'); ?></a></li>
                        </ul>
                    <?php endif; ?>
                </div>

                <!-- Column 3: Contact Info -->
                <div>
                    <h3 class="font-heading text-xl font-bold text-secondary mb-4"><?php esc_html_e('Contact Us', 'goetz-legal'); ?></h3>
                    <ul class="space-y-3 text-sm text-white/80">
                        <li class="flex items-start gap-2">
                            <svg class="w-5 h-5 text-secondary mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            <a href="tel:+12399360066" class="hover:text-secondary transition-colors">(239) 936-0066</a>
                        </li>
                        <li class="flex items-start gap-2">
                            <svg class="w-5 h-5 text-secondary mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <a href="mailto:info@goetzlegal.com" class="hover:text-secondary transition-colors">info@goetzlegal.com</a>
                        </li>
                        <li class="flex items-start gap-2">
                            <svg class="w-5 h-5 text-secondary mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span><?php esc_html_e('Mon – Fri: 8:30 AM – 5:00 PM', 'goetz-legal'); ?></span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Bottom Bar -->
            <div class="border-t border-white/20 mt-8 pt-8 flex flex-col md:flex-row justify-between items-center text-sm text-white/60">
                <div>
                    &copy; <?php echo esc_html(date_i18n('Y')); ?> <?php bloginfo('name'); ?>. <?php esc_html_e('All rights reserved.', 'goetz-legal'); ?>
                </div>
                <div class="mt-4 md:mt-0">
                    <p class="text-xs leading-relaxed max-w-lg text-center md:text-right">
                        <?php esc_html_e('This site is for informational purposes only and does not constitute legal advice. An attorney-client relationship is not formed until a formal engagement agreement is signed.', 'goetz-legal'); ?>
                    </p>
                </div>
            </div>
        </div>
    </footer>
</div>

<?php wp_footer(); ?>
</body>
</html>
