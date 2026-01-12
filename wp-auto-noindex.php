<?php
/**
 * Plugin Name: WP Auto Noindex
 * Plugin URI: https://wptools.co.uk/wp-auto-noindex
 * Description: Automatically applies safe noindex rules to low-value WordPress pages such as search results, archives, pagination, attachments, and system pages.
 * Version: 1.1.0
 * Author: WP Tools
 * Author URI: https://wptools.co.uk
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-auto-noindex
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

final class WP_Auto_Noindex {
    const OPTION_KEY = 'wpan_settings';

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_post_wpan_check_token', [__CLASS__, 'handle_check_token']);
        add_filter('wp_robots', [__CLASS__, 'filter_wp_robots'], 999);
    }

    public static function defaults(): array {
        return [
            'enabled' => 1,

            'pro_token' => '',
            'pro_status' => 'unknown',
            'pro_last_check' => 0,
            'pro_grace_until' => 0,

            'noindex_search' => 1,
            'noindex_author' => 1,
            'noindex_tag' => 1,
            'noindex_category' => 0,
            'noindex_date' => 1,
            'noindex_paged' => 1,
            'noindex_attachment' => 1,

            'search_min_results_enabled' => 0,
            'search_min_results' => 1,

            'taxonomies_enabled' => 0,
            'taxonomies' => [],

            'post_type_archives_enabled' => 0,
            'post_type_archives' => [],

            'noindex_woo_cart' => 1,
            'noindex_woo_checkout' => 1,
            'noindex_woo_account' => 1,

            'exclude_url_contains' => '',

            'force_apply' => 1,
        ];
    }

    public static function get_settings(): array {
        $saved = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) $saved = [];
        $s = array_merge(self::defaults(), $saved);

        if (!is_array($s['taxonomies'])) $s['taxonomies'] = [];
        if (!is_array($s['post_type_archives'])) $s['post_type_archives'] = [];

        $s['search_min_results'] = max(0, (int)($s['search_min_results'] ?? 0));

        $s['pro_token'] = trim((string)($s['pro_token'] ?? ''));
        $s['pro_status'] = (string)($s['pro_status'] ?? 'unknown');
        $s['pro_last_check'] = (int)($s['pro_last_check'] ?? 0);
        $s['pro_grace_until'] = (int)($s['pro_grace_until'] ?? 0);

        return $s;
    }

    public static function admin_menu(): void {
        add_management_page(
            'WP Auto Noindex',
            'WP Auto Noindex',
            'manage_options',
            'wp-auto-noindex',
            [__CLASS__, 'render_settings_page']
        );
    }

    private static function taxonomies_for_ui(): array {
        $taxes = get_taxonomies(['public' => true], 'objects');
        $out = [];
        foreach ($taxes as $tax) {
            if (empty($tax->name)) continue;
            if (in_array($tax->name, ['post_format'], true)) continue;
            $out[$tax->name] = $tax->labels->singular_name ?? $tax->name;
        }
        ksort($out);
        return $out;
    }

    private static function post_types_for_ui(): array {
        $pts = get_post_types(['public' => true], 'objects');
        $out = [];
        foreach ($pts as $pt) {
            if (empty($pt->name)) continue;
            if (in_array($pt->name, ['attachment'], true)) continue;
            if (empty($pt->has_archive)) continue;
            $out[$pt->name] = $pt->labels->singular_name ?? $pt->name;
        }
        ksort($out);
        return $out;
    }

    private static function pro_ui_disabled_attr(): string {
        return self::pro_enabled() ? '' : 'disabled="disabled"';
    }

    public static function register_settings(): void {
        register_setting(
            'wpan_group',
            self::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
                'default' => self::defaults(),
            ]
        );

        add_settings_section(
            'wpan_section_main',
            'Rules',
            function () {
                echo '<p>Enable/disable automatic <code>noindex,follow</code> rules for low-value pages.</p>';
            },
            'wp-auto-noindex'
        );

        add_settings_field(
            'wpan_entitlement',
            'Subscription',
            function () {
                $s = self::get_settings();

                $status = (string)($s['pro_status'] ?? 'unknown');
                $last = (int)($s['pro_last_check'] ?? 0);
                $grace = (int)($s['pro_grace_until'] ?? 0);

                $badge = 'Unknown';
                if ($status === 'active') $badge = 'Active';
                if ($status === 'grace') $badge = 'Grace';
                if ($status === 'inactive') $badge = 'Inactive';

                $last_h = $last ? gmdate('Y-m-d H:i', $last) . ' UTC' : 'Never';
                $grace_h = $grace ? gmdate('Y-m-d H:i', $grace) . ' UTC' : '-';

                $check_url = wp_nonce_url(
                    admin_url('admin-post.php?action=wpan_check_token'),
                    'wpan_check_token'
                );

                echo '<div style="max-width:680px;padding:12px;border:1px solid #ddd;background:#fff;border-radius:6px;">';
                echo '<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">';
                echo '<strong>Status:</strong> <span style="padding:3px 8px;border-radius:999px;border:1px solid #ddd;background:#f8f8f8;">' . esc_html($badge) . '</span>';
                echo '<span style="color:#666">Last check: ' . esc_html($last_h) . '</span>';
                echo '<span style="color:#666">Grace until: ' . esc_html($grace_h) . '</span>';
                echo '</div>';

                printf(
                    '<input type="text" name="%s[pro_token]" value="%s" placeholder="Paste your subscription token" style="width:100%%;max-width:520px;">',
                    esc_attr(self::OPTION_KEY),
                    esc_attr((string)($s['pro_token'] ?? ''))
                );

                echo '<div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">';
                echo '<a href="' . esc_url($check_url) . '" class="button">Check now</a>';
                echo '<a href="https://wptools.co.uk/account" target="_blank" rel="noopener" class="button button-secondary">Manage subscription</a>';
                echo '<span style="color:#666">Pro rules require an active subscription.</span>';
                echo '</div>';

                echo '</div>';
            },
            'wp-auto-noindex',
            'wpan_section_main'
        );

        $checkboxes = [
            'enabled' => ['Plugin Enabled', 'Master toggle.'],
            'noindex_search' => ['Search Results', 'Applies to search pages.'],
            'noindex_author' => ['Author Archives', 'Applies to author archive pages.'],
            'noindex_tag' => ['Tag Archives', 'Applies to tag archive pages.'],
            'noindex_category' => ['Category Archives', 'Applies to category archive pages.'],
            'noindex_date' => ['Date Archives', 'Applies to year/month/day archives.'],
            'noindex_paged' => ['Paginated Pages', 'Applies to <code>/page/2/</code> etc.'],
            'noindex_attachment' => ['Attachment Pages', 'Applies to attachment pages.'],
        ];

        foreach ($checkboxes as $key => [$label, $desc]) {
            add_settings_field(
                'wpan_' . $key,
                esc_html($label),
                function () use ($key, $desc) {
                    $s = self::get_settings();
                    $checked = !empty($s[$key]) ? 'checked' : '';
                    printf(
                        '<label><input type="checkbox" name="%s[%s]" value="1" %s> <span style="color:#666">%s</span></label>',
                        esc_attr(self::OPTION_KEY),
                        esc_attr($key),
                        $checked,
                        wp_kses_post($desc)
                    );
                },
                'wp-auto-noindex',
                'wpan_section_main'
            );
        }

        add_settings_field(
            'wpan_search_min_results',
            'Thin Search Results (Pro)',
            function () {
                $s = self::get_settings();
                $enabled = !empty($s['search_min_results_enabled']) ? 'checked' : '';
                $val = (int)$s['search_min_results'];
                $disabled = self::pro_ui_disabled_attr();

                printf(
                    '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="%s[search_min_results_enabled]" value="1" %s %s> <span style="color:#666">Noindex search pages when results are less than</span></label>',
                    esc_attr(self::OPTION_KEY),
                    $enabled,
                    $disabled
                );

                printf(
                    '<input type="number" min="0" step="1" name="%s[search_min_results]" value="%d" style="width:90px;" %s> <span style="color:#666">results</span>',
                    esc_attr(self::OPTION_KEY),
                    $val,
                    $disabled
                );

                if (!self::pro_enabled()) {
                    echo '<div style="margin-top:6px;color:#666">Requires an active subscription.</div>';
                }
            },
            'wp-auto-noindex',
            'wpan_section_main'
        );

        add_settings_field(
            'wpan_taxonomies',
            'Custom Taxonomy Archives (Pro)',
            function () {
                $s = self::get_settings();
                $enabled = !empty($s['taxonomies_enabled']) ? 'checked' : '';
                $selected = array_fill_keys(array_map('strval', $s['taxonomies']), true);
                $taxes = self::taxonomies_for_ui();
                $disabled = self::pro_ui_disabled_attr();

                printf(
                    '<label><input type="checkbox" name="%s[taxonomies_enabled]" value="1" %s %s> <span style="color:#666">Enable noindex for selected taxonomies</span></label>',
                    esc_attr(self::OPTION_KEY),
                    $enabled,
                    $disabled
                );

                echo '<div style="margin-top:10px;max-width:680px;padding:10px;border:1px solid #ddd;background:#fff;border-radius:6px;">';

                if (empty($taxes)) {
                    echo '<span style="color:#666">No public taxonomies found.</span>';
                    echo '</div>';
                    return;
                }

                foreach ($taxes as $name => $label) {
                    $is_core = in_array($name, ['category', 'post_tag'], true);
                    $checked = !empty($selected[$name]) ? 'checked' : '';
                    $suffix = $is_core ? ' <span style="color:#999">(core)</span>' : '';
                    printf(
                        '<label style="display:inline-block;width:220px;max-width:100%%;margin:4px 0;"><input type="checkbox" name="%s[taxonomies][]" value="%s" %s %s> %s%s</label>',
                        esc_attr(self::OPTION_KEY),
                        esc_attr($name),
                        $checked,
                        $disabled,
                        esc_html($label),
                        $suffix
                    );
                }

                echo '</div>';

                if (!self::pro_enabled()) {
                    echo '<div style="margin-top:6px;color:#666">Requires an active subscription.</div>';
                }
            },
            'wp-auto-noindex',
            'wpan_section_main'
        );

        add_settings_field(
            'wpan_post_type_archives',
            'Post Type Archives (Pro)',
            function () {
                $s = self::get_settings();
                $enabled = !empty($s['post_type_archives_enabled']) ? 'checked' : '';
                $selected = array_fill_keys(array_map('strval', $s['post_type_archives']), true);
                $pts = self::post_types_for_ui();
                $disabled = self::pro_ui_disabled_attr();

                printf(
                    '<label><input type="checkbox" name="%s[post_type_archives_enabled]" value="1" %s %s> <span style="color:#666">Enable noindex for selected post type archives</span></label>',
                    esc_attr(self::OPTION_KEY),
                    $enabled,
                    $disabled
                );

                echo '<div style="margin-top:10px;max-width:680px;padding:10px;border:1px solid #ddd;background:#fff;border-radius:6px;">';

                if (empty($pts)) {
                    echo '<span style="color:#666">No public post type archives found.</span>';
                    echo '</div>';
                    return;
                }

                foreach ($pts as $name => $label) {
                    $checked = !empty($selected[$name]) ? 'checked' : '';
                    printf(
                        '<label style="display:inline-block;width:220px;max-width:100%%;margin:4px 0;"><input type="checkbox" name="%s[post_type_archives][]" value="%s" %s %s> %s</label>',
                        esc_attr(self::OPTION_KEY),
                        esc_attr($name),
                        $checked,
                        $disabled,
                        esc_html($label)
                    );
                }

                echo '</div>';

                if (!self::pro_enabled()) {
                    echo '<div style="margin-top:6px;color:#666">Requires an active subscription.</div>';
                }
            },
            'wp-auto-noindex',
            'wpan_section_main'
        );

        add_settings_field(
            'wpan_woocommerce',
            'WooCommerce System Pages',
            function () {
                $s = self::get_settings();
                $items = [
                    'noindex_woo_cart' => ['Cart', 'Applies to the Cart page.'],
                    'noindex_woo_checkout' => ['Checkout', 'Applies to checkout (including order-received).'],
                    'noindex_woo_account' => ['My Account', 'Applies to my-account endpoints.'],
                ];

                foreach ($items as $key => [$label, $desc]) {
                    $checked = !empty($s[$key]) ? 'checked' : '';
                    printf(
                        '<label style="display:block;margin:6px 0;"><input type="checkbox" name="%s[%s]" value="1" %s> %s <span style="color:#666">%s</span></label>',
                        esc_attr(self::OPTION_KEY),
                        esc_attr($key),
                        $checked,
                        esc_html($label),
                        esc_html($desc)
                    );
                }

                if (!function_exists('is_woocommerce')) {
                    echo '<div style="margin-top:8px;color:#666">WooCommerce not detected.</div>';
                }
            },
            'wp-auto-noindex',
            'wpan_section_main'
        );

        add_settings_field(
            'wpan_exclusions',
            'Exclusions (Pro)',
            function () {
                $s = self::get_settings();
                $val = (string)($s['exclude_url_contains'] ?? '');
                $disabled = self::pro_ui_disabled_attr();

                echo '<div style="max-width:680px">';
                echo '<div style="color:#666;margin-bottom:6px;">Do not apply noindex when the current URL contains any of these values (one per line).</div>';
                printf(
                    '<textarea name="%s[exclude_url_contains]" rows="5" style="width:100%%;max-width:680px;" %s>%s</textarea>',
                    esc_attr(self::OPTION_KEY),
                    $disabled,
                    esc_textarea($val)
                );
                echo '<div style="color:#999;margin-top:6px;">Example: <code>/blog/</code> or <code>?s=</code></div>';
                if (!self::pro_enabled()) {
                    echo '<div style="margin-top:6px;color:#666">Requires an active subscription.</div>';
                }
                echo '</div>';
            },
            'wp-auto-noindex',
            'wpan_section_main'
        );

        add_settings_field(
            'wpan_force_apply',
            'Force Apply',
            function () {
                $s = self::get_settings();
                $checked = !empty($s['force_apply']) ? 'checked' : '';
                printf(
                    '<label><input type="checkbox" name="%s[force_apply]" value="1" %s> <span style="color:#666">Enforce noindex when a rule matches</span></label>',
                    esc_attr(self::OPTION_KEY),
                    $checked
                );
            },
            'wp-auto-noindex',
            'wpan_section_main'
        );
    }

    public static function sanitize_settings($input): array {
        $defaults = self::defaults();
        if (!is_array($input)) $input = [];

        $existing = get_option(self::OPTION_KEY, []);
        if (!is_array($existing)) $existing = [];
        $existing = array_merge($defaults, $existing);

        $out = $defaults;

        $bool_keys = [
            'enabled',
            'noindex_search','noindex_author','noindex_tag','noindex_category','noindex_date','noindex_paged','noindex_attachment',
            'search_min_results_enabled',
            'taxonomies_enabled',
            'post_type_archives_enabled',
            'noindex_woo_cart','noindex_woo_checkout','noindex_woo_account',
            'force_apply',
        ];

        foreach ($bool_keys as $k) {
            $out[$k] = !empty($input[$k]) ? 1 : 0;
        }

        $out['search_min_results'] = isset($input['search_min_results'])
            ? max(0, (int)$input['search_min_results'])
            : (int)$defaults['search_min_results'];

        $out['taxonomies'] = [];
        if (!empty($input['taxonomies']) && is_array($input['taxonomies'])) {
            $allowed = array_keys(self::taxonomies_for_ui());
            foreach ($input['taxonomies'] as $t) {
                $t = sanitize_key((string)$t);
                if (in_array($t, $allowed, true)) $out['taxonomies'][] = $t;
            }
            $out['taxonomies'] = array_values(array_unique($out['taxonomies']));
        }

        $out['post_type_archives'] = [];
        if (!empty($input['post_type_archives']) && is_array($input['post_type_archives'])) {
            $allowed = array_keys(self::post_types_for_ui());
            foreach ($input['post_type_archives'] as $p) {
                $p = sanitize_key((string)$p);
                if (in_array($p, $allowed, true)) $out['post_type_archives'][] = $p;
            }
            $out['post_type_archives'] = array_values(array_unique($out['post_type_archives']));
        }

        $out['exclude_url_contains'] = '';
        if (isset($input['exclude_url_contains'])) {
            $raw = (string)$input['exclude_url_contains'];
            $raw = str_replace(["\r\n", "\r"], "\n", $raw);
            $lines = array_filter(array_map('trim', explode("\n", $raw)), function ($v) { return $v !== ''; });
            $lines = array_slice($lines, 0, 200);
            $out['exclude_url_contains'] = implode("\n", $lines);
        }

        if (isset($input['pro_token'])) {
            $tok = trim((string)$input['pro_token']);
            $tok = preg_replace('/\s+/', '', $tok);
            $out['pro_token'] = substr($tok, 0, 200);
        } else {
            $out['pro_token'] = (string)($existing['pro_token'] ?? '');
        }

        $out['pro_status'] = (string)($existing['pro_status'] ?? 'unknown');
        $out['pro_last_check'] = (int)($existing['pro_last_check'] ?? 0);
        $out['pro_grace_until'] = (int)($existing['pro_grace_until'] ?? 0);

        $pro = self::pro_enabled();
        if (!$pro) {
            $out['search_min_results_enabled'] = 0;
            $out['search_min_results'] = (int)$defaults['search_min_results'];

            $out['taxonomies_enabled'] = 0;
            $out['taxonomies'] = [];

            $out['post_type_archives_enabled'] = 0;
            $out['post_type_archives'] = [];

            $out['exclude_url_contains'] = '';
        }

        return $out;
    }

    public static function render_settings_page(): void {
        if (!current_user_can('manage_options')) return;

        echo '<div class="wrap">';
        echo '<h1>WP Auto Noindex <span style="font-size:13px;color:#666">by WP Tools</span></h1>';

        if (isset($_GET['wpan_msg']) && $_GET['wpan_msg'] === 'token_missing') {
            echo '<div class="notice notice-error"><p>Subscription token is missing.</p></div>';
        }
        if (isset($_GET['wpan_msg']) && $_GET['wpan_msg'] === 'checked') {
            echo '<div class="notice notice-success"><p>Subscription status updated.</p></div>';
        }

        echo '<form method="post" action="options.php">';
        settings_fields('wpan_group');

        echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr(admin_url('tools.php?page=wp-auto-noindex')) . '">';

        do_settings_sections('wp-auto-noindex');
        submit_button('Save Settings');
        echo '</form>';
        echo '</div>';
    }

    private static function entitlement_endpoint(): string {
        return 'https://wptools.co.uk/index.php?rest_route=/wptools/v1/noindex/validate';
    }

    private static function site_host(): string {
        $home = home_url('/');
        $p = wp_parse_url($home);
        $host = (string)($p['host'] ?? '');
        return strtolower($host);
    }

    private static function entitlement_get_state(): array {
        $s = self::get_settings();

        $status = (string)($s['pro_status'] ?? 'unknown');
        $grace = (int)($s['pro_grace_until'] ?? 0);
        $now = time();

        if ($status === 'active') return ['active' => true, 'mode' => 'active'];
        if ($grace && $now <= $grace) return ['active' => true, 'mode' => 'grace'];

        return ['active' => false, 'mode' => 'inactive'];
    }

    private static function pro_enabled(): bool {
        $s = self::get_settings();

        if (empty($s['pro_token'])) return false;

        $last = (int)($s['pro_last_check'] ?? 0);
        if (!$last || (time() - $last) > (7 * DAY_IN_SECONDS)) return false;

        $state = self::entitlement_get_state();
        return !empty($state['active']);
    }

    private static function entitlement_check_remote(string $token): array {
        $url = self::entitlement_endpoint();
        $payload = [
            'token' => $token,
            'site' => self::site_host(),
            'home_url' => home_url('/'),
            'plugin' => 'wp-auto-noindex',
            'version' => '1.1.0',
        ];

        $resp = wp_remote_post($url, [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($resp)) return ['ok' => false, 'error' => 'request_failed'];

        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);

        $json = json_decode($body, true);
        if (!is_array($json)) $json = [];

        $ok = ($code >= 200 && $code < 300) && array_key_exists('active', $json);
        return ['ok' => $ok, 'code' => $code, 'json' => $json];
    }

    private static function entitlement_apply_result(array $result): void {
        $s = self::get_settings();

        $now = time();
        $status = 'inactive';
        $grace_until = 0;

        if (!empty($result['ok']) && !empty($result['json']) && is_array($result['json'])) {
            $j = $result['json'];

            $remote_active = !empty($j['active']);
            $remote_grace = (int)($j['grace_until'] ?? 0);

            if ($remote_active) {
                $status = 'active';
                $grace_until = $remote_grace;
            } else {
                $status = 'inactive';
                $grace_until = $remote_grace;
                if ($grace_until > $now) $status = 'grace';
            }
        } else {
            $prev_grace = (int)($s['pro_grace_until'] ?? 0);
            if ($prev_grace > $now) {
                $status = 'grace';
                $grace_until = $prev_grace;
            }
        }

        $new = $s;
        $new['pro_status'] = $status;
        $new['pro_last_check'] = $now;
        $new['pro_grace_until'] = $grace_until;

        update_option(self::OPTION_KEY, $new);
    }

    public static function handle_check_token(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce((string)$_GET['_wpnonce'], 'wpan_check_token')) {
            wp_die('Invalid nonce');
        }

        $s = self::get_settings();
        $token = trim((string)($s['pro_token'] ?? ''));

        if ($token === '') {
            $new = $s;
            $new['pro_status'] = 'inactive';
            $new['pro_last_check'] = time();
            $new['pro_grace_until'] = 0;
            update_option(self::OPTION_KEY, $new);

            wp_safe_redirect(add_query_arg(['page' => 'wp-auto-noindex', 'wpan_msg' => 'token_missing'], admin_url('tools.php')));
            exit;
        }

        $res = self::entitlement_check_remote($token);
        self::entitlement_apply_result($res);

        wp_safe_redirect(add_query_arg(['page' => 'wp-auto-noindex', 'wpan_msg' => 'checked'], admin_url('tools.php')));
        exit;
    }

    private static function is_excluded(array $s): bool {
        $raw = trim((string)($s['exclude_url_contains'] ?? ''));
        if ($raw === '') return false;

        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if ($uri === '') return false;

        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = array_filter(array_map('trim', explode("\n", $raw)), function ($v) { return $v !== ''; });

        foreach ($lines as $needle) {
            if ($needle !== '' && stripos($uri, $needle) !== false) return true;
        }

        return false;
    }

    private static function search_is_thin(array $s): bool {
        if (empty($s['search_min_results_enabled'])) return false;
        if (!is_search()) return false;

        global $wp_query;
        $count = 0;

        if (isset($wp_query) && $wp_query instanceof WP_Query) {
            $count = (int)($wp_query->found_posts ?? 0);
        }

        $threshold = (int)$s['search_min_results'];
        return $count < $threshold;
    }

    private static function should_noindex(array $s): bool {
        if (empty($s['enabled'])) return false;

        if (self::pro_enabled() && self::is_excluded($s)) return false;

        if (!empty($s['noindex_search']) && is_search()) return true;
        if (self::pro_enabled() && self::search_is_thin($s)) return true;

        if (!empty($s['noindex_author']) && is_author()) return true;
        if (!empty($s['noindex_tag']) && is_tag()) return true;
        if (!empty($s['noindex_category']) && is_category()) return true;
        if (!empty($s['noindex_date']) && is_date()) return true;
        if (!empty($s['noindex_paged']) && is_paged()) return true;
        if (!empty($s['noindex_attachment']) && is_attachment()) return true;

        if (self::pro_enabled() && !empty($s['taxonomies_enabled']) && is_tax()) {
            $qo = get_queried_object();
            $tax = '';
            if (isset($qo->taxonomy)) $tax = (string)$qo->taxonomy;
            if ($tax !== '' && in_array($tax, $s['taxonomies'], true)) return true;
        }

        if (self::pro_enabled() && !empty($s['post_type_archives_enabled']) && is_post_type_archive()) {
            $pt = (string)get_query_var('post_type');
            if ($pt !== '' && in_array($pt, $s['post_type_archives'], true)) return true;
        }

        if (function_exists('is_woocommerce')) {
            if (!empty($s['noindex_woo_cart']) && function_exists('is_cart') && is_cart()) return true;
            if (!empty($s['noindex_woo_checkout']) && function_exists('is_checkout') && is_checkout()) return true;
            if (!empty($s['noindex_woo_account']) && function_exists('is_account_page') && is_account_page()) return true;
        }

        return false;
    }

    public static function filter_wp_robots(array $robots): array {
        $s = self::get_settings();
        if (!self::should_noindex($s)) return $robots;

        $robots['follow'] = true;

        if (!empty($s['force_apply'])) {
            $robots['index'] = false;
            $robots['noindex'] = true;
            return $robots;
        }

        if (empty($robots['noindex'])) $robots['noindex'] = true;

        return $robots;
    }
}

WP_Auto_Noindex::init();

register_activation_hook(__FILE__, function () {
    $existing = get_option(WP_Auto_Noindex::OPTION_KEY, null);
    if ($existing === null) {
        add_option(WP_Auto_Noindex::OPTION_KEY, WP_Auto_Noindex::defaults());
    }
});
