<?php
/**
 * Plugin Name: WP Tools - Entitlements
 * Description: Entitlement validation endpoint for WP Tools plugins.
 * Version: 1.0.0
 * Author: WP Tools
 */

if (!defined('ABSPATH')) exit;

final class WPTools_Entitlements {
    const OPTION_KEY = 'wptools_entitlement_tokens';

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function register_routes(): void {
        register_rest_route('wptools/v1', '/noindex/validate', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'validate_noindex'],
            'permission_callback' => '__return_true',
            'args' => [],
        ]);
    }

    private static function normalize_host(string $urlOrHost): string {
        $urlOrHost = trim($urlOrHost);
        if ($urlOrHost === '') return '';

        if (strpos($urlOrHost, '://') === false) {
            $host = $urlOrHost;
        } else {
            $p = wp_parse_url($urlOrHost);
            $host = (string)($p['host'] ?? '');
        }

        $host = strtolower($host);
        $host = preg_replace('/:\d+$/', '', $host);
        return $host;
    }

    private static function get_tokens(): array {
        $raw = get_option(self::OPTION_KEY, []);
        if (!is_array($raw)) $raw = [];
        return $raw;
    }

    private static function token_lookup(string $token): array {
        $tokens = self::get_tokens();
        $t = $tokens[$token] ?? null;
        if (!is_array($t)) return [];
        return $t;
    }

    public static function validate_noindex(WP_REST_Request $req): WP_REST_Response {
        $body = $req->get_json_params();
        if (!is_array($body)) $body = [];

        $token = trim((string)($body['token'] ?? ''));
        $site  = trim((string)($body['site'] ?? ''));
        $home  = trim((string)($body['home_url'] ?? ''));

        if ($token === '') {
            return new WP_REST_Response(['active' => false, 'grace_until' => 0], 200);
        }

        $row = self::token_lookup($token);
        if (empty($row)) {
            return new WP_REST_Response(['active' => false, 'grace_until' => 0], 200);
        }

        $status = (string)($row['status'] ?? 'inactive');
        $expires = (int)($row['expires_at'] ?? 0);
        $grace = (int)($row['grace_until'] ?? 0);
        $allowed_sites = $row['sites'] ?? [];

        $now = time();

        $siteHost = self::normalize_host($site);
        if ($siteHost === '') $siteHost = self::normalize_host($home);

        if ($siteHost === '') {
            return new WP_REST_Response(['active' => false, 'grace_until' => $grace], 200);
        }

        if (!is_array($allowed_sites)) $allowed_sites = [];
        $allowed_norm = [];
        foreach ($allowed_sites as $s) {
            $h = self::normalize_host((string)$s);
            if ($h !== '') $allowed_norm[] = $h;
        }

        if (!empty($allowed_norm) && !in_array($siteHost, $allowed_norm, true)) {
            return new WP_REST_Response(['active' => false, 'grace_until' => $grace], 200);
        }

        if ($expires > 0 && $now > $expires) {
            if ($grace > $now) {
                return new WP_REST_Response(['active' => false, 'grace_until' => $grace], 200);
            }
            return new WP_REST_Response(['active' => false, 'grace_until' => 0], 200);
        }

        if ($status !== 'active') {
            if ($grace > $now) {
                return new WP_REST_Response(['active' => false, 'grace_until' => $grace], 200);
            }
            return new WP_REST_Response(['active' => false, 'grace_until' => 0], 200);
        }

        return new WP_REST_Response(['active' => true, 'grace_until' => $grace], 200);
    }

    public static function admin_menu(): void {
        add_management_page(
            'WP Tools Tokens',
            'WP Tools Tokens',
            'manage_options',
            'wptools-tokens',
            [__CLASS__, 'render_tokens_page']
        );
    }

    public static function register_settings(): void {
        register_setting(
            'wptools_tokens_group',
            self::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [__CLASS__, 'sanitize_tokens'],
                'default' => [],
            ]
        );
    }

    public static function sanitize_tokens($input): array {
        if (!is_array($input)) $input = [];
        $out = [];

        foreach ($input as $token => $row) {
            $token = trim((string)$token);
            if ($token === '') continue;
            if (!is_array($row)) $row = [];

            $status = (string)($row['status'] ?? 'inactive');
            if (!in_array($status, ['active', 'inactive'], true)) $status = 'inactive';

            $expires = (int)($row['expires_at'] ?? 0);
            $grace = (int)($row['grace_until'] ?? 0);

            $sites_raw = (string)($row['sites_raw'] ?? '');
            $sites_raw = str_replace(["\r\n", "\r"], "\n", $sites_raw);
            $sites = array_filter(array_map('trim', explode("\n", $sites_raw)), fn($v) => $v !== '');
            $sites = array_slice($sites, 0, 50);

            $out[$token] = [
                'status' => $status,
                'expires_at' => $expires,
                'grace_until' => $grace,
                'sites' => $sites,
                'sites_raw' => implode("\n", $sites),
            ];
        }

        return $out;
    }

    public static function render_tokens_page(): void {
        if (!current_user_can('manage_options')) return;

        $tokens = self::get_tokens();

        echo '<div class="wrap">';
        echo '<h1>WP Tools Tokens</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('wptools_tokens_group');

        echo '<p>Add tokens for testing. Token is the array key. You can bind a token to specific domains (one per line). Times are Unix timestamps (UTC). Leave expires/grace as 0 for none.</p>';

        echo '<table class="widefat striped" style="max-width:1100px">';
        echo '<thead><tr><th style="width:260px">Token</th><th style="width:90px">Status</th><th style="width:160px">Expires At</th><th style="width:160px">Grace Until</th><th>Allowed Sites (one per line)</th></tr></thead>';
        echo '<tbody>';

        if (empty($tokens)) {
            echo '<tr><td colspan="5" style="color:#666">No tokens added yet.</td></tr>';
        } else {
            foreach ($tokens as $token => $row) {
                $status = esc_attr((string)($row['status'] ?? 'inactive'));
                $expires = esc_attr((string)($row['expires_at'] ?? 0));
                $grace = esc_attr((string)($row['grace_until'] ?? 0));
                $sites_raw = esc_textarea((string)($row['sites_raw'] ?? ''));

                echo '<tr>';
                echo '<td><input type="text" name="' . esc_attr(self::OPTION_KEY) . '[' . esc_attr($token) . '][__token]" value="' . esc_attr($token) . '" readonly style="width:100%"></td>';
                echo '<td><select name="' . esc_attr(self::OPTION_KEY) . '[' . esc_attr($token) . '][status]">';
                echo '<option value="active"' . selected($status, 'active', false) . '>active</option>';
                echo '<option value="inactive"' . selected($status, 'inactive', false) . '>inactive</option>';
                echo '</select></td>';
                echo '<td><input type="number" name="' . esc_attr(self::OPTION_KEY) . '[' . esc_attr($token) . '][expires_at]" value="' . $expires . '" style="width:100%"></td>';
                echo '<td><input type="number" name="' . esc_attr(self::OPTION_KEY) . '[' . esc_attr($token) . '][grace_until]" value="' . $grace . '" style="width:100%"></td>';
                echo '<td><textarea rows="4" name="' . esc_attr(self::OPTION_KEY) . '[' . esc_attr($token) . '][sites_raw]" style="width:100%">' . $sites_raw . '</textarea></td>';
                echo '</tr>';
            }
        }

        echo '</tbody>';
        echo '</table>';

        echo '<h2 style="margin-top:18px">Add new token</h2>';
        echo '<div style="max-width:1100px;padding:12px;border:1px solid #ddd;background:#fff;border-radius:6px;">';
        echo '<p style="margin-top:0;color:#666">To add a token, paste this block into wp-admin → Settings → General → (no, don’t) — instead: temporarily add via phpMyAdmin/options or edit the option directly in the DB. This page is for managing existing entries.</p>';
        echo '<p style="margin:0;color:#666">Fastest: in phpMyAdmin, edit option <code>' . esc_html(self::OPTION_KEY) . '</code> and add a new array entry with your token as the key.</p>';
        echo '</div>';

        submit_button('Save Tokens');
        echo '</form>';
        echo '</div>';
    }
}

WPTools_Entitlements::init();
