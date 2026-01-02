<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

get_header();

$mode = (string) get_query_var('mm_share_mode');
$mode = ($mode === 'compare') ? 'compare' : (($mode === 'match') ? 'match' : 'view');
?>

<main id="main" class="site-main" style="max-width: 720px; margin: 0 auto;">
    <div class="mm-share-result">
        <?php if ($mode === 'match') : ?>
            <h1>Comparison Result</h1>
            <p>Your match breakdown.</p>
        <?php elseif ($mode === 'compare') : ?>
            <h1>Compare Results</h1>
            <p>Take the quiz to compare your results with this person.</p>
        <?php else : ?>
            <h1>Quiz Results</h1>
        <?php endif; ?>

        <div id="mm-share-result-root"></div>
    </div>
</main>

<?php
get_footer();


