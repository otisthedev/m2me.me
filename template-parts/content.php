<?php
/**
 * Template part for displaying posts
 *
 * @package MatchMe
 */

?>

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
    <?php if (!is_singular() && has_post_thumbnail()) : ?>
        <a class="mm-post-card-thumb" href="<?php echo esc_url(get_permalink()); ?>" aria-label="<?php echo esc_attr(get_the_title()); ?>">
            <?php the_post_thumbnail('large', ['class' => 'mm-post-card-img', 'loading' => 'lazy', 'decoding' => 'async']); ?>
        </a>
    <?php endif; ?>

    <header class="entry-header">
        <?php
        if (is_singular()) {
            // the_title('<h1 class="entry-title">', '</h1>');
        } else {
            the_title('<h2 class="entry-title"><a href="' . esc_url(get_permalink()) . '" rel="bookmark">', '</a></h2>');
        }
        ?>
    </header>

    <div class="entry-content">
        <?php
        if (is_singular()) {
            // the_content();
        } else {
            the_excerpt();
        }
        
        wp_link_pages(array(
            'before' => '<div class="page-links">' . 'Pages:',
            'after'  => '</div>',
        ));
        ?>
    </div>

    <?php if (is_singular() && 'post' === get_post_type()) : ?>
        <footer class="entry-footer">
            <?php
            $tags = get_the_tag_list('', ', ');
            if ($tags) {
                echo '<span class="tags-links">' . 'Tagged: ' . $tags . '</span>';
            }
            ?>
        </footer>
    <?php endif; ?>
</article>

