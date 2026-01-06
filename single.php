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
        
        // Check if this is a quiz post by looking for quiz JSON slug in meta
        $post_id = get_the_ID();
        $quiz_json_slug = get_post_meta($post_id, '_quiz_json_slug', true);
        $is_quiz_post = false;
        
        if ($quiz_json_slug !== '') {
            $is_quiz_post = true;
        } else {
            // Fallback: check if post slug matches a quiz JSON file (for backward compatibility)
            $post_slug = get_post_field('post_name', $post_id);
            $quiz_file = \MatchMe\Wp\Container::config()->quizDirectory() . $post_slug . '.json';
            if (file_exists($quiz_file)) {
                $is_quiz_post = true;
            }
        }
        
        $container_class = $is_quiz_post ? 'container quiz-post-container' : 'container';
    ?>
    <div class="<?php echo esc_attr($container_class); ?>">
        <?php
        if ($is_quiz_post) {
            if ($quiz_json_slug !== '') {
                // This is a quiz post, render the quiz using the stored JSON slug
                echo do_shortcode('[X_quiz id="' . esc_attr($quiz_json_slug) . '"]');
            } else {
                // Fallback: render quiz using post slug
                echo do_shortcode('[X_quiz id="' . esc_attr($post_slug) . '"]');
            }
        } else {
            // Regular post
            get_template_part('template-parts/content', get_post_format());
        }
        ?>
    </div>
    <?php
    }
    ?>
</main>

<?php
get_footer();

