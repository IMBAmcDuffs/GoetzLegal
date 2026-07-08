<?php
/**
 * Template Name: Homepage
 * Template Post Type: page
 *
 * @package GoetzLegal
 */

get_header();
?>

<?php if (have_posts()): ?>
    <?php while (have_posts()): the_post(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
            <div class="entry-content">
                <?php the_content(); ?>
            </div>
        </article>
    <?php endwhile; ?>
<?php endif; ?>

<?php
get_footer();
