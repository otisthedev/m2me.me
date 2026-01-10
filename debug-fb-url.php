<?php
/**
 * Temporary debug script to check Facebook redirect URI
 * Upload this to your theme root and access it via browser
 * Then delete it after checking
 */

// Load WordPress
require_once '../../../wp-load.php';

echo '<h1>Facebook OAuth Debug Info</h1>';
echo '<pre>';
echo '<strong>Home URL:</strong> ' . home_url() . "\n";
echo '<strong>Site URL:</strong> ' . site_url() . "\n";
echo '<strong>Redirect URI:</strong> ' . home_url('/?facebook_auth=1') . "\n";
echo '<strong>Is HTTPS:</strong> ' . (is_ssl() ? 'Yes' : 'No') . "\n";
echo '<strong>$_SERVER[HTTPS]:</strong> ' . ($_SERVER['HTTPS'] ?? 'not set') . "\n";
echo '<strong>$_SERVER[HTTP_HOST]:</strong> ' . ($_SERVER['HTTP_HOST'] ?? 'not set') . "\n";
echo '<strong>$_SERVER[SERVER_NAME]:</strong> ' . ($_SERVER['SERVER_NAME'] ?? 'not set') . "\n";
echo '</pre>';

echo '<h2>What to add to Facebook App Settings:</h2>';
echo '<p>Go to: <a href="https://developers.facebook.com/apps/" target="_blank">Facebook Developers Console</a></p>';
echo '<p>Navigate to: <strong>Your App → Facebook Login → Settings</strong></p>';
echo '<p>Add this EXACT URL to "Valid OAuth Redirect URIs":</p>';
echo '<pre style="background: #f0f0f0; padding: 10px; font-size: 14px; border: 2px solid #333;">';
echo home_url('/?facebook_auth=1');
echo '</pre>';
