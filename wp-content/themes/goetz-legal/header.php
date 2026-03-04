<?php
/**
 * Theme header template.
 *
 * @package GoetzLegal
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="profile" href="https://gmpg.org/xfn/11">
    <link rel="pingback" href="<?php bloginfo('pingback_url'); ?>">
    <?php wp_head(); ?>
</head>
<body <?php body_class('bg-white text-gray-900 antialiased font-body'); ?>>
<?php do_action('tailpress_site_before'); ?>

<div id="page" class="min-h-screen flex flex-col">
    <?php do_action('tailpress_header'); ?>

    <!-- Emergency Banner -->
    <div class="bg-secondary text-primary text-center py-2 text-sm font-semibold">
        <div class="container mx-auto px-4">
            <?php esc_html_e('Need Immediate Legal Help? Call Us 24/7:', 'goetz-legal'); ?>
            <a href="tel:+12399360066" class="underline hover:no-underline ml-1">(239) 936-0066</a>
        </div>
    </div>

    <header class="bg-primary text-white shadow-lg">
        <div class="container mx-auto px-4 py-4">
            <div class="md:flex md:justify-between md:items-center">
                <div class="flex justify-between items-center">
                    <div>
                        <?php if (has_custom_logo()): ?>
                            <?php the_custom_logo(); ?>
                        <?php else: ?>
                            <a href="<?php echo esc_url(home_url('/')); ?>" class="!no-underline">
                                <span class="font-heading text-2xl font-bold text-white tracking-wide">Goetz &amp; Goetz</span>
                                <span class="block text-secondary text-sm font-light tracking-widest uppercase">Attorneys at Law</span>
                            </a>
                        <?php endif; ?>
                    </div>

                    <?php if (has_nav_menu('primary')): ?>
                        <div class="md:hidden">
                            <button type="button" aria-label="<?php esc_attr_e('Toggle navigation', 'goetz-legal'); ?>" id="primary-menu-toggle" class="text-white focus:outline-none">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                                </svg>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <div id="primary-navigation" class="hidden md:flex md:bg-transparent gap-6 items-center mt-4 md:mt-0">
                    <nav>
                        <?php if (current_user_can('administrator') && !has_nav_menu('primary')): ?>
                            <a href="<?php echo esc_url(admin_url('nav-menus.php')); ?>" class="text-sm text-white/70"><?php esc_html_e('Edit Menus', 'goetz-legal'); ?></a>
                        <?php else: ?>
                            <?php
                            wp_nav_menu([
                                'container_id'    => 'primary-menu',
                                'container_class' => '',
                                'menu_class'      => 'md:flex md:-mx-4 [&_a]:!no-underline [&_a]:text-white [&_a]:hover:text-secondary [&_a]:transition-colors',
                                'theme_location'  => 'primary',
                                'li_class'        => 'md:mx-4',
                                'fallback_cb'     => false,
                            ]);
                            ?>
                        <?php endif; ?>
                    </nav>

                    <a href="<?php echo esc_url(home_url('/contact')); ?>" class="inline-flex rounded-full px-5 py-2 text-sm font-semibold bg-secondary text-primary hover:bg-secondary/90 transition !no-underline">
                        <?php esc_html_e('Free Consultation', 'goetz-legal'); ?>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div id="content" class="site-content grow">
        <?php do_action('tailpress_content_start'); ?>
        <main>
