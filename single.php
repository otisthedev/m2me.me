<?php
/**
 * The template for displaying single posts
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
            
            // Check if this is a quiz post by looking for quiz JSON slug in meta
            $post_id = get_the_ID();
            $quiz_json_slug = get_post_meta($post_id, '_quiz_json_slug', true);
            
            if ($quiz_json_slug !== '') {
                // This is a quiz post, render the quiz using the stored JSON slug
                echo do_shortcode('[X_quiz id="' . esc_attr($quiz_json_slug) . '"]');
            } else {
                // Fallback: check if post slug matches a quiz JSON file (for backward compatibility)
                $post_slug = get_post_field('post_name', $post_id);
                $quiz_file = \MatchMe\Wp\Container::config()->quizDirectory() . $post_slug . '.json';
                
                if (file_exists($quiz_file)) {
                    // This is a quiz post, render the quiz
                    echo do_shortcode('[X_quiz id="' . esc_attr($post_slug) . '"]');
                } else {
                    // Regular post
                    get_template_part('template-parts/content', get_post_format());
                }
            }
        }
        ?>
    </div>
</main>

<?php
get_footer();

