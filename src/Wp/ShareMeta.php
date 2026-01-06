<?php
declare(strict_types=1);

namespace MatchMe\Wp;

use MatchMe\Infrastructure\Db\ResultRepository;
use MatchMe\Infrastructure\Quiz\QuizJsonRepository;

/**
 * Adds Open Graph / Twitter meta tags for shareable result & compare pages.
 */
final class ShareMeta
{
    public function register(): void
    {
        add_filter('pre_get_document_title', [$this, 'filterDocumentTitle'], 20);
        add_action('wp_head', [$this, 'renderMetaTags'], 1);
    }

    public function filterDocumentTitle(string $title): string
    {
        $mode = (string) get_query_var('mm_share_mode');
        $mode = ($mode === 'compare') ? 'compare' : (($mode === 'match') ? 'match' : 'view');

        $token = (string) get_query_var('mm_share_token');
        $cmp = (string) get_query_var('mm_comparison_token');
        if ($token === '' && $cmp === '') {
            return $title;
        }

        $data = $this->buildMetaData($token, $cmp, $mode);
        if ($data === null) {
            return $title;
        }

        return $data['title'];
    }

    public function renderMetaTags(): void
    {
        $mode = (string) get_query_var('mm_share_mode');
        $mode = ($mode === 'compare') ? 'compare' : (($mode === 'match') ? 'match' : 'view');

        $token = (string) get_query_var('mm_share_token');
        $cmp = (string) get_query_var('mm_comparison_token');
        if ($token === '' && $cmp === '') {
            return;
        }

        $data = $this->buildMetaData($token, $cmp, $mode);
        if ($data === null) {
            return;
        }

        $title = esc_attr($data['title']);
        $desc = esc_attr($data['description']);
        $url = esc_url($data['url']);
        $image = esc_url($data['image']);
        $siteName = esc_attr(get_bloginfo('name'));
        $locale = esc_attr(str_replace('_', '-', (string) get_locale()));

        echo "\n" . '<!-- MatchMe Share Meta -->' . "\n";
        echo '<link rel="canonical" href="' . $url . '">' . "\n";
        echo '<meta name="description" content="' . $desc . '">' . "\n";

        // Open Graph
        echo '<meta property="og:type" content="website">' . "\n";
        echo '<meta property="og:site_name" content="' . $siteName . '">' . "\n";
        echo '<meta property="og:locale" content="' . $locale . '">' . "\n";
        echo '<meta property="og:title" content="' . $title . '">' . "\n";
        echo '<meta property="og:description" content="' . $desc . '">' . "\n";
        echo '<meta property="og:url" content="' . $url . '">' . "\n";
        if ($image !== '') {
            echo '<meta property="og:image" content="' . $image . '">' . "\n";
        }

        // Twitter
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '<meta name="twitter:title" content="' . $title . '">' . "\n";
        echo '<meta name="twitter:description" content="' . $desc . '">' . "\n";
        if ($image !== '') {
            echo '<meta name="twitter:image" content="' . $image . '">' . "\n";
        }
        echo '<!-- /MatchMe Share Meta -->' . "\n";
    }

