<?php
/**
 * Theme footer template.
 *
 * @package GoetzLegal
 */
require_once __DIR__ . '/inc/site-settings.php';

$business_name = (string) goetz_legal_setting('business_name', 'Goetz & Goetz');
$phone_display = (string) goetz_legal_setting('phone_display', GOETZ_LEGAL_PHONE_DISPLAY);
$phone_e164 = (string) goetz_legal_setting('phone_e164', GOETZ_LEGAL_PHONE_TEL);
$email = (string) goetz_legal_setting('email', GOETZ_LEGAL_EMAIL);
$location_label = (string) goetz_legal_setting('location_label', 'Fort Myers, Florida');
$footer_disclaimer = (string) goetz_legal_setting('footer_disclaimer', '');
$footer_legal_copy = (string) goetz_legal_setting('footer_legal_copy', '');
$copyright_start_year = (int) goetz_legal_setting('copyright_start_year', 2024);
$copyright_text = (string) goetz_legal_setting('copyright_text', 'Goetz & Goetz. All Rights Reserved');
$copyright_dynamic_year = (bool) goetz_legal_setting('copyright_dynamic_year', true);
$current_year = (int) wp_date('Y');
$copyright_years = $copyright_dynamic_year && $current_year > $copyright_start_year
    ? $copyright_start_year . ' – ' . $current_year
    : (string) $copyright_start_year;
?>
        </main>

        <?php do_action('tailpress_content_end'); ?>
    </div>

    <?php do_action('tailpress_content_after'); ?>

    <footer id="colophon" class="site-footer" role="contentinfo">
        <div class="site-footer__inner">
            <div class="site-footer__grid">
                <section>
                    <h2 class="goetz-visually-hidden"><?php echo esc_html($business_name); ?></h2>
                    <?php
                    $footer_logo_url = function_exists('goetz_legal_asset_url')
                        ? goetz_legal_asset_url('Goetz-footer-logo.png', 'https://goetzlegal.com/wp-content/uploads/2022/08/Goetz-footer-logo.png')
                        : 'https://goetzlegal.com/wp-content/uploads/2022/08/Goetz-footer-logo.png';
                    ?>
                    <img class="site-footer__logo" src="<?php echo esc_url($footer_logo_url); ?>" alt="<?php echo esc_attr($business_name); ?>" width="274" height="86" loading="lazy">
                    <div class="site-footer__disclaimer">
                        <?php echo wp_kses_post(wpautop($footer_disclaimer)); ?>
                    </div>
                </section>

                <section class="site-footer__nav">
                    <h2><?php esc_html_e('Site Navigation', 'goetz-legal'); ?></h2>
                    <?php if (has_nav_menu('footer')): ?>
                        <?php
                        wp_nav_menu([
                            'container'       => false,
                            'menu_class'      => 'site-footer__menu',
                            'theme_location'  => 'footer',
                            'fallback_cb'     => false,
                        ]);
                        ?>
                    <?php else: ?>
                        <ul class="site-footer__menu">
                            <?php foreach (goetz_legal_nav_items() as $item): ?>
                                <li>
                                    <a href="<?php echo esc_url($item['url']); ?>">
                                        <?php echo esc_html($item['label']); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

                <section>
                    <h2><?php esc_html_e('Contact Us', 'goetz-legal'); ?></h2>
                    <p><?php echo esc_html($location_label); ?></p>
                    <p><strong><?php esc_html_e('Phone', 'goetz-legal'); ?></strong> &ndash; <a href="<?php echo esc_url('tel:' . $phone_e164); ?>"><?php echo esc_html($phone_display); ?></a></p>
                    <p><strong><?php esc_html_e('E-Mail Address', 'goetz-legal'); ?></strong></p>
                    <p>
                        <a href="<?php echo esc_url('mailto:' . $email); ?>"><?php esc_html_e('James L. Goetz', 'goetz-legal'); ?></a>
                        |
                        <a href="<?php echo esc_url('mailto:' . $email); ?>"><?php esc_html_e('Gregory W. Goetz', 'goetz-legal'); ?></a>
                    </p>
                    <div class="site-footer__legal-copy"><?php echo wp_kses_post(wpautop($footer_legal_copy)); ?></div>
                </section>
            </div>

            <div class="site-footer__bottom">
                <div>
                    &copy; <?php esc_html_e('Copyright', 'goetz-legal'); ?> <?php echo esc_html($copyright_years); ?> &ndash; <?php echo esc_html($copyright_text); ?>
                </div>
            </div>
        </div>
    </footer>
</div>

<?php wp_footer(); ?>
</body>
</html>
