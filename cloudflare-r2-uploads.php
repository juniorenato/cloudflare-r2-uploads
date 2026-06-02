<?php
/**
 * Plugin Name: Cloudflare R2 Uploads
 * Description: Offloads WordPress media uploads to Cloudflare R2.
 * Version: 1.0.0
 * Author: juniorenato
 */

if (! defined('ABSPATH')) {
    exit;
}

final class Cloudflare_R2_Uploads
{
    private const OPTION_KEY = 'cloudflare_r2_uploads_options';
    private const OPTION_GROUP = 'cloudflare_r2_uploads';

    public static function init(): void
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new self();
        }
    }

    private function __construct()
    {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_menu']);

        add_filter('upload_dir', [$this, 'filter_upload_dir']);
        add_action('add_attachment', [$this, 'upload_attachment_to_r2']);
        add_filter('wp_generate_attachment_metadata', [$this, 'upload_generated_sizes_to_r2'], 10, 2);
    }

    public function register_settings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_KEY,
            ['sanitize_callback' => [$this, 'sanitize_options']]
        );
    }

    public function register_menu(): void
    {
        add_options_page(
            'Cloudflare R2 Uploads',
            'Cloudflare R2 Uploads',
            'manage_options',
            'cloudflare-r2-uploads',
            [$this, 'render_settings_page']
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function sanitize_options(array $input): array
    {
        $sanitized = [];
        $sanitized['account_id'] = sanitize_text_field((string) ($input['account_id'] ?? ''));
        $sanitized['access_key_id'] = sanitize_text_field((string) ($input['access_key_id'] ?? ''));
        $sanitized['secret_access_key'] = trim((string) ($input['secret_access_key'] ?? ''));
        $sanitized['bucket_name'] = sanitize_text_field((string) ($input['bucket_name'] ?? ''));
        $sanitized['public_base_url'] = esc_url_raw((string) ($input['public_base_url'] ?? ''));
        $sanitized['path_prefix'] = trim(sanitize_text_field((string) ($input['path_prefix'] ?? '')), '/');

        return $sanitized;
    }

    public function render_settings_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $options = $this->get_options();
        ?>
        <div class="wrap">
            <h1>Cloudflare R2 Uploads</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="r2-account-id">Account ID</label></th>
                            <td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[account_id]" type="text" id="r2-account-id" value="<?php echo esc_attr($options['account_id']); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="r2-access-key-id">Access Key ID</label></th>
                            <td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[access_key_id]" type="text" id="r2-access-key-id" value="<?php echo esc_attr($options['access_key_id']); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="r2-secret-access-key">Secret Access Key</label></th>
                            <td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[secret_access_key]" type="password" id="r2-secret-access-key" value="<?php echo esc_attr($options['secret_access_key']); ?>" class="regular-text" autocomplete="off" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="r2-bucket-name">Bucket Name</label></th>
                            <td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[bucket_name]" type="text" id="r2-bucket-name" value="<?php echo esc_attr($options['bucket_name']); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="r2-public-base-url">Public Base URL</label></th>
                            <td>
                                <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[public_base_url]" type="url" id="r2-public-base-url" value="<?php echo esc_attr($options['public_base_url']); ?>" class="regular-text" />
                                <p class="description">Example: https://media.example.com</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="r2-path-prefix">Object Prefix (optional)</label></th>
                            <td>
                                <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[path_prefix]" type="text" id="r2-path-prefix" value="<?php echo esc_attr($options['path_prefix']); ?>" class="regular-text" />
                                <p class="description">Example: wordpress/uploads</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $uploads
     * @return array<string, mixed>
     */
    public function filter_upload_dir(array $uploads): array
    {
        $options = $this->get_options();

        if ($options['public_base_url'] === '' || ! $this->is_configured($options)) {
            return $uploads;
        }

        $uploads['baseurl'] = $this->build_public_baseurl($options['public_base_url'], $options['path_prefix']);
        $uploads['url'] = $uploads['baseurl'] . $uploads['subdir'];

        return $uploads;
    }

    public function upload_attachment_to_r2(int $attachment_id): void
    {
        $options = $this->get_options();
        if (! $this->is_configured($options)) {
            return;
        }

        $path = get_attached_file($attachment_id);
        if (! is_string($path) || $path === '' || ! file_exists($path)) {
            return;
        }

        $relative = $this->relative_upload_path($path);
        if ($relative === null) {
            return;
        }

        $this->put_object($relative, $path, $options);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function upload_generated_sizes_to_r2(array $metadata, int $attachment_id): array
    {
        $options = $this->get_options();
        if (! $this->is_configured($options)) {
            return $metadata;
        }

        $main_file = get_attached_file($attachment_id);
        if (! is_string($main_file) || $main_file === '' || ! file_exists($main_file)) {
            return $metadata;
        }

        $relative_main = $this->relative_upload_path($main_file);
        if ($relative_main !== null) {
            $this->put_object($relative_main, $main_file, $options);
        }

        if (! isset($metadata['sizes']) || ! is_array($metadata['sizes'])) {
            return $metadata;
        }

        $main_relative_dir = dirname((string) ($metadata['file'] ?? ''));
        $main_relative_dir = $main_relative_dir === '.' ? '' : $main_relative_dir;
        $uploads = wp_get_upload_dir();
        $base_dir = (string) ($uploads['basedir'] ?? '');

        foreach ($metadata['sizes'] as $size_data) {
            if (! is_array($size_data) || empty($size_data['file'])) {
                continue;
            }

            $relative_file = ltrim(($main_relative_dir !== '' ? $main_relative_dir . '/' : '') . $size_data['file'], '/');
            $absolute_file = trailingslashit($base_dir) . $relative_file;

            if (! file_exists($absolute_file)) {
                continue;
            }

            $this->put_object($relative_file, $absolute_file, $options);
        }

        return $metadata;
    }

    /**
     * @param array<string, string> $options
     */
    private function is_configured(array $options): bool
    {
        return $options['account_id'] !== ''
            && $options['access_key_id'] !== ''
            && $options['secret_access_key'] !== ''
            && $options['bucket_name'] !== '';
    }

    /**
     * @return array<string, string>
     */
    private function get_options(): array
    {
        $defaults = [
            'account_id' => '',
            'access_key_id' => '',
            'secret_access_key' => '',
            'bucket_name' => '',
            'public_base_url' => '',
            'path_prefix' => '',
        ];

        $saved = get_option(self::OPTION_KEY, []);
        if (! is_array($saved)) {
            $saved = [];
        }

        /** @var array<string, string> */
        return wp_parse_args($saved, $defaults);
    }

    private function build_public_baseurl(string $public_base_url, string $prefix): string
    {
        $public_base_url = untrailingslashit($public_base_url);
        $prefix = trim($prefix, '/');

        return $prefix !== '' ? $public_base_url . '/' . $prefix : $public_base_url;
    }

    private function relative_upload_path(string $absolute_path): ?string
    {
        $uploads = wp_get_upload_dir();
        $base_dir = (string) ($uploads['basedir'] ?? '');
        $base_dir = wp_normalize_path($base_dir);
        $absolute_path = wp_normalize_path($absolute_path);

        if ($base_dir === '' || strpos($absolute_path, $base_dir) !== 0) {
            return null;
        }

        return ltrim(substr($absolute_path, strlen($base_dir)), '/');
    }

    private function build_object_key(string $relative_path, string $prefix): string
    {
        $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
        $prefix = trim($prefix, '/');

        return $prefix !== '' ? $prefix . '/' . $relative_path : $relative_path;
    }

    /**
     * @param array<string, string> $options
     */
    private function put_object(string $relative_path, string $absolute_path, array $options): void
    {
        $host = $options['account_id'] . '.r2.cloudflarestorage.com';
        $object_key = $this->build_object_key($relative_path, $options['path_prefix']);
        $canonical_key = implode('/', array_map('rawurlencode', explode('/', $object_key)));
        $canonical_uri = '/' . rawurlencode($options['bucket_name']) . '/' . $canonical_key;

        $timestamp = gmdate('Ymd\\THis\\Z');
        $date = gmdate('Ymd');
        $service = 's3';
        $region = 'auto';
        $scope = $date . '/' . $region . '/' . $service . '/aws4_request';

        $payload_hash = hash_file('sha256', $absolute_path);
        if (! is_string($payload_hash) || $payload_hash === '') {
            return;
        }

        $canonical_headers =
            'host:' . $host . "\n" .
            'x-amz-content-sha256:' . $payload_hash . "\n" .
            'x-amz-date:' . $timestamp . "\n";

        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
        $canonical_request =
            "PUT\n" .
            $canonical_uri . "\n\n" .
            $canonical_headers . "\n" .
            $signed_headers . "\n" .
            $payload_hash;

        $string_to_sign =
            "AWS4-HMAC-SHA256\n" .
            $timestamp . "\n" .
            $scope . "\n" .
            hash('sha256', $canonical_request);

        $signing_key = $this->get_signature_key($options['secret_access_key'], $date, $region, $service);
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);

        $authorization =
            'AWS4-HMAC-SHA256 Credential=' . $options['access_key_id'] . '/' . $scope .
            ', SignedHeaders=' . $signed_headers .
            ', Signature=' . $signature;

        $mime_type = wp_check_filetype($absolute_path)['type'] ?? null;
        if (! is_string($mime_type) || $mime_type === '') {
            $mime_type = 'application/octet-stream';
        }

        $endpoint = 'https://' . $host . $canonical_uri;
        $headers = [
            'Authorization' => $authorization,
            'Content-Type' => $mime_type,
            'Host' => $host,
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date' => $timestamp,
        ];

        if ($this->put_object_with_curl($endpoint, $headers, $absolute_path)) {
            return;
        }

        $max_fallback_size = (int) apply_filters('cloudflare_r2_uploads_http_fallback_max_bytes', 25 * MB_IN_BYTES);
        $file_size = filesize($absolute_path);
        if (! is_int($file_size) || $file_size < 0 || $file_size > $max_fallback_size) {
            error_log('Cloudflare R2 Uploads: fallback upload skipped due to file size for ' . $absolute_path);
            return;
        }

        $body = file_get_contents($absolute_path);
        if (! is_string($body)) {
            error_log('Cloudflare R2 Uploads: could not read file for upload ' . $absolute_path);
            return;
        }

        $response = wp_remote_request(
            $endpoint,
            [
                'method' => 'PUT',
                'headers' => $headers,
                'body' => $body,
                'timeout' => 30,
            ]
        );

        if (is_wp_error($response)) {
            error_log('Cloudflare R2 Uploads: HTTP upload failed - ' . $response->get_error_message());
            return;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            error_log('Cloudflare R2 Uploads: HTTP upload failed with status ' . $status_code . ' for ' . $absolute_path);
        }
    }


    /**
     * @param array<string, string> $headers
     */
    private function put_object_with_curl(string $endpoint, array $headers, string $absolute_path): bool
    {
        if (! function_exists('curl_init')) {
            return false;
        }

        $file_size = filesize($absolute_path);
        if (! is_int($file_size) || $file_size < 0) {
            return false;
        }

        $file_handle = fopen($absolute_path, 'rb');
        if ($file_handle === false) {
            error_log('Cloudflare R2 Uploads: could not open file for upload ' . $absolute_path);
            return false;
        }

        $header_lines = [];
        foreach ($headers as $header_name => $header_value) {
            $header_lines[] = $header_name . ': ' . $header_value;
        }

        $ch = curl_init($endpoint);
        if ($ch === false) {
            fclose($file_handle);
            return false;
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $file_handle);
        curl_setopt($ch, CURLOPT_INFILESIZE, $file_size);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header_lines);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        curl_exec($ch);
        $curl_error = curl_error($ch);
        $status_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        curl_close($ch);
        fclose($file_handle);

        if ($curl_error !== '') {
            error_log('Cloudflare R2 Uploads: cURL upload failed - ' . $curl_error);
            return false;
        }

        if ($status_code < 200 || $status_code >= 300) {
            error_log('Cloudflare R2 Uploads: cURL upload failed with status ' . $status_code . ' for ' . $absolute_path);
            return false;
        }

        return true;
    }

    private function get_signature_key(string $key, string $date_stamp, string $region_name, string $service_name): string
    {
        $k_secret = 'AWS4' . $key;
        $k_date = hash_hmac('sha256', $date_stamp, $k_secret, true);
        $k_region = hash_hmac('sha256', $region_name, $k_date, true);
        $k_service = hash_hmac('sha256', $service_name, $k_region, true);

        return hash_hmac('sha256', 'aws4_request', $k_service, true);
    }
}

Cloudflare_R2_Uploads::init();
