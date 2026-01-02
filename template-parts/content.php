<?php
/**
 * Template part for displaying posts
 *
 * @package MatchMe
 */

?>

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
    <header class="entry-header">
        <?php
        if (is_singular()) {
            the_title('<h1 class="entry-title">', '</h1>');
        } else {
            the_title('<h2 class="entry-title"><a href="' . esc_url(get_permalink()) . '" rel="bookmark">', '</a></h2>');
        }
        ?>
    </header>

    <div class="entry-content">
        <?php
        if (is_singular()) {
            the_content();
        } else {
            the_excerpt();
        }
        
        wp_link_pages(array(
            'before' => '<div class="page-links">' . esc_html__('Pages:', 'match-me'),
            'after'  => '</div>',
        ));
        ?>
    </div>

    <?php if (is_singular() && 'post' === get_post_type()) : ?>
        <footer class="entry-footer">
            <?php
            $tags = get_the_tag_list('', ', ');
            if ($tags) {
                echo '<span class="tags-links">' . esc_html__('Tagged: ', 'match-me') . $tags . '</span>';
            }
            ?>
        </footer>
    <?php endif; ?>
</article>

