<?php
/**
 * The main template file
 *
 * @package MatchMe
 */

get_header();
?>

<main id="main" class="site-main">
    <?php
    if (have_posts()) {
        while (have_posts()) {
            the_post();
            get_template_part('template-parts/content', get_post_type());
        }
        
        // Pagination
        the_posts_pagination(array(
            'mid_size'  => 2,
            'prev_text' => __('&laquo; Previous', 'match-me'),
            'next_text' => __('Next &raquo;', 'match-me'),
        ));
    } else {
        get_template_part('template-parts/content', 'none');
    }
    ?>
</main>

<?php
get_footer();

