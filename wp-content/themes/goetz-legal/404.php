<!DOCTYPE html>
<html <?php language_attributes(); ?> class="no-js">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width">
    <link rel="profile" href="http://gmpg.org/xfn/11">
    <?php wp_head(); ?>
</head>
<body class="antialiased bg-light">
    <div class="md:flex min-h-screen">
        <div class="w-full md:w-1/2 flex items-center justify-center">
            <div class="max-w-sm m-8">
                <div class="text-5xl md:text-15xl text-primary border-secondary border-b font-heading font-bold">404</div>
                <div class="w-16 h-1 bg-secondary my-3 md:my-6"></div>
                <p class="text-gray-700 text-2xl md:text-3xl font-light leading-relaxed mb-8">
                    <?php _e('Sorry, the page you are looking for could not be found.', 'goetz-legal'); ?>
                </p>
                <a href="<?php echo esc_url(home_url('/')); ?>" class="inline-flex rounded-full px-5 py-2 text-sm font-semibold transition bg-primary text-white hover:bg-primary/90 !no-underline">
                    <?php _e('Go Home', 'goetz-legal'); ?>
                </a>
            </div>
        </div>
    </div>

    <?php wp_footer(); ?>
</body>
</html>
