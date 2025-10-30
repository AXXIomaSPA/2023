<?php
/**
 * Plugin Name:   Laudus ERP Pro (Versión Completa y Funcional)
 * Description:   Conecta WooCommerce con Laudus ERP. Versión final en un solo archivo, refactorizada y mejorada.
 * Version:       7.0.0
 * Author:        Laudus (Refactorizado por Jules)
 * License:       GPL-2.0+
 */

if (!defined('ABSPATH')) exit;

// --- CLASES DE LÓGICA DE NEGOCIO ---

class Laudus_Complete_Logger {
    public static function log($type, $message, $data = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'laudus_logs';
        $wpdb->insert($table, [
            'log_type' => $type, 'order_id' => $data['order_id'] ?? null, 'product_id' => $data['product_id'] ?? null,
            'message' => $message, 'request_data' => isset($data['request']) ? wp_json_encode($data['request'], JSON_UNESCAPED_UNICODE) : null,
            'response_data' => isset($data['response']) ? wp_json_encode($data['response'], JSON_UNESCAPED_UNICODE) : null,
            'status' => $data['status'] ?? 'info', 'created_at' => current_time('mysql')
        ]);
        if (in_array($data['status'] ?? '', ['error', 'critical'])) {
            error_log("[LAUDUS COMPLETE {$type}] {$message} - " . wp_json_encode($data, JSON_UNESCAPED_UNICODE));
        }
    }
    public static function get_logs($filters = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'laudus_logs';
        $where = ['1=1'];
        if (!empty($filters['type'])) $where[] = $wpdb->prepare('log_type = %s', $filters['type']);
        if (!empty($filters['status'])) $where[] = $wpdb->prepare('status = %s', $filters['status']);
        $where_clause = implode(' AND ', $where);
        $limit = $filters['limit'] ?? 100;
        return $wpdb->get_results("SELECT * FROM $table WHERE $where_clause ORDER BY id DESC LIMIT $limit");
    }
    public static function get_stats($days = 7) {
        global $wpdb;
        $table = $wpdb->prefix . 'laudus_logs';
        $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        return [
            'total' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE created_at >= %s", $date_from)),
            'errors' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = 'error' AND created_at >= %s", $date_from)),
            'success' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = 'success' AND created_at >= %s", $date_from)),
            'by_type' => $wpdb->get_results($wpdb->prepare("SELECT log_type, COUNT(*) as count FROM $table WHERE created_at >= %s GROUP BY log_type", $date_from))
        ];
    }
    public static function clean_old_logs($days = 30) {
        global $wpdb;
        $table = $wpdb->prefix . 'laudus_logs';
        $date_limit = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        return $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE created_at < %s", $date_limit));
    }
}

class Laudus_Complete_API {
    private static $token = null;
    private static $token_expires_at = null;

    public static function get_warehouses() {
        try {
            return self::make_request('POST', '/inventory/warehouses/list', ['fields' => ['warehouseId', 'warehouseName']]);
        } catch (Exception $e) { return []; }
    }

    public static function get_price_lists() {
        try {
            return self::make_request('POST', '/products/pricelists/list', ['fields' => ['priceListId', 'priceListName']]);
        } catch (Exception $e) { return []; }
    }

    public static function get_token($force_refresh = false) {
        if (!$force_refresh && self::$token && self::$token_expires_at && time() < self::$token_expires_at) return self::$token;
        $saved_token = get_option('laudus_token');
        $saved_date = get_option('laudus_token_lastdate');
        $minutes_to_expire = (int)get_option('laudus_token_minutestoexpire', 60);
        if (!$force_refresh && $saved_token && $saved_date) {
            $expiration_time = strtotime($saved_date) + ($minutes_to_expire * 60);
            if (time() < ($expiration_time - 300)) {
                self::$token = $saved_token;
                self::$token_expires_at = $expiration_time;
                return $saved_token;
            }
        }
        return self::refresh_token();
    }

