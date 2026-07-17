<?php
/**
 * Theme header template.
 *
 * @package GoetzLegal
 */
$business_name = (string) goetz_legal_setting('business_name', 'Goetz & Goetz');
$phone_display = (string) goetz_legal_setting('phone_display', GOETZ_LEGAL_PHONE_DISPLAY);
$phone_e164 = (string) goetz_legal_setting('phone_e164', GOETZ_LEGAL_PHONE_TEL);
$email = (string) goetz_legal_setting('email', GOETZ_LEGAL_EMAIL);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="profile" href="https://gmpg.org/xfn/11">
    <?php wp_head(); ?>
</head>
<body <?php body_class('goetz-site'); ?>>
<?php do_action('tailpress_site_before'); ?>

<div id="page" class="site-page">
    <?php do_action('tailpress_header'); ?>

    <header class="site-header" role="banner">
        <div class="site-header__top">
            <div class="site-header__inner">
                <div class="site-contact-links" aria-label="<?php esc_attr_e('Contact information', 'goetz-legal'); ?>">
                    <a href="<?php echo esc_url('tel:' . $phone_e164); ?>">
                        <span class="site-contact-links__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" focusable="false"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1C10.61 21 3 13.39 3 4c0-.55.45-1 1-1h3.49c.55 0 1 .45 1 1 0 1.24.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.19 2.2Z"/></svg>
                        </span>
                        <?php echo esc_html($phone_display); ?>
                    </a>
                    <a href="<?php echo esc_url('mailto:' . $email); ?>">
                        <span class="site-contact-links__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" focusable="false"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2Zm0 4-8 5-8-5V6l8 5 8-5v2Z"/></svg>
                        </span>
                        <?php echo esc_html($email); ?>
                    </a>
                </div>
            </div>
        </div>

        <div class="site-header__nav-row">
            <div class="site-header__inner site-header__nav-inner">
                <div class="site-branding-card">
                    <?php if (has_custom_logo()): ?>
                        <?php the_custom_logo(); ?>
                    <?php else: ?>
                        <a href="<?php echo esc_url(home_url('/')); ?>" class="site-branding-card__text">
                            <?php if ($business_name === 'Goetz & Goetz'): ?>
                                <span>Goetz <b>&amp;</b> Goetz</span>
                            <?php else: ?>
                                <span><?php echo esc_html($business_name); ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                </div>

                <button type="button" aria-label="<?php esc_attr_e('Toggle navigation', 'goetz-legal'); ?>" id="primary-menu-toggle" class="site-menu-toggle" aria-expanded="false">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>

                <nav id="primary-navigation" class="site-navigation" aria-label="<?php esc_attr_e('Primary menu', 'goetz-legal'); ?>">
                    <?php if (has_nav_menu('primary')): ?>
                        <?php
                        wp_nav_menu([
                            'container'      => false,
                            'menu_class'     => 'site-navigation__list',
                            'theme_location' => 'primary',
                            'fallback_cb'    => false,
                        ]);
                        ?>
                    <?php else: ?>
                        <ul class="site-navigation__list">
                            <?php foreach (goetz_legal_nav_items() as $item): ?>
                                <li>
                                    <a href="<?php echo esc_url($item['url']); ?>">
                                        <?php echo esc_html($item['label']); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </header>

    <div id="content" class="site-content">
        <?php do_action('tailpress_content_start'); ?>
        <main>
