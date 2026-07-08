<?php
/**
 * Template Name: Contact
 * Template Post Type: page
 *
 * @package GoetzLegal
 */

get_header();
?>

<?php if (have_posts()): ?>
    <?php while (have_posts()): the_post(); ?>
        <?php get_template_part('template-parts/content', 'contact'); ?>
    <?php endwhile; ?>
<?php endif; ?>

<?php
get_footer();
