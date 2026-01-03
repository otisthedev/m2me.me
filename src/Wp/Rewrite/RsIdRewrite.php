<?php
declare(strict_types=1);

namespace MatchMe\Wp\Rewrite;

final class RsIdRewrite
{
    public function register(): void
    {
        add_action('init', [$this, 'addRules']);
        add_filter('query_vars', [$this, 'addQueryVars']);
        add_action('wp', [$this, 'syncQueryVarToGet']);
        add_action('template_redirect', [$this, 'redirectQueryStringToPretty'], 1);
    }

    public function addRules(): void
    {
        add_rewrite_rule(
            '^([^/]+)/(\d+)/?$',
            'index.php?name=$matches[1]&rsID=$matches[2]',
            'top'
        );

        add_rewrite_tag('%rsID%', '(\d+)');
    }

    /**
     * @param array<int,string> $vars
     * @return array<int,string>
     */
    public function addQueryVars(array $vars): array
    {
        $vars[] = 'rsID';
        return $vars;
    }

    public function syncQueryVarToGet(): void
    {
        $rsid = get_query_var('rsID');
        if ($rsid !== '') {
            $_GET['rsID'] = $rsid;
        }
    }

    public function redirectQueryStringToPretty(): void
    {
        global $post;
        if (!$post) {
            return;
        }

        if (!isset($_GET['rsID']) || !is_numeric($_GET['rsID'])) {
            return;
        }

        if (!str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '?rsID=')) {
            return;
        }

        $rsid = absint($_GET['rsID']);
        $cleanUrl = home_url("/{$post->post_name}/{$rsid}/");
        wp_safe_redirect($cleanUrl, 301);
        exit;
    }
}



