<?php
/**
 * The template for displaying pages
 *
 * @package MatchMe
 */

get_header();
?>

<main id="main" class="site-main">
    <div class="container">
        <?php
        while (have_posts()) {
            the_post();
            get_template_part('template-parts/content', 'page');
        }
        ?>
    </div>
</main>

<?php
get_footer();


