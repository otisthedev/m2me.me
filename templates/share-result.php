<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

get_header();

$mode = (string) get_query_var('mm_share_mode');
$mode = ($mode === 'compare') ? 'compare' : (($mode === 'match') ? 'match' : 'view');
?>

<main id="main" class="site-main">
    <div class="mm-share-result container mm-page mm-page-720">
        <?php if ($mode === 'match') : ?>
            <h1>Comparison Result</h1>
            <p>Your match breakdown.</p>
        <?php elseif ($mode === 'compare') : ?>
            <h1>Compare Results</h1>
            <p>Take the quiz to compare your results with this person.</p>
        <?php else : ?>
            <h1>Quiz Results</h1>
        <?php endif; ?>

        <noscript>
            <div class="error-message mm-share-noscript">
                JavaScript is required to view shared results.
            </div>
        </noscript>

        <div id="mm-share-result-root">
            <div class="mm-result-loading">
                <div class="mm-result-loading-title">Loading…</div>
                <div class="mm-spinner" aria-hidden="true"></div>
                <div class="mm-result-loading-subtitle">One moment.</div>
            </div>
        </div>
    </div>
</main>

<?php
get_footer();


