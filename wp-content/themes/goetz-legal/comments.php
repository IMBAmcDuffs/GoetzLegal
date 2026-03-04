<?php
/**
 * Comments template.
 *
 * @package GoetzLegal
 */

if (post_password_required()) {
    return;
}
?>

<div id="comments" class="comments-area my-10 sm:my-20 mx-auto max-w-3xl">
    <?php if (have_comments()): ?>
        <h2 class="comments-title font-heading text-3xl font-medium text-primary mb-8">
            <?php
            printf(
                esc_html(_nx(
                    'One comment',
                    '%1$s comments',
                    get_comments_number(),
                    'comments title',
                    'goetz-legal'
                )),
                esc_html(number_format_i18n(get_comments_number()))
            );
            ?>
        </h2>

        <ol class="comment-list [&_.children]:ml-20 [&_.children_>_li]:mt-8 mb-12">
            <?php
            wp_list_comments([
                'format'      => 'html5',
                'style'       => 'ol',
                'short_ping'  => true,
                'avatar_size' => 56,
            ]);
            ?>
        </ol>

        <?php if (get_comment_pages_count() > 1 && get_option('page_comments')): ?>
            <nav class="comment-navigation flex justify-between" id="comment-nav-above" aria-label="<?php esc_attr_e('Comment navigation', 'goetz-legal'); ?>">
                <div class="nav-previous">
                    <?php previous_comments_link(esc_html__('Older Comments &larr;', 'goetz-legal')); ?>
                </div>
                <div class="nav-next">
                    <?php next_comments_link(esc_html__('Newer Comments &rarr;', 'goetz-legal')); ?>
                </div>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!comments_open() && get_comments_number() && post_type_supports(get_post_type(), 'comments')): ?>
        <p class="no-comments text-gray-600"><?php esc_html_e('Comments are closed.', 'goetz-legal'); ?></p>
    <?php endif; ?>

    <?php
        $commenter = wp_get_current_commenter();

        $req = get_option('require_name_email');
        $aria_req = ($req ? ' aria-required="true"' : '');

        comment_form([
            'fields' => apply_filters('comment_form_default_fields', [
                'author' =>
                    '<p class="comment-form-author">' .
                    '<input id="author" class="bg-light w-full px-4 py-3 mb-4 rounded-xl text-sm" placeholder="' . esc_attr__('Your Name*', 'goetz-legal') . '" name="author" type="text" value="' . esc_attr($commenter['comment_author']) .
                    '" size="30"' . $aria_req . ' /></p>',

                'email' =>
                    '<p class="comment-form-email">' .
                    '<input id="email" class="bg-light w-full px-4 py-3 mb-4 rounded-xl text-sm" placeholder="' . esc_attr__('Your Email Address*', 'goetz-legal') . '" name="email" type="text" value="' . esc_attr($commenter['comment_author_email']) .
                    '" size="30"' . $aria_req . ' /></p>',

                'url' =>
                    '<p class="comment-form-url">' .
                    '<input id="url" class="bg-light w-full px-4 py-3 mb-4 rounded-xl text-sm" placeholder="' . esc_attr__('Your Website URL', 'goetz-legal') . '" name="url" type="text" value="' . esc_attr($commenter['comment_author_url']) .
                    '" size="30" /></p>'
            ]),
            'title_reply_before' => '<h3 id="reply-title" class="comment-reply-title font-heading text-2xl font-bold text-primary mb-2">',
            'class_submit'      => 'bg-primary rounded-full px-5 py-2 text-sm font-semibold text-white my-4 hover:bg-primary/90 transition cursor-pointer',
            'comment_field'     => '<textarea id="comment" name="comment" class="bg-light w-full px-4 py-3 my-2 rounded-xl text-sm" aria-required="true" placeholder="' . esc_attr__('Your comment', 'goetz-legal') . '"></textarea>',
            'logged_in_as'      => '<p class="logged-in-as mb-4">',
        ]);
    ?>
</div>
