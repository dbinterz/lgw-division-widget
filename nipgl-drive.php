<?php
/**
 * NIPGL Google Drive Integration
 * Uploads confirmed scorecards (PDF + original photo) to Google Drive.
 * Uses Service Account JSON key loaded from a file path configured in settings.
 * No Composer / no external libraries required — uses WordPress HTTP API.
 *
 * Folder structure:
 *   Root Folder / Year / Division / Club / filename.pdf
 * Files are saved into BOTH home and away club folders.
 *
 * Versioning: if a file already exists, saves as -v2, -v3 etc.
 */

// ── Settings helpers ──────────────────────────────────────────────────────────

function nipgl_drive_get_settings() {
    return get_option('nipgl_drive', array(
        'enabled'        => false,
        'key_path'       => '',
        'root_folder_id' => '',
    ));
}

function nipgl_drive_enabled() {
    $s = nipgl_drive_get_settings();
    return !empty($s['enabled']) && !empty($s['key_path']) && !empty($s['root_folder_id']);
}

// ── Main entry point ──────────────────────────────────────────────────────────

/**
 * Save a confirmed scorecard to Google Drive.
 *
 * @param int  $post_id   Scorecard post ID
 * @param bool $is_edit   True if this is an admin edit (triggers versioning)
 */
function nipgl_drive_save_scorecard($post_id, $is_edit = false) {
    if (!nipgl_drive_enabled()) return;

    require_once plugin_dir_path(__FILE__) . 'nipgl-pdf.php';

    $sc = get_post_meta($post_id, 'nipgl_scorecard_data', true);
    if (!$sc) return;

    $settings = nipgl_drive_get_settings();
    $token    = nipgl_drive_get_access_token($settings['key_path']);
    if (!$token) {
        nipgl_drive_log($post_id, 'error', 'Could not obtain access token — check service account key path');
        return;
    }

    // Folder path components
    $date      = $sc['date'] ?? '';
    $year      = nipgl_drive_year_from_date($date);
    $division  = nipgl_safe_filename($sc['division'] ?? 'Unknown Division');
    $home_club = nipgl_safe_filename(nipgl_team_to_club($sc['home_team'] ?? '') ?: ($sc['home_team'] ?? 'Unknown'));
    $away_club = nipgl_safe_filename(nipgl_team_to_club($sc['away_team'] ?? '') ?: ($sc['away_team'] ?? 'Unknown'));
    $root_id   = $settings['root_folder_id'];

    // Generate PDF
    $pdf_bytes = nipgl_build_scorecard_pdf($sc, $post_id);

    // Base filename — YYYYMMDD HomeTeam v AwayTeam
    $safe_home = nipgl_safe_filename($sc['home_team'] ?? 'Home');
    $safe_away = nipgl_safe_filename($sc['away_team'] ?? 'Away');
    // Convert dd/mm/yyyy to YYYYMMDD
    $yyyymmdd  = '';
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $dm)) {
        $yyyymmdd = $dm[3] . $dm[2] . $dm[1];
    } else {
        $yyyymmdd = date('Ymd'); // fallback to today
    }
    $base_name = $yyyymmdd . ' ' . $safe_home . ' v ' . $safe_away;

    // Photo (only on first confirmation, not admin edits)
    $photo_path  = !$is_edit ? get_post_meta($post_id, 'nipgl_photo_path', true) : '';
    $photo_bytes = ($photo_path && file_exists($photo_path)) ? file_get_contents($photo_path) : false;
    $photo_ext   = $photo_bytes ? pathinfo($photo_path, PATHINFO_EXTENSION) : '';
    $photo_mime  = $photo_bytes ? nipgl_drive_mime_from_ext($photo_ext) : '';
    $photo_name  = $photo_bytes ? ($base_name . '-original.' . $photo_ext) : '';

    // Upload to home club folder and away club folder
    foreach (array($home_club, $away_club) as $club) {
        $folder_id = nipgl_drive_ensure_path($token, $root_id, array($year, $division, $club));
        if (!$folder_id) {
            nipgl_drive_log($post_id, 'error', 'Could not create folder path for ' . $club);
            continue;
        }

        // PDF — versioned filename on admin edits
        $pdf_name = nipgl_drive_versioned_name($token, $folder_id, $base_name . '.pdf', $is_edit);
        $pdf_id   = nipgl_drive_upload_file($token, $folder_id, $pdf_name, $pdf_bytes, 'application/pdf');
        if ($pdf_id) {
            nipgl_drive_log($post_id, 'success', 'PDF uploaded to ' . $club . ': ' . $pdf_name);
        } else {
            nipgl_drive_log($post_id, 'error', 'PDF upload failed for ' . $club);
        }

        // Photo
        if ($photo_bytes) {
            $pid = nipgl_drive_upload_file($token, $folder_id, $photo_name, $photo_bytes, $photo_mime);
            if ($pid) {
                nipgl_drive_log($post_id, 'success', 'Photo uploaded to ' . $club . ': ' . $photo_name);
            }
        }
    }
}

