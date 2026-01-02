<?php
declare(strict_types=1);

namespace MatchMe\Wp\Rewrite;

final class ShareTokenRewrite
{
    public function register(): void
    {
        add_action('init', [$this, 'addRules']);
        add_filter('query_vars', [$this, 'addQueryVars']);
        add_filter('template_include', [$this, 'templateInclude']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addRules(): void
    {
        add_rewrite_rule(
            '^result/([A-Za-z0-9]+)/?$',
            'index.php?mm_share_token=$matches[1]&mm_share_mode=view',
            'top'
        );

        add_rewrite_rule(
            '^compare/([A-Za-z0-9]+)/?$',
            'index.php?mm_share_token=$matches[1]&mm_share_mode=compare',
            'top'
        );

        add_rewrite_tag('%mm_share_token%', '([A-Za-z0-9]+)');
        add_rewrite_tag('%mm_share_mode%', '(view|compare)');
    }

    /**
     * @param array<int,string> $vars
     * @return array<int,string>
     */
    public function addQueryVars(array $vars): array
    {
        $vars[] = 'mm_share_token';
        $vars[] = 'mm_share_mode';
        return $vars;
    }

    public function templateInclude(string $template): string
    {
        $token = (string) get_query_var('mm_share_token');
        if ($token === '') {
            return $template;
        }

        $mode = (string) get_query_var('mm_share_mode');
        if ($mode !== 'view' && $mode !== 'compare') {
            return $template;
        }

        $candidate = (string) get_template_directory() . '/templates/share-result.php';
        return is_file($candidate) ? $candidate : $template;
    }

    public function enqueueAssets(): void
    {
        $token = (string) get_query_var('mm_share_token');
        if ($token === '') {
            return;
        }

        $baseDir = (string) get_template_directory();
        $fallback = defined('MATCH_ME_VERSION') ? (string) MATCH_ME_VERSION : '1.0';

        $ajaxClient = $baseDir . '/assets/js/quiz-ajax-client.js';
        $resultsUi = $baseDir . '/assets/js/quiz-results-ui.js';
        $resultsCss = $baseDir . '/assets/css/quiz-results.css';
        $pageJs = $baseDir . '/assets/js/share-result-page.js';

        $ajaxClientVer = is_file($ajaxClient) ? (string) filemtime($ajaxClient) : $fallback;
        $resultsUiVer = is_file($resultsUi) ? (string) filemtime($resultsUi) : $fallback;
        $resultsCssVer = is_file($resultsCss) ? (string) filemtime($resultsCss) : $fallback;
        $pageJsVer = is_file($pageJs) ? (string) filemtime($pageJs) : $fallback;

        wp_enqueue_style('match-me-quiz-results', get_template_directory_uri() . '/assets/css/quiz-results.css', [], $resultsCssVer);
        wp_enqueue_script('match-me-quiz-ajax-client', get_template_directory_uri() . '/assets/js/quiz-ajax-client.js', [], $ajaxClientVer, true);
        wp_enqueue_script('match-me-quiz-results-ui', get_template_directory_uri() . '/assets/js/quiz-results-ui.js', [], $resultsUiVer, true);
        wp_enqueue_script('match-me-share-result-page', get_template_directory_uri() . '/assets/js/share-result-page.js', [], $pageJsVer, true);

        $inline = 'window.matchMeShareToken=' . wp_json_encode($token) . ';'
            . 'window.matchMeShareMode=' . wp_json_encode((string) get_query_var('mm_share_mode')) . ';';
        wp_add_inline_script('match-me-share-result-page', $inline, 'before');
    }
}


