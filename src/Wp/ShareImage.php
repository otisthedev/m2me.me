<?php
declare(strict_types=1);

namespace MatchMe\Wp;

use MatchMe\Infrastructure\Db\ComparisonRepository;
use MatchMe\Infrastructure\Db\ResultRepository;
use MatchMe\Infrastructure\Quiz\QuizJsonRepository;

/**
 * Dynamic share images (SVG) for OG/Twitter and Instagram story downloads.
 *
 * Endpoints (no rewrite required):
 * - /?mm_share_image=1&mode=result&token=...&size=og
 * - /?mm_share_image=1&mode=match&token=...&size=og
 * - /?mm_share_image=1&mode=result&token=...&size=story
 * - /?mm_share_image=1&mode=match&token=...&size=story
 */
final class ShareImage
{
    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybeRender']);
    }

    public function maybeRender(): void
    {
        $flag = isset($_GET['mm_share_image']) ? (string) wp_unslash($_GET['mm_share_image']) : '';
        if ($flag === '') {
            return;
        }

        $mode = isset($_GET['mode']) ? (string) wp_unslash($_GET['mode']) : 'result';
        $mode = ($mode === 'match') ? 'match' : 'result';

        $size = isset($_GET['size']) ? (string) wp_unslash($_GET['size']) : 'og';
        $size = ($size === 'story') ? 'story' : 'og';

        $token = isset($_GET['token']) ? (string) wp_unslash($_GET['token']) : '';
        $token = preg_replace('/[^A-Za-z0-9]/', '', $token);
        if ($token === '') {
            $this->sendSvg($this->errorSvg($size, 'Missing token'), $size);
            exit;
        }

        $data = ($mode === 'match')
            ? $this->buildMatchData($token)
            : $this->buildResultData($token);

        if ($data === null) {
            status_header(404);
            $this->sendSvg($this->errorSvg($size, 'Not found'), $size);
            exit;
        }

        $svg = ($mode === 'match')
            ? $this->renderMatchSvg($data, $size)
            : $this->renderResultSvg($data, $size);

        $this->sendSvg($svg, $size);
        exit;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildResultData(string $shareToken): ?array
    {
        $wpdb = Container::wpdb();
        $config = Container::config();

        $repo = new ResultRepository($wpdb);
        $row = $repo->findByShareToken($shareToken);
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

        $quizTitle = 'Quiz Results';
        $quizSlug = (string) ($row['quiz_slug'] ?? '');
        $traitLabels = [];

        if ($quizSlug !== '') {
            try {
                $quiz = (new QuizJsonRepository($config))->load($quizSlug);
                $quizTitle = (string) (($quiz['meta']['title'] ?? '') ?: $quizTitle);
                $traits = is_array($quiz['traits'] ?? null) ? $quiz['traits'] : [];
                foreach ($traits as $t) {
                    if (!is_array($t)) continue;
                    $id = isset($t['id']) ? (string) $t['id'] : '';
                    $label = isset($t['label']) ? (string) $t['label'] : '';
                    if ($id !== '' && $label !== '') {
                        $traitLabels[$id] = $label;
                    }
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        $ownerId = (int) ($row['user_id'] ?? 0);
        $ownerName = 'You';
        $avatarUrl = '';

        if ($ownerId > 0) {
            $u = get_user_by('id', $ownerId);
            if ($u instanceof \WP_User) {
                $first = (string) get_user_meta($ownerId, 'first_name', true);
                $ownerName = $first !== '' ? $first : (string) ($u->display_name ?: $ownerName);
                $avatarUrl = (string) get_avatar_url($ownerId, ['size' => 256]);
            }
        }

        $vec = [];
        try {
            $vec = json_decode((string) ($row['trait_vector'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $vec = [];
        }

        $topTrait = '';
        $topVal = 0.0;
        if (is_array($vec)) {
            foreach ($vec as $k => $v) {
                $kk = (string) $k;
                $vv = is_numeric($v) ? (float) $v : 0.0;
                if ($kk === '') continue;
                if ($vv >= $topVal) {
                    $topVal = $vv;
                    $topTrait = $kk;
                }
            }
        }

        $topPct = (int) round(max(0.0, min(1.0, $topVal)) * 100);
        $topLabel = $topTrait !== '' ? (string) ($traitLabels[$topTrait] ?? $this->prettyTrait($topTrait)) : 'Your result';

        return [
            'quiz_title' => $quizTitle,
            'owner_name' => $ownerName,
            'avatar_url' => $avatarUrl,
            'top_label' => $topLabel,
            'top_pct' => $topPct,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildMatchData(string $comparisonToken): ?array
    {
        $wpdb = Container::wpdb();
        $config = Container::config();

        $cmpRepo = new ComparisonRepository($wpdb);
        $cmp = $cmpRepo->findByShareToken($comparisonToken);
        if ($cmp === null) {
            return null;
        }

        $resultRepo = new ResultRepository($wpdb);
        $rowA = $resultRepo->findById((int) ($cmp['result_a'] ?? 0));
        $rowB = $resultRepo->findById((int) ($cmp['result_b'] ?? 0));
        if ($rowA === null || $rowB === null) {
            return null;
        }

        $quizTitle = 'Quiz Results';
        $quizSlug = (string) ($rowA['quiz_slug'] ?? '');
        if ($quizSlug !== '') {
            try {
                $quiz = (new QuizJsonRepository($config))->load($quizSlug);
                $quizTitle = (string) (($quiz['meta']['title'] ?? '') ?: $quizTitle);
            } catch (\Throwable) {
                // ignore
            }
        }

        $aId = (int) ($rowA['user_id'] ?? 0);
        $bId = (int) ($rowB['user_id'] ?? 0);

        $aName = 'Them';
        $bName = 'You';
        $aAvatar = '';
        $bAvatar = '';

        if ($aId > 0) {
            $u = get_user_by('id', $aId);
            if ($u instanceof \WP_User) {
                $first = (string) get_user_meta($aId, 'first_name', true);
                $aName = $first !== '' ? $first : (string) ($u->display_name ?: $aName);
                $aAvatar = (string) get_avatar_url($aId, ['size' => 256]);
            }
        }
        if ($bId > 0) {
            $u = get_user_by('id', $bId);
            if ($u instanceof \WP_User) {
                $first = (string) get_user_meta($bId, 'first_name', true);
                $bName = $first !== '' ? $first : (string) ($u->display_name ?: $bName);
                $bAvatar = (string) get_avatar_url($bId, ['size' => 256]);
            }
        }

        $score = (float) ($cmp['match_score'] ?? 0.0);
        $scorePct = (int) round(max(0.0, min(1.0, $score)) * 100);

        return [
            'quiz_title' => $quizTitle,
            'a_name' => $aName,
            'b_name' => $bName,
            'a_avatar' => $aAvatar,
            'b_avatar' => $bAvatar,
            'match_pct' => $scorePct,
        ];
    }

    private function sendSvg(string $svg, string $size): void
    {
        // cache a little; token pages may be requested by crawlers.
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        header('X-Content-Type-Options: nosniff');
        header('Vary: Accept-Encoding');
        echo $svg;
    }

    private function prettyTrait(string $id): string
    {
        $t = str_replace('_', ' ', $id);
        return preg_replace_callback('/\b\w/u', static fn($m) => strtoupper((string) $m[0]), $t) ?: $id;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function logoUrl(): string
    {
        return (string) get_template_directory_uri() . '/assets/img/M2me.me-white.svg';
    }

    private function renderResultSvg(array $d, string $size): string
    {
        $w = ($size === 'story') ? 1080 : 1200;
        $h = ($size === 'story') ? 1920 : 630;

        $quizTitle = $this->esc((string) ($d['quiz_title'] ?? 'Quiz Results'));
        $name = $this->esc((string) ($d['owner_name'] ?? 'You'));
        $avatar = $this->esc((string) ($d['avatar_url'] ?? ''));
        $top = $this->esc((string) ($d['top_label'] ?? 'Your result'));
        $pct = (int) ($d['top_pct'] ?? 0);
        $pctText = $this->esc((string) $pct . '%');

        $initial = $this->esc(mb_strtoupper(mb_substr($name !== '' ? $name : 'Y', 0, 1)));
        $logo = $this->esc($this->logoUrl());

        if ($size === 'story') {
            return $this->resultStorySvg($w, $h, $quizTitle, $name, $avatar, $initial, $top, $pctText, $logo);
        }
        return $this->resultOgSvg($w, $h, $quizTitle, $name, $avatar, $initial, $top, $pctText, $logo);
    }

    private function renderMatchSvg(array $d, string $size): string
    {
        $w = ($size === 'story') ? 1080 : 1200;
        $h = ($size === 'story') ? 1920 : 630;

        $quizTitle = $this->esc((string) ($d['quiz_title'] ?? 'Quiz Results'));
        $aName = $this->esc((string) ($d['a_name'] ?? 'Them'));
        $bName = $this->esc((string) ($d['b_name'] ?? 'You'));
        $aAvatar = $this->esc((string) ($d['a_avatar'] ?? ''));
        $bAvatar = $this->esc((string) ($d['b_avatar'] ?? ''));
        $pct = (int) ($d['match_pct'] ?? 0);
        $pctText = $this->esc((string) $pct . '%');
        $aInitial = $this->esc(mb_strtoupper(mb_substr($aName !== '' ? $aName : 'T', 0, 1)));
        $bInitial = $this->esc(mb_strtoupper(mb_substr($bName !== '' ? $bName : 'Y', 0, 1)));
        $logo = $this->esc($this->logoUrl());

        if ($size === 'story') {
            return $this->matchStorySvg($w, $h, $quizTitle, $aName, $bName, $aAvatar, $bAvatar, $aInitial, $bInitial, $pctText, $logo);
        }
        return $this->matchOgSvg($w, $h, $quizTitle, $aName, $bName, $aAvatar, $bAvatar, $aInitial, $bInitial, $pctText, $logo);
    }

    private function errorSvg(string $size, string $msg): string
    {
        $w = ($size === 'story') ? 1080 : 1200;
        $h = ($size === 'story') ? 1920 : 630;
        $m = $this->esc($msg);
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="$w" height="$h" viewBox="0 0 $w $h">
  <rect width="$w" height="$h" fill="#1E2A44"/>
  <image href="{$this->esc($this->logoUrl())}" x="60" y="70" width="220" height="64" preserveAspectRatio="xMinYMid meet" opacity="0.96"/>
  <text x="60" y="190" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="28" font-weight="600" fill="rgba(246,245,242,0.85)">$m</text>
</svg>
SVG;
    }

    private function resultOgSvg(int $w, int $h, string $quizTitle, string $name, string $avatar, string $initial, string $top, string $pctText, string $logo): string
    {
        // 1200x630
        $avatarBlock = $avatar !== ''
            ? '<image href="' . $avatar . '" x="508" y="240" width="184" height="184" preserveAspectRatio="xMidYMid slice" clip-path="url(#clipAvatar)"/>'
            : '<text x="600" y="360" text-anchor="middle" font-size="78" font-weight="900" fill="#F6F5F2">' . $initial . '</text>';

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="$w" height="$h" viewBox="0 0 $w $h">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#1E2A44"/>
      <stop offset="0.55" stop-color="#6FAFB3"/>
      <stop offset="1" stop-color="#8FAEA3"/>
    </linearGradient>
    <radialGradient id="glow" cx="55%" cy="25%" r="75%">
      <stop offset="0" stop-color="#F6F5F2" stop-opacity="0.20"/>
      <stop offset="0.6" stop-color="#F6F5F2" stop-opacity="0.06"/>
      <stop offset="1" stop-color="#F6F5F2" stop-opacity="0"/>
    </radialGradient>
    <filter id="shadow" x="-50%" y="-50%" width="200%" height="200%">
      <feDropShadow dx="0" dy="14" stdDeviation="18" flood-color="#0B1220" flood-opacity="0.28"/>
    </filter>
    <clipPath id="clipAvatar">
      <circle cx="600" cy="332" r="92"/>
    </clipPath>
  </defs>

  <rect width="$w" height="$h" fill="url(#bg)"/>
  <rect width="$w" height="$h" fill="url(#glow)"/>

  <text x="72" y="92" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="20" font-weight="900" letter-spacing="2.2" fill="rgba(246,245,242,0.92)">QUIZ RESULTS</text>
  <text x="72" y="140" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="46" font-weight="900" letter-spacing="-0.02em" fill="#F6F5F2">$quizTitle</text>

  <text x="72" y="206" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="28" font-weight="650" fill="rgba(246,245,242,0.92)">Result</text>
  <text x="72" y="258" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="58" font-weight="950" letter-spacing="-0.03em" fill="#F6F5F2">$top — $pctText</text>

  <g filter="url(#shadow)">
    <circle cx="600" cy="332" r="118" fill="rgba(246,245,242,0.14)" stroke="rgba(246,245,242,0.30)" stroke-width="2"/>
    <circle cx="600" cy="332" r="104" fill="rgba(246,245,242,0.10)"/>
    <circle cx="600" cy="332" r="92" fill="rgba(30,42,68,0.24)"/>
    $avatarBlock
    <g>
      <rect x="420" y="446" width="360" height="72" rx="36" fill="rgba(246,245,242,0.92)"/>
      <text x="600" y="492" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="28" font-weight="950" fill="#1E2A44">$name • $pctText</text>
    </g>
  </g>

  <image href="$logo" x="72" y="552" width="180" height="52" preserveAspectRatio="xMinYMid meet" opacity="0.96"/>
</svg>
SVG;
    }

    private function resultStorySvg(int $w, int $h, string $quizTitle, string $name, string $avatar, string $initial, string $top, string $pctText, string $logo): string
    {
        // 1080x1920 (story) - modern "glass" card
        $avatarBlock = $avatar !== ''
            ? '<image href="' . $avatar . '" x="380" y="872" width="320" height="320" preserveAspectRatio="xMidYMid slice" clip-path="url(#clipAvatar)"/>'
            : '<text x="540" y="1060" text-anchor="middle" font-size="120" font-weight="900" fill="#F6F5F2">' . $initial . '</text>';

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="$w" height="$h" viewBox="0 0 $w $h">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#1E2A44"/>
      <stop offset="0.55" stop-color="#6FAFB3"/>
      <stop offset="1" stop-color="#8FAEA3"/>
    </linearGradient>
    <filter id="blur70" x="-50%" y="-50%" width="200%" height="200%">
      <feGaussianBlur stdDeviation="70"/>
    </filter>
    <filter id="shadow" x="-50%" y="-50%" width="200%" height="200%">
      <feDropShadow dx="0" dy="18" stdDeviation="22" flood-color="#0B1220" flood-opacity="0.30"/>
    </filter>
    <clipPath id="clipAvatar">
      <circle cx="540" cy="1032" r="160"/>
    </clipPath>
  </defs>

  <rect width="$w" height="$h" fill="url(#bg)"/>
  <!-- blurred blobs -->
  <g filter="url(#blur70)" opacity="0.6">
    <circle cx="220" cy="260" r="280" fill="rgba(246,245,242,0.20)"/>
    <circle cx="880" cy="360" r="300" fill="rgba(111,175,179,0.26)"/>
    <circle cx="720" cy="1120" r="380" fill="rgba(143,174,163,0.22)"/>
  </g>

  <!-- pill -->
  <g>
    <rect x="80" y="104" width="250" height="56" rx="28" fill="rgba(246,245,242,0.16)" stroke="rgba(246,245,242,0.26)"/>
    <text x="105" y="141" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="24" font-weight="900" fill="rgba(246,245,242,0.92)">QUIZ RESULTS</text>
  </g>
  <text x="80" y="258" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="62" font-weight="950" letter-spacing="-0.02em" fill="#F6F5F2">$quizTitle</text>

  <!-- Glass card -->
  <g filter="url(#shadow)">
    <rect x="70" y="520" width="940" height="980" rx="46" fill="rgba(246,245,242,0.10)" stroke="rgba(246,245,242,0.20)"/>
  </g>
  <text x="114" y="604" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="28" font-weight="850" fill="rgba(246,245,242,0.86)">Result</text>
  <text x="114" y="716" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="110" font-weight="950" letter-spacing="-0.03em" fill="#F6F5F2">$pctText</text>
  <text x="114" y="792" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="44" font-weight="900" fill="rgba(246,245,242,0.92)">$top</text>

  <g>
    <circle cx="540" cy="1032" r="178" fill="rgba(246,245,242,0.12)" stroke="rgba(246,245,242,0.26)" stroke-width="2"/>
    <circle cx="540" cy="1032" r="166" fill="rgba(30,42,68,0.22)"/>
    $avatarBlock
  </g>
  <g filter="url(#shadow)">
    <rect x="240" y="1388" width="600" height="84" rx="42" fill="rgba(246,245,242,0.92)"/>
    <text x="540" y="1442" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="32" font-weight="950" fill="#1E2A44">$name</text>
  </g>

  <image href="$logo" x="84" y="1766" width="210" height="60" preserveAspectRatio="xMinYMid meet" opacity="0.96"/>
</svg>
SVG;
    }

    private function matchOgSvg(int $w, int $h, string $quizTitle, string $aName, string $bName, string $aAvatar, string $bAvatar, string $aInitial, string $bInitial, string $pctText, string $logo): string
    {
        $bAvatarBlock = $bAvatar !== ''
            ? '<image href="' . $bAvatar . '" x="600" y="226" width="170" height="170" preserveAspectRatio="xMidYMid slice" clip-path="url(#clipB)"/>'
            : '<text x="685" y="328" text-anchor="middle" font-size="62" font-weight="900" fill="#F6F5F2">' . $bInitial . '</text>';
        $aAvatarBlock = $aAvatar !== ''
            ? '<image href="' . $aAvatar . '" x="430" y="226" width="170" height="170" preserveAspectRatio="xMidYMid slice" clip-path="url(#clipA)"/>'
            : '<text x="515" y="328" text-anchor="middle" font-size="62" font-weight="900" fill="#F6F5F2">' . $aInitial . '</text>';

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="$w" height="$h" viewBox="0 0 $w $h">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#1E2A44"/>
      <stop offset="0.45" stop-color="#6FAFB3"/>
      <stop offset="1" stop-color="#8FAEA3"/>
    </linearGradient>
    <radialGradient id="glow" cx="52%" cy="25%" r="75%">
      <stop offset="0" stop-color="#F6F5F2" stop-opacity="0.20"/>
      <stop offset="0.6" stop-color="#F6F5F2" stop-opacity="0.06"/>
      <stop offset="1" stop-color="#F6F5F2" stop-opacity="0"/>
    </radialGradient>
    <filter id="shadow" x="-50%" y="-50%" width="200%" height="200%">
      <feDropShadow dx="0" dy="14" stdDeviation="18" flood-color="#0B1220" flood-opacity="0.28"/>
    </filter>
    <clipPath id="clipA"><circle cx="515" cy="311" r="85"/></clipPath>
    <clipPath id="clipB"><circle cx="685" cy="311" r="85"/></clipPath>
  </defs>

  <rect width="$w" height="$h" fill="url(#bg)"/>
  <rect width="$w" height="$h" fill="url(#glow)"/>

  <text x="72" y="92" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="20" font-weight="900" letter-spacing="2.2" fill="rgba(246,245,242,0.92)">QUIZ RESULTS</text>
  <text x="72" y="140" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="46" font-weight="900" letter-spacing="-0.02em" fill="#F6F5F2">Comparison</text>
  <text x="72" y="206" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="28" font-weight="650" fill="rgba(246,245,242,0.92)">$quizTitle</text>

  <text x="72" y="286" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="30" font-weight="650" fill="rgba(246,245,242,0.92)">Match</text>
  <text x="72" y="352" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="78" font-weight="950" letter-spacing="-0.03em" fill="#F6F5F2">$pctText</text>

  <g filter="url(#shadow)">
    <circle cx="515" cy="311" r="98" fill="rgba(246,245,242,0.14)" stroke="rgba(246,245,242,0.30)" stroke-width="2"/>
    <circle cx="515" cy="311" r="85" fill="rgba(30,42,68,0.24)"/>
    $aAvatarBlock
    <circle cx="685" cy="311" r="98" fill="rgba(246,245,242,0.14)" stroke="rgba(246,245,242,0.30)" stroke-width="2"/>
    <circle cx="685" cy="311" r="85" fill="rgba(30,42,68,0.24)"/>
    $bAvatarBlock
    <rect x="570" y="292" width="60" height="38" rx="19" fill="rgba(246,245,242,0.16)" stroke="rgba(246,245,242,0.22)" stroke-width="1"/>
    <text x="600" y="319" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="18" font-weight="950" fill="#F6F5F2">+</text>

    <g>
      <rect x="430" y="430" width="340" height="66" rx="33" fill="rgba(246,245,242,0.92)"/>
      <text x="600" y="472" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="22" font-weight="950" fill="#1E2A44">$bName + $aName</text>
    </g>
  </g>

  <image href="$logo" x="72" y="552" width="180" height="52" preserveAspectRatio="xMinYMid meet" opacity="0.96"/>
</svg>
SVG;
    }

    private function matchStorySvg(int $w, int $h, string $quizTitle, string $aName, string $bName, string $aAvatar, string $bAvatar, string $aInitial, string $bInitial, string $pctText, string $logo): string
    {
        $bAvatarBlock = $bAvatar !== ''
            ? '<image href="' . $bAvatar . '" x="605" y="872" width="300" height="300" preserveAspectRatio="xMidYMid slice" clip-path="url(#clipB)"/>'
            : '<text x="755" y="1050" text-anchor="middle" font-size="110" font-weight="900" fill="#F6F5F2">' . $bInitial . '</text>';
        $aAvatarBlock = $aAvatar !== ''
            ? '<image href="' . $aAvatar . '" x="175" y="872" width="300" height="300" preserveAspectRatio="xMidYMid slice" clip-path="url(#clipA)"/>'
            : '<text x="325" y="1050" text-anchor="middle" font-size="110" font-weight="900" fill="#F6F5F2">' . $aInitial . '</text>';

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="$w" height="$h" viewBox="0 0 $w $h">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#1E2A44"/>
      <stop offset="0.45" stop-color="#6FAFB3"/>
      <stop offset="1" stop-color="#8FAEA3"/>
    </linearGradient>
    <filter id="blur70" x="-50%" y="-50%" width="200%" height="200%">
      <feGaussianBlur stdDeviation="70"/>
    </filter>
    <filter id="shadow" x="-50%" y="-50%" width="200%" height="200%">
      <feDropShadow dx="0" dy="18" stdDeviation="22" flood-color="#0B1220" flood-opacity="0.30"/>
    </filter>
    <clipPath id="clipA"><circle cx="325" cy="1022" r="150"/></clipPath>
    <clipPath id="clipB"><circle cx="755" cy="1022" r="150"/></clipPath>
  </defs>

  <rect width="$w" height="$h" fill="url(#bg)"/>
  <g filter="url(#blur70)" opacity="0.6">
    <circle cx="220" cy="260" r="280" fill="rgba(246,245,242,0.20)"/>
    <circle cx="880" cy="360" r="300" fill="rgba(111,175,179,0.26)"/>
    <circle cx="720" cy="1120" r="380" fill="rgba(143,174,163,0.22)"/>
  </g>

  <g>
    <rect x="80" y="104" width="250" height="56" rx="28" fill="rgba(246,245,242,0.16)" stroke="rgba(246,245,242,0.26)"/>
    <text x="105" y="141" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="24" font-weight="900" fill="rgba(246,245,242,0.92)">QUIZ RESULTS</text>
  </g>
  <text x="80" y="258" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="62" font-weight="950" letter-spacing="-0.02em" fill="#F6F5F2">Comparison</text>
  <text x="80" y="336" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="34" font-weight="750" fill="rgba(246,245,242,0.92)">$quizTitle</text>

  <g filter="url(#shadow)">
    <rect x="70" y="520" width="940" height="980" rx="46" fill="rgba(246,245,242,0.10)" stroke="rgba(246,245,242,0.20)"/>
  </g>
  <text x="114" y="604" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="28" font-weight="850" fill="rgba(246,245,242,0.86)">Match</text>
  <text x="114" y="716" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="110" font-weight="950" letter-spacing="-0.03em" fill="#F6F5F2">$pctText</text>

  <g>
    <circle cx="325" cy="1022" r="178" fill="rgba(246,245,242,0.12)" stroke="rgba(246,245,242,0.26)" stroke-width="2"/>
    <circle cx="325" cy="1022" r="166" fill="rgba(30,42,68,0.22)"/>
    $aAvatarBlock
    <circle cx="755" cy="1022" r="178" fill="rgba(246,245,242,0.12)" stroke="rgba(246,245,242,0.26)" stroke-width="2"/>
    <circle cx="755" cy="1022" r="166" fill="rgba(30,42,68,0.22)"/>
    $bAvatarBlock
    <g>
      <rect x="500" y="992" width="80" height="60" rx="30" fill="rgba(246,245,242,0.18)" stroke="rgba(246,245,242,0.26)"/>
      <text x="540" y="1032" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="26" font-weight="950" fill="#F6F5F2">+</text>
    </g>
  </g>

  <g filter="url(#shadow)">
    <rect x="180" y="1388" width="720" height="84" rx="42" fill="rgba(246,245,242,0.92)"/>
    <text x="540" y="1442" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial" font-size="30" font-weight="950" fill="#1E2A44">$bName + $aName</text>
  </g>

  <image href="$logo" x="84" y="1766" width="210" height="60" preserveAspectRatio="xMinYMid meet" opacity="0.96"/>
</svg>
SVG;
    }
}