// ── Folder path helper ────────────────────────────────────────────────────────

/**
 * Ensure a folder path exists, creating missing subfolders.
 * Returns the ID of the deepest folder.
 */
function nipgl_drive_ensure_path($token, $root_id, $path) {
    $parent = $root_id;
    foreach ($path as $name) {
        $existing = nipgl_drive_find_folder($token, $parent, $name);
        if ($existing) {
            $parent = $existing;
        } else {
            $new_id = nipgl_drive_create_folder($token, $parent, $name);
            if (!$new_id) return false;
            $parent = $new_id;
        }
    }
    return $parent;
}

// ── Google Drive API calls ────────────────────────────────────────────────────

function nipgl_drive_find_folder($token, $parent_id, $name) {
    $q   = "name='" . addslashes($name) . "'"
         . " and mimeType='application/vnd.google-apps.folder'"
         . " and '" . $parent_id . "' in parents"
         . " and trashed=false";
    $url = 'https://www.googleapis.com/drive/v3/files'
         . '?q=' . urlencode($q) . '&fields=files(id,name)';
    $res = nipgl_drive_request($token, 'GET', $url);
    if (!$res || empty($res['files'])) return false;
    return $res['files'][0]['id'];
}

function nipgl_drive_create_folder($token, $parent_id, $name) {
    $body = json_encode(array(
        'name'     => $name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents'  => array($parent_id),
    ));
    $res = nipgl_drive_request($token, 'POST',
        'https://www.googleapis.com/drive/v3/files?fields=id',
        array('Content-Type' => 'application/json'),
        $body
    );
    return $res['id'] ?? false;
}

function nipgl_drive_upload_file($token, $parent_id, $filename, $content, $mime) {
    $metadata = json_encode(array(
        'name'    => $filename,
        'parents' => array($parent_id),
    ));
    $boundary = 'nipgl_' . md5(uniqid('', true));
    $body     = "--$boundary\r\n"
              . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
              . $metadata . "\r\n"
              . "--$boundary\r\n"
              . "Content-Type: $mime\r\n\r\n"
              . $content . "\r\n"
              . "--$boundary--";
    $res = nipgl_drive_request($token, 'POST',
        'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id',
        array('Content-Type' => 'multipart/related; boundary=' . $boundary),
        $body
    );
    return $res['id'] ?? false;
}

/**
 * Return a versioned filename that does not clash with existing files.
 * If $force_version is true, always bumps to next version number.
 */
function nipgl_drive_versioned_name($token, $folder_id, $base_name, $force_version = false) {
    if (!$force_version) {
        $q   = "name='" . addslashes($base_name) . "'"
             . " and '" . $folder_id . "' in parents and trashed=false";
        $url = 'https://www.googleapis.com/drive/v3/files'
             . '?q=' . urlencode($q) . '&fields=files(id)';
        $res = nipgl_drive_request($token, 'GET', $url);
        if (empty($res['files'])) return $base_name; // no conflict
    }

    $ext       = pathinfo($base_name, PATHINFO_EXTENSION);
    $stem      = pathinfo($base_name, PATHINFO_FILENAME);
    $stem_base = preg_replace('/-v\d+$/', '', $stem);

    $q   = "name contains '" . addslashes($stem_base) . "'"
         . " and '" . $folder_id . "' in parents and trashed=false";
    $url = 'https://www.googleapis.com/drive/v3/files'
         . '?q=' . urlencode($q) . '&fields=files(name)';
    $res = nipgl_drive_request($token, 'GET', $url);

    $max_v = 1;
    foreach (($res['files'] ?? array()) as $f) {
        if (preg_match('/-v(\d+)\.' . preg_quote($ext, '/') . '$/', $f['name'], $m)) {
            $max_v = max($max_v, intval($m[1]));
        }
    }
    return $stem_base . '-v' . ($max_v + 1) . '.' . $ext;
}

// ── HTTP wrapper ──────────────────────────────────────────────────────────────

function nipgl_drive_request($token, $method, $url, $extra_headers = array(), $body = null) {
    $args = array(
        'method'  => $method,
        'timeout' => 30,
        'headers' => array_merge(
            array('Authorization' => 'Bearer ' . $token),
            $extra_headers
        ),
    );
    if ($body !== null) $args['body'] = $body;

    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) {
        error_log('NIPGL Drive WP error: ' . $response->get_error_message());
        return false;
    }
    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if ($code >= 400) {
        error_log('NIPGL Drive API ' . $code . ': ' . wp_remote_retrieve_body($response));
        return false;
    }
    return $data;
}

// ── OAuth2 / JWT (pure PHP, no Composer) ─────────────────────────────────────

function nipgl_drive_get_access_token($key_path) {
    $cached = get_transient('nipgl_drive_token');
    if ($cached) return $cached;

    if (!file_exists($key_path)) {
        error_log('NIPGL Drive: key file not found at ' . $key_path);
        return false;
    }
    $key_data = json_decode(file_get_contents($key_path), true);
    if (empty($key_data['private_key']) || empty($key_data['client_email'])) {
        error_log('NIPGL Drive: invalid service account JSON');
        return false;
    }

    $jwt = nipgl_drive_build_jwt($key_data['client_email'], $key_data['private_key']);
    if (!$jwt) return false;

    $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
        'timeout' => 15,
        'body'    => array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ),
    ));
    if (is_wp_error($response)) return false;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data['access_token'])) {
        error_log('NIPGL Drive: token exchange failed — ' . wp_remote_retrieve_body($response));
        return false;
    }

    set_transient('nipgl_drive_token', $data['access_token'], 55 * MINUTE_IN_SECONDS);
    return $data['access_token'];
}