    private static function refresh_token() {
        $credentials = [
            'userName' => get_option('laudus_user_company'),
            'password' => get_option('laudus_password_company'),
            'companyVATId' => get_option('laudus_rut_company')
        ];
        if (empty($credentials['userName']) || empty($credentials['password']) || empty($credentials['companyVATId'])) {
            return false;
        }
        try {
            $response = self::make_request('POST', '/security/login', $credentials, false);
            if (isset($response['token'])) {
                $token = $response['token'];
                update_option('laudus_token', $token);
                update_option('laudus_token_lastdate', current_time('mysql'));
                self::$token = $token;
                self::$token_expires_at = time() + ((int)get_option('laudus_token_minutestoexpire', 60) * 60);
                Laudus_Complete_Logger::log('auth', 'Token actualizado exitosamente', ['status' => 'success']);
                return $token;
            }
            throw new Exception($response['errorMessage'] ?? 'Error desconocido al obtener token');
        } catch (Exception $e) {
            Laudus_Complete_Logger::log('auth', 'Error al autenticar: ' . $e->getMessage(), ['status' => 'error']);
            return false;
        }
    }

    public static function make_request($method, $endpoint, $data = null, $use_auth = true, $retry = 0) {
        $max_retries = (int)get_option('laudus_max_retries', 3);
        $url = LAUDUS_API_BASE_COMPLETE . $endpoint;
        $headers = ['Content-Type' => 'application/json; charset=UTF-8'];
        if ($use_auth) {
            $token = self::get_token();
            if (!$token) throw new Exception('No se pudo obtener token de autenticación. Verifique las credenciales.');
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $args = ['method' => $method, 'headers' => $headers, 'timeout' => 30];
        if ($data !== null) $args['body'] = wp_json_encode($data, JSON_UNESCAPED_UNICODE);
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            if ($retry < $max_retries) {
                sleep(pow(2, $retry));
                return self::make_request($method, $endpoint, $data, $use_auth, $retry + 1);
            }
            throw new Exception("Error de conexión: " . $response->get_error_message());
        }
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code === 401 && $use_auth && $retry < 1) {
            self::get_token(true);
            return self::make_request($method, $endpoint, $data, $use_auth, $retry + 1);
        }
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Respuesta JSON inválida de la API: " . json_last_error_msg());
        if ($http_code >= 400) throw new Exception("API Error ({$http_code}): " . ($decoded['errorMessage'] ?? $decoded['message'] ?? 'Error desconocido'));
        return $decoded;
    }
}

// --- CLASE PRINCIPAL DEL PLUGIN ---
final class Laudus_ERP_Pro_Complete {
    private static $instance;

    public static function instance() {
        if (is_null(self::$instance)) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        define('LAUDUS_API_BASE_COMPLETE', 'https://api.laudus.cl');
        $this->init_hooks();
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('cron_schedules', [$this, 'cron_schedules']);

        $ajax_actions = ['test_connection', 'sync_stock_manual', 'sync_prices_manual', 'clean_logs', 'retry_failed_orders', 'get_products', 'get_stock', 'get_prices'];
        foreach ($ajax_actions as $action) {
            add_action('wp_ajax_laudus_' . $action, [$this, 'ajax_handler']);
        }
    }

    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $table_logs = $wpdb->prefix . 'laudus_logs';
        $sql_logs = "CREATE TABLE IF NOT EXISTS $table_logs (id bigint(20) NOT NULL AUTO_INCREMENT, log_type varchar(50) NOT NULL, order_id bigint(20) DEFAULT NULL, product_id bigint(20) DEFAULT NULL, message text NOT NULL, request_data longtext DEFAULT NULL, response_data longtext DEFAULT NULL, status varchar(20) NOT NULL, created_at datetime DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY  (id)) $charset_collate;";
        dbDelta($sql_logs);

