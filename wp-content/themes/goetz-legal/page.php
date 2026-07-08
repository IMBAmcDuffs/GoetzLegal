<?php
/**
 * Page template.
 *
 * @package GoetzLegal
 */

get_header();
?>

<?php if (have_posts()): ?>
    <?php while (have_posts()): the_post(); ?>
        <?php if (is_page('contact')): ?>
            <?php get_template_part('template-parts/content', 'contact'); ?>
        <?php else: ?>
            <?php get_template_part('template-parts/content', 'single'); ?>
        <?php endif; ?>
    <?php endwhile; ?>
<?php endif; ?>

<?php
get_footer();