function nipgl_drive_build_jwt($client_email, $private_key) {
    if (!function_exists('openssl_sign')) {
        error_log('NIPGL Drive: openssl_sign not available');
        return false;
    }
    $now     = time();
    $header  = nipgl_drive_b64url(json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
    $payload = nipgl_drive_b64url(json_encode(array(
        'iss'   => $client_email,
        'scope' => 'https://www.googleapis.com/auth/drive',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    )));
    $input = $header . '.' . $payload;
    $sig   = '';
    if (!openssl_sign($input, $sig, $private_key, 'SHA256')) {
        error_log('NIPGL Drive: JWT signing failed — ' . openssl_error_string());
        return false;
    }
    return $input . '.' . nipgl_drive_b64url($sig);
}

function nipgl_drive_b64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// ── Utility ───────────────────────────────────────────────────────────────────

function nipgl_drive_year_from_date($date_str) {
    // dd/mm/yyyy
    if (preg_match('/(\d{4})$/', $date_str, $m)) return $m[1];
    return date('Y');
}

function nipgl_drive_mime_from_ext($ext) {
    return array(
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'heic' => 'image/heic',
    )[strtolower($ext)] ?? 'application/octet-stream';
}

function nipgl_drive_log($post_id, $level, $message) {
    $log   = get_post_meta($post_id, 'nipgl_drive_log', true) ?: array();
    $log[] = array('ts' => current_time('mysql'), 'level' => $level, 'message' => $message);
    if (count($log) > 20) $log = array_slice($log, -20);
    update_post_meta($post_id, 'nipgl_drive_log', $log);
    if ($level === 'error') error_log('NIPGL Drive [' . $post_id . ']: ' . $message);
}

// ── Drive log panel for admin scorecard page ──────────────────────────────────

function nipgl_render_drive_log($post_id) {
    $log = get_post_meta($post_id, 'nipgl_drive_log', true) ?: array();
    if (!nipgl_drive_enabled()) {
        echo '<p style="color:#888;font-size:12px">Google Drive integration is not enabled.</p>';
        return;
    }
    if (empty($log)) {
        echo '<p style="color:#888;font-size:12px">No Drive activity yet for this scorecard.</p>';
        return;
    }
    echo '<div style="font-size:12px">';
    foreach (array_reverse($log) as $entry) {
        $icon  = $entry['level'] === 'error' ? '❌' : '✅';
        $color = $entry['level'] === 'error' ? '#842029' : '#0a3622';
        $ts    = date('d M Y H:i', strtotime($entry['ts']));
        echo '<div style="padding:4px 0;border-bottom:1px solid #eee;color:' . $color . '">';
        echo $icon . ' <span style="color:#888">' . esc_html($ts) . '</span> ' . esc_html($entry['message']);
        echo '</div>';
    }
    echo '</div>';
}

// ── Hooks into confirmation / admin edit ──────────────────────────────────────

// Fires when scorecard is confirmed via second-club submission
add_action('nipgl_scorecard_confirmed', function($post_id) {
    nipgl_drive_save_scorecard($post_id, false);
});

// Fires when admin saves an edit
add_action('nipgl_scorecard_admin_edited', function($post_id) {
    nipgl_drive_save_scorecard($post_id, true);
});

// ── Settings section ──────────────────────────────────────────────────────────

// ── Key storage location ──────────────────────────────────────────────────────

/**
 * Returns the directory where uploaded key files are stored.
 * Inside wp-content/uploads so it's writable, but protected by .htaccess.
 */
function nipgl_drive_key_dir() {
    $upload = wp_upload_dir();
    return trailingslashit($upload['basedir']) . 'nipgl-private/';
}

/**
 * Ensure the key directory exists and is protected from web access.
 */
function nipgl_drive_ensure_key_dir() {
    $dir = nipgl_drive_key_dir();
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    // Write .htaccess to block direct web access
    $htaccess = $dir . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Deny from all\n");
    }
    // Write index.php to prevent directory listing
    $index = $dir . 'index.php';
    if (!file_exists($index)) {
        file_put_contents($index, "<?php // silence\n");
    }
    return $dir;
}

// ── AJAX: upload key file ─────────────────────────────────────────────────────

add_action('wp_ajax_nipgl_drive_upload_key', 'nipgl_ajax_drive_upload_key');
function nipgl_ajax_drive_upload_key() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    check_ajax_referer('nipgl_drive_upload_key', 'nonce');

    if (empty($_FILES['keyfile']['tmp_name'])) {
        wp_send_json_error('No file received');
    }

    $file = $_FILES['keyfile'];

    // Validate it's actually JSON with the right structure
    $raw = file_get_contents($file['tmp_name']);
    $key = json_decode($raw, true);
    if (!$key || empty($key['private_key']) || empty($key['client_email'])) {
        wp_send_json_error('This does not look like a valid Service Account JSON key — make sure you downloaded the right file from Google Cloud Console');
    }
    if (($key['type'] ?? '') !== 'service_account') {
        wp_send_json_error('File is valid JSON but is not a service_account key (type: "' . ($key['type'] ?? 'unknown') . '")');
    }

    $dir      = nipgl_drive_ensure_key_dir();
    $filename = 'nipgl-service-account.json';
    $dest     = $dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        wp_send_json_error('Could not save file — check directory permissions on ' . $dir);
    }

    // Restrict file permissions as tightly as PHP allows
    @chmod($dest, 0600);

    // Save the path into Drive settings
    $s            = nipgl_drive_get_settings();
    $s['key_path'] = $dest;
    update_option('nipgl_drive', $s);
    delete_transient('nipgl_drive_token');

    wp_send_json_success(array(
        'message'  => 'Key uploaded successfully',
        'email'    => $key['client_email'],
        'key_path' => $dest,
    ));
}

// ── Settings section ──────────────────────────────────────────────────────────

