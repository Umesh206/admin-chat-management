<?php
/**
 * Plugin Name: Admin Chat Management
 * Description: Customer-to-admin chat with mandatory name and email before starting a conversation.
 * Version: 1.4.0
 * Author: OpenAI
 */

if (!defined('ABSPATH')) exit;

class ACM_Admin_Chat {
    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'save_settings']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue']);
        add_shortcode('admin_chat', [$this, 'shortcode']);

        add_action('wp_ajax_acm_start_chat', [$this, 'start_chat']);
        add_action('wp_ajax_nopriv_acm_start_chat', [$this, 'start_chat']);
        add_action('wp_ajax_acm_send_message', [$this, 'send_message']);
        add_action('wp_ajax_nopriv_acm_send_message', [$this, 'send_message']);
        add_action('wp_ajax_acm_get_messages', [$this, 'get_messages']);
        add_action('wp_ajax_nopriv_acm_get_messages', [$this, 'get_messages']);
        add_action('wp_ajax_acm_admin_reply', [$this, 'admin_reply']);
        add_action('wp_ajax_acm_admin_load', [$this, 'admin_load']);
        add_action('wp_ajax_acm_admin_poll', [$this, 'admin_poll']);
        add_action('wp_ajax_acm_close_chat', [$this, 'close_chat']);
        add_action('wp_ajax_acm_delete_chat', [$this, 'delete_chat']);
    }

    public function register_cpt() {
        register_post_type('acm_chat', [
            'labels' => ['name' => 'Chats', 'singular_name' => 'Chat'],
            'public' => false,
            'show_ui' => false,
            'supports' => ['title'],
        ]);
    }

    public function admin_menu() {
        add_menu_page(
            'Admin Chat',
            'Admin Chat',
            'manage_options',
            'acm-admin-chat',
            [$this, 'admin_page'],
            'dashicons-format-chat',
            25
        );
    }

    public function enqueue() {
        wp_enqueue_style('acm-style', plugins_url('assets/chat.css', __FILE__), [], '1.0');
        wp_enqueue_script('acm-script', plugins_url('assets/chat.js', __FILE__), ['jquery'], '1.0', true);
        wp_localize_script('acm-script', 'ACM', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('acm_nonce')
        ]);
    }

    public function admin_enqueue($hook) {
        if ($hook !== 'toplevel_page_acm-admin-chat') return;
        wp_enqueue_style('acm-admin-style', plugins_url('assets/admin.css', __FILE__), [], '1.5.0');
        wp_enqueue_script('acm-admin-script', plugins_url('assets/admin.js', __FILE__), ['jquery'], '1.4.0', true);
        wp_localize_script('acm-admin-script', 'ACM_ADMIN', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('acm_nonce')
        ]);
    }

    private function get_settings() {
        $defaults = [
            'button_text' => 'Chat with Admin',
            'header_text' => 'Chat with Admin',
            'intro_text' => 'Please enter your details to continue.',
            'name_placeholder' => 'Name',
            'email_placeholder' => 'Email',
            'start_button_text' => 'Continue Chat',
            'message_placeholder' => 'Type your message...',
            'send_button_text' => 'Send',
        ];
        $saved = get_option('acm_text_settings', []);
        return wp_parse_args(is_array($saved) ? $saved : [], $defaults);
    }

    public function save_settings() {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if (empty($_POST['acm_save_text_settings'])) return;
        if (empty($_POST['acm_text_settings_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['acm_text_settings_nonce'])), 'acm_save_text_settings')) {
            return;
        }

        $fields = [
            'button_text',
            'header_text',
            'intro_text',
            'name_placeholder',
            'email_placeholder',
            'start_button_text',
            'message_placeholder',
            'send_button_text',
        ];
        $settings = [];
        foreach ($fields as $field) {
            $settings[$field] = sanitize_text_field(wp_unslash($_POST['acm_text_settings'][$field] ?? ''));
        }
        update_option('acm_text_settings', $settings);
    }

    public function shortcode() {
        $settings = $this->get_settings();
        ob_start(); ?>
        <div id="acm-chat-widget">
            <button id="acm-open">💬 <?php echo esc_html($settings['button_text']); ?></button>

            <div id="acm-box" style="display:none;">
                <div class="acm-header"><?php echo esc_html($settings['header_text']); ?> <button id="acm-close">×</button></div>

                <div id="acm-start">
                    <p class="acm-intro"><?php echo esc_html($settings['intro_text']); ?></p>
                    <input id="acm-name" type="text" placeholder="<?php echo esc_attr($settings['name_placeholder']); ?>">
                    <input id="acm-email" type="email" placeholder="<?php echo esc_attr($settings['email_placeholder']); ?>">
                    <div id="acm-start-error"></div>
                    <button id="acm-start-btn"><?php echo esc_html($settings['start_button_text']); ?></button>
                </div>

                <div id="acm-chat" style="display:none;">
                    <div id="acm-messages"></div>
                    <div class="acm-input-row">
                        <textarea id="acm-message" placeholder="<?php echo esc_attr($settings['message_placeholder']); ?>"></textarea>
                        <button id="acm-send"><?php echo esc_html($settings['send_button_text']); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function clean_email($email) {
        return sanitize_email($email);
    }

    public function start_chat() {
        check_ajax_referer('acm_nonce', 'nonce');

        $name = sanitize_text_field($_POST['name'] ?? '');
        $email = $this->clean_email($_POST['email'] ?? '');

        if (!$name || !$email || !is_email($email)) {
            wp_send_json_error(['message' => 'Please enter a valid name and email.']);
        }

        $token = wp_generate_uuid4();
        $chat_id = wp_insert_post([
            'post_type' => 'acm_chat',
            'post_status' => 'publish',
            'post_title' => $name . ' - ' . $email,
        ]);

        if (!$chat_id) wp_send_json_error(['message' => 'Unable to start chat.']);

        update_post_meta($chat_id, '_acm_name', $name);
        update_post_meta($chat_id, '_acm_email', $email);
        update_post_meta($chat_id, '_acm_token', $token);
        update_post_meta($chat_id, '_acm_status', 'open');
        update_post_meta($chat_id, '_acm_messages', []);
        update_post_meta($chat_id, '_acm_unread', 1);
        update_post_meta($chat_id, '_acm_message_version', 0);

        setcookie('acm_chat_token', $token, time() + DAY_IN_SECONDS * 30, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);

        wp_send_json_success(['chat_id' => $chat_id, 'token' => $token]);
    }

    private function find_chat($chat_id, $token) {
        $chat = get_post($chat_id);
        if (!$chat || $chat->post_type !== 'acm_chat') return false;
        $saved = get_post_meta($chat_id, '_acm_token', true);
        return $saved && hash_equals($saved, (string)$token) ? $chat_id : false;
    }

    public function send_message() {
        check_ajax_referer('acm_nonce', 'nonce');
        $chat_id = absint($_POST['chat_id'] ?? 0);
        $token = sanitize_text_field($_POST['token'] ?? '');
        $chat_id = $this->find_chat($chat_id, $token);
        if (!$chat_id) wp_send_json_error(['message' => 'Chat not found.']);

        $message = sanitize_textarea_field($_POST['message'] ?? '');
        if (!$message) wp_send_json_error(['message' => 'Please enter a message.']);

        $messages = get_post_meta($chat_id, '_acm_messages', true);
        if (!is_array($messages)) $messages = [];
        $messages[] = [
            'sender' => 'customer',
            'message' => $message,
            'time' => current_time('mysql')
        ];
        update_post_meta($chat_id, '_acm_messages', $messages);
        update_post_meta($chat_id, '_acm_unread', 1);
        $version = (int) get_post_meta($chat_id, '_acm_message_version', true) + 1;
        update_post_meta($chat_id, '_acm_message_version', $version);

        wp_send_json_success(['version' => $version]);
    }

    public function get_messages() {
        check_ajax_referer('acm_nonce', 'nonce');
        $chat_id = absint($_POST['chat_id'] ?? 0);
        $token = sanitize_text_field($_POST['token'] ?? '');
        $chat_id = $this->find_chat($chat_id, $token);
        if (!$chat_id) wp_send_json_error(['message' => 'Chat not found.']);

        wp_send_json_success([
            'messages' => get_post_meta($chat_id, '_acm_messages', true) ?: [],
            'status' => get_post_meta($chat_id, '_acm_status', true) ?: 'open'
        ]);
    }

    private function admin_check() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized.'], 403);
        }
        check_ajax_referer('acm_nonce', 'nonce');
    }

    public function admin_reply() {
        $this->admin_check();
        $chat_id = absint($_POST['chat_id'] ?? 0);
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        if (!$chat_id || !$message) wp_send_json_error(['message' => 'Missing data.']);

        $messages = get_post_meta($chat_id, '_acm_messages', true);
        if (!is_array($messages)) $messages = [];
        $messages[] = [
            'sender' => 'admin',
            'message' => $message,
            'time' => current_time('mysql')
        ];
        update_post_meta($chat_id, '_acm_messages', $messages);
        update_post_meta($chat_id, '_acm_unread', 0);
        $version = (int) get_post_meta($chat_id, '_acm_message_version', true) + 1;
        update_post_meta($chat_id, '_acm_message_version', $version);
        wp_send_json_success(['messages' => $messages, 'version' => $version]);
    }

    public function close_chat() {
        $this->admin_check();
        $chat_id = absint($_POST['chat_id'] ?? 0);
        if (!$chat_id || get_post_type($chat_id) !== 'acm_chat') {
            wp_send_json_error(['message' => 'Invalid chat.']);
        }
        update_post_meta($chat_id, '_acm_status', 'closed');
        wp_send_json_success();
    }

    public function delete_chat() {
        $this->admin_check();
        $chat_id = absint($_POST['chat_id'] ?? 0);
        if (!$chat_id || get_post_type($chat_id) !== 'acm_chat') {
            wp_send_json_error(['message' => 'Invalid chat.']);
        }
        $deleted = wp_delete_post($chat_id, true);
        if (!$deleted) {
            wp_send_json_error(['message' => 'Unable to permanently delete this chat.']);
        }
        wp_send_json_success(['deleted' => $chat_id]);
    }

    public function admin_load() {
        $this->admin_check();
        $chat_id = absint($_POST['chat_id'] ?? 0);
        if (!$chat_id) wp_send_json_error(['message' => 'Invalid chat.']);
        update_post_meta($chat_id, '_acm_unread', 0);
        wp_send_json_success([
            'name' => get_post_meta($chat_id, '_acm_name', true),
            'email' => get_post_meta($chat_id, '_acm_email', true),
            'status' => get_post_meta($chat_id, '_acm_status', true) ?: 'open',
            'messages' => get_post_meta($chat_id, '_acm_messages', true) ?: [],
            'version' => (int) get_post_meta($chat_id, '_acm_message_version', true)
        ]);
    }

    public function admin_poll() {
        $this->admin_check();

        $current_id = absint($_POST['current_chat_id'] ?? 0);
        $chats = get_posts([
            'post_type' => 'acm_chat',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);

        $items = [];
        foreach ($chats as $chat) {
            $items[] = [
                'id' => $chat->ID,
                'name' => get_post_meta($chat->ID, '_acm_name', true),
                'email' => get_post_meta($chat->ID, '_acm_email', true),
                'status' => get_post_meta($chat->ID, '_acm_status', true) ?: 'open',
                'unread' => (bool) get_post_meta($chat->ID, '_acm_unread', true),
                'version' => (int) get_post_meta($chat->ID, '_acm_message_version', true),
                'date' => $chat->post_date
            ];
        }

        $active = null;
        if ($current_id && get_post_type($current_id) === 'acm_chat') {
            $active = [
                'id' => $current_id,
                'version' => (int) get_post_meta($current_id, '_acm_message_version', true),
                'messages' => get_post_meta($current_id, '_acm_messages', true) ?: [],
                'status' => get_post_meta($current_id, '_acm_status', true) ?: 'open'
            ];
        }

        wp_send_json_success(['chats' => $items, 'active' => $active]);
    }

    public function admin_page() {
        $chats = get_posts([
            'post_type' => 'acm_chat',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);
        $settings = $this->get_settings();
        ?>
        <div class="wrap acm-admin-wrap">
            <h1>Admin Chat Management</h1>

            <?php if (!empty($_POST['acm_save_text_settings'])): ?>
                <div class="notice notice-success is-dismissible"><p>Chat text settings saved.</p></div>
            <?php endif; ?>

            <div class="card" style="max-width:900px;padding:20px;margin:20px 0;">
                <h2 style="margin-top:0;">Chat Widget Text</h2>
                <p>Change the text shown on the website without editing the plugin code.</p>
                <form method="post">
                    <?php wp_nonce_field('acm_save_text_settings', 'acm_text_settings_nonce'); ?>
                    <table class="form-table" role="presentation">
                        <tr><th scope="row"><label for="acm-button-text">Chat Button Text</label></th>
                            <td><input id="acm-button-text" class="regular-text" type="text" name="acm_text_settings[button_text]" value="<?php echo esc_attr($settings['button_text']); ?>"></td></tr>
                        <tr><th scope="row"><label for="acm-header-text">Chat Header Text</label></th>
                            <td><input id="acm-header-text" class="regular-text" type="text" name="acm_text_settings[header_text]" value="<?php echo esc_attr($settings['header_text']); ?>"></td></tr>
                        <tr><th scope="row"><label for="acm-intro-text">Intro Text</label></th>
                            <td><input id="acm-intro-text" class="regular-text" type="text" name="acm_text_settings[intro_text]" value="<?php echo esc_attr($settings['intro_text']); ?>"></td></tr>
                        <tr><th scope="row"><label for="acm-name-placeholder">Name Placeholder</label></th>
                            <td><input id="acm-name-placeholder" class="regular-text" type="text" name="acm_text_settings[name_placeholder]" value="<?php echo esc_attr($settings['name_placeholder']); ?>"></td></tr>
                        <tr><th scope="row"><label for="acm-email-placeholder">Email Placeholder</label></th>
                            <td><input id="acm-email-placeholder" class="regular-text" type="text" name="acm_text_settings[email_placeholder]" value="<?php echo esc_attr($settings['email_placeholder']); ?>"></td></tr>
                        <tr><th scope="row"><label for="acm-start-button">Continue Button Text</label></th>
                            <td><input id="acm-start-button" class="regular-text" type="text" name="acm_text_settings[start_button_text]" value="<?php echo esc_attr($settings['start_button_text']); ?>"></td></tr>
                        <tr><th scope="row"><label for="acm-message-placeholder">Message Placeholder</label></th>
                            <td><input id="acm-message-placeholder" class="regular-text" type="text" name="acm_text_settings[message_placeholder]" value="<?php echo esc_attr($settings['message_placeholder']); ?>"></td></tr>
                        <tr><th scope="row"><label for="acm-send-button">Send Button Text</label></th>
                            <td><input id="acm-send-button" class="regular-text" type="text" name="acm_text_settings[send_button_text]" value="<?php echo esc_attr($settings['send_button_text']); ?>"></td></tr>
                    </table>
                    <p><button type="submit" name="acm_save_text_settings" value="1" class="button button-primary">Save Text Settings</button></p>
                </form>
            </div>

            <div class="acm-admin-layout">
                <div class="acm-chat-list">
                    <?php if (!$chats): ?>
                        <p>No conversations yet.</p>
                    <?php endif; ?>
                    <?php foreach ($chats as $chat):
                        $name = get_post_meta($chat->ID, '_acm_name', true);
                        $email = get_post_meta($chat->ID, '_acm_email', true);
                        $status = get_post_meta($chat->ID, '_acm_status', true) ?: 'open';
                        $unread = get_post_meta($chat->ID, '_acm_unread', true);
                    ?>
                        <div class="acm-chat-row" data-id="<?php echo esc_attr($chat->ID); ?>">
                            <button class="acm-chat-item" data-id="<?php echo esc_attr($chat->ID); ?>" type="button">
                                <strong><?php echo esc_html($name); ?></strong>
                                <span><?php echo esc_html($email); ?></span>
                                <small><?php echo esc_html(ucfirst($status)); ?><?php echo $unread ? ' • New' : ''; ?></small>
                            </button>
                            <div class="acm-chat-actions">
                                <button class="acm-delete-user-chat" type="button" data-id="<?php echo esc_attr($chat->ID); ?>" title="Delete user and chat permanently">Delete User &amp; Chat</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="acm-admin-conversation">
                    <div id="acm-admin-empty">Select a conversation.</div>
                    <div id="acm-admin-active" style="display:none;">
                        <div id="acm-admin-customer"></div>
                        <div id="acm-admin-messages"></div>
                        <div class="acm-admin-reply">
                            <textarea id="acm-admin-message" placeholder="Type your reply..."></textarea>
                            <button class="button button-primary" id="acm-admin-send">Send Reply</button>
                            <button class="button" id="acm-admin-close">Close Chat</button>
                            <button class="button button-link-delete" id="acm-admin-delete">Delete Permanently</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

new ACM_Admin_Chat();