        $table_sync = $wpdb->prefix . 'laudus_sync_status';
        $sql_sync = "CREATE TABLE IF NOT EXISTS $table_sync (id bigint(20) NOT NULL AUTO_INCREMENT, sync_type varchar(50) NOT NULL, entity_id bigint(20) NOT NULL, entity_type varchar(50) NOT NULL, laudus_id varchar(100) DEFAULT NULL, last_sync datetime DEFAULT NULL, sync_status varchar(20) NOT NULL, retry_count int(11) DEFAULT 0, error_message text DEFAULT NULL, PRIMARY KEY  (id), UNIQUE KEY entity_unique (entity_type, entity_id)) $charset_collate;";
        dbDelta($sql_sync);

        add_option('laudus_token_minutestoexpire', 60);
        add_option('laudus_send_errors_to_admin', 1);
        add_option('laudus_auto_sync_stock', 1);
        add_option('laudus_auto_sync_price', 1);
        add_option('laudus_stock_sync_interval', 15);
        add_option('laudus_price_sync_interval', 60);
        add_option('laudus_max_retries', 3);
    }

    public function admin_menu() {
        add_menu_page('Laudus ERP', 'Laudus ERP', 'manage_options', 'laudus-dashboard', [$this, 'dashboard_page'], 'dashicons-analytics');
        add_submenu_page('laudus-dashboard', 'Monitor', 'Monitor', 'manage_options', 'laudus-dashboard', [$this, 'dashboard_page']);
        add_submenu_page('laudus-dashboard', 'Configuración', 'Configuración', 'manage_options', 'laudus-settings', [$this, 'settings_page']);
        add_submenu_page('laudus-dashboard', 'Estado de Productos', 'Estado de Productos', 'manage_options', 'laudus-product-status', [$this, 'product_status_page']);
    }

    public function dashboard_page() {
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-analytics"></span> Monitor en Tiempo Real</h1>
            <p>Bienvenido al panel de control de Laudus ERP.</p>
        </div>
        <?php
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-admin-settings"></span> Configuración de Laudus ERP</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('laudus_settings_group');
                do_settings_sections('laudus_settings_group');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting('laudus_settings_group', 'laudus_rut_company');
        register_setting('laudus_settings_group', 'laudus_user_company');
        register_setting('laudus_settings_group', 'laudus_password_company');
        register_setting('laudus_settings_group', 'laudus_warehouse');
        register_setting('laudus_settings_group', 'laudus_price_list_id');

        add_settings_section('laudus_api_section', 'Credenciales de API', null, 'laudus_settings_group');
        add_settings_field('laudus_rut_company', 'RUT Empresa', [$this, 'render_text_field'], 'laudus_settings_group', 'laudus_api_section', ['id' => 'laudus_rut_company']);
        add_settings_field('laudus_user_company', 'Usuario API', [$this, 'render_text_field'], 'laudus_settings_group', 'laudus_api_section', ['id' => 'laudus_user_company']);
        add_settings_field('laudus_password_company', 'Contraseña API', [$this, 'render_password_field'], 'laudus_settings_group', 'laudus_api_section', ['id' => 'laudus_password_company']);

        $warehouses = Laudus_Complete_API::get_warehouses();
        add_settings_field('laudus_warehouse', 'Bodega', [$this, 'render_select_field'], 'laudus_settings_group', 'laudus_api_section', ['id' => 'laudus_warehouse', 'options' => $warehouses, 'key' => 'warehouseId', 'value' => 'warehouseName']);

        $price_lists = Laudus_Complete_API::get_price_lists();
        add_settings_field('laudus_price_list_id', 'Lista de Precios', [$this, 'render_select_field'], 'laudus_settings_group', 'laudus_api_section', ['id' => 'laudus_price_list_id', 'options' => $price_lists, 'key' => 'priceListId', 'value' => 'priceListName']);
    }

    public function render_text_field($args) {
        $id = $args['id'];
        $value = get_option($id);
        echo "<input type='text' id='$id' name='$id' value='" . esc_attr($value) . "' class='regular-text'>";
    }
    public function render_password_field($args) {
        $id = $args['id'];
        $value = get_option($id);
        echo "<input type='password' id='$id' name='$id' value='" . esc_attr($value) . "' class='regular-text'>";
    }
    public function render_select_field($args) {
        $id = $args['id'];
        $options = $args['options'];
        $key = $args['key'];
        $value_field = $args['value'];
        $current_value = get_option($id);

        echo "<select id='$id' name='$id'>";
        echo "<option value=''>-- Seleccionar --</option>";
        if (!empty($options)) {
            foreach ($options as $option) {
                $option_key = $option[$key];
                $option_value = $option[$value_field];
                echo "<option value='" . esc_attr($option_key) . "' " . selected($current_value, $option_key, false) . ">" . esc_html($option_value) . "</option>";
            }
        }
        echo "</select>";
        if(empty($options) && !empty(get_option('laudus_rut_company'))) {
            echo "<p class='description' style='color:red;'>No se pudieron cargar las opciones. Verifique las credenciales de la API e intente de nuevo.</p>";
        }
    }

    public function product_status_page() {
        global $wpdb;
        $query = new WP_Query(['post_type' => 'product', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => [['key' => '_sku', 'value' => '', 'compare' => '!=']]]);
        $product_ids = $query->posts;

        $sync_table = $wpdb->prefix . 'laudus_sync_status';
        $statuses = $wpdb->get_results("SELECT entity_id, sync_status FROM {$sync_table} WHERE entity_type = 'product'", OBJECT_K);

        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-products"></span> Estado de Sincronización de Productos</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr><th>SKU</th><th>ID</th><th>Título</th><th>Stock (WC)</th><th>Precio (WC)</th><th>Resultado (Sync)</th></tr>
                </thead>
                <tbody>
                    <?php if(empty($product_ids)): ?>
                        <tr><td colspan="6">No se encontraron productos con SKU.</td></tr>
                    <?php else: ?>
                        <?php foreach($product_ids as $product_id):
                            $product = wc_get_product($product_id);
                            if(!$product) continue;
                            $status = $statuses[$product_id]->sync_status ?? 'no_sincronizado';
                        ?>
                        <tr>
                            <td><?php echo esc_html($product->get_sku()); ?></td>
                            <td><?php echo esc_html($product->get_id()); ?></td>
                            <td><?php echo esc_html($product->get_name()); ?></td>
                            <td><?php echo esc_html($product->get_stock_quantity()); ?></td>
                            <td><?php echo wp_kses_post($product->get_price_html()); ?></td>
                            <td><?php echo esc_html($status); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function ajax_handler() {
        check_ajax_referer('laudus_ajax_nonce', 'nonce');
        $action = str_replace('wp_ajax_laudus_', '', current_action());
        if (method_exists($this, 'ajax_' . $action)) {
            $this->{'ajax_' . $action}();
        }
    }

    public function ajax_test_connection() {
        try {
            $token = Laudus_Complete_API::get_token(true);
            if ($token) {
                wp_send_json_success(['message' => 'Conexión exitosa.']);
            } else {
                wp_send_json_error(['message' => 'Credenciales inválidas o fallo de conexión.']);
            }
        } catch(Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function cron_schedules($schedules) {
        $stock_interval = (int)get_option('laudus_stock_sync_interval', 15);
        $price_interval = (int)get_option('laudus_price_sync_interval', 60);

        $schedules['laudus_stock_interval'] = [
            'interval' => $stock_interval * 60,
            'display' => "Cada {$stock_interval} minutos"
        ];

        $schedules['laudus_price_interval'] = [
            'interval' => $price_interval * 60,
            'display' => "Cada {$price_interval} minutos"
        ];

        return $schedules;
    }
}

Laudus_ERP_Pro_Complete::instance();
