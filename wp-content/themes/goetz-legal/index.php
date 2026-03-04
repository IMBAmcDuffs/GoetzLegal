<?php
/**
 * Main template file for displaying posts.
 *
 * @package GoetzLegal
 */

get_header();
?>

<div class="container mx-auto px-4 py-12 space-y-24 lg:space-y-32">
	<?php if (!is_singular()): ?>
		<?php if (is_archive()): ?>
			<header class="mb-8">
				<h1 class="font-heading text-3xl font-semibold text-primary">
					<?php the_archive_title(); ?>
				</h1>
			</header>
		<?php elseif (is_category()): ?>
			<header class="mb-8">
				<h1 class="font-heading text-3xl font-semibold text-primary">
					<?php single_cat_title(); ?>
				</h1>
			</header>
		<?php elseif (is_tag()): ?>
			<header class="mb-8">
				<h1 class="font-heading text-3xl font-semibold text-primary">
					<?php single_tag_title(); ?>
				</h1>
			</header>
		<?php elseif (is_author()): ?>
			<header class="mb-8">
				<h1 class="font-heading text-3xl font-semibold text-primary">
					<?php printf(__('Posts by %s', 'goetz-legal'), get_the_author()); ?>
				</h1>
			</header>
		<?php elseif (is_search()): ?>
			<header class="mb-8">
				<h1 class="font-heading text-3xl font-semibold text-primary">
					<?php printf(__('Search results for: %s', 'goetz-legal'), get_search_query()); ?>
				</h1>
			</header>
		<?php elseif (is_404()): ?>
			<header class="mb-8">
				<h1 class="font-heading text-3xl font-semibold text-primary">
					<?php _e('Page Not Found', 'goetz-legal'); ?>
				</h1>
			</header>
		<?php endif; ?>
	<?php endif; ?>

    <?php if (have_posts()): ?>
        <?php while (have_posts()): the_post(); ?>
            <?php get_template_part('template-parts/content', is_singular() ? 'single' : ''); ?>
        <?php endwhile; ?>

        <?php TailPress\Pagination::render(); ?>
    <?php endif; ?>
</div>

<?php
get_footer();
