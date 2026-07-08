<?php
/**
 * Production-matching contact page content.
 *
 * @package GoetzLegal
 */

$page_hero_url = function_exists('goetz_legal_asset_url')
    ? goetz_legal_asset_url('bann-img.jpg', 'https://goetzlegal.com/wp-content/uploads/2022/08/bann-img.jpg')
    : 'https://goetzlegal.com/wp-content/uploads/2022/08/bann-img.jpg';
$page_hero_style = 'background-image: linear-gradient(rgb(0 0 0 / 50%), rgb(0 0 0 / 50%)), url(' . esc_url($page_hero_url) . ');';

$page_content = get_the_content();
$form_shortcode = '';

if (preg_match('/\[wpforms[^\]]+\]/', $page_content, $matches)) {
    $form_shortcode = $matches[0];
}

if (!$form_shortcode && post_type_exists('wpforms')) {
    $forms = get_posts([
        'post_type'      => 'wpforms',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ]);

    if (!empty($forms[0])) {
        $form_shortcode = '[wpforms id="' . absint($forms[0]) . '" title="false" description="false"]';
    }
}
?>

<article id="post-<?php the_ID(); ?>" <?php post_class('goetz-contact-page'); ?>>
    <header class="goetz-page-hero" style="<?php echo esc_attr($page_hero_style); ?>">
        <h1><?php the_title(); ?></h1>
    </header>

    <section class="goetz-contact-page__canvas" aria-labelledby="goetz-contact-heading">
        <div class="goetz-contact-page__columns">
            <div class="goetz-contact-page__form-column">
                <h2 id="goetz-contact-heading"><span><?php esc_html_e('Contact', 'goetz-legal'); ?></span> <?php esc_html_e('Us', 'goetz-legal'); ?></h2>

                <div class="goetz-contact-form">
                    <?php if ($form_shortcode): ?>
                        <?php echo do_shortcode($form_shortcode); ?>
                    <?php else: ?>
                        <form action="<?php echo esc_url(home_url('/contact/')); ?>" method="post">
                            <p class="form-element form-element-half"><input type="text" name="name" placeholder="<?php esc_attr_e('Name*', 'goetz-legal'); ?>" aria-label="<?php esc_attr_e('Name', 'goetz-legal'); ?>"></p>
                            <p class="form-element form-element-half"><input type="email" name="email" placeholder="<?php esc_attr_e('E-Mail*', 'goetz-legal'); ?>" aria-label="<?php esc_attr_e('E-Mail', 'goetz-legal'); ?>"></p>
                            <p class="form-element form-fullwidth"><input type="tel" name="phone" placeholder="<?php esc_attr_e('Phone*', 'goetz-legal'); ?>" aria-label="<?php esc_attr_e('Phone', 'goetz-legal'); ?>"></p>
                            <p class="form-element form-fullwidth"><textarea name="message" placeholder="<?php esc_attr_e('Message*', 'goetz-legal'); ?>" aria-label="<?php esc_attr_e('Message', 'goetz-legal'); ?>"></textarea></p>
                            <p class="form-element form-fullwidth"><button type="submit"><?php esc_html_e('Submit', 'goetz-legal'); ?></button></p>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="goetz-contact-page__info-column">
                <h2><span><?php esc_html_e('Contact', 'goetz-legal'); ?></span> <?php esc_html_e('Info.', 'goetz-legal'); ?></h2>

                <ul class="goetz-contact-info-list">
                    <li>
                        <span class="goetz-contact-info-list__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" focusable="false"><path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7Zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5Z"/></svg>
                        </span>
                        <article>
                            <h3><?php esc_html_e('Address:', 'goetz-legal'); ?></h3>
                            <p><?php esc_html_e('33 Barkley Cir Ste 100. Fort Myers, Florida 33907', 'goetz-legal'); ?></p>
                        </article>
                    </li>
                    <li>
                        <span class="goetz-contact-info-list__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" focusable="false"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1C10.61 21 3 13.39 3 4c0-.55.45-1 1-1h3.49c.55 0 1 .45 1 1 0 1.24.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.19 2.2Z"/></svg>
                        </span>
                        <article>
                            <h3><?php esc_html_e('Phone:', 'goetz-legal'); ?></h3>
                            <p><a href="tel:<?php echo esc_attr(GOETZ_LEGAL_PHONE_TEL); ?>"><?php echo esc_html(GOETZ_LEGAL_PHONE_DISPLAY); ?></a></p>
                        </article>
                    </li>
                </ul>

                <h2 class="goetz-contact-page__location-heading"><?php esc_html_e('Location', 'goetz-legal'); ?></h2>
                <p class="goetz-contact-page__directions"><a href="https://www.google.com/maps/place/33+Barkley+Cir,+Fort+Myers,+FL+33907/@26.587307,-81.882481,17z/data=!3m1!4b1!4m2!3m1!1s0x88db40160abaa0c1:0x3cc0990283c267a1!6m1!1e1"><?php esc_html_e('Driving Directions', 'goetz-legal'); ?></a></p>
            </div>
        </div>

        <div class="goetz-contact-page__disclaimer">
            <p><strong><?php esc_html_e('Disclaimer:', 'goetz-legal'); ?></strong></p>
            <p><em><?php esc_html_e('By providing my phone number to "Goetz & Goetz", I agree and acknowledge that "Goetz & Goetz" may send text messages to my wireless phone number for any purpose. Message and data rates may apply. Message frequency will vary, and you will be able to opt out by replying "STOP", assistance can be found by texting "HELP". For more information on how your data will be handled please visit:', 'goetz-legal'); ?> <a href="<?php echo esc_url(home_url('/contact/')); ?>"><?php echo esc_html(home_url('/contact/')); ?></a></em></p>
            <p><strong><?php esc_html_e('Privacy and policy:', 'goetz-legal'); ?></strong></p>
            <p><em><?php esc_html_e('"No mobile information will be shared with third parties/affiliates for marketing/promotional purposes. All the above categories exclude text messaging originator opt-in data and consent; this information will not be shared with any third parties."', 'goetz-legal'); ?></em></p>
        </div>
    </section>

    <?php echo do_blocks('<!-- wp:goetz/cta /-->'); ?>
</article>