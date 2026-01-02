# Social Auth Setup (Google / Facebook / Instagram)

This theme reads credentials from environment variables (or `define()` in `wp-config.php`):

- `MATCH_ME_GOOGLE_CLIENT_ID`
- `MATCH_ME_GOOGLE_CLIENT_SECRET`
- `MATCH_ME_FACEBOOK_APP_ID`
- `MATCH_ME_FACEBOOK_APP_SECRET`
- `MATCH_ME_INSTAGRAM_APP_ID`
- `MATCH_ME_INSTAGRAM_APP_SECRET`

Your redirect URIs (for this theme) are:

- **Google**: `https://YOUR_DOMAIN/?google_auth=1`
- **Facebook**: `https://YOUR_DOMAIN/?facebook_auth=1`
- **Instagram (Basic Display)**: `https://YOUR_DOMAIN/?instagram_auth=1`

## 1) Google (Client ID + Client Secret)

1. Go to Google Cloud Console → create/select a project.
2. Configure OAuth Consent Screen.
3. Create OAuth Client ID (type: Web application).
4. Add **Authorized redirect URI**: `https://YOUR_DOMAIN/?google_auth=1`
5. Copy **Client ID** and **Client Secret**.
6. Put them into your server env or `wp-config.php`.

Security notes: use exact redirect URIs and keep secrets server-side. Google’s OAuth best-practices are here: `https://developers.google.com/identity/protocols/oauth2/resources/best-practices` ([Google OAuth best practices](https://developers.google.com/identity/protocols/oauth2/resources/best-practices?utm_source=openai)).

## 2) Facebook (App ID + App Secret)

1. Go to Meta for Developers → create an App.
2. Add product: **Facebook Login**.
3. Set **Valid OAuth Redirect URIs** to: `https://YOUR_DOMAIN/?facebook_auth=1`
4. Copy **App ID** and **App Secret**.
5. Put them into your server env or `wp-config.php`.

## 3) Instagram (App ID + App Secret)

Important: **Instagram does not provide email** via Basic Display. We can store username/id and create a placeholder email (`@instagram.invalid`). If you need email, you’ll need a different product/flow.

1. Go to Meta for Developers → create an App (or reuse your Meta app).
2. Add product: **Instagram Basic Display**.
3. Configure OAuth with Redirect URI: `https://YOUR_DOMAIN/?instagram_auth=1`
4. Copy **Instagram App ID** and **Instagram App Secret**.
5. Put them into your server env or `wp-config.php`.

## Where to set secrets

### Option A: environment variables (recommended)
Set env vars on your hosting (preferred).

### Option B: `wp-config.php`
Add:

```php
define('MATCH_ME_GOOGLE_CLIENT_ID', '...');
define('MATCH_ME_GOOGLE_CLIENT_SECRET', '...');
define('MATCH_ME_FACEBOOK_APP_ID', '...');
define('MATCH_ME_FACEBOOK_APP_SECRET', '...');
define('MATCH_ME_INSTAGRAM_APP_ID', '...');
define('MATCH_ME_INSTAGRAM_APP_SECRET', '...');
```

## Data we store per provider

- **Google**: email, full name, first/last name (best-effort), profile picture URL
- **Facebook**: email, first/last name, display name, profile picture URL
- **Instagram**: username + id (no email); placeholder email; no profile picture via this flow


