<?php
/**
 * Template Name: Comparisons
 */
declare(strict_types=1);

defined('ABSPATH') || exit;

use MatchMe\Infrastructure\Db\ComparisonRepository;
use MatchMe\Infrastructure\Quiz\QuizJsonRepository;
use MatchMe\Wp\Container;

get_header();

$currentPath = (string) ($_SERVER['REQUEST_URI'] ?? '/comparisons/');
$redirectTo = wp_validate_redirect(home_url($currentPath), home_url('/'));
?>

<main class="mm-comparisons container mm-page mm-page-980">
    <h1>Comparison history</h1>

    <?php if (!is_user_logged_in()) : ?>
        <p>Please log in to see your comparison history.</p>
        <p>
            <a class="mm-auth-link" data-auth="login" href="<?php echo esc_url(add_query_arg(['login' => '1', 'redirect_to' => $redirectTo], home_url('/'))); ?>">Login</a>
            &nbsp;or&nbsp;
            <a class="mm-auth-link" data-auth="register" href="<?php echo esc_url(add_query_arg(['register' => '1', 'redirect_to' => $redirectTo], home_url('/'))); ?>">Register</a>
        </p>
    <?php else : ?>
        <?php
        $userId = (int) get_current_user_id();
        $wpdb = Container::wpdb();
        $cfg = Container::config();

        $repo = new ComparisonRepository($wpdb);
        $quizRepo = new QuizJsonRepository($cfg);
        $quizTitleCache = [];
        $rows = $repo->latestByUser($userId, 24);
        
        // Bulk fetch user data to avoid N+1 queries
        $otherUserIds = [];
        foreach ($rows as $row) {
            $userA = isset($row['user_a']) ? (int) $row['user_a'] : 0;
            $userB = isset($row['user_b']) ? (int) $row['user_b'] : 0;
            $otherId = ($userA === $userId) ? $userB : $userA;
            if ($otherId > 0) {
                $otherUserIds[] = $otherId;
            }
        }
        $otherUserIds = array_unique($otherUserIds);
        
        // Bulk fetch users
        $otherUsers = [];
        if (!empty($otherUserIds)) {
            $users = get_users(['include' => $otherUserIds, 'number' => count($otherUserIds)]);
            foreach ($users as $u) {
                if ($u instanceof \WP_User) {
                    $otherUsers[$u->ID] = $u;
                }
            }
            
            // Bulk fetch user meta (first_name, profile_picture)
            $userMetaData = [];
            if (!empty($otherUserIds)) {
                $placeholders = implode(',', array_fill(0, count($otherUserIds), '%d'));
                $metaQuery = $wpdb->prepare(
                    "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} 
                     WHERE user_id IN ($placeholders) 
                     AND meta_key IN ('first_name', 'profile_picture')",
                    ...$otherUserIds
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
        }
        ?>

        <?php if ($rows === []) : ?>
            <div class="mm-empty mm-mt-md">
                <p>No comparisons yet. Take a quiz and share the compare link to get started.</p>
            </div>
        <?php else : ?>
            <div class="mm-compare-grid mm-mt-md">
                <?php foreach ($rows as $row) : ?>
                    <?php
                    $shareToken = (string) ($row['share_token'] ?? '');
                    if ($shareToken === '') {
                        continue;
                    }

                    $matchScore = (int) round((float) ($row['match_score'] ?? 0.0));

                    $createdAt = (string) ($row['created_at'] ?? '');
                    $createdTs = $createdAt !== '' ? strtotime($createdAt) : 0;
                    $ago = $createdTs > 0 ? human_time_diff($createdTs, (int) current_time('timestamp')) . ' ago' : '';

                    $userA = isset($row['user_a']) ? (int) $row['user_a'] : 0;
                    $userB = isset($row['user_b']) ? (int) $row['user_b'] : 0;
                    $otherId = ($userA === $userId) ? $userB : $userA;

                    $otherName = 'Someone';
                    $otherAvatar = '';
                    if ($otherId > 0 && isset($otherUsers[$otherId])) {
                        $u = $otherUsers[$otherId];
                        $first = isset($userMetaData[$otherId]['first_name']) ? (string) $userMetaData[$otherId]['first_name'] : '';
                        $otherName = $first !== '' ? $first : (string) ($u->display_name ?: $otherName);
                        $profilePic = isset($userMetaData[$otherId]['profile_picture']) ? (string) $userMetaData[$otherId]['profile_picture'] : '';
                        if ($profilePic !== '' && filter_var($profilePic, FILTER_VALIDATE_URL)) {
                            $otherAvatar = $profilePic;
                        } else {
                            $otherAvatar = (string) get_avatar_url($otherId, ['size' => 128]);
                        }
                    }

                    $quizSlug = (string) (($row['quiz_slug_a'] ?? '') ?: ($row['quiz_slug_b'] ?? ''));
                    $quizTitle = $quizSlug !== '' ? ucfirst(str_replace('-', ' ', $quizSlug)) : 'Quiz';
                    if ($quizSlug !== '') {
                        if (isset($quizTitleCache[$quizSlug])) {
                            $quizTitle = (string) $quizTitleCache[$quizSlug];
                        } else {
                            try {
                                $qc = $quizRepo->load($quizSlug);
                                $quizTitle = (string) (($qc['meta']['title'] ?? '') ?: $quizTitle);
                            } catch (\Throwable) {
                                // ignore
                            }
                            $quizTitleCache[$quizSlug] = $quizTitle;
                        }
                    }

                    $url = home_url('/match/' . rawurlencode($shareToken) . '/');
                    ?>

                    <article class="mm-compare-card">
                        <div class="mm-compare-card-top">
                            <div class="mm-compare-avatar">
                                <?php if ($otherAvatar !== '') : ?>
                                    <img src="<?php echo esc_url($otherAvatar); ?>" alt="<?php echo esc_attr($otherName); ?>" loading="lazy" decoding="async">
                                <?php else : ?>
                                    <span><?php echo esc_html(mb_substr($otherName, 0, 1)); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="mm-compare-meta">
                                <div class="mm-compare-line">
                                    <strong><?php echo esc_html($otherName); ?></strong>
                                    <?php if ($ago !== '') : ?>
                                        did a comparison <?php echo esc_html($ago); ?>
                                    <?php else : ?>
                                        did a comparison recently
                                    <?php endif; ?>
                                </div>
                                <div class="mm-compare-sub"><?php echo esc_html($quizTitle); ?> • <?php echo esc_html($matchScore); ?>% match</div>
                            </div>
                        </div>
                        <div class="mm-compare-actions">
                            <a class="mm-compare-link" href="<?php echo esc_url($url); ?>">Check results</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?php
get_footer();


