<?php
declare(strict_types=1);

namespace MatchMe\Wp;

use MatchMe\Config\ThemeConfig;

final class QuizAdmin
{
    public function __construct(private ThemeConfig $config)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_post_match_me_save_quiz_settings', [$this, 'saveSettings']);
    }

    public function registerMenu(): void
    {
        add_menu_page(
            'Quiz Manager',
            'Quizzes',
            'manage_options',
            'quiz-manager',
            [$this, 'renderPage'],
            'dashicons-media-text'
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        $baseDir = $this->config->quizDirectory();
        wp_mkdir_p($baseDir);

        $this->handleActions($baseDir);
        $this->renderNotices();

        echo '<div class="wrap"><h1>Quiz Manager</h1>';

        echo '<div class="card"><h2>Quiz Settings</h2>';
        $this->renderSettings();
        echo '</div>';

        echo '<div class="card"><h2>Upload New Quiz</h2>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('jqm_upload', 'jqm_nonce');
        echo '<input type="file" name="jqm_file[]" accept=".json" multiple required>';
        echo '<p class="description">You can select multiple JSON files to upload at once. Posts will be automatically created for each quiz if they don\'t already exist.</p>';
        submit_button('Upload JSON Files');
        echo '</form></div>';

        echo '<div class="card"><h2>Existing Quizzes</h2>';
        $files = array_diff(scandir($baseDir) ?: [], ['.', '..']);
        $jsonFiles = array_values(array_filter($files, static fn ($f) => pathinfo((string) $f, PATHINFO_EXTENSION) === 'json'));

        if ($jsonFiles === []) {
            echo '<p>No quiz files found.</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>File</th><th>Actions</th></tr></thead>';
            foreach ($jsonFiles as $file) {
                $file = (string) $file;
                $editNonce = wp_create_nonce('jqm_edit_' . $file);
                $deleteNonce = wp_create_nonce('jqm_delete_' . $file);

                echo '<tr><td>' . esc_html($file) . '</td><td>';
                echo '<a href="?page=quiz-manager&action=edit&file=' . urlencode($file) . '&_wpnonce=' . $editNonce . '" class="button">Edit</a> ';
                echo '<a href="?page=quiz-manager&action=delete&file=' . urlencode($file) . '&_wpnonce=' . $deleteNonce . '" class="button button-link-delete" onclick="return confirm(\'Are you sure?\')">Delete</a>';
                echo '</td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';

        if (isset($_GET['action']) && (string) $_GET['action'] === 'edit') {
            $this->renderEditForm($baseDir);
        }

        echo '</div>';
    }

    private function renderSettings(): void
    {
        $value = get_option('match_me_require_login_for_results', '1');
        // Handle both string ('1'/'0') and boolean values for backward compatibility
        if (is_bool($value)) {
            $requireLogin = $value;
        } else {
            $requireLogin = (string) $value === '1';
        }
        $checked = $requireLogin ? 'checked' : '';
        
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="match_me_save_quiz_settings">';
        wp_nonce_field('match_me_save_quiz_settings');
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="require_login">Require Login for Results</label></th>';
        echo '<td>';
        echo '<label><input id="require_login" type="checkbox" name="require_login" value="1" ' . $checked . '> Require users to log in to view and share quiz results</label>';
        echo '<p class="description">When enabled, users must log in to see their results. When disabled, results are publicly viewable and shareable.</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
        submit_button('Save Settings');
        echo '</form>';
    }

    public function saveSettings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('match_me_save_quiz_settings');

        // Checkbox only posts when checked.
        $requireLogin = isset($_POST['require_login']) && (string) $_POST['require_login'] === '1';
        // Store as string '1' or '0' to ensure the option exists even when false
        update_option('match_me_require_login_for_results', $requireLogin ? '1' : '0');

        $this->addNotice('Settings saved successfully!');
        wp_safe_redirect(admin_url('admin.php?page=quiz-manager'));
        exit;
    }

    private function handleActions(string $baseDir): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!empty($_FILES['jqm_file']) && is_array($_FILES['jqm_file'])) {
            if (isset($_POST['jqm_nonce']) && wp_verify_nonce((string) $_POST['jqm_nonce'], 'jqm_upload')) {
                $files = $_FILES['jqm_file'];
                $uploadedCount = 0;
                $postCreatedCount = 0;
                $errors = [];
                
                // Handle multiple files
                $fileCount = is_array($files['name']) ? count($files['name']) : 1;
                
                for ($i = 0; $i < $fileCount; $i++) {
                    $name = is_array($files['name']) ? ($files['name'][$i] ?? '') : (string) ($files['name'] ?? '');
                    $tmp = is_array($files['tmp_name']) ? ($files['tmp_name'][$i] ?? '') : (string) ($files['tmp_name'] ?? '');
                    $error = is_array($files['error']) ? ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) : (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE);
                    
                    if ($error !== UPLOAD_ERR_OK) {
                        if ($error !== UPLOAD_ERR_NO_FILE) {
                            $errors[] = "Error uploading {$name}: " . $this->getUploadErrorMessage($error);
                        }
                        continue;
                    }
                    
                    if (pathinfo($name, PATHINFO_EXTENSION) !== 'json') {
                        $errors[] = "{$name} is not a JSON file. Skipped.";
                        continue;
                    }
                    
                    if (!is_uploaded_file($tmp)) {
                        $errors[] = "{$name} is not a valid uploaded file. Skipped.";
                        continue;
                    }
                    
                    $sanitizedName = sanitize_file_name($name);
                    $target = $baseDir . $sanitizedName;
                    
                    if (move_uploaded_file($tmp, $target)) {
                        $uploadedCount++;
                        
                        // Try to create post for this quiz
                        $quizSlug = pathinfo($sanitizedName, PATHINFO_FILENAME);
                        if ($this->createPostForQuiz($target, $quizSlug)) {
                            $postCreatedCount++;
                        }
                    } else {
                        $errors[] = "Failed to move {$name} to target directory.";
                    }
                }
                
                // Build success message
                $messages = [];
                if ($uploadedCount > 0) {
                    $messages[] = sprintf(
                        '%d file(s) uploaded successfully',
                        $uploadedCount
                    );
                }
                if ($postCreatedCount > 0) {
                    $messages[] = sprintf(
                        '%d post(s) created',
                        $postCreatedCount
                    );
                }
                if ($uploadedCount > 0) {
                    $this->addNotice(implode('. ', $messages) . '.', 'success');
                }
                
                // Show errors if any
                if (!empty($errors)) {
                    foreach ($errors as $error) {
                        $this->addNotice($error, 'error');
                    }
                }
            }
        }

        if (isset($_GET['action'], $_GET['file']) && (string) $_GET['action'] === 'delete') {
            $file = sanitize_file_name((string) $_GET['file']);
            $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';
            if (wp_verify_nonce($nonce, 'jqm_delete_' . $file)) {
                $path = $baseDir . $file;
                if (is_file($path) && unlink($path)) {
                    $this->addNotice('File deleted successfully!');
                }
            }
        }

        if (isset($_POST['jqm_content'], $_POST['jqm_file'], $_POST['jqm_edit_nonce'])) {
            $file = sanitize_file_name((string) $_POST['jqm_file']);
            $nonce = (string) $_POST['jqm_edit_nonce'];
            if (wp_verify_nonce($nonce, 'jqm_edit_' . $file)) {
                $path = $baseDir . $file;
                file_put_contents($path, wp_unslash((string) $_POST['jqm_content']));
                $this->addNotice('File saved successfully!');
            }
        }
    }

    private function renderEditForm(string $baseDir): void
    {
        if (!isset($_GET['file'])) {
            return;
        }

        $file = sanitize_file_name((string) $_GET['file']);
        $path = $baseDir . $file;
        if (!is_file($path)) {
            return;
        }

        $content = (string) file_get_contents($path);

        echo '<div class="card mm-admin-card">';
        echo '<h2>Edit: ' . esc_html($file) . '</h2>';
        echo '<form method="post">';
        wp_nonce_field('jqm_edit_' . $file, 'jqm_edit_nonce');
        echo '<input type="hidden" name="jqm_file" value="' . esc_attr($file) . '">';
        echo '<textarea class="widefat code" rows="25" name="jqm_content">' . esc_textarea($content) . '</textarea>';
        submit_button('Save Changes');
        echo '</form></div>';
    }

    private function addNotice(string $message, string $type = 'success'): void
    {
        $notices = get_option('jqm_notices', []);
        if (!is_array($notices)) {
            $notices = [];
        }
        $notices[] = ['message' => $message, 'type' => $type];
        update_option('jqm_notices', $notices);
    }

    private function renderNotices(): void
    {
        $notices = get_option('jqm_notices');
        if (!is_array($notices) || $notices === []) {
            return;
        }

        foreach ($notices as $notice) {
            if (!is_array($notice) || !isset($notice['type'], $notice['message'])) {
                continue;
            }
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr((string) $notice['type']),
                esc_html((string) $notice['message'])
            );
        }
        delete_option('jqm_notices');
    }

    /**
     * Create a WordPress post for a quiz if it doesn't already exist
     * 
     * @param string $quizFilePath Full path to the quiz JSON file
     * @param string $quizSlug The quiz slug (filename without .json)
     * @return bool True if post was created or already exists, false on error
     */
    private function createPostForQuiz(string $quizFilePath, string $quizSlug): bool
    {
        // Read quiz JSON to extract metadata
        $quizContent = file_get_contents($quizFilePath);
        if ($quizContent === false) {
            return false;
        }
        
        $quizData = json_decode($quizContent, true);
        if (!is_array($quizData) || !isset($quizData['meta'])) {
            return false;
        }
        
        $meta = $quizData['meta'];
        $title = isset($meta['title']) ? (string) $meta['title'] : ucwords(str_replace(['-', '_'], ' ', $quizSlug));
        $description = isset($meta['description']) ? (string) $meta['description'] : '';
        
        // Generate slug from title (not from JSON filename)
        // Remove version suffixes like -v1, -v2, etc.
        $postSlug = sanitize_title($title);
        
        // Check if post already exists with this slug
        $existingPost = get_page_by_path($postSlug, OBJECT, 'post');
        if ($existingPost instanceof \WP_Post) {
            // Update the quiz slug reference if it changed
            update_post_meta((int) $existingPost->ID, '_quiz_json_slug', $quizSlug);
            return true; // Post already exists
        }
        
        // Create post content with just the shortcode (description is shown in quiz intro)
        $postContent = '[match_me_quiz id="' . esc_attr($quizSlug) . '"]';
        
        // Create the post
        $postId = wp_insert_post([
            'post_title' => $title,
            'post_name' => $postSlug,
            'post_content' => $postContent,
            'post_excerpt' => $description, // Store description as excerpt for quiz intro
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => get_current_user_id() ?: 1,
        ], true);
        
        if (is_wp_error($postId)) {
            return false;
        }
        
        // Store quiz metadata as post meta for reference
        if (isset($meta['aspect'])) {
            update_post_meta((int) $postId, '_quiz_aspect', (string) $meta['aspect']);
        }
        if (isset($meta['version'])) {
            update_post_meta((int) $postId, '_quiz_version', (string) $meta['version']);
        }
        // Store the JSON filename slug for loading the quiz
        update_post_meta((int) $postId, '_quiz_json_slug', $quizSlug);
        // Keep old meta for backward compatibility
        update_post_meta((int) $postId, '_quiz_slug', $quizSlug);
        
        return true;
    }

    /**
     * Get human-readable upload error message
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return 'File exceeds upload_max_filesize directive in php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'File exceeds MAX_FILE_SIZE directive in HTML form';
            case UPLOAD_ERR_PARTIAL:
                return 'File was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'File upload stopped by extension';
            default:
                return 'Unknown upload error';
        }
    }
}


