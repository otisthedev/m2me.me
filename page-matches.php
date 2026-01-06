<?php
/**
 * Template Name: Matches
 */
declare(strict_types=1);

defined('ABSPATH') || exit;

use MatchMe\Infrastructure\Db\ComparisonRepository;
use MatchMe\Infrastructure\Db\ResultRepository;
use MatchMe\Infrastructure\Quiz\QuizJsonRepository;
use MatchMe\Quiz\QuizCalculator;
use MatchMe\Wp\Container;

get_header();

$currentPath = (string) ($_SERVER['REQUEST_URI'] ?? '/matches/');
$redirectTo = wp_validate_redirect(home_url($currentPath), home_url('/'));

/**
 * @param string $json
 * @return array<string,float>
 */
function mm_decode_vector(string $json): array {
    $d = json_decode($json, true);
    return is_array($d) ? $d : [];
}

/**
 * @param array<string,float> $a
 * @param array<string,float> $b
 */
function mm_match_score(QuizCalculator $calc, array $a, array $b, string $algorithm = 'cosine'): float {
    return match ($algorithm) {
        'euclidean' => $calc->computeMatchEuclidean($a, $b),
        'absolute' => $calc->computeMatchAbsolute($a, $b),
        default => $calc->computeMatch($a, $b),
    };
}

/**
 * @return array{overall:float, per_quiz:array<int,array{quiz_slug:string,quiz_title:string,score:float}>}
 */
function mm_overall_match_from_maps(
    array $mapA,
    array $mapB,
    QuizJsonRepository $quizRepo,
    QuizCalculator $calc,
    array &$quizTitleCache
): array {
    $shared = array_values(array_intersect(array_keys($mapA), array_keys($mapB)));
    if ($shared === []) {
        return ['overall' => 0.0, 'per_quiz' => []];
    }

    $per = [];
    $sum = 0.0;
    foreach ($shared as $slug) {
        $title = isset($quizTitleCache[$slug]) ? (string) $quizTitleCache[$slug] : ucfirst(str_replace('-', ' ', $slug));
        if (!isset($quizTitleCache[$slug])) {
            try {
                $qc = $quizRepo->load($slug);
                $title = (string) (($qc['meta']['title'] ?? '') ?: $title);
            } catch (\Throwable) {
                // ignore
            }
            $quizTitleCache[$slug] = $title;
        }

        $score = mm_match_score($calc, mm_decode_vector($mapA[$slug]), mm_decode_vector($mapB[$slug]), 'cosine');
        $per[] = ['quiz_slug' => $slug, 'quiz_title' => $title, 'score' => $score];
        $sum += $score;
    }

    usort($per, fn($x, $y) => ($y['score'] <=> $x['score']));
    $overall = $sum / max(1, count($per));

    return ['overall' => $overall, 'per_quiz' => $per];
}

?>

