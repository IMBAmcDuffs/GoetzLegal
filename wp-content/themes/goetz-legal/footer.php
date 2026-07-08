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

    <footer id="colophon" class="site-footer" role="contentinfo">
        <div class="site-footer__inner">
            <div class="site-footer__grid">
                <section>
                    <?php
                    $footer_logo_url = function_exists('goetz_legal_asset_url')
                        ? goetz_legal_asset_url('Goetz-footer-logo.png', 'https://goetzlegal.com/wp-content/uploads/2022/08/Goetz-footer-logo.png')
                        : 'https://goetzlegal.com/wp-content/uploads/2022/08/Goetz-footer-logo.png';
                    ?>
                    <img class="site-footer__logo" src="<?php echo esc_url($footer_logo_url); ?>" alt="<?php esc_attr_e('Goetz & Goetz', 'goetz-legal'); ?>" width="274" height="86" loading="lazy">
                    <p>
                        <?php esc_html_e('The content of this Website is intended to provide general information about Goetz & Goetz. The information provided is not an offer to represent you or create an attorney-client relationship. The content of any E-mail communication, facsimile or correspondence sent to Goetz & Goetz or to any of its attorneys will not, in and of itself, create an attorney-client relationship.', 'goetz-legal'); ?>
                    </p>
                </section>

                <section class="site-footer__nav">
                    <h3><?php esc_html_e('Site Navigation', 'goetz-legal'); ?></h3>
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
                    <h3><?php esc_html_e('Contact Us', 'goetz-legal'); ?></h3>
                    <p><?php esc_html_e('Fort Myers, Florida', 'goetz-legal'); ?></p>
                    <p><strong><?php esc_html_e('Phone', 'goetz-legal'); ?></strong> &ndash; <a href="tel:<?php echo esc_attr(GOETZ_LEGAL_PHONE_TEL); ?>"><?php echo esc_html(GOETZ_LEGAL_PHONE_DISPLAY); ?></a></p>
                    <p><strong><?php esc_html_e('E-Mail Address', 'goetz-legal'); ?></strong></p>
                    <p>
                        <a href="mailto:<?php echo esc_attr(GOETZ_LEGAL_EMAIL); ?>"><?php esc_html_e('James L. Goetz', 'goetz-legal'); ?></a>
                        |
                        <a href="mailto:<?php echo esc_attr(GOETZ_LEGAL_EMAIL); ?>"><?php esc_html_e('Gregory W. Goetz', 'goetz-legal'); ?></a>
                    </p>
                    <p><?php esc_html_e('The hiring of a lawyer is an important decision that should not be based solely upon advertisements. Before you decide, ask us to send you free written information about our qualifications and experience.', 'goetz-legal'); ?></p>
                </section>
            </div>

            <div class="site-footer__bottom">
                <div>
                    &copy; 2024 &ndash; <?php bloginfo('name'); ?>. <?php esc_html_e('All Rights Reserved', 'goetz-legal'); ?>
                </div>
            </div>
        </div>
    </footer>
</div>

<?php wp_footer(); ?>
</body>
</html>
