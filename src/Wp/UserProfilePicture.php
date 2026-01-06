<?php
declare(strict_types=1);

namespace MatchMe\Wp;

final class UserProfilePicture
{
    public function register(): void
    {
        add_filter('get_avatar_url', [$this, 'filterAvatarUrl'], 10, 3);
    }

    /**
     * @param string $url
     * @param int|string|\WP_User $idOrEmail
     * @param array<string,mixed> $args
     */
    public function filterAvatarUrl(string $url, $idOrEmail, array $args): string
    {
        $user = null;
        if ($idOrEmail instanceof \WP_User) {
            $user = $idOrEmail;
        } elseif (is_numeric($idOrEmail)) {
            $user = get_user_by('id', (int) $idOrEmail) ?: null;
        } elseif (is_string($idOrEmail)) {
            $email = sanitize_email($idOrEmail);
            if ($email !== '') {
                $user = get_user_by('email', $email) ?: null;
            }
        }

        if (!$user instanceof \WP_User) {
            return $url;
        }

        $pic = (string) get_user_meta((int) $user->ID, 'profile_picture', true);
        if ($pic === '' || !filter_var($pic, FILTER_VALIDATE_URL)) {
            return $url;
        }

        // Prefer user-provided picture; fallback remains WordPress/gravatar.
        return $pic;
    }
}



