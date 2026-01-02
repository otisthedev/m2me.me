<?php
/**
 * The template for displaying archive pages
 *
 * @package MatchMe
 */

get_header();
?>

<main id="main" class="site-main">
    <header class="page-header">
        <?php
        the_archive_title('<h1 class="page-title">', '</h1>');
        the_archive_description('<div class="archive-description">', '</div>');
        ?>
    </header>

    <?php
    if (have_posts()) {
        echo '<div class="mm-post-grid">';
        while (have_posts()) {
            the_post();
            get_template_part('template-parts/content', get_post_format());
        }
        echo '</div>';
        
        the_posts_navigation();
    } else {
        get_template_part('template-parts/content', 'none');
    }
    ?>
</main>

<?php
get_footer();


