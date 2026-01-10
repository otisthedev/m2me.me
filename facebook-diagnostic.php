<?php
/**
 * Facebook OAuth Diagnostic Tool
 * Upload this file to your theme root and access it via:
 * https://m2me.me/wp-content/themes/me2me.me/facebook-diagnostic.php
 *
 * Then delete it after getting the information.
 */

// Load WordPress
require_once '../../../wp-load.php';

// Get the redirect URI that would be used
$redirectUri = home_url('/?facebook_auth=1');

?>
<!DOCTYPE html>
<html>
<head>
    <title>Facebook OAuth Diagnostic</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            line-height: 1.6;
        }
        .info-box {
            background: #f0f0f0;
            border-left: 4px solid #0066cc;
            padding: 15px;
            margin: 20px 0;
        }
        .url-box {
            background: #1e1e1e;
            color: #00ff00;
            padding: 15px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            word-break: break-all;
            margin: 10px 0;
        }
        .step {
            background: #fff;
            border: 1px solid #ddd;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
        }
        .step h3 {
            margin-top: 0;
            color: #0066cc;
        }
        .error {
            background: #ffe6e6;
            border-left: 4px solid #cc0000;
            padding: 15px;
            margin: 20px 0;
        }
        .success {
            background: #e6ffe6;
            border-left: 4px solid #00cc00;
            padding: 15px;
            margin: 20px 0;
        }
        code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <h1>🔍 Facebook OAuth Diagnostic</h1>

    <div class="info-box">
        <strong>Current WordPress Configuration:</strong>
    </div>

    <table style="width: 100%; border-collapse: collapse;">
        <tr style="border-bottom: 1px solid #ddd;">
            <td style="padding: 10px; font-weight: bold;">Home URL:</td>
            <td style="padding: 10px;"><code><?php echo esc_html(home_url()); ?></code></td>
        </tr>
        <tr style="border-bottom: 1px solid #ddd;">
            <td style="padding: 10px; font-weight: bold;">Site URL:</td>
            <td style="padding: 10px;"><code><?php echo esc_html(site_url()); ?></code></td>
        </tr>
        <tr style="border-bottom: 1px solid #ddd;">
            <td style="padding: 10px; font-weight: bold;">Is HTTPS:</td>
            <td style="padding: 10px;"><code><?php echo is_ssl() ? 'Yes ✓' : 'No ✗'; ?></code></td>
        </tr>
        <tr style="border-bottom: 1px solid #ddd;">
            <td style="padding: 10px; font-weight: bold;">HTTP_HOST:</td>
            <td style="padding: 10px;"><code><?php echo esc_html($_SERVER['HTTP_HOST'] ?? 'not set'); ?></code></td>
        </tr>
    </table>

    <div class="success">
        <h3>✅ Your Redirect URI (Copy this EXACT URL):</h3>
        <div class="url-box"><?php echo esc_html($redirectUri); ?></div>
    </div>

    <div class="step">
        <h3>Step 1: Copy the Redirect URI Above</h3>
        <p>Copy the URL from the green box above. This is the EXACT URL your code will send to Facebook.</p>
    </div>

    <div class="step">
        <h3>Step 2: Add to Facebook App Settings</h3>
        <ol>
            <li>Go to <a href="https://developers.facebook.com/apps/" target="_blank">Facebook Developers Console</a></li>
            <li>Select your app</li>
            <li>Go to <strong>Products → Facebook Login → Settings</strong> (in left sidebar)</li>
            <li>Scroll to <strong>"Valid OAuth Redirect URIs"</strong></li>
            <li>Paste the URL from Step 1 (the EXACT URL from the green box)</li>
            <li>Click <strong>"Save Changes"</strong></li>
        </ol>
    </div>

    <div class="step">
        <h3>Step 3: Enable OAuth Settings</h3>
        <p>In the same Facebook Login Settings page, make sure these are turned ON:</p>
        <ul>
            <li>☑ Client OAuth Login</li>
            <li>☑ Web OAuth Login</li>
        </ul>
    </div>

    <div class="step">
        <h3>Step 4: Check App Mode & Permissions</h3>
        <p><strong>Option A - Switch to Live Mode:</strong></p>
        <ol>
            <li>Go to <strong>App Settings → Basic</strong></li>
            <li>Toggle the app from "Development" to "Live" (top right)</li>
            <li>Note: Requires Privacy Policy URL and App Icon</li>
        </ol>

        <p><strong>Option B - Add Yourself as Admin (for testing):</strong></p>
        <ol>
            <li>Go to <strong>App roles → Roles</strong> in left sidebar</li>
            <li>Click <strong>"Add Administrators"</strong></li>
            <li>Enter your Facebook email/username</li>
            <li>Accept the invitation sent to your Facebook account</li>
        </ol>
    </div>

    <div class="step">
        <h3>Step 5: Wait & Clear Cache</h3>
        <ul>
            <li>Wait 5-10 minutes after making changes (Facebook caches settings)</li>
            <li>Clear your browser cache</li>
            <li>Try Facebook login again</li>
        </ul>
    </div>

    <div class="error">
        <h3>⚠️ Common Issues:</h3>
        <ul>
            <li><strong>http vs https mismatch:</strong> Your redirect URI shows "<code><?php echo parse_url($redirectUri, PHP_URL_SCHEME); ?></code>" - make sure Facebook has the same protocol</li>
            <li><strong>www vs non-www:</strong> Your URL is "<code><?php echo parse_url($redirectUri, PHP_URL_HOST); ?></code>" - if your site also works with www, add both versions</li>
            <li><strong>App in Development:</strong> Add yourself as Admin or switch to Live</li>
        </ul>
    </div>

    <hr style="margin: 30px 0;">

    <div class="info-box">
        <p><strong>After fixing:</strong> Delete this file for security reasons.</p>
        <p>File location: <code><?php echo __FILE__; ?></code></p>
    </div>

    <?php
    // Show additional URLs to add if www is detected
    $host = parse_url($redirectUri, PHP_URL_HOST);
    $scheme = parse_url($redirectUri, PHP_URL_SCHEME);

    if (strpos($host, 'www.') === false) {
        $wwwUri = $scheme . '://www.' . $host . '/?facebook_auth=1';
        echo '<div class="info-box">';
        echo '<h3>💡 Optional: If your site works with www subdomain</h3>';
        echo '<p>Also add this URL to Facebook:</p>';
        echo '<div class="url-box">' . esc_html($wwwUri) . '</div>';
        echo '</div>';
    }
    ?>
</body>
</html>
