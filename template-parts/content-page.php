<?php
/**
 * Template part for displaying pages
 *
 * @package MatchMe
 */
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Detect if current page content is primarily a quiz runner page.
 *
 * We treat pages containing [match_me_quiz] or [X_quiz] as quiz pages and suppress the
 * outer WP title/description to avoid duplication with the quiz intro inside .mmq-fullheight.
 */
function mm_is_quiz_page_content(): bool {
    $postId = get_the_ID();
    if (!$postId) return false;

    $content = (string) get_post_field('post_content', $postId);
    if ($content === '') return false;

    // Prefer WP's shortcode parser, but keep a string fallback for edge cases.
    if (function_exists('has_shortcode')) {
        if (has_shortcode($content, 'match_me_quiz') || has_shortcode($content, 'X_quiz')) {
            return true;
        }
    }

    return (stripos($content, '[match_me_quiz') !== false) || (stripos($content, '[X_quiz') !== false);
}

$isQuizPage = mm_is_quiz_page_content();
?>

<article
    id="post-<?php the_ID(); ?>"
    <?php post_class($isQuizPage ? 'mm-page-quiz' : ''); ?>
>
    <?php if (!$isQuizPage) : ?>
        <header class="entry-header">
            <?php the_title('<h1 class="entry-title">', '</h1>'); ?>
        </header>
    <?php endif; ?>

    <div class="entry-content">
        <?php the_content(); ?>

        <?php
        wp_link_pages(array(
            'before' => '<div class="page-links">' . 'Pages:',
            'after'  => '</div>',
        ));
        ?>
    </div>
</article>