<main class="mm-matches container mm-page mm-page-1100">
    <h1><?php echo 'Matches; ?></h1>

    <?php if (!is_user_logged_in()) : ?>
        <p><?php echo 'Please log in to see your matches.; ?></p>
        <p>
            <a class="mm-auth-link" data-auth="login" href="<?php echo esc_url(add_query_arg(['login' => '1', 'redirect_to' => $redirectTo], home_url('/'))); ?>"><?php echo 'Login; ?></a>
            &nbsp;<?php echo 'or; ?>&nbsp;
            <a class="mm-auth-link" data-auth="register" href="<?php echo esc_url(add_query_arg(['register' => '1', 'redirect_to' => $redirectTo], home_url('/'))); ?>"><?php echo 'Register; ?></a>
        </p>
    <?php else : ?>
        <?php
        $me = (int) get_current_user_id();
        $wpdb = Container::wpdb();
        $cfg = Container::config();
        $compRepo = new ComparisonRepository($wpdb);
        $resRepo = new ResultRepository($wpdb);
        $quizRepo = new QuizJsonRepository($cfg);
        $calc = new QuizCalculator();

        $people = $compRepo->counterpartUsersForUser($me, 40);
        $quizTitleCache = [];

        // Precompute "me" vectors once.
        $myRows = $resRepo->latestVectorsByUserGroupedByQuizSlug($me);
        $myMap = [];
        foreach ($myRows as $row) {
            $slug = (string) ($row['quiz_slug'] ?? '');
            if ($slug === '') continue;
            $myMap[$slug] = (string) ($row['trait_vector'] ?? '{}');
        }

        // Bulk-fetch latest vectors for all counterpart users (avoid N DB queries).
        $otherIds = array_values(array_unique(array_map(fn($p) => (int) ($p['other_user_id'] ?? 0), $people)));
        $otherIds = array_values(array_filter($otherIds, fn($v) => $v > 0));
        $bulkVectors = $resRepo->latestVectorsByUsersGroupedByQuizSlug($otherIds);
        
        // Bulk fetch users and user meta to avoid N+1 queries
        $otherUsers = [];
        $userMetaData = [];
        if (!empty($otherIds)) {
            $users = get_users(['include' => $otherIds, 'number' => count($otherIds)]);
            foreach ($users as $u) {
                if ($u instanceof \WP_User) {
                    $otherUsers[$u->ID] = $u;
                }
            }
            
            // Bulk fetch user meta (first_name, profile_picture)
            $placeholders = implode(',', array_fill(0, count($otherIds), '%d'));
            $metaQuery = $wpdb->prepare(
                "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} 
                 WHERE user_id IN ($placeholders) 
                 AND meta_key IN ('first_name', 'profile_picture')",
                ...$otherIds
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $metaRows = $wpdb->get_results($metaQuery, ARRAY_A);
            foreach ($metaRows as $metaRow) {
                $uid = (int) $metaRow['user_id'];
                $key = $metaRow['meta_key'];
                if (!isset($userMetaData[$uid])) {
                    $userMetaData[$uid] = [];
                }
                $userMetaData[$uid][$key] = $metaRow['meta_value'];
            }
        }

        $with = isset($_GET['with']) ? (int) $_GET['with'] : 0;
        $allowedIds = array_map(fn($r) => (int) $r['other_user_id'], $people);
        $showDetail = $with > 0 && in_array($with, $allowedIds, true);

        $cards = [];
        foreach ($people as $p) {
            $oid = (int) $p['other_user_id'];
            if ($oid <= 0) continue;
            if (!isset($otherUsers[$oid])) continue;
            $u = $otherUsers[$oid];

            $first = isset($userMetaData[$oid]['first_name']) ? (string) $userMetaData[$oid]['first_name'] : '';
            $name = $first !== '' ? $first : (string) ($u->display_name ?: 'Someone');
            $profilePic = isset($userMetaData[$oid]['profile_picture']) ? (string) $userMetaData[$oid]['profile_picture'] : '';
            if ($profilePic !== '' && filter_var($profilePic, FILTER_VALIDATE_URL)) {
                $avatar = $profilePic;
            } else {
                $avatar = (string) get_avatar_url($oid, ['size' => 128]);
            }

            $theirMap = is_array($bulkVectors[$oid] ?? null) ? (array) $bulkVectors[$oid] : [];

            $overall = mm_overall_match_from_maps($myMap, $theirMap, $quizRepo, $calc, $quizTitleCache);
            $score = (float) $overall['overall'];

            $cards[] = [
                'id' => $oid,
                'name' => $name,
                'avatar' => $avatar,
                'overall' => $score,
                'shared_quizzes' => count($overall['per_quiz']),
                'last_at' => (string) ($p['last_at'] ?? ''),
            ];
        }

        usort($cards, fn($a, $b) => ($b['overall'] <=> $a['overall']));
        $top = array_slice($cards, 0, 6);
        ?>

        <?php if ($showDetail) : ?>
            <?php
            $otherId = $with;
            $otherUser = get_user_by('id', $otherId);
            $otherName = $otherUser instanceof \WP_User ? (string) ($otherUser->display_name ?: 'Someone') : 'Someone';
            $first = (string) get_user_meta($otherId, 'first_name', true);
            if ($first !== '') $otherName = $first;
            $otherAvatar = (string) get_avatar_url($otherId, ['size' => 256]);

            $theirMap = is_array($bulkVectors[$otherId] ?? null) ? (array) $bulkVectors[$otherId] : [];

            $overall = mm_overall_match_from_maps($myMap, $theirMap, $quizRepo, $calc, $quizTitleCache);
            $overallPct = (int) round(max(0.0, min(100.0, (float) $overall['overall'])));
            $latestMatchToken = $compRepo->latestShareTokenBetweenUsers($me, $otherId);
            ?>

            <div class="mm-match-detail">
                <a class="mm-back-link" href="<?php echo esc_url(home_url('/matches/')); ?>">← <?php echo 'Back to matches; ?></a>

                <div class="mm-match-hero">
                    <div class="mm-compare-avatar mm-compare-avatar-lg">
                        <?php if ($otherAvatar !== '') : ?>
                            <img src="<?php echo esc_url($otherAvatar); ?>" alt="<?php echo esc_attr($otherName); ?>" loading="lazy" decoding="async">
                        <?php else : ?>
                            <span><?php echo esc_html(mb_substr($otherName, 0, 1)); ?></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="mm-match-title"><strong><?php echo esc_html($otherName); ?></strong></div>
                        <div class="mm-match-sub"><?php echo esc_html($overallPct); ?>% <?php echo 'overall match; ?> • <?php echo esc_html(count($overall['per_quiz'])); ?> <?php echo 'shared quizzes; ?></div>
                    </div>

                    <div class="mm-match-hero-actions">
                        <?php if ($latestMatchToken !== '') : ?>
                            <a class="mm-compare-link" href="<?php echo esc_url(home_url('/match/' . $latestMatchToken . '/')); ?>"><?php echo 'View latest comparison; ?></a>
                        <?php endif; ?>
                    </div>
                </div>

                <h2 class="mm-mt-lg"><?php echo 'Match by quiz; ?></h2>
                <div class="mm-match-by-quiz">
                    <?php if ($overall['per_quiz'] === []) : ?>
                        <div class="mm-empty"><p><?php echo 'No shared quizzes yet.; ?></p></div>
                    <?php else : ?>
                        <?php foreach ($overall['per_quiz'] as $q) : ?>
                            <?php $pct = (int) round(max(0.0, min(100.0, (float) $q['score']))); ?>
                            <div class="mm-match-row">
                                <div class="mm-match-row-title"><?php echo esc_html((string) $q['quiz_title']); ?></div>
                                <div class="mm-match-row-score"><?php echo esc_html($pct); ?>%</div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        <?php else : ?>

            <section class="mm-mt-md">
                <div class="mm-recent-header">
                    <h2 class="mm-recent-title"><?php echo 'Top matches; ?></h2>
                </div>

                <?php if ($top === []) : ?>
                    <div class="mm-empty"><p><?php echo 'No matches yet. Do a few comparisons first.; ?></p></div>
                <?php else : ?>
                    <div class="mm-compare-grid">
                        <?php foreach ($top as $c) : ?>
                            <?php $pct = (int) round(max(0.0, min(100.0, (float) $c['overall']))); ?>
                            <article class="mm-compare-card">
                                <div class="mm-compare-card-top">
                                    <div class="mm-compare-avatar">
                                        <?php if ($c['avatar'] !== '') : ?>
                                            <img src="<?php echo esc_url($c['avatar']); ?>" alt="<?php echo esc_attr($c['name']); ?>" loading="lazy" decoding="async">
                                        <?php else : ?>
                                            <span><?php echo esc_html(mb_substr($c['name'], 0, 1)); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mm-compare-meta">
                                        <div class="mm-compare-line"><strong><?php echo esc_html($c['name']); ?></strong></div>
                                        <div class="mm-compare-sub"><?php echo esc_html($pct); ?>% <?php echo 'overall; ?> • <?php echo esc_html((string) $c['shared_quizzes']); ?> <?php echo 'shared quizzes; ?></div>
                                    </div>
                                </div>
                                <div class="mm-compare-actions">
                                    <a class="mm-compare-link" href="<?php echo esc_url(add_query_arg(['with' => (string) $c['id']], home_url('/matches/'))); ?>"><?php echo 'View overall match; ?></a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="mm-mt-lg">
                <div class="mm-recent-header">
                    <h2 class="mm-recent-title"><?php echo 'All matches; ?></h2>
                </div>

                <?php if ($cards === []) : ?>
                    <div class="mm-empty"><p><?php echo 'No matches yet.; ?></p></div>
                <?php else : ?>
                    <div class="mm-compare-grid">
                        <?php foreach ($cards as $c) : ?>
                            <?php $pct = (int) round(max(0.0, min(100.0, (float) $c['overall']))); ?>
                            <article class="mm-compare-card">
                                <div class="mm-compare-card-top">
                                    <div class="mm-compare-avatar">
                                        <?php if ($c['avatar'] !== '') : ?>
                                            <img src="<?php echo esc_url($c['avatar']); ?>" alt="<?php echo esc_attr($c['name']); ?>" loading="lazy" decoding="async">
                                        <?php else : ?>
                                            <span><?php echo esc_html(mb_substr($c['name'], 0, 1)); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mm-compare-meta">
                                        <div class="mm-compare-line"><strong><?php echo esc_html($c['name']); ?></strong></div>
                                        <div class="mm-compare-sub"><?php echo esc_html($pct); ?>% <?php echo 'overall; ?> • <?php echo esc_html((string) $c['shared_quizzes']); ?> <?php echo 'shared quizzes; ?></div>
                                    </div>
                                </div>
                                <div class="mm-compare-actions">
                                    <a class="mm-compare-link" href="<?php echo esc_url(add_query_arg(['with' => (string) $c['id']], home_url('/matches/'))); ?>"><?php echo 'View overall match; ?></a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

        <?php endif; ?>
    <?php endif; ?>
</main>

<?php
get_footer();


