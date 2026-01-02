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
        
        if (!is_singular() && 'post' === get_post_type()) {
            ?>
            <div class="entry-meta">
                <span class="posted-on">
                    <time datetime="<?php echo esc_attr(get_the_date('c')); ?>">
                        <?php echo esc_html(get_the_date()); ?>
                    </time>
                </span>
                <?php if (get_the_author()) : ?>
                    <span class="byline">
                        <?php esc_html_e('by', 'match-me'); ?>
                        <span class="author vcard">
                            <a class="url fn n" href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>">
                                <?php echo esc_html(get_the_author()); ?>
                            </a>
                        </span>
                    </span>
                <?php endif; ?>
                <?php
                $categories = get_the_category_list(', ');
                if ($categories) {
                    echo '<span class="cat-links">' . $categories . '</span>';
                }
                ?>
            </div>
            <?php
        }
        ?>
    </header>

    <div class="entry-content">
        <?php
        if (is_singular()) {
            the_content();
        } else {
            the_excerpt();
            ?>
            <p><a href="<?php echo esc_url(get_permalink()); ?>" class="button"><?php esc_html_e('Read More', 'match-me'); ?></a></p>
            <?php
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

