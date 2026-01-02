<?php
/**
 * The template for displaying single posts
 *
 * @package MatchMe
 */

get_header();
?>

<main id="main" class="site-main">
    <?php
    while (have_posts()) {
        the_post();
        
        // Check if post slug matches a quiz JSON file
        $post_slug = get_post_field('post_name', get_the_ID());
        $quiz_file = \MatchMe\Wp\Container::config()->quizDirectory() . $post_slug . '.json';
        
        if (file_exists($quiz_file)) {
            // This is a quiz post, render the quiz
            echo do_shortcode('[X_quiz id="' . esc_attr($post_slug) . '"]');
        } else {
            // Regular post
            get_template_part('template-parts/content', get_post_format());
        }
    }
    ?>
</main>

<?php
get_footer();

