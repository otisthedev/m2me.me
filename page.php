<?php
/**
 * The template for displaying pages
 *
 * @package MatchMe
 */

get_header();
?>

<main id="main" class="site-main">
    <?php
    while (have_posts()) {
        the_post();
        get_template_part('template-parts/content', 'page');
    }
    ?>
</main>

<?php
get_footer();