    /**
     * @return array{title:string,description:string,url:string,image:string}|null
     */
    private function buildMetaData(string $token, string $comparisonToken, string $mode): ?array
    {
        $wpdb = Container::wpdb();
        $config = Container::config();

        $resultRepo = new ResultRepository($wpdb);

        $row = null;
        $quizTitle = 'Quiz Results';
        $ownerName = 'Someone';
        $image = $this->fallbackShareImage();

        if ($mode === 'match') {
            // For match shares, we don't need perfect identity—use the "other" person's image if available.
            $cmpRepo = new \MatchMe\Infrastructure\Db\ComparisonRepository($wpdb);
            $cmp = $cmpRepo->findByShareToken($comparisonToken);
            if ($cmp === null) {
                return null;
            }

            $rowA = $resultRepo->findById((int) ($cmp['result_a'] ?? 0));
            if ($rowA === null) {
                return null;
            }
            $row = $rowA;
        } else {
            $row = $resultRepo->findByShareToken($token);
        }

        if ($row === null) {
            return null;
        }

        // Do not leak metadata for private results.
        $shareMode = (string) ($row['share_mode'] ?? 'private');
        if ($shareMode === 'private') {
            $viewerId = (int) get_current_user_id();
            $ownerId = (int) ($row['user_id'] ?? 0);
            if ($viewerId === 0 || $viewerId !== $ownerId) {
                return null;
            }
        }

        $quizSlug = (string) ($row['quiz_slug'] ?? '');
        if ($quizSlug !== '') {
            try {
                $quizConfig = (new QuizJsonRepository($config))->load($quizSlug);
                $quizTitle = (string) (($quizConfig['meta']['title'] ?? '') ?: $quizTitle);
            } catch (\Throwable) {
                // ignore
            }
        }

        $ownerId = (int) ($row['user_id'] ?? 0);
        if ($ownerId > 0) {
            $u = get_user_by('id', $ownerId);
            if ($u instanceof \WP_User) {
                $first = (string) get_user_meta($ownerId, 'first_name', true);
                $ownerName = $first !== '' ? $first : (string) ($u->display_name ?: $ownerName);
                $image = (string) get_avatar_url($ownerId, ['size' => 512]);
                if ($image === '') {
                    $image = $this->fallbackShareImage();
                }
            }
        }

        $url = $mode === 'compare'
            ? home_url('/compare/' . rawurlencode($token) . '/')
            : ($mode === 'match'
                ? home_url('/match/' . rawurlencode($comparisonToken) . '/')
                : home_url('/result/' . rawurlencode($token) . '/'));

        // Prefer our dynamic share image endpoint (SVG). Fall back to avatar/site icon if needed.
        // Mode mapping:
        // - view/compare -> result image (based on mm_share_token)
        // - match -> match image (based on mm_comparison_token)
        $imgMode = ($mode === 'match') ? 'match' : 'result';
        $imgToken = ($mode === 'match') ? $comparisonToken : $token;
        if ($imgToken !== '') {
            $image = add_query_arg(
                [
                    'mm_share_image' => '1',
                    'mode' => $imgMode,
                    'token' => $imgToken,
                    'size' => 'og',
                ],
                home_url('/')
            );
        }

        if ($mode === 'compare') {
            $title = 'Compare with ' . $ownerName;
            // Limit to ~60 chars
            if (strlen($title) > 60) {
                $title = 'Compare Your Results';
            }
            $description = 'Take the quiz to compare your personality results with ' . $ownerName . ' and see how you match.';
        } elseif ($mode === 'match') {
            $title = 'Comparison Result — ' . $quizTitle;
            if (strlen($title) > 60) {
                $title = 'Comparison Result';
            }
            $description = 'See the comparison results and discover how your personalities align.';
        } else {
            $title = $ownerName . "'s Quiz Results";
            if (strlen($title) > 60) {
                $title = "See $ownerName's Results";
            }
            $description = 'Take the quiz to see how your results compare with ' . $ownerName . "'s personality profile.";
        }

        return [
            'title' => $title,
            'description' => $description,
            'url' => $url,
            'image' => $image,
        ];
    }

    private function fallbackShareImage(): string
    {
        $siteIcon = (string) get_site_icon_url(512);
        if ($siteIcon !== '') {
            return $siteIcon;
        }

        $headerLogoId = (int) get_theme_mod('match_me_header_logo', 0);
        if ($headerLogoId > 0) {
            $url = wp_get_attachment_image_url($headerLogoId, 'full');
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        $customLogoId = (int) get_theme_mod('custom_logo', 0);
        if ($customLogoId > 0) {
            $url = wp_get_attachment_image_url($customLogoId, 'full');
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return (string) get_template_directory_uri() . '/assets/img/M2me.me.svg';
    }
}