function nipgl_drive_settings_section() {
    $s          = nipgl_drive_get_settings();
    $nonce_test = wp_create_nonce('nipgl_drive_test');
    $nonce_key  = wp_create_nonce('nipgl_drive_upload_key');
    $has_key    = !empty($s['key_path']) && file_exists($s['key_path']);

    // Read client_email from stored key for display
    $key_email = '';
    if ($has_key) {
        $kd = json_decode(file_get_contents($s['key_path']), true);
        $key_email = $kd['client_email'] ?? '';
    }
    ?>
    <hr>
    <h2>Google Drive Integration</h2>
    <p>Automatically saves a PDF of each confirmed scorecard — plus the original photo if one was uploaded — to a Google Drive archive folder. Files are saved into both the home and away club folders.</p>

    <table class="form-table">
    <tr>
        <th scope="row">Enable Drive Upload</th>
        <td>
            <label>
                <input type="checkbox" name="nipgl_drive_enabled" value="1" <?php checked(!empty($s['enabled'])); ?>>
                Save confirmed scorecards to Google Drive
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row">Service Account Key</th>
        <td>
            <?php if ($has_key): ?>
                <div id="nipgl-key-current" style="margin-bottom:10px;padding:10px 14px;background:#d1e7dd;color:#0a3622;border-radius:4px;font-size:13px">
                    ✅ <strong>Key uploaded</strong><br>
                    <span style="font-family:monospace;font-size:12px"><?php echo esc_html($key_email); ?></span><br>
                    <small style="color:#1a5c38">Stored at: <?php echo esc_html($s['key_path']); ?></small>
                </div>
            <?php else: ?>
                <div id="nipgl-key-current" style="margin-bottom:10px;padding:10px 14px;background:#fff3cd;color:#856404;border-radius:4px;font-size:13px">
                    ⚠️ No key uploaded yet
                </div>
            <?php endif; ?>

            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <label class="button" for="nipgl-key-file-input" style="cursor:pointer">
                    📁 <?php echo $has_key ? 'Replace Key File' : 'Upload Key File'; ?>
                </label>
                <input type="file" id="nipgl-key-file-input" accept=".json,application/json"
                    style="display:none">
                <span id="nipgl-key-upload-status" style="font-size:13px"></span>
            </div>
            <p class="description" style="margin-top:8px">
                Download a JSON key from <strong>Google Cloud Console → IAM → Service Accounts → your account → Keys → Add Key → JSON</strong>.<br>
                The file will be stored in a protected directory on your server — not publicly accessible.<br>
                Share your Drive archive folder with the service account email as <strong>Editor</strong>.
            </p>
            <!-- Hidden field so the path survives the main form save even without re-uploading -->
            <input type="hidden" name="nipgl_drive_key_path" value="<?php echo esc_attr($s['key_path'] ?? ''); ?>" id="nipgl-key-path-field">
        </td>
    </tr>
    <tr>
        <th scope="row">Root Drive Folder ID</th>
        <td>
            <input type="text" name="nipgl_drive_root_folder"
                value="<?php echo esc_attr($s['root_folder_id']); ?>"
                class="regular-text"
                placeholder="1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74o">
            <p class="description">
                The ID of the Google Drive folder that will contain the archive.<br>
                Find it in the folder URL: <code>drive.google.com/drive/folders/<strong style="color:#1a2e5a">THIS_PART</strong></code>
            </p>
        </td>
    </tr>
    </table>

    <p style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <button type="button" class="button" id="nipgl-drive-test" data-nonce="<?php echo $nonce_test; ?>">
            🔌 Test Connection
        </button>
        <span id="nipgl-drive-test-result" style="font-size:13px"></span>
    </p>

    <script>
    (function() {
        var uploadNonce = '<?php echo $nonce_key; ?>';

        // Key file upload
        document.getElementById('nipgl-key-file-input').addEventListener('change', function() {
            var file = this.files[0];
            if (!file) return;
            var status = document.getElementById('nipgl-key-upload-status');
            status.textContent = 'Uploading…';
            status.style.color = '#333';
            var fd = new FormData();
            fd.append('action',  'nipgl_drive_upload_key');
            fd.append('nonce',   uploadNonce);
            fd.append('keyfile', file);
            fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                .then(function(r) { return r.json(); })
                .then(function(r) {
                    if (r.success) {
                        status.textContent = '✅ Uploaded';
                        status.style.color = 'green';
                        // Update the current key display
                        document.getElementById('nipgl-key-current').innerHTML =
                            '✅ <strong>Key uploaded</strong><br>'
                            + '<span style="font-family:monospace;font-size:12px">' + r.data.email + '</span><br>'
                            + '<small style="color:#1a5c38">Stored at: ' + r.data.key_path + '</small>';
                        document.getElementById('nipgl-key-current').style.background = '#d1e7dd';
                        document.getElementById('nipgl-key-current').style.color      = '#0a3622';
                        // Update hidden path field so main form save keeps it
                        document.getElementById('nipgl-key-path-field').value = r.data.key_path;
                    } else {
                        status.textContent = '❌ ' + (r.data || 'Upload failed');
                        status.style.color = 'red';
                    }
                })
                .catch(function() {
                    status.textContent = '❌ Upload request failed';
                    status.style.color = 'red';
                });
        });

        // Test connection
        document.getElementById('nipgl-drive-test').addEventListener('click', function() {
            var btn = this, res = document.getElementById('nipgl-drive-test-result');
            btn.disabled = true;
            res.textContent = 'Testing…';
            res.style.color = '#333';
            var fd = new FormData();
            fd.append('action', 'nipgl_drive_test');
            fd.append('nonce',  btn.dataset.nonce);
            fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                .then(function(r) { return r.json(); })
                .then(function(r) {
                    btn.disabled    = false;
                    res.textContent = r.success ? '✅ ' + r.data.message : '❌ ' + (r.data || 'Failed');
                    res.style.color = r.success ? 'green' : 'red';
                })
                .catch(function() {
                    btn.disabled    = false;
                    res.textContent = '❌ Request failed';
                    res.style.color = 'red';
                });
        });
    })();
    </script>
    <?php
}

