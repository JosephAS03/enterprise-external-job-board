<?php
/**
 * Plugin Name: Enterprise External Job Board
 * Description: External job board companion plugin for the Enterprise User Management internal site. Pulls job listings from the internal site and submits candidate applications back to it.
 * Version: 0.1.9
 * Author: OpenAI
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('EEJB_VERSION', '0.1.9');
define('EEJB_FILE', __FILE__);
define('EEJB_DIR', plugin_dir_path(__FILE__));
define('EEJB_URL', plugin_dir_url(__FILE__));

require_once EEJB_DIR . 'includes/update-checker-bootstrap.php';

final class EEJB_Plugin {
    const OPTION_KEY = 'eejb_settings';
    const NONCE_ACTION = 'eejb_apply';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_shortcode('eum_external_job_board', [__CLASS__, 'render_job_board']);
        add_shortcode('eum_external_job_application', [__CLASS__, 'render_application_shortcode']);
        add_action('init', [__CLASS__, 'maybe_handle_application']);
        add_action('template_redirect', [__CLASS__, 'maybe_render_public_feed']);
        add_action('wp_head', [__CLASS__, 'render_share_meta_v2'], 1);
        add_filter('pre_get_document_title', [__CLASS__, 'filter_document_title']);
        add_filter('document_title_parts', [__CLASS__, 'filter_document_title_parts'], 20);
        add_filter('single_post_title', [__CLASS__, 'filter_single_post_title'], 20, 2);
        add_filter('the_title', [__CLASS__, 'filter_the_title'], 20, 2);
        add_filter('wpseo_title', [__CLASS__, 'filter_seo_title_v2']);
        add_filter('wpseo_metadesc', [__CLASS__, 'filter_seo_description_v2']);
        add_filter('wpseo_canonical', [__CLASS__, 'filter_seo_canonical_v2']);
        add_filter('wpseo_opengraph_title', [__CLASS__, 'filter_seo_title_v2']);
        add_filter('wpseo_opengraph_desc', [__CLASS__, 'filter_seo_description_v2']);
        add_filter('wpseo_opengraph_url', [__CLASS__, 'filter_seo_canonical_v2']);
        add_filter('wpseo_twitter_title', [__CLASS__, 'filter_seo_title_v2']);
        add_filter('wpseo_twitter_description', [__CLASS__, 'filter_seo_description_v2']);
        add_filter('rank_math/frontend/title', [__CLASS__, 'filter_seo_title_v2']);
        add_filter('rank_math/frontend/description', [__CLASS__, 'filter_seo_description_v2']);
        add_filter('rank_math/frontend/canonical', [__CLASS__, 'filter_seo_canonical_v2']);
        add_filter('rank_math/opengraph/facebook/title', [__CLASS__, 'filter_seo_title_v2']);
        add_filter('rank_math/opengraph/facebook/description', [__CLASS__, 'filter_seo_description_v2']);
        add_filter('rank_math/opengraph/facebook/url', [__CLASS__, 'filter_seo_canonical_v2']);
        add_filter('rank_math/opengraph/twitter/title', [__CLASS__, 'filter_seo_title_v2']);
        add_filter('rank_math/opengraph/twitter/description', [__CLASS__, 'filter_seo_description_v2']);
        add_filter('aioseo_title', [__CLASS__, 'filter_seo_title_v2']);
        add_filter('aioseo_description', [__CLASS__, 'filter_seo_description_v2']);
        add_filter('aioseo_canonical_url', [__CLASS__, 'filter_seo_canonical_v2']);
        add_filter('aioseo_facebook_title', [__CLASS__, 'filter_seo_title_v2']);
        add_filter('aioseo_facebook_description', [__CLASS__, 'filter_seo_description_v2']);
        add_filter('aioseo_twitter_title', [__CLASS__, 'filter_seo_title_v2']);
        add_filter('aioseo_twitter_description', [__CLASS__, 'filter_seo_description_v2']);
        add_filter('seopress_titles_title', [__CLASS__, 'filter_seo_title_v2']);
        add_filter('seopress_titles_desc', [__CLASS__, 'filter_seo_description_v2']);
        add_filter('seopress_titles_canonical', [__CLASS__, 'filter_seo_canonical_v2']);
        add_filter('seopress_social_og_title', [__CLASS__, 'filter_seo_title_v2']);
        add_filter('seopress_social_og_desc', [__CLASS__, 'filter_seo_description_v2']);
        add_filter('seopress_social_twitter_title', [__CLASS__, 'filter_seo_title_v2']);
        add_filter('seopress_social_twitter_desc', [__CLASS__, 'filter_seo_description_v2']);
    }

    public static function defaults() {
        return [
            'internal_base_url' => '',
            'feed_path' => '/wp-admin/admin-post.php?action=eum_job_board_feed',
            'apply_path' => '/wp-admin/admin-post.php?action=eum_job_board_apply_api',
            'feed_url' => '',
            'apply_url' => '',
            'api_token' => '',
            'listings_per_page' => 20,
            'intro_text' => '',
        ];
    }

    public static function get_settings() {
        return wp_parse_args(get_option(self::OPTION_KEY, []), self::defaults());
    }

    public static function admin_menu() {
        add_options_page(
            'External Job Board',
            'External Job Board',
            'manage_options',
            'eejb-settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [__CLASS__, 'sanitize_settings']);

        add_settings_section('eejb_main', 'Connection Settings', '__return_false', self::OPTION_KEY);

        $fields = [
            'internal_base_url' => 'Internal Site Base URL',
            'feed_path' => 'Listings Feed Path',
            'apply_path' => 'Application Submit Path',
            'feed_url' => 'Full Listings Feed URL',
            'apply_url' => 'Full Application Submit URL',
            'api_token' => 'API Token',
            'listings_per_page' => 'Listings Per Page',
            'intro_text' => 'Intro Text',
        ];

        foreach ($fields as $key => $label) {
            add_settings_field($key, $label, [__CLASS__, 'render_field'], self::OPTION_KEY, 'eejb_main', ['key' => $key, 'label' => $label]);
        }
    }

    public static function sanitize_settings($input) {
        $defaults = self::defaults();
        $out = [];
        $out['internal_base_url'] = isset($input['internal_base_url']) ? esc_url_raw(trim((string) $input['internal_base_url'])) : $defaults['internal_base_url'];
        $out['feed_path'] = isset($input['feed_path']) ? '/' . ltrim(trim((string) $input['feed_path']), '/') : $defaults['feed_path'];
        $out['apply_path'] = isset($input['apply_path']) ? '/' . ltrim(trim((string) $input['apply_path']), '/') : $defaults['apply_path'];
        $out['feed_url'] = isset($input['feed_url']) ? esc_url_raw(trim((string) $input['feed_url'])) : '';
        $out['apply_url'] = isset($input['apply_url']) ? esc_url_raw(trim((string) $input['apply_url'])) : '';
        $out['api_token'] = isset($input['api_token']) ? sanitize_text_field((string) $input['api_token']) : '';
        $out['listings_per_page'] = max(1, (int) ($input['listings_per_page'] ?? $defaults['listings_per_page']));
        $out['intro_text'] = isset($input['intro_text']) ? wp_kses_post((string) $input['intro_text']) : '';
        return $out;
    }

    public static function render_field($args) {
        $settings = self::get_settings();
        $key = $args['key'];
        $value = $settings[$key] ?? '';
        switch ($key) {
            case 'intro_text':
                printf(
                    '<textarea name="%1$s[%2$s]" rows="5" class="large-text">%3$s</textarea>',
                    esc_attr(self::OPTION_KEY),
                    esc_attr($key),
                    esc_textarea($value)
                );
                break;
            case 'listings_per_page':
                printf(
                    '<input type="number" min="1" name="%1$s[%2$s]" value="%3$d" class="small-text" />',
                    esc_attr(self::OPTION_KEY),
                    esc_attr($key),
                    (int) $value
                );
                break;
            case 'feed_url':
            case 'apply_url':
                printf(
                    '<input type="url" name="%1$s[%2$s]" value="%3$s" class="large-text" />',
                    esc_attr(self::OPTION_KEY),
                    esc_attr($key),
                    esc_attr($value)
                );
                if ($key === 'feed_url') { echo '<p class="description">Paste the full Internal Feed URL from the internal site here. This is the easiest option.</p>'; }
                if ($key === 'apply_url') { echo '<p class="description">Paste the full Internal Apply API URL from the internal site here. This is the easiest option.</p>'; }
                break;
            case 'api_token':
                printf(
                    '<input type="password" name="%1$s[%2$s]" value="%3$s" class="regular-text" autocomplete="off" />',
                    esc_attr(self::OPTION_KEY),
                    esc_attr($key),
                    esc_attr($value)
                );
                echo '<p class="description">Used as a Bearer token when calling the internal site.</p>';
                break;
            default:
                printf(
                    '<input type="text" name="%1$s[%2$s]" value="%3$s" class="regular-text" />',
                    esc_attr(self::OPTION_KEY),
                    esc_attr($key),
                    esc_attr($value)
                );
        }
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = self::get_settings();
        $feed_url = self::resolve_endpoint_url('feed');
        $apply_url = self::resolve_endpoint_url('apply');
        $board_url = self::public_board_url_v2();
        $public_feed_url = self::public_feed_url_v2();
        ?>
        <div class="wrap">
            <h1>External Job Board</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_KEY);
                do_settings_sections(self::OPTION_KEY);
                submit_button();
                ?>
            </form>
            <hr>
            <h2>Shortcodes</h2>
            <p><code>[eum_external_job_board]</code> shows the shared listings, detail, and application flow used by the current internal job board.</p>
            <p><code>[eum_external_job_application job_id="123"]</code> renders the application form only.</p>
            <h2>Current URLs</h2>
            <p><strong>Listings Feed:</strong> <code><?php echo esc_html($feed_url); ?></code></p>
            <p><strong>Application Endpoint:</strong> <code><?php echo esc_html($apply_url); ?></code></p>
            <p><strong>Detected Board Page:</strong> <code><?php echo esc_html($board_url); ?></code></p>
            <p><strong>Public Job Listings Feed:</strong> <code><?php echo esc_html($public_feed_url); ?></code></p>
            <hr>
            <h2>Connection Test</h2>
            <?php $test = self::fetch_listings(true); ?>
            <p><strong>Resolved Feed URL:</strong> <code><?php echo esc_html(is_array($test) ? (string) ($test['url'] ?? '') : ''); ?></code></p>
            <?php if (is_array($test) && !empty($test['ok'])) : ?>
                <p style="color:#0a7f39;"><strong>Success.</strong> Jobs found: <?php echo (int) ($test['count'] ?? 0); ?> · HTTP <?php echo (int) ($test['status'] ?? 200); ?></p>
            <?php elseif (is_array($test)) : ?>
                <p style="color:#b32d2e;"><strong>Failed.</strong> <?php echo esc_html((string) ($test['error'] ?? 'Unknown error')); ?></p>
                <?php if (!empty($test['status'])) : ?><p><strong>HTTP:</strong> <?php echo (int) $test['status']; ?></p><?php endif; ?>
                <?php if (!empty($test['body'])) : ?><textarea class="large-text code" rows="8" readonly><?php echo esc_textarea((string) $test['body']); ?></textarea><?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function enqueue_assets() {
        wp_register_style('eejb-style', EEJB_URL . 'assets/style.css', [], EEJB_VERSION);
        wp_register_script('eejb-script', EEJB_URL . 'assets/script.js', ['jquery'], EEJB_VERSION, true);
        wp_enqueue_style('eejb-style');
        wp_enqueue_script('eejb-script');
    }

    public static function build_url($base, $path) {
        $base = rtrim((string) $base, '/');
        $path = '/' . ltrim((string) $path, '/');
        return $base . $path;
    }

    public static function api_headers() {
        return ['Accept' => 'application/json'];
    }

    public static function resolve_endpoint_url($type = 'feed') {
        $settings = self::get_settings();
        $full_key = $type === 'apply' ? 'apply_url' : 'feed_url';
        $path_key = $type === 'apply' ? 'apply_path' : 'feed_path';
        if (!empty($settings[$full_key])) {
            return (string) $settings[$full_key];
        }
        if (empty($settings['internal_base_url'])) {
            return '';
        }
        return self::build_url($settings['internal_base_url'], (string) ($settings[$path_key] ?? ''));
    }

    public static function fetch_listings($debug = false, $query_args = []) {
        $settings = self::get_settings();
        $url = self::resolve_endpoint_url('feed');
        if ($url === '') {
            return new WP_Error('eejb_missing_url', 'Internal feed URL is not configured.');
        }
        $per_page = isset($query_args['per_page']) ? max(1, (int) $query_args['per_page']) : (int) $settings['listings_per_page'];
        $args_q = ['per_page' => max(1, $per_page)];
        if (!empty($settings['api_token']) && strpos($url, 'token=') === false) { $args_q['token'] = $settings['api_token']; }
        $url = add_query_arg($args_q, $url);
        $response = wp_remote_get($url, [
            'headers' => self::api_headers(),
            'timeout' => 20,
        ]);
        if (is_wp_error($response)) {
            return $debug ? ['ok' => false, 'url' => $url, 'error' => $response->get_error_message()] : $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if ($code >= 400) {
            $message = self::extract_response_message($data, $body, 'Unable to fetch job listings.');
            return $debug ? ['ok' => false, 'url' => $url, 'status' => $code, 'body' => substr((string) $body, 0, 1000), 'error' => $message] : new WP_Error('eejb_feed_error', $message);
        }
        if (is_array($data) && isset($data['jobs']) && is_array($data['jobs'])) {
            $data = $data['jobs'];
        }
        if (!is_array($data)) {
            return $debug ? ['ok' => false, 'url' => $url, 'status' => $code, 'body' => substr((string) $body, 0, 1000), 'error' => 'The internal listings feed did not return valid JSON.'] : new WP_Error('eejb_invalid_feed', 'The internal listings feed did not return valid JSON.');
        }
        return $debug ? ['ok' => true, 'url' => $url, 'status' => $code, 'count' => count($data), 'body' => substr((string) $body, 0, 1000)] : $data;
    }

    public static function maybe_render_public_feed() {
        if (!isset($_GET['eejb_job_feed'])) {
            return;
        }

        $per_page = isset($_GET['per_page']) ? max(1, (int) wp_unslash($_GET['per_page'])) : 0;
        $payload = self::build_public_feed_payload_v2($per_page);
        if (is_wp_error($payload)) {
            wp_send_json([
                'ok' => false,
                'message' => $payload->get_error_message(),
            ], 502);
        }

        wp_send_json($payload, 200);
    }

    public static function filter_document_title($title) {
        $job_title = self::job_title_for_request_v2();
        return $job_title !== '' ? $job_title : $title;
    }

    public static function filter_document_title_parts($parts) {
        $job_title = self::job_title_for_request_v2();
        if ($job_title !== '') {
            $parts['title'] = $job_title;
        }
        return $parts;
    }

    public static function filter_single_post_title($title, $display = true) {
        $job_title = self::job_title_for_request_v2();
        return $job_title !== '' ? $job_title : $title;
    }

    public static function filter_the_title($title, $post_id = 0) {
        if (!self::should_filter_job_title_v2() || !in_the_loop() || !is_main_query()) {
            return $title;
        }

        $post = self::current_board_post_v2();
        if (!($post instanceof WP_Post) || (int) $post_id !== (int) $post->ID) {
            return $title;
        }

        $job_title = self::job_title_for_request_v2();
        return $job_title !== '' ? $job_title : $title;
    }

    public static function filter_seo_title_v2($title) {
        $share_meta = self::share_meta_for_request_v2();
        return !empty($share_meta['title']) ? $share_meta['title'] : $title;
    }

    public static function filter_seo_description_v2($description) {
        $share_meta = self::share_meta_for_request_v2();
        return !empty($share_meta['description']) ? $share_meta['description'] : $description;
    }

    public static function filter_seo_canonical_v2($url) {
        $share_meta = self::share_meta_for_request_v2();
        return !empty($share_meta['url']) ? $share_meta['url'] : $url;
    }

    public static function render_share_meta_v2() {
        $share_meta = self::share_meta_for_request_v2();
        if (empty($share_meta)) {
            return;
        }

        $title = (string) ($share_meta['title'] ?? '');
        $description = (string) ($share_meta['description'] ?? '');
        $url = (string) ($share_meta['url'] ?? '');
        $image_url = (string) ($share_meta['image_url'] ?? '');
        if ($image_url === '') {
            $image_url = self::share_image_url_v2();
        }
        $twitter_card = $image_url !== '' ? 'summary_large_image' : 'summary';
        $locale = str_replace('-', '_', (string) get_locale());

        echo "\n" . '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
        echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:locale" content="' . esc_attr($locale) . '">' . "\n";
        echo '<meta property="og:type" content="article">' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr((string) get_bloginfo('name')) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        if ($image_url !== '') {
            echo '<meta property="og:image" content="' . esc_url($image_url) . '">' . "\n";
        }
        echo '<meta name="twitter:card" content="' . esc_attr($twitter_card) . '">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($description) . '">' . "\n";
        if ($image_url !== '') {
            echo '<meta name="twitter:image" content="' . esc_url($image_url) . '">' . "\n";
        }
    }

    public static function render_job_board($atts = []) {
        return self::render_job_board_v2($atts);
        $listings = self::fetch_listings();
        ob_start();
        echo '<div class="eejb-wrap">';
        $settings = self::get_settings();
        if (!empty($settings['intro_text'])) {
            echo '<div class="eejb-intro">' . wpautop(wp_kses_post($settings['intro_text'])) . '</div>';
        }

        if (isset($_GET['eejb_status'])) {
            $status = sanitize_text_field(wp_unslash($_GET['eejb_status']));
            if ($status === 'success') {
                echo '<div class="eejb-notice success">Application submitted.</div>';
            } elseif ($status === 'error' && !empty($_GET['eejb_message'])) {
                echo '<div class="eejb-notice error">' . esc_html(wp_unslash($_GET['eejb_message'])) . '</div>';
            }
        }

        if (is_wp_error($listings)) {
            echo '<div class="eejb-notice error">' . esc_html($listings->get_error_message()) . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $selected_job_id = 0;
        if (isset($_GET['eum_apply_job'])) {
            $selected_job_id = (int) wp_unslash($_GET['eum_apply_job']);
        } elseif (isset($_GET['job_id'])) {
            $selected_job_id = (int) wp_unslash($_GET['job_id']);
        }

        echo '<div class="eum-public-job-board">';
        echo '<style>.eum-public-job-board{max-width:960px}.eum-public-job-board__heading{margin:0 0 18px}.eum-public-job-board__list{display:block}.eum-public-job-board__item{padding:0 0 18px;margin:0 0 18px;border-bottom:1px solid #dcdcde}.eum-public-job-board__item:last-child{margin-bottom:0}.eum-public-job-board__title{margin:0 0 4px;font-size:17px;line-height:1.25;font-weight:700}.eum-public-job-board__title a{color:#0b5ea8;text-decoration:none}.eum-public-job-board__title a:hover{text-decoration:underline}.eum-public-job-board__location{margin:0 0 8px;font-size:14px;line-height:1.35;color:#1f2937}.eum-public-job-board__excerpt{margin:0;font-size:15px;line-height:1.55;color:#1f2937}.eum-public-job-board__actions{margin-top:12px}.eum-public-job-board__actions a{color:#0b5ea8;text-decoration:none;font-weight:600}.eum-public-job-board__actions a:hover{text-decoration:underline}.eum-public-job-board__expanded{margin-top:14px;padding:16px;border:1px solid #dcdcde;border-radius:10px;background:#fff}.eum-public-job-board__expanded h5{margin:0 0 10px;font-size:16px}.eum-public-job-board__expanded-copy{margin:0 0 14px;color:#1f2937;line-height:1.6}</style>';
        echo '<h3 class="eum-public-job-board__heading">Open Positions</h3>';

        if (empty($listings)) {
            echo '<p>No jobs are currently available.</p>';
            echo '</div></div>';
            return ob_get_clean();
        }

        echo '<div class="eum-public-job-board__list">';
        foreach ($listings as $listing) {
            $job_id = (int) ($listing['id'] ?? 0);
            $title = (string) ($listing['title'] ?? 'Untitled Job');
            $description = (string) ($listing['description'] ?? '');
            $requirements = (string) ($listing['requirements'] ?? '');
            $summary = (string) ($listing['summary'] ?? '');
            $location = (string) ($listing['location'] ?? '');
            $division = (string) ($listing['division'] ?? '');
            $job_path = (string) ($listing['job_path'] ?? '');

            $excerpt_source = $summary !== '' ? $summary : ($description !== '' ? $description : $requirements);
            $excerpt = trim((string) wp_strip_all_tags($excerpt_source));
            if ($excerpt === '') {
                $excerpt = 'No description provided.';
            }
            $excerpt = wp_trim_words($excerpt, 70, '…');

            $open_url = add_query_arg(['eum_apply_job' => $job_id], get_permalink());
            $close_url = remove_query_arg(['eum_apply_job', 'job_id'], get_permalink());

            echo '<article class="eum-public-job-board__item">';
            echo '<h4 class="eum-public-job-board__title"><a href="' . esc_url($open_url) . '">' . esc_html($title) . '</a></h4>';
            if ($location !== '') {
                echo '<p class="eum-public-job-board__location">' . esc_html($location) . '</p>';
            }
            echo '<p class="eum-public-job-board__excerpt">' . esc_html($excerpt) . '</p>';
            echo '<p class="eum-public-job-board__actions"><a href="' . esc_url($open_url) . '">' . (($selected_job_id === $job_id) ? 'Viewing application details' : 'Apply') . '</a></p>';

            if ($selected_job_id === $job_id) {
                echo '<div class="eum-public-job-board__expanded">';
                echo '<h5>' . esc_html($title) . '</h5>';
                if ($location !== '') {
                    echo '<p class="eum-public-job-board__expanded-copy"><strong>Location:</strong> ' . esc_html($location) . '</p>';
                }
                if ($division !== '') {
                    echo '<p class="eum-public-job-board__expanded-copy"><strong>Division:</strong> ' . esc_html($division) . '</p>';
                }
                if ($job_path !== '') {
                    echo '<p class="eum-public-job-board__expanded-copy"><strong>Path:</strong> ' . esc_html($job_path) . '</p>';
                }
                if ($description !== '') {
                    echo '<div class="eum-public-job-board__expanded-copy">' . wp_kses_post(wpautop($description)) . '</div>';
                } elseif ($summary !== '') {
                    echo '<div class="eum-public-job-board__expanded-copy">' . wp_kses_post(wpautop($summary)) . '</div>';
                }
                if ($requirements !== '') {
                    echo '<div class="eum-public-job-board__expanded-copy"><strong>Requirements</strong>' . wp_kses_post(wpautop($requirements)) . '</div>';
                }
                echo self::render_application_form($listing);
                echo '<p class="eum-public-job-board__actions"><a href="' . esc_url($close_url) . '">Close</a></p>';
                echo '</div>';
            }
            echo '</article>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';
        return ob_get_clean();
    }

    public static function render_application_shortcode($atts = []) {
        $atts = shortcode_atts(['job_id' => ''], $atts, 'eum_external_job_application');
        $job = null;
        $listings = self::fetch_listings();
        if (is_wp_error($listings)) {
            return '<div class="eejb-notice error">' . esc_html($listings->get_error_message()) . '</div>';
        }
        foreach ($listings as $listing) {
            if ((string) ($listing['id'] ?? '') === (string) $atts['job_id']) {
                $job = $listing;
                break;
            }
        }
        if (!$job) {
            return '<div class="eejb-notice error">Job not found.</div>';
        }
        return self::render_application_form($job);
    }

    public static function render_application_form($job) {
        return self::render_application_form_v2($job);
        $job_id = (string) ($job['id'] ?? '');
        ob_start();
        ?>
        <div class="eejb-application">
            <form method="post" enctype="multipart/form-data" class="eejb-form">
                <?php wp_nonce_field(self::NONCE_ACTION, '_eejb_nonce'); ?>
                <input type="hidden" name="eejb_action" value="apply">
                <input type="hidden" name="job_id" value="<?php echo esc_attr($job_id); ?>">

                <div class="eejb-two-col">
                    <p><label>First Name *<br><input type="text" name="first_name" required></label></p>
                    <p><label>Last Name *<br><input type="text" name="last_name" required></label></p>
                </div>
                <div class="eejb-two-col">
                    <p><label>Middle Name<br><input type="text" name="middle_name"></label></p>
                    <p><label>Preferred Name<br><input type="text" name="preferred_name"></label></p>
                </div>
                <div class="eejb-two-col">
                    <p><label>Pronouns<br><input type="text" name="pronouns"></label></p>
                    <p><label>Personal Email *<br><input type="email" name="personal_email" required></label></p>
                </div>
                <div class="eejb-two-col">
                    <p><label>Phone Number *<br><input type="text" name="phone_number" required></label></p>
                    <p><label>City of Residence<br><input type="text" name="city"></label></p>
                </div>
                <div class="eejb-two-col">
                    <p><label>State / Province<br><input type="text" name="state"></label></p>
                    <p><label>LinkedIn<br><input type="url" name="linkedin"></label></p>
                </div>
                <p><label>Skills / Expertise<br><textarea name="skills" rows="3"></textarea></label></p>
                <p><label>Languages<br><input type="text" name="languages"></label></p>
                <p><label>Additional Information / Cover Note<br><textarea name="cover_note" rows="5"></textarea></label></p>

                <h3>Work History</h3>
                <input type="hidden" name="work_history_count" value="0">
                <div class="eejb-repeater" data-name="work_history"></div>
                <p><button type="button" class="button eejb-add-repeater" data-type="work">Add Work History</button></p>

                <h3>Education History</h3>
                <input type="hidden" name="education_history_count" value="0">
                <div class="eejb-repeater" data-name="education_history"></div>
                <p><button type="button" class="button eejb-add-repeater" data-type="education">Add Education</button></p>

                <div class="eejb-two-col">
                    <p><label>Resume<br><input type="file" name="resume"></label></p>
                    <p><label>Cover Letter<br><input type="file" name="cover_letter"></label></p>
                </div>

                <p><button class="button button-primary" type="submit">Submit Application</button></p>
            </form>
        </div>
        <template id="eejb-work-template">
            <div class="eejb-repeater-item">
                <div class="eejb-repeater-header"><strong>Work History Item</strong> <button type="button" class="button-link-delete eejb-remove-item">Remove</button></div>
                <div class="eejb-two-col">
                    <p><label>Job Title<br><input type="text" data-field="job_title"></label></p>
                    <p><label>Company<br><input type="text" data-field="company"></label></p>
                </div>
                <div class="eejb-two-col">
                    <p><label>Start Date<br><input type="date" data-field="start_date"></label></p>
                    <p><label>End Date<br><input type="date" data-field="end_date"></label></p>
                </div>
                <p><label>Responsibilities<br><textarea rows="4" data-field="responsibilities"></textarea></label></p>
            </div>
        </template>
        <template id="eejb-education-template">
            <div class="eejb-repeater-item">
                <div class="eejb-repeater-header"><strong>Education Item</strong> <button type="button" class="button-link-delete eejb-remove-item">Remove</button></div>
                <div class="eejb-two-col">
                    <p><label>School<br><input type="text" data-field="school"></label></p>
                    <p><label>Degree / Program<br><input type="text" data-field="degree"></label></p>
                </div>
                <div class="eejb-two-col">
                    <p><label>Field of Study<br><input type="text" data-field="field_of_study"></label></p>
                    <p><label>Details<br><input type="text" data-field="details"></label></p>
                </div>
                <div class="eejb-two-col">
                    <p><label>Start Date<br><input type="date" data-field="start_date"></label></p>
                    <p><label>End Date<br><input type="date" data-field="end_date"></label></p>
                </div>
            </div>
        </template>
        <?php
        return ob_get_clean();
    }

    protected static function prepare_file_payload($key) {
        if (empty($_FILES[$key]['tmp_name']) || !is_uploaded_file($_FILES[$key]['tmp_name'])) {
            return null;
        }
        $contents = file_get_contents($_FILES[$key]['tmp_name']);
        if ($contents === false) {
            return null;
        }
        $filename = sanitize_file_name((string) ($_FILES[$key]['name'] ?? 'upload'));
        if ($filename === '') {
            $filename = 'upload';
        }
        $type = sanitize_mime_type((string) ($_FILES[$key]['type'] ?? ''));
        if ($type === '') {
            $check = wp_check_filetype($filename);
            $type = (string) ($check['type'] ?? 'application/octet-stream');
        }
        return [
            'name' => $filename,
            'type' => $type ?: 'application/octet-stream',
            'contents_base64' => base64_encode($contents),
        ];
    }

    public static function maybe_handle_application() {
        self::maybe_handle_application_v2();
        return;
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }
        if (empty($_POST['eejb_action']) || $_POST['eejb_action'] !== 'apply') {
            return;
        }
        if (!isset($_POST['_eejb_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_eejb_nonce'])), self::NONCE_ACTION)) {
            self::redirect_with_status('error', 'Security check failed.');
        }

        $required = [
            'job_id' => 'Job is required.',
            'first_name' => 'First name is required.',
            'last_name' => 'Last name is required.',
            'personal_email' => 'Personal email is required.',
            'phone_number' => 'Phone number is required.',
        ];

        $payload = [];
        foreach ($required as $key => $message) {
            $value = isset($_POST[$key]) ? trim((string) wp_unslash($_POST[$key])) : '';
            if ($value === '') {
                self::redirect_with_status('error', $message);
            }
            $payload[$key] = $value;
        }

        $optional_fields = ['middle_name', 'preferred_name', 'pronouns', 'city', 'state', 'linkedin', 'skills', 'languages', 'cover_note'];
        foreach ($optional_fields as $field) {
            $payload[$field] = isset($_POST[$field]) ? trim((string) wp_unslash($_POST[$field])) : '';
        }

        $payload['application_source'] = 'external';
        $payload['work_history'] = self::parse_repeater('work_history', ['job_title', 'company', 'start_date', 'end_date', 'responsibilities']);
        $payload['education_history'] = self::parse_repeater('education_history', ['school', 'degree', 'field_of_study', 'details', 'start_date', 'end_date']);

        $settings = self::get_settings();
        $url = self::resolve_endpoint_url('apply');
        if ($url === '') {
            self::redirect_with_status('error', 'The external board is not configured yet.');
        }

        if (!empty($settings['api_token'])) {
            $payload['token'] = $settings['api_token'];
        }
        $payload['source'] = 'external';

        $resume_upload = self::prepare_file_payload('resume');
        if (is_array($resume_upload)) {
            $payload['resume_upload'] = $resume_upload;
        }
        $cover_upload = self::prepare_file_payload('cover_letter');
        if (is_array($cover_upload)) {
            $payload['cover_letter_upload'] = $cover_upload;
        }

        $args = [
            'method' => 'POST',
            'timeout' => 40,
            'headers' => self::api_headers(),
            'body' => wp_json_encode($payload),
        ];
        $args['headers']['Content-Type'] = 'application/json';
        if (!empty($settings['api_token'])) {
            $args['headers']['Authorization'] = 'Bearer ' . $settings['api_token'];
        }

        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            self::redirect_with_status('error', $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        if ($code >= 400) {
            $message = is_array($json) && !empty($json['message']) ? $json['message'] : 'Application failed.';
            self::redirect_with_status('error', $message);
        }

        self::redirect_with_status('success', 'Application submitted.');
    }

    protected static function parse_repeater($prefix, $fields) {
        $count = isset($_POST[$prefix . '_count']) ? (int) $_POST[$prefix . '_count'] : 0;
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $item = [];
            $has_value = false;
            foreach ($fields as $field) {
                $key = $prefix . '_' . $field . '_' . $i;
                $value = isset($_POST[$key]) ? trim((string) wp_unslash($_POST[$key])) : '';
                $item[$field] = $value;
                if ($value !== '') {
                    $has_value = true;
                }
            }
            if ($has_value) {
                $items[] = $item;
            }
        }
        return $items;
    }

    protected static function render_job_board_v2($atts = []) {
        $listings = self::cached_listings_v2();
        ob_start();
        echo '<div class="eejb-wrap">';
        $settings = self::get_settings();
        if (!empty($settings['intro_text'])) {
            echo '<div class="eejb-intro">' . wpautop(wp_kses_post($settings['intro_text'])) . '</div>';
        }
        echo self::render_status_notice();

        if (is_wp_error($listings)) {
            echo '<div class="eejb-notice error">' . esc_html($listings->get_error_message()) . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $job_map = self::build_job_map_v2($listings);

        if (empty($job_map)) {
            echo '<div class="eum-public-job-board"><p>No jobs are currently available.</p></div></div>';
            return ob_get_clean();
        }

        $current_job = self::current_job_request_v2($job_map);
        $selected_job_id = (int) ($current_job['job_id'] ?? 0);
        $selected_view = (string) ($current_job['view'] ?? 'detail');

        echo '<div class="eum-public-job-board">';
        echo '<style>.eum-public-job-board{max-width:1040px;margin:0 auto;color:#111827}.eum-public-job-board__hero,.eum-public-job-board__page,.eum-public-job-board__card{background:#fff;border:1px solid #dbe3ea;border-radius:18px;box-shadow:0 14px 34px rgba(15,23,42,.05)}.eum-public-job-board__hero{padding:28px 28px 22px;margin:0 0 18px;background:linear-gradient(180deg,#f7fbff 0%,#ffffff 100%)}.eum-public-job-board__eyebrow{margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#0f6cbd}.eum-public-job-board__heading{margin:0;font-size:32px;line-height:1.1}.eum-public-job-board__subheading{margin:12px 0 0;max-width:720px;font-size:16px;line-height:1.65;color:#475569}.eum-public-job-board__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px}.eum-public-job-board__card{padding:22px}.eum-public-job-board__title{margin:0 0 10px;font-size:22px;line-height:1.2}.eum-public-job-board__title a{color:#0f172a;text-decoration:none}.eum-public-job-board__title a:hover{color:#0f6cbd}.eum-public-job-board__meta{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px;padding:0;list-style:none}.eum-public-job-board__meta-item{padding:6px 10px;border-radius:999px;background:#f1f5f9;font-size:13px;line-height:1.3;color:#334155}.eum-public-job-board__excerpt{margin:0;color:#334155;line-height:1.7}.eum-public-job-board__actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}.eum-public-job-board__button,.eum-public-job-board__link{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:999px;text-decoration:none;font-weight:600}.eum-public-job-board__button{padding:11px 18px;background:#0f6cbd;color:#fff}.eum-public-job-board__button:hover{background:#0b5a9e;color:#fff}.eum-public-job-board__button--secondary{background:#fff;color:#0f172a;border:1px solid #cbd5e1}.eum-public-job-board__button--secondary:hover{background:#f8fafc;color:#0f172a}.eum-public-job-board__link{color:#0f6cbd}.eum-public-job-board__link:hover{text-decoration:underline}.eum-public-job-board__page{padding:28px}.eum-public-job-board__back{margin:0 0 16px}.eum-public-job-board__back a{color:#0f6cbd;text-decoration:none;font-weight:600}.eum-public-job-board__back a:hover{text-decoration:underline}.eum-public-job-board__section{margin-top:18px;padding:20px;border:1px solid #e2e8f0;border-radius:14px;background:#fff}.eum-public-job-board__section h4{margin:0 0 12px;font-size:18px}.eum-public-job-board__copy{color:#334155;line-height:1.7}.eum-public-job-board__copy p:first-child{margin-top:0}.eum-public-job-board__copy p:last-child{margin-bottom:0}.eum-public-job-board__summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:18px}.eum-public-job-board__summary-card{padding:14px 16px;border-radius:14px;background:#f8fafc;border:1px solid #e2e8f0}.eum-public-job-board__summary-label{display:block;margin-bottom:6px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748b}.eum-public-job-board__form-wrap{padding:18px;border:1px solid #dbe3ea;border-radius:16px;background:#f8f9fa}@media (max-width:720px){.eum-public-job-board__hero,.eum-public-job-board__page,.eum-public-job-board__card{padding:20px}.eum-public-job-board__heading{font-size:26px}}</style>';
        echo self::render_internal_compatible_styles_v2();

        if ($selected_job_id > 0) {
            $job = $job_map[$selected_job_id];
            $title = (string) ($job['title'] ?? 'Untitled Job');
            $detail_url = self::job_url_v2($selected_job_id, 'detail');
            $apply_url = self::job_url_v2($selected_job_id, 'apply');
            $content = self::job_detail_content_v2($job);
            $requirements = (string) ($job['requirements'] ?? '');
            echo '<p class="eum-public-job-board__back"><a href="' . esc_url(self::board_base_url_v2()) . '">&larr; Back to job opportunities</a></p>';
            echo '<section class="eum-public-job-board__page">';
            echo '<p class="eum-public-job-board__eyebrow">Job Opening</p>';
            echo '<h3 class="eum-public-job-board__heading">' . esc_html($title) . '</h3>';
            echo self::render_meta_list_v2($job);
            if ($selected_view === 'apply') {
                echo '<p class="eum-public-job-board__subheading">Complete the application below. You can return to the job details at any time before submitting.</p>';
                echo '<div class="eum-public-job-board__section"><h4>Application</h4><div class="eum-public-job-board__form-wrap">' . self::render_application_form_v2($job) . '</div></div>';
                echo '<div class="eum-public-job-board__actions"><a class="eum-public-job-board__button eum-public-job-board__button--secondary" href="' . esc_url($detail_url) . '">View Job Details</a><a class="eum-public-job-board__link" href="' . esc_url(self::board_base_url_v2()) . '">Return to All Openings</a></div>';
            } else {
                echo '<p class="eum-public-job-board__subheading">Review the role details below, then continue to the application when you are ready.</p>';
                echo self::render_summary_cards_v2($job);
                if ($content !== '') echo '<div class="eum-public-job-board__section"><h4>Job Details</h4><div class="eum-public-job-board__copy">' . self::format_job_content_v2($content) . '</div></div>';
                if ($requirements !== '' && strpos(wp_strip_all_tags($content), wp_strip_all_tags($requirements)) === false) echo '<div class="eum-public-job-board__section"><h4>Requirements</h4><div class="eum-public-job-board__copy">' . self::format_job_content_v2($requirements) . '</div></div>';
                echo '<div class="eum-public-job-board__actions"><a class="eum-public-job-board__button" href="' . esc_url($apply_url) . '">Continue to Application</a><a class="eum-public-job-board__button eum-public-job-board__button--secondary" href="' . esc_url(self::board_base_url_v2()) . '">Browse Other Roles</a></div>';
            }
            echo '</section>';
        } else {
            echo '<section class="eum-public-job-board__hero">';
            echo '<p class="eum-public-job-board__eyebrow">Career Opportunities</p>';
            echo '<h3 class="eum-public-job-board__heading">Job Opportunities</h3>';
            echo '<p class="eum-public-job-board__subheading">Choose a role to review the full posting before continuing to the application.</p>';
            echo '</section>';
            echo '<div class="eum-public-job-board__grid">';
            foreach ($job_map as $job_id => $job) {
                $title = (string) ($job['title'] ?? 'Untitled Job');
                $detail_url = self::job_url_v2($job_id, 'detail');
                echo '<article class="eum-public-job-board__card">';
                echo '<h4 class="eum-public-job-board__title"><a href="' . esc_url($detail_url) . '">' . esc_html($title) . '</a></h4>';
                echo self::render_meta_list_v2($job);
                echo '<p class="eum-public-job-board__excerpt">' . esc_html(self::job_excerpt_v2($job, 42)) . '</p>';
                echo '<div class="eum-public-job-board__actions"><a class="eum-public-job-board__button" href="' . esc_url($detail_url) . '">View Details</a></div>';
                echo '</article>';
            }
            echo '</div>';
        }

        echo '</div></div>';
        return ob_get_clean();
    }

    protected static function render_internal_compatible_styles_v2() {
        return <<<'HTML'
<style>
.eum-public-job-board { max-width: 960px; margin: 0 auto; color: #222; font-family: Arial, Helvetica, sans-serif; }
.eum-public-job-board .eum-public-job-board__hero,
.eum-public-job-board .eum-public-job-board__page,
.eum-public-job-board .eum-public-job-board__card { background: #fff; border: 0; border-radius: 0; box-shadow: none; }
.eum-public-job-board .eum-public-job-board__hero { padding: 0 0 18px; margin: 0 0 4px; border-bottom: 1px solid #ccc; }
.eum-public-job-board .eum-public-job-board__eyebrow { margin: 0 0 7px; color: #555; font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
.eum-public-job-board .eum-public-job-board__heading { margin: 0; color: #222; font-size: 28px; font-weight: 600; line-height: 1.25; }
.eum-public-job-board .eum-public-job-board__subheading { max-width: 700px; margin: 8px 0 0; color: #555; font-size: 16px; line-height: 1.55; }
.eum-public-job-board .eum-public-job-board__grid { display: grid; grid-template-columns: 1fr; gap: 0; }
.eum-public-job-board .eum-public-job-board__card { padding: 20px 0; border-bottom: 1px solid #ddd; }
.eum-public-job-board .eum-public-job-board__title { margin: 0 0 6px; font-size: 20px !important; font-weight: 600; line-height: 1.3; }
.eum-public-job-board .eum-public-job-board__title a { color: #8b0000; text-decoration: none; }
.eum-public-job-board .eum-public-job-board__title a:hover { color: #650000; text-decoration: underline; }
.eum-public-job-board .eum-public-job-board__meta { display: flex; flex-wrap: wrap; gap: 0; margin: 0 0 10px; padding: 0; list-style: none; }
.eum-public-job-board .eum-public-job-board__meta-item { padding: 0; border: 0; border-radius: 0; background: transparent; color: #555; font-size: 14px; line-height: 1.5; }
.eum-public-job-board .eum-public-job-board__meta-item + .eum-public-job-board__meta-item::before { content: "·"; margin: 0 8px; color: #888; }
.eum-public-job-board .eum-public-job-board__excerpt { margin: 0; color: #333; font-size: 15px; line-height: 1.6; }
.eum-public-job-board .eum-public-job-board__actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
.eum-public-job-board .eum-public-job-board__button { min-height: 0; padding: 9px 14px; border: 1px solid #8b0000; border-radius: 4px !important; background: #8b0000; box-shadow: none !important; color: #fff; font-weight: 600; text-decoration: none; }
.eum-public-job-board .eum-public-job-board__button:hover { border-color: #650000; background: #650000; color: #fff; }
.eum-public-job-board .eum-public-job-board__button--secondary { border-color: #aaa; background: #fff; color: #333; }
.eum-public-job-board .eum-public-job-board__button--secondary:hover { border-color: #666; background: #f5f5f5; color: #222; }
.eum-public-job-board .eum-public-job-board__link,
.eum-public-job-board .eum-public-job-board__back a { color: #8b0000; font-weight: 600; text-decoration: none; }
.eum-public-job-board .eum-public-job-board__link:hover,
.eum-public-job-board .eum-public-job-board__back a:hover { text-decoration: underline; }
.eum-public-job-board .eum-public-job-board__page { padding: 20px 0; border-top: 1px solid #ddd; }
.eum-public-job-board .eum-public-job-board__back { margin: 0 0 16px; }
.eum-public-job-board .eum-public-job-board__section { margin-top: 18px; padding: 18px 0; border: 0; border-top: 1px solid #ddd; border-radius: 0; background: transparent; }
.eum-public-job-board .eum-public-job-board__section h4 { margin: 0 0 10px; color: #222; font-size: 18px; }
.eum-public-job-board .eum-public-job-board__copy { color: #333; line-height: 1.65; }
.eum-public-job-board .eum-public-job-board__summary { display: flex; flex-wrap: wrap; gap: 24px; margin-top: 18px; padding: 14px 0; border-top: 1px solid #ddd; border-bottom: 1px solid #ddd; }
.eum-public-job-board .eum-public-job-board__summary-card { padding: 0; border: 0; border-radius: 0; background: transparent; color: #333; }
.eum-public-job-board .eum-public-job-board__summary-label { margin-bottom: 3px; color: #666; font-size: 11px; font-weight: 600; letter-spacing: .04em; }
.eum-public-job-board .eum-public-job-board__form-wrap { padding: 18px; border: 1px solid #ddd; border-radius: 4px; background: #f7f7f7; }
.eum-public-job-board .eum-public-job-board__notice { border-radius: 4px; box-shadow: none; }
.eejb-wrap > .eejb-notice { border-radius: 4px; box-shadow: none; }
.eum-public-job-board .eejb-application { padding: 0; border: 0; border-radius: 0; background: transparent; box-shadow: none; }
.eum-public-job-board .eejb-form { display: grid; gap: 14px; }
.eum-public-job-board .eejb-form > p { margin: 0; }
.eum-public-job-board .eejb-form h3 { margin: 18px 0 8px; font-size: 18px; }
.eum-public-job-board .eejb-two-col { grid-template-columns: 1fr; gap: 0; }
.eum-public-job-board .eejb-form input:not([type="checkbox"]):not([type="radio"]),
.eum-public-job-board .eejb-form select,
.eum-public-job-board .eejb-form textarea { width: 100%; min-height: 42px; border: 1px solid #cbd5e1 !important; border-radius: 12px !important; padding: 10px 12px !important; background: #fff; box-sizing: border-box; }
.eum-public-job-board .eejb-form textarea { min-height: 110px; }
.eum-public-job-board .eejb-repeater-item { border-radius: 4px; box-shadow: none; }
.eum-public-job-board .eejb-form .button,
.eum-public-job-board .eejb-form .button-primary { min-height: 0; padding: 9px 14px; border: 1px solid #8b0000; border-radius: 4px !important; background: #8b0000; box-shadow: none !important; color: #fff; font-weight: 600; }
.eum-public-job-board .eejb-form .button:hover,
.eum-public-job-board .eejb-form .button-primary:hover { border-color: #650000; background: #650000; color: #fff; }
@media (max-width: 720px) {
  .eum-public-job-board .eum-public-job-board__heading { font-size: 25px; }
  .eum-public-job-board .eum-public-job-board__summary { gap: 14px; }
}
</style>
HTML;
    }

    protected static function render_application_form_v2($job) {
        $job_id = (string) ($job['id'] ?? '');
        $application_fields = self::sanitize_application_fields_v2($job['application_fields'] ?? []);
        ob_start();
        ?>
        <div class="eejb-application">
            <form method="post" enctype="multipart/form-data" class="eejb-form">
                <?php wp_nonce_field(self::NONCE_ACTION, '_eejb_nonce'); ?>
                <input type="hidden" name="eejb_action" value="apply">
                <input type="hidden" name="job_id" value="<?php echo esc_attr($job_id); ?>">

                <div class="eejb-two-col">
                    <p><label>First Name *<br><input type="text" name="first_name" required></label></p>
                    <p><label>Last Name *<br><input type="text" name="last_name" required></label></p>
                </div>
                <div class="eejb-two-col">
                    <p><label>Middle Name<br><input type="text" name="middle_name"></label></p>
                    <p><label>Preferred Name<br><input type="text" name="preferred_name"></label></p>
                </div>
                <div class="eejb-two-col">
                    <p><label>Pronouns<br><input type="text" name="pronouns"></label></p>
                    <p><label>Personal Email *<br><input type="email" name="personal_email" required></label></p>
                </div>
                <div class="eejb-two-col">
                    <p><label>Phone Number *<br><input type="text" name="phone_number" required></label></p>
                    <p><label>City of Residence<br><input type="text" name="city"></label></p>
                </div>
                <div class="eejb-two-col">
                    <p><label>State / Province<br><input type="text" name="state"></label></p>
                    <p><label>LinkedIn<br><input type="url" name="linkedin"></label></p>
                </div>
                <p><label>Skills / Expertise<br><textarea name="skills" rows="3"></textarea></label></p>
                <p><label>Languages<br><input type="text" name="languages"></label></p>
                <p><label>Additional Information / Cover Note<br><textarea name="cover_note" rows="5"></textarea></label></p>

                <?php if (!empty($application_fields)) : ?>
                    <h3>Additional Job Questions</h3>
                    <?php foreach ($application_fields as $field) :
                        $field_key = sanitize_key((string) ($field['key'] ?? ''));
                        if ($field_key === '') continue;
                        $field_label = sanitize_text_field((string) ($field['label'] ?? $field_key));
                        $field_type = sanitize_key((string) ($field['type'] ?? 'text'));
                        if (!in_array($field_type, ['text', 'textarea', 'email', 'url', 'number', 'date'], true)) $field_type = 'text';
                        $field_placeholder = sanitize_text_field((string) ($field['placeholder'] ?? ''));
                        $field_help = sanitize_textarea_field((string) ($field['help_text'] ?? ''));
                        $field_required = !empty($field['required']);
                    ?>
                        <p>
                            <label><?php echo esc_html($field_label); ?><?php if ($field_required) : ?> *<?php endif; ?><br>
                                <?php if ($field_type === 'textarea') : ?>
                                    <textarea name="eum_application_responses[<?php echo esc_attr($field_key); ?>]" rows="4" <?php echo $field_required ? 'required' : ''; ?> placeholder="<?php echo esc_attr($field_placeholder); ?>"></textarea>
                                <?php else : ?>
                                    <input type="<?php echo esc_attr(in_array($field_type, ['email', 'url', 'number', 'date'], true) ? $field_type : 'text'); ?>" name="eum_application_responses[<?php echo esc_attr($field_key); ?>]" <?php echo $field_required ? 'required' : ''; ?> placeholder="<?php echo esc_attr($field_placeholder); ?>">
                                <?php endif; ?>
                            </label>
                            <?php if ($field_help !== '') : ?><span class="description" style="display:block;margin-top:6px;"><?php echo esc_html($field_help); ?></span><?php endif; ?>
                        </p>
                    <?php endforeach; ?>
                <?php endif; ?>

                <h3>Work History</h3>
                <input type="hidden" name="work_history_count" value="0">
                <div class="eejb-repeater" data-name="work_history"></div>
                <p><button type="button" class="button eejb-add-repeater" data-type="work">Add Work History</button></p>

                <h3>Education History</h3>
                <input type="hidden" name="education_history_count" value="0">
                <div class="eejb-repeater" data-name="education_history"></div>
                <p><button type="button" class="button eejb-add-repeater" data-type="education">Add Education</button></p>

                <div class="eejb-two-col">
                    <p><label>Resume<br><input type="file" name="resume"></label></p>
                    <p><label>Cover Letter<br><input type="file" name="cover_letter"></label></p>
                </div>

                <p><button class="button button-primary" type="submit">Submit Application</button></p>
            </form>
        </div>
        <template id="eejb-work-template">
            <div class="eejb-repeater-item">
                <div class="eejb-repeater-header"><strong>Work History Item</strong> <button type="button" class="button-link-delete eejb-remove-item">Remove</button></div>
                <div class="eejb-two-col">
                    <p><label>Job Title<br><input type="text" data-field="job_title"></label></p>
                    <p><label>Company<br><input type="text" data-field="company"></label></p>
                </div>
                <div class="eejb-two-col">
                    <p><label>Start Date<br><input type="date" data-field="start_date"></label></p>
                    <p><label>End Date<br><input type="date" data-field="end_date"></label></p>
                </div>
                <p><label>Responsibilities<br><textarea rows="4" data-field="responsibilities"></textarea></label></p>
            </div>
        </template>
        <template id="eejb-education-template">
            <div class="eejb-repeater-item">
                <div class="eejb-repeater-header"><strong>Education Item</strong> <button type="button" class="button-link-delete eejb-remove-item">Remove</button></div>
                <div class="eejb-two-col">
                    <p><label>School<br><input type="text" data-field="school"></label></p>
                    <p><label>Degree / Program<br><input type="text" data-field="degree"></label></p>
                </div>
                <div class="eejb-two-col">
                    <p><label>Field of Study<br><input type="text" data-field="field"></label></p>
                    <p><label>Details<br><input type="text" data-field="details"></label></p>
                </div>
                <div class="eejb-two-col">
                    <p><label>Start Date<br><input type="date" data-field="start_date"></label></p>
                    <p><label>End Date<br><input type="date" data-field="end_date"></label></p>
                </div>
            </div>
        </template>
        <?php
        return ob_get_clean();
    }

    protected static function maybe_handle_application_v2() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        if (empty($_POST['eejb_action']) || $_POST['eejb_action'] !== 'apply') return;
        if (!isset($_POST['_eejb_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_eejb_nonce'])), self::NONCE_ACTION)) {
            self::redirect_with_status_v2('error', 'Security check failed.');
        }

        $required = [
            'job_id' => 'Job is required.',
            'first_name' => 'First name is required.',
            'last_name' => 'Last name is required.',
            'personal_email' => 'Personal email is required.',
            'phone_number' => 'Phone number is required.',
        ];

        $payload = [];
        foreach ($required as $key => $message) {
            $value = isset($_POST[$key]) ? trim((string) wp_unslash($_POST[$key])) : '';
            if ($value === '') self::redirect_with_status_v2('error', $message);
            $payload[$key] = $value;
        }

        foreach (['middle_name', 'preferred_name', 'pronouns', 'city', 'state', 'linkedin', 'skills', 'languages', 'cover_note'] as $field) {
            $payload[$field] = isset($_POST[$field]) ? trim((string) wp_unslash($_POST[$field])) : '';
        }

        $payload['application_source'] = 'external';
        $payload['source'] = 'external';
        $payload['work_history'] = self::parse_repeater('work_history', ['job_title', 'company', 'start_date', 'end_date', 'responsibilities']);
        $payload['education_history'] = self::parse_repeater('education_history', ['school', 'degree', 'field', 'details', 'start_date', 'end_date']);
        $payload['eum_application_responses'] = self::sanitize_application_response_payload_v2($_POST['eum_application_responses'] ?? []);

        $settings = self::get_settings();
        $url = self::resolve_endpoint_url('apply');
        if ($url === '') self::redirect_with_status_v2('error', 'The external board is not configured yet.');

        if (!empty($settings['api_token'])) $payload['token'] = $settings['api_token'];

        $resume_upload = self::prepare_file_payload('resume');
        if (is_array($resume_upload)) $payload['resume_upload'] = $resume_upload;
        $cover_upload = self::prepare_file_payload('cover_letter');
        if (is_array($cover_upload)) $payload['cover_letter_upload'] = $cover_upload;

        $args = [
            'method' => 'POST',
            'timeout' => 40,
            'headers' => self::api_headers(),
            'body' => wp_json_encode($payload),
        ];
        $args['headers']['Content-Type'] = 'application/json';
        if (!empty($settings['api_token'])) $args['headers']['Authorization'] = 'Bearer ' . $settings['api_token'];

        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) self::redirect_with_status_v2('error', $response->get_error_message());

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        if ($code >= 400 || (is_array($json) && array_key_exists('ok', $json) && empty($json['ok']))) {
            self::redirect_with_status_v2('error', self::extract_response_message($json, $body, 'Application failed.'));
        }

        self::redirect_with_status_v2('success', 'Application submitted.');
    }

    protected static function sanitize_application_response_payload_v2($responses) {
        $responses = is_array($responses) ? $responses : [];
        $out = [];
        foreach ($responses as $key => $value) {
            $clean_key = sanitize_key((string) $key);
            if ($clean_key === '') continue;
            $out[$clean_key] = is_scalar($value) ? trim((string) wp_unslash($value)) : '';
        }
        return $out;
    }

    protected static function sanitize_application_fields_v2($fields) {
        $fields = is_array($fields) ? $fields : [];
        $out = [];
        foreach ($fields as $field) {
            if (!is_array($field)) continue;
            $key = sanitize_key((string) ($field['key'] ?? ''));
            $label = sanitize_text_field((string) ($field['label'] ?? ''));
            if ($key === '' || $label === '') continue;
            $type = sanitize_key((string) ($field['type'] ?? 'text'));
            if (!in_array($type, ['text', 'textarea', 'email', 'url', 'number', 'date'], true)) $type = 'text';
            $out[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => !empty($field['required']) ? 1 : 0,
                'placeholder' => sanitize_text_field((string) ($field['placeholder'] ?? '')),
                'help_text' => sanitize_textarea_field((string) ($field['help_text'] ?? '')),
            ];
        }
        return $out;
    }

    protected static function render_status_notice() {
        if (!isset($_GET['eejb_status'])) return '';
        $status = sanitize_text_field(wp_unslash($_GET['eejb_status']));
        if ($status === 'success') return '<div class="eejb-notice success">Application submitted.</div>';
        if ($status === 'error' && !empty($_GET['eejb_message'])) return '<div class="eejb-notice error">' . esc_html(wp_unslash($_GET['eejb_message'])) . '</div>';
        return '';
    }

    protected static function extract_response_message($json, $body, $default) {
        if (is_array($json)) {
            if (!empty($json['message'])) return sanitize_text_field((string) $json['message']);
            if (!empty($json['error'])) return sanitize_text_field((string) $json['error']);
        }
        $body_text = trim((string) wp_strip_all_tags((string) $body));
        if ($body_text !== '') return sanitize_text_field($body_text);
        return $default;
    }

    protected static function current_url_v2() {
        return (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? parse_url(home_url('/'), PHP_URL_HOST)) . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    protected static function board_base_url_v2() {
        return remove_query_arg(['job_id', 'eum_public_job', 'eum_public_view', 'eum_apply_job', 'eejb_status', 'eejb_message'], self::current_url_v2());
    }

    protected static function job_url_v2($job_id, $view = 'detail', $base_url = '') {
        if (!is_string($base_url) || $base_url === '') {
            $base_url = self::board_base_url_v2();
        }
        return add_query_arg([
            'eum_public_job' => (int) $job_id,
            'eum_public_view' => $view === 'apply' ? 'apply' : 'detail',
        ], $base_url);
    }

    protected static function redirect_with_status_v2($status, $message) {
        $base_url = wp_get_referer() ?: self::current_url_v2();
        if ($status === 'success') $base_url = remove_query_arg(['job_id', 'eum_public_job', 'eum_public_view', 'eum_apply_job'], $base_url);
        $url = add_query_arg([
            'eejb_status' => $status,
            'eejb_message' => $message,
        ], $base_url);
        wp_safe_redirect($url);
        exit;
    }

    protected static function job_excerpt_v2($job, $words = 42) {
        $excerpt = trim((string) ($job['short_description'] ?? ''));
        if ($excerpt === '') $excerpt = trim((string) ($job['summary'] ?? ''));
        if ($excerpt === '') $excerpt = trim((string) wp_strip_all_tags((string) ($job['description'] ?? '')));
        if ($excerpt === '') $excerpt = trim((string) wp_strip_all_tags((string) ($job['requirements'] ?? '')));
        if ($excerpt === '') $excerpt = 'No description provided.';
        return wp_trim_words($excerpt, $words, '...');
    }

    protected static function render_meta_list_v2($job) {
        $meta = [];
        foreach (['location', 'job_title'] as $key) {
            $value = trim((string) ($job[$key] ?? ''));
            if ($value !== '') $meta[] = $value;
        }
        if (empty($meta)) return '';
        $html = '<ul class="eum-public-job-board__meta">';
        foreach ($meta as $meta_item) $html .= '<li class="eum-public-job-board__meta-item">' . esc_html($meta_item) . '</li>';
        $html .= '</ul>';
        return $html;
    }

    protected static function render_summary_cards_v2($job) {
        $cards = [];
        $map = [
            'location' => 'Location',
            'job_title' => 'Job Title',
        ];
        foreach ($map as $key => $label) {
            $value = trim((string) ($job[$key] ?? ''));
            if ($value !== '') $cards[] = '<div class="eum-public-job-board__summary-card"><span class="eum-public-job-board__summary-label">' . esc_html($label) . '</span>' . esc_html($value) . '</div>';
        }
        if (empty($cards)) return '';
        return '<div class="eum-public-job-board__summary">' . implode('', $cards) . '</div>';
    }

    protected static function job_detail_content_v2($job) {
        $description = (string) ($job['description'] ?? '');
        if ($description !== '') return $description;
        return (string) ($job['requirements'] ?? '');
    }

    protected static function cached_listings_v2() {
        static $loaded = false;
        static $listings = null;
        if (!$loaded) {
            $loaded = true;
            $listings = self::fetch_listings();
        }
        return $listings;
    }

    protected static function build_job_map_v2($listings) {
        $job_map = [];
        foreach ((array) $listings as $listing) {
            if (!is_array($listing)) {
                continue;
            }
            $job_id = (int) ($listing['id'] ?? 0);
            if ($job_id > 0) {
                $job_map[$job_id] = $listing;
            }
        }
        return $job_map;
    }

    protected static function current_job_request_v2($job_map) {
        $selected_job_id = isset($_GET['eum_public_job']) ? (int) wp_unslash($_GET['eum_public_job']) : 0;
        if ($selected_job_id <= 0 && isset($_GET['eum_apply_job'])) $selected_job_id = (int) wp_unslash($_GET['eum_apply_job']);
        if ($selected_job_id <= 0 && isset($_GET['job_id'])) $selected_job_id = (int) wp_unslash($_GET['job_id']);
        $selected_view = isset($_GET['eum_public_view']) ? sanitize_key((string) wp_unslash($_GET['eum_public_view'])) : (($selected_job_id > 0 && isset($_GET['eum_apply_job'])) ? 'apply' : 'detail');
        if (!in_array($selected_view, ['detail', 'apply'], true)) {
            $selected_view = 'detail';
        }

        if ($selected_job_id <= 0 || !isset($job_map[$selected_job_id])) {
            return [
                'job_id' => 0,
                'view' => $selected_view,
                'job' => null,
            ];
        }

        return [
            'job_id' => $selected_job_id,
            'view' => $selected_view,
            'job' => $job_map[$selected_job_id],
        ];
    }

    protected static function request_has_selected_job_v2() {
        return isset($_GET['eum_public_job']) || isset($_GET['eum_apply_job']) || isset($_GET['job_id']);
    }

    protected static function current_board_post_v2() {
        if (!is_singular()) {
            return null;
        }

        $post = get_queried_object();
        if (!($post instanceof WP_Post)) {
            return null;
        }

        $content = (string) ($post->post_content ?? '');
        if ($content === '') {
            return null;
        }

        if (!has_shortcode($content, 'eum_external_job_board') && !has_shortcode($content, 'eum_external_job_application')) {
            return null;
        }

        return $post;
    }

    protected static function public_board_url_v2() {
        $post = self::current_board_post_v2();
        if ($post instanceof WP_Post) {
            $permalink = get_permalink($post);
            if (is_string($permalink) && $permalink !== '') {
                return $permalink;
            }
        }

        static $cached_url = null;
        if ($cached_url !== null) {
            return $cached_url;
        }

        global $wpdb;
        $query = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type IN ('page', 'post')
               AND (post_content LIKE %s OR post_content LIKE %s)
             ORDER BY CASE WHEN post_type = 'page' THEN 0 ELSE 1 END, menu_order ASC, post_date ASC
             LIMIT 1",
            '%[eum_external_job_board%',
            '%[eum_external_job_application%'
        );
        $post_id = (int) $wpdb->get_var($query);
        $cached_url = $post_id > 0 ? get_permalink($post_id) : home_url('/');
        if (!is_string($cached_url) || $cached_url === '') {
            $cached_url = home_url('/');
        }

        return $cached_url;
    }

    protected static function public_feed_url_v2() {
        return add_query_arg(['eejb_job_feed' => 1], home_url('/'));
    }

    protected static function should_filter_job_title_v2() {
        return !is_admin() && self::request_has_selected_job_v2() && (self::current_board_post_v2() instanceof WP_Post);
    }

    protected static function job_title_for_request_v2() {
        if (!self::should_filter_job_title_v2()) {
            return '';
        }

        $listings = self::cached_listings_v2();
        if (is_wp_error($listings)) {
            return '';
        }

        $job_map = self::build_job_map_v2($listings);
        $current_job = self::current_job_request_v2($job_map);
        if (empty($current_job['job']) || !is_array($current_job['job'])) {
            return '';
        }

        return trim((string) ($current_job['job']['title'] ?? ''));
    }

    protected static function share_meta_for_request_v2() {
        if (!self::should_filter_job_title_v2()) {
            return [];
        }

        $listings = self::cached_listings_v2();
        if (is_wp_error($listings)) {
            return [];
        }

        $job_map = self::build_job_map_v2($listings);
        $current_job = self::current_job_request_v2($job_map);
        if (empty($current_job['job']) || !is_array($current_job['job'])) {
            return [];
        }

        $job = $current_job['job'];
        $job_id = (int) ($current_job['job_id'] ?? 0);
        $view = (string) ($current_job['view'] ?? 'detail');
        $title = trim((string) ($job['title'] ?? ''));
        if ($title === '') {
            return [];
        }

        return [
            'title' => $title,
            'description' => self::job_share_description_v2($job),
            'url' => self::job_url_v2($job_id, $view, self::public_board_url_v2()),
            'image_url' => self::share_image_url_v2($job),
        ];
    }

    protected static function job_share_description_v2($job) {
        $sources = [
            (string) ($job['short_description'] ?? ''),
            (string) ($job['summary'] ?? ''),
            (string) ($job['description'] ?? ''),
            (string) ($job['requirements'] ?? ''),
        ];

        foreach ($sources as $source) {
            $clean = trim((string) wp_strip_all_tags($source));
            if ($clean !== '') {
                return wp_trim_words($clean, 32, '...');
            }
        }

        return 'View the full job details and application steps.';
    }

    protected static function share_image_url_v2($job = []) {
        if (is_array($job)) {
            foreach (['social_image_url', 'job_image_url', 'division_image_url'] as $key) {
                $image_url = esc_url_raw((string) ($job[$key] ?? ''));
                if ($image_url !== '') {
                    return $image_url;
                }
            }
        }

        $site_icon_id = (int) get_option('site_icon');
        if ($site_icon_id <= 0) {
            return '';
        }

        $image_url = wp_get_attachment_image_url($site_icon_id, 'full');
        return is_string($image_url) ? $image_url : '';
    }

    protected static function build_public_feed_payload_v2($per_page = 0) {
        $settings = self::get_settings();
        $resolved_per_page = $per_page > 0 ? $per_page : max(50, (int) ($settings['listings_per_page'] ?? 20));
        $listings = self::fetch_listings(false, ['per_page' => $resolved_per_page]);
        if (is_wp_error($listings)) {
            return $listings;
        }

        $board_url = self::public_board_url_v2();
        $jobs = [];
        foreach ((array) $listings as $listing) {
            if (!is_array($listing)) {
                continue;
            }

            $job_id = (int) ($listing['id'] ?? 0);
            if ($job_id <= 0) {
                continue;
            }

            $listing['detail_url'] = self::job_url_v2($job_id, 'detail', $board_url);
            $listing['apply_url'] = self::job_url_v2($job_id, 'apply', $board_url);
            $jobs[] = $listing;
        }

        return [
            'generated_at' => gmdate('c'),
            'site_name' => get_bloginfo('name'),
            'site_url' => home_url('/'),
            'board_url' => $board_url,
            'feed_url' => self::public_feed_url_v2(),
            'count' => count($jobs),
            'jobs' => array_values($jobs),
        ];
    }

    protected static function format_job_content_v2($content) {
        $content = (string) $content;
        if ($content === '') return '';
        $has_html = $content !== wp_strip_all_tags($content);
        return wp_kses_post($has_html ? $content : wpautop($content));
    }

    protected static function build_multipart_body($boundary, $payload, $file_keys) {
        $eol = "\r\n";
        $body = '';
        foreach ($payload as $name => $value) {
            $body .= '--' . $boundary . $eol;
            if (is_array($value)) {
                $value = wp_json_encode($value);
            }
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
            $body .= $value . $eol;
        }
        foreach ($file_keys as $key) {
            if (empty($_FILES[$key]['tmp_name']) || !is_uploaded_file($_FILES[$key]['tmp_name'])) {
                continue;
            }
            $filename = sanitize_file_name($_FILES[$key]['name']);
            $contents = file_get_contents($_FILES[$key]['tmp_name']);
            $type = !empty($_FILES[$key]['type']) ? $_FILES[$key]['type'] : 'application/octet-stream';
            $body .= '--' . $boundary . $eol;
            $body .= 'Content-Disposition: form-data; name="' . $key . '"; filename="' . $filename . '"' . $eol;
            $body .= 'Content-Type: ' . $type . $eol . $eol;
            $body .= $contents . $eol;
        }
        $body .= '--' . $boundary . '--' . $eol;
        return $body;
    }

    protected static function redirect_with_status($status, $message) {
        $base_url = wp_get_referer() ?: home_url('/');
        if ($status === 'success') {
            $base_url = remove_query_arg(['job_id'], $base_url);
        }
        $url = add_query_arg([
            'eejb_status' => $status,
            'eejb_message' => $message,
        ], $base_url);
        wp_safe_redirect($url);
        exit;
    }
}

EEJB_Plugin::init();