// ── Test connection AJAX ──────────────────────────────────────────────────────

add_action('wp_ajax_nipgl_drive_test', 'nipgl_ajax_drive_test');
function nipgl_ajax_drive_test() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    check_ajax_referer('nipgl_drive_test', 'nonce');

    $s = nipgl_drive_get_settings();
    if (empty($s['key_path']))        wp_send_json_error('No key uploaded yet — upload a Service Account JSON key first');
    if (!file_exists($s['key_path'])) wp_send_json_error('Key file missing from server — please re-upload it');
    if (empty($s['root_folder_id']))  wp_send_json_error('No root folder ID configured');

    delete_transient('nipgl_drive_token');
    $token = nipgl_drive_get_access_token($s['key_path']);
    if (!$token) wp_send_json_error('Could not obtain access token — verify the key file is a valid Service Account JSON');

    $folder_id = trim($s['root_folder_id']);
    $response  = wp_remote_request(
        'https://www.googleapis.com/drive/v3/files/' . $folder_id . '?fields=name,id&supportsAllDrives=true',
        array(
            'method'  => 'GET',
            'timeout' => 15,
            'headers' => array('Authorization' => 'Bearer ' . $token),
        )
    );

    if (is_wp_error($response)) {
        wp_send_json_error('HTTP error: ' . $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 200 && !empty($data['id'])) {
        wp_send_json_success(array('message' => 'Connected. Root folder: "' . ($data['name'] ?? '?') . '"'));
    }

    $api_msg = $data['error']['message'] ?? $data['error']['status'] ?? '(no detail)';
    wp_send_json_error('API ' . $code . ': ' . $api_msg);
}

// ── Persist Drive settings (called from nipgl_save_settings) ─────────────────

function nipgl_drive_save_settings() {
    $s = nipgl_drive_get_settings();
    update_option('nipgl_drive', array(
        'enabled'        => !empty($_POST['nipgl_drive_enabled']),
        // key_path comes from hidden field — preserves uploaded path across saves
        'key_path'       => sanitize_text_field($_POST['nipgl_drive_key_path']    ?? $s['key_path']),
        'root_folder_id' => sanitize_text_field($_POST['nipgl_drive_root_folder'] ?? ''),
    ));
    delete_transient('nipgl_drive_token');
}
