<?php
/**
 * Plugin Name: DC Servers Manager
 * Description: ניהול שרתים לפי לקוחות.
 * Version: 1.0.0
 * Author: Ofri
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DC_Servers_Manager {

    private static $instance = null;
    private $table_name;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'dc_servers';

        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        add_shortcode( 'dc_servers_manager', array( $this, 'render_shortcode' ) );
        add_shortcode( 'dc_servers_trash', array( $this, 'render_trash_shortcode' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'init', array( $this, 'handle_post_requests' ) );
        add_action( 'init', array( $this, 'handle_download_requests' ) );
        add_action( 'plugins_loaded', array( $this, 'maybe_create_lookup_tables' ) );
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_post_dc_save_lookup', array( $this, 'handle_save_lookup' ) );
        add_action( 'admin_post_dc_delete_lookup', array( $this, 'handle_delete_lookup' ) );
    }

    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT(20) UNSIGNED NOT NULL,
            server_name VARCHAR(255) NOT NULL,
            ip_internal VARCHAR(45) NOT NULL,
            ip_wan VARCHAR(255) NOT NULL,
            location VARCHAR(255) NULL,
            farm VARCHAR(255) NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_customer_id (customer_id),
            UNIQUE KEY unique_internal_ip (ip_internal),
            UNIQUE KEY unique_wan_ip (ip_wan)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        $lookup_sql = "CREATE TABLE {$wpdb->prefix}dc_server_locations (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_location_name (name)
        ) $charset_collate;";

        $farm_sql = "CREATE TABLE {$wpdb->prefix}dc_server_farms (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_farm_name (name)
        ) $charset_collate;";

        dbDelta( $lookup_sql );
        dbDelta( $farm_sql );
    }

    public function maybe_create_lookup_tables() {
        global $wpdb;
        $location_table = $wpdb->prefix . 'dc_server_locations';
        $farm_table     = $wpdb->prefix . 'dc_server_farms';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $location_table ) ) !== $location_table ) {
            $sql = "CREATE TABLE {$location_table} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY unique_location_name (name)
            ) {$charset_collate};";
            dbDelta( $sql );
        }

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $farm_table ) ) !== $farm_table ) {
            $sql = "CREATE TABLE {$farm_table} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY unique_farm_name (name)
            ) {$charset_collate};";
            dbDelta( $sql );
        }
    }

    public function enqueue_assets() {
        wp_register_style(
            'dc-servers-css',
            plugins_url( 'assets/servers.css', __FILE__ ),
            array(),
            '1.0.0'
        );
        wp_enqueue_style( 'dc-servers-css' );

        wp_register_script(
            'dc-servers-js',
            plugins_url( 'assets/servers.js', __FILE__ ),
            array(),
            '1.0.0',
            true
        );
        wp_enqueue_script( 'dc-servers-js' );
    }

    private function validate_server( $customer_id, $server_name, $ip_internal, $ip_wan, $id = null, &$errors = array() ) {
        $server_name = trim( $server_name );
        $ip_internal = trim( $ip_internal );
        $ip_wan      = trim( $ip_wan );

        if ( ! $customer_id ) {
            $errors[] = 'יש לבחור לקוח.';
        }
        if ( $server_name === '' ) {
            $errors[] = 'שם השרת חובה.';
        }

        if ( ! filter_var( $ip_internal, FILTER_VALIDATE_IP ) ) {
            $errors[] = 'כתובת ה-IP הפנימית אינה תקינה.';
        }
        if ( ! filter_var( $ip_wan, FILTER_VALIDATE_IP ) ) {
            $errors[] = 'כתובת ה-IP ה-WAN אינה תקינה.';
        }

        if ( $ip_internal === $ip_wan ) {
            $errors[] = 'אסור שכתובת ה-IP הפנימית וכתובת ה-WAN יהיו זהות.';
        }

        if ( ! empty( $errors ) ) return false;

        global $wpdb;

        // בדיקת כפילות ip_internal
        $query  = "SELECT id FROM {$this->table_name} WHERE ip_internal = %s";
        $params = array( $ip_internal );
        if ( $id ) {
            $query .= " AND id != %d";
            $params[] = $id;
        }
        $existing_int = $wpdb->get_var( $wpdb->prepare( $query, $params ) );
        if ( $existing_int ) {
            $errors[] = 'כתובת ה-IP הפנימית כבר בשימוש.';
        }

        // בדיקת כפילות ip_wan
        $query  = "SELECT id FROM {$this->table_name} WHERE ip_wan = %s";
        $params = array( $ip_wan );
        if ( $id ) {
            $query .= " AND id != %d";
            $params[] = $id;
        }
        $existing_wan = $wpdb->get_var( $wpdb->prepare( $query, $params ) );
        if ( $existing_wan ) {
            $errors[] = 'כתובת ה-IP ה-WAN כבר בשימוש.';
        }

        return empty( $errors );
    }

    public function handle_post_requests() {
        if ( empty( $_POST['dc_servers_action'] ) ) return;
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'dc_servers_action' ) ) {
            return;
        }

        $action = sanitize_text_field( $_POST['dc_servers_action'] );

        switch ( $action ) {
            case 'add_or_update':
                $this->handle_add_or_update();
                break;
            case 'soft_delete':
                $this->handle_soft_delete();
                break;
            case 'soft_delete_bulk':
                $this->handle_soft_delete_bulk();
                break;
            case 'delete_permanent':
                $this->handle_delete_permanent();
                break;
            case 'delete_permanent_all':
                $this->handle_delete_permanent_all();
                break;
            case 'duplicate':
                $this->handle_duplicate();
                break;
            case 'import_servers':
                $this->handle_import_servers();
                break;
        }

        wp_safe_redirect( wp_get_referer() );
        exit;
    }

    private function handle_add_or_update() {
        global $wpdb;
        $id          = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : null;
        $customer_id = isset( $_POST['customer_id'] ) ? intval( $_POST['customer_id'] ) : 0;
        $server_name = isset( $_POST['server_name'] ) ? sanitize_text_field( $_POST['server_name'] ) : '';
        $ip_internal = isset( $_POST['ip_internal'] ) ? sanitize_text_field( $_POST['ip_internal'] ) : '';
        $ip_wan      = isset( $_POST['ip_wan'] ) ? sanitize_text_field( $_POST['ip_wan'] ) : '';
        $location    = isset( $_POST['location'] ) ? sanitize_text_field( $_POST['location'] ) : '';
        $farm        = isset( $_POST['farm'] ) ? sanitize_text_field( $_POST['farm'] ) : '';

        $errors = array();
        if ( ! $this->validate_server( $customer_id, $server_name, $ip_internal, $ip_wan, $id, $errors ) ) {
            set_transient( 'dc_servers_errors', $errors, 30 );
            return;
        }

        $now = current_time( 'mysql' );

        if ( $id ) {
            $wpdb->update(
                $this->table_name,
                array(
                    'customer_id' => $customer_id,
                    'server_name' => $server_name,
                    'ip_internal' => $ip_internal,
                    'ip_wan'      => $ip_wan,
                    'location'    => $location,
                    'farm'        => $farm,
                    'updated_at'  => $now,
                ),
                array( 'id' => $id ),
                array( '%d','%s','%s','%s','%s','%s','%s' ),
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $this->table_name,
                array(
                    'customer_id' => $customer_id,
                    'server_name' => $server_name,
                    'ip_internal' => $ip_internal,
                    'ip_wan'      => $ip_wan,
                    'location'    => $location,
                    'farm'        => $farm,
                    'is_deleted'  => 0,
                    'deleted_at'  => null,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ),
                array( '%d','%s','%s','%s','%s','%s','%d','%s','%s','%s' )
            );
        }
    }

    private function handle_soft_delete() {
        global $wpdb;
        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) return;

        $wpdb->update(
            $this->table_name,
            array( 'is_deleted' => 1, 'deleted_at' => current_time( 'mysql' ) ),
            array( 'id' => $id ),
            array( '%d','%s' ),
            array( '%d' )
        );
    }

    private function handle_soft_delete_bulk() {
        global $wpdb;
        if ( empty( $_POST['ids'] ) || ! is_array( $_POST['ids'] ) ) return;

        $ids = array_map( 'intval', $_POST['ids'] );
        $ids = array_filter( $ids );
        if ( empty( $ids ) ) return;

        $in_placeholder = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "UPDATE {$this->table_name}
                SET is_deleted = 1, deleted_at = %s
                WHERE id IN ($in_placeholder)";
        $params = array_merge( array( current_time( 'mysql' ) ), $ids );
        $wpdb->query( $wpdb->prepare( $sql, $params ) );
    }

    private function handle_delete_permanent() {
        global $wpdb;
        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) return;
        $wpdb->delete( $this->table_name, array( 'id' => $id ), array( '%d' ) );
    }

    private function handle_delete_permanent_all() {
        global $wpdb;
        $sql = "DELETE FROM {$this->table_name} WHERE is_deleted = 1";
        $wpdb->query( $sql );
    }

    private function handle_duplicate() {
        global $wpdb;
        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) return;

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE id = %d", $id )
        );
        if ( ! $row ) return;

        // שיבוט – אפשר לשנות שם שרת, IP, וכד' לפי לוגיקה שלך
        $now = current_time( 'mysql' );
        $wpdb->insert(
            $this->table_name,
            array(
                'customer_id' => $row->customer_id,
                'server_name' => $row->server_name . ' (Copy)',
                'ip_internal' => $row->ip_internal, // רצוי אולי לאפס או להחליף
                'ip_wan'      => $row->ip_wan,      // כנ"ל
                'location'    => $row->location,
                'farm'        => $row->farm,
                'is_deleted'  => 0,
                'deleted_at'  => null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array( '%d','%s','%s','%s','%s','%s','%d','%s','%s','%s' )
        );
    }

    private function handle_import_servers() {
        if ( empty( $_FILES['servers_file']['name'] ) ) {
            set_transient( 'dc_servers_errors', array( 'לא נבחר קובץ לייבוא.' ), 30 );
            return;
        }

        $file      = $_FILES['servers_file'];
        $file_type = wp_check_filetype( $file['name'] );
        $allowed   = array( 'csv', 'xls', 'xlsx' );
        if ( empty( $file_type['ext'] ) || ! in_array( strtolower( $file_type['ext'] ), $allowed, true ) ) {
            set_transient( 'dc_servers_errors', array( 'ניתן לייבא רק קבצי CSV או Excel.' ), 30 );
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $upload = wp_handle_upload( $file, array( 'test_form' => false ) );
        if ( isset( $upload['error'] ) ) {
            set_transient( 'dc_servers_errors', array( 'שגיאה בהעלאת הקובץ: ' . esc_html( $upload['error'] ) ), 30 );
            return;
        }

        $path      = $upload['file'];
        $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $rows      = array();

        if ( $extension === 'csv' ) {
            $rows = $this->parse_csv_rows( $path );
        } else {
            $rows = $this->parse_xlsx_rows( $path );
        }

        if ( empty( $rows ) ) {
            set_transient( 'dc_servers_errors', array( 'לא נמצאו נתונים בקובץ שהועלה.' ), 30 );
            return;
        }

        global $wpdb;
        $errors   = array();
        $inserted = 0;
        foreach ( $rows as $index => $row ) {
            $customer_number = isset( $row['customer_number'] ) ? trim( $row['customer_number'] ) : '';
            $customer_name   = isset( $row['customer_name'] ) ? trim( $row['customer_name'] ) : '';
            $customer_id     = $this->find_customer_id( $customer_number, $customer_name );
            if ( ! $customer_id ) {
                $errors[] = sprintf( 'שורה %d: לקוח לא נמצא (%s).', $index + 1, esc_html( $customer_number ?: $customer_name ) );
                continue;
            }

            $server_name = isset( $row['server_name'] ) ? sanitize_text_field( $row['server_name'] ) : '';
            $ip_internal = isset( $row['ip_internal'] ) ? sanitize_text_field( $row['ip_internal'] ) : '';
            $ip_wan      = isset( $row['ip_wan'] ) ? sanitize_text_field( $row['ip_wan'] ) : '';
            $location    = isset( $row['location'] ) ? sanitize_text_field( $row['location'] ) : '';
            $farm        = isset( $row['farm'] ) ? sanitize_text_field( $row['farm'] ) : '';

            $row_errors = array();
            if ( ! $this->validate_server( $customer_id, $server_name, $ip_internal, $ip_wan, null, $row_errors ) ) {
                $errors = array_merge( $errors, $row_errors );
                continue;
            }

            $now = current_time( 'mysql' );
            $wpdb->insert(
                $this->table_name,
                array(
                    'customer_id' => $customer_id,
                    'server_name' => $server_name,
                    'ip_internal' => $ip_internal,
                    'ip_wan'      => $ip_wan,
                    'location'    => $location,
                    'farm'        => $farm,
                    'is_deleted'  => 0,
                    'deleted_at'  => null,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ),
                array( '%d','%s','%s','%s','%s','%s','%d','%s','%s','%s' )
            );
            $inserted++;
        }

        if ( $inserted ) {
            set_transient( 'dc_servers_errors', array( sprintf( 'יובאו בהצלחה %d רשומות.', $inserted ) ), 30 );
        }
        if ( ! empty( $errors ) ) {
            set_transient( 'dc_servers_errors', $errors, 30 );
        }
    }

    private function parse_csv_rows( $path ) {
        $rows = array();
        if ( ( $handle = fopen( $path, 'r' ) ) !== false ) {
            $headers = array();
            $row_num = 0;
            while ( ( $data = fgetcsv( $handle ) ) !== false ) {
                $row_num++;
                if ( $row_num === 1 ) {
                    $headers = array_map( 'sanitize_key', $data );
                    continue;
                }
                $rows[] = $this->map_row_to_keys( $headers, $data );
            }
            fclose( $handle );
        }
        return $rows;
    }

    private function parse_xlsx_rows( $path ) {
        $rows = array();
        if ( ! class_exists( 'ZipArchive' ) ) {
            return $rows;
        }

        $zip = new ZipArchive();
        if ( $zip->open( $path ) !== true ) {
            return $rows;
        }

        $sharedStrings = array();
        if ( ( $strings_xml = $zip->getFromName( 'xl/sharedStrings.xml' ) ) !== false ) {
            $sxml = simplexml_load_string( $strings_xml );
            if ( isset( $sxml->si ) ) {
                foreach ( $sxml->si as $si ) {
                    $sharedStrings[] = (string) $si->t;
                }
            }
        }

        $sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
        if ( $sheet_xml === false ) {
            $zip->close();
            return $rows;
        }

        $sheet = simplexml_load_string( $sheet_xml );
        $headers = array();
        $row_num = 0;
        foreach ( $sheet->sheetData->row as $row ) {
            $row_num++;
            $cells = array();
            foreach ( $row->c as $c ) {
                $value = (string) $c->v;
                if ( (string) $c['t'] === 's' && isset( $sharedStrings[ intval( $value ) ] ) ) {
                    $value = $sharedStrings[ intval( $value ) ];
                }
                $cells[] = $value;
            }
            if ( $row_num === 1 ) {
                $headers = array_map( 'sanitize_key', $cells );
                continue;
            }
            $rows[] = $this->map_row_to_keys( $headers, $cells );
        }

        $zip->close();
        return $rows;
    }

    private function map_row_to_keys( $headers, $values ) {
        $mapped = array();
        foreach ( $headers as $index => $key ) {
            $mapped[ $key ] = isset( $values[ $index ] ) ? $values[ $index ] : '';
        }

        return array(
            'customer_number' => isset( $mapped['customer_number'] ) ? $mapped['customer_number'] : '',
            'customer_name'   => isset( $mapped['customer_name'] ) ? $mapped['customer_name'] : '',
            'server_name'     => isset( $mapped['server_name'] ) ? $mapped['server_name'] : '',
            'ip_internal'     => isset( $mapped['ip_internal'] ) ? $mapped['ip_internal'] : '',
            'ip_wan'          => isset( $mapped['ip_wan'] ) ? $mapped['ip_wan'] : '',
            'location'        => isset( $mapped['location'] ) ? $mapped['location'] : '',
            'farm'            => isset( $mapped['farm'] ) ? $mapped['farm'] : '',
        );
    }

    private function find_customer_id( $customer_number, $customer_name ) {
        if ( ! class_exists( 'DC_Customers_Manager' ) ) {
            return 0;
        }

        $customers = DC_Customers_Manager::get_all_customers();
        foreach ( $customers as $c ) {
            if ( $customer_number !== '' && (string) $c->customer_number === (string) $customer_number ) {
                return intval( $c->id );
            }
            if ( $customer_name !== '' && strcasecmp( $c->customer_name, $customer_name ) === 0 ) {
                return intval( $c->id );
            }
        }
        return 0;
    }

    private function handle_download_requests() {
        if ( empty( $_GET['dc_servers_export'] ) ) return;

        $type = sanitize_key( $_GET['dc_servers_export'] );
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'dc_servers_export' ) ) {
            return;
        }

        $servers = $this->get_servers( true );
        if ( $type === 'csv' ) {
            $this->export_csv( $servers );
        } elseif ( $type === 'excel' ) {
            $this->export_excel( $servers );
        }
    }

    private function export_csv( $servers ) {
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="dc-servers.csv"' );
        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, array( 'customer_number', 'customer_name', 'server_name', 'ip_internal', 'ip_wan', 'location', 'farm', 'is_deleted' ) );
        foreach ( $servers as $s ) {
            fputcsv( $output, array( $s->customer_number, $s->customer_name, $s->server_name, $s->ip_internal, $s->ip_wan, $s->location, $s->farm, $s->is_deleted ) );
        }
        fclose( $output );
        exit;
    }

    private function export_excel( $servers ) {
        header( 'Content-Type: application/vnd.ms-excel; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="dc-servers.xls"' );

        echo "<table border='1'>";
        echo '<tr><th>customer_number</th><th>customer_name</th><th>server_name</th><th>ip_internal</th><th>ip_wan</th><th>location</th><th>farm</th><th>is_deleted</th></tr>';
        foreach ( $servers as $s ) {
            echo '<tr>';
            echo '<td>' . esc_html( $s->customer_number ) . '</td>';
            echo '<td>' . esc_html( $s->customer_name ) . '</td>';
            echo '<td>' . esc_html( $s->server_name ) . '</td>';
            echo '<td>' . esc_html( $s->ip_internal ) . '</td>';
            echo '<td>' . esc_html( $s->ip_wan ) . '</td>';
            echo '<td>' . esc_html( $s->location ) . '</td>';
            echo '<td>' . esc_html( $s->farm ) . '</td>';
            echo '<td>' . esc_html( $s->is_deleted ) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        exit;
    }

    private function get_servers( $include_deleted = false, $search = '', $orderby = 'server_name', $order = 'ASC' ) {
        global $wpdb;

        $allowed_orderby = array( 'id','server_name','ip_internal','ip_wan','location','farm','created_at' );
        if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
            $orderby = 'server_name';
        }
        $order = strtoupper( $order ) === 'DESC' ? 'DESC' : 'ASC';

        $where   = 'WHERE 1=1';
        $params  = array();

        if ( ! $include_deleted ) {
            $where .= ' AND s.is_deleted = 0';
        }

        if ( $search !== '' ) {
            $where .= ' AND (s.server_name LIKE %s OR s.ip_internal LIKE %s OR s.ip_wan LIKE %s OR c.customer_name LIKE %s OR c.customer_number LIKE %s)';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $params = array_merge( $params, array( $like, $like, $like, $like, $like ) );
        }

        $sql = "SELECT s.*, c.customer_name, c.customer_number
                FROM {$this->table_name} s
                LEFT JOIN {$wpdb->prefix}dc_customers c ON s.customer_id = c.id
                {$where}
                ORDER BY {$orderby} {$order}";

        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, $params );
        }

        return $wpdb->get_results( $sql );
    }

    private function get_locations() {
        global $wpdb;
        $table = $wpdb->prefix . 'dc_server_locations';
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" );
    }

    private function get_farms() {
        global $wpdb;
        $table = $wpdb->prefix . 'dc_server_farms';
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" );
    }

    public function register_admin_menu() {
        add_menu_page(
            'DC Servers Settings',
            'DC Servers',
            'manage_options',
            'dc-servers-settings',
            array( $this, 'render_settings_page' ),
            'dashicons-admin-generic'
        );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $locations = $this->get_locations();
        $farms     = $this->get_farms();
        ?>
        <div class="wrap">
            <h1>הגדרות שרתים</h1>
            <div style="display:flex; gap:24px; align-items:flex-start;">
                <div style="flex:1;">
                    <h2>טבלת מיקום</h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'dc_lookup_action', '_wpnonce' ); ?>
                        <input type="hidden" name="action" value="dc_save_lookup">
                        <input type="hidden" name="lookup_type" value="location">
                        <input type="text" name="name" placeholder="שם מיקום" required style="width:100%;max-width:320px;">
                        <button class="button button-primary" type="submit">הוספה</button>
                    </form>
                    <table class="widefat striped" style="margin-top:12px;">
                        <thead><tr><th>שם</th><th>פעולות</th></tr></thead>
                        <tbody>
                            <?php foreach ( $locations as $loc ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $loc->name ); ?></td>
                                    <td>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                                            <?php wp_nonce_field( 'dc_lookup_action', '_wpnonce' ); ?>
                                            <input type="hidden" name="action" value="dc_delete_lookup">
                                            <input type="hidden" name="lookup_type" value="location">
                                            <input type="hidden" name="id" value="<?php echo esc_attr( $loc->id ); ?>">
                                            <button class="button button-link-delete" type="submit">מחיקה</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="flex:1;">
                    <h2>טבלת חווה</h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'dc_lookup_action', '_wpnonce' ); ?>
                        <input type="hidden" name="action" value="dc_save_lookup">
                        <input type="hidden" name="lookup_type" value="farm">
                        <input type="text" name="name" placeholder="שם חווה" required style="width:100%;max-width:320px;">
                        <button class="button button-primary" type="submit">הוספה</button>
                    </form>
                    <table class="widefat striped" style="margin-top:12px;">
                        <thead><tr><th>שם</th><th>פעולות</th></tr></thead>
                        <tbody>
                            <?php foreach ( $farms as $farm ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $farm->name ); ?></td>
                                    <td>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                                            <?php wp_nonce_field( 'dc_lookup_action', '_wpnonce' ); ?>
                                            <input type="hidden" name="action" value="dc_delete_lookup">
                                            <input type="hidden" name="lookup_type" value="farm">
                                            <input type="hidden" name="id" value="<?php echo esc_attr( $farm->id ); ?>">
                                            <button class="button button-link-delete" type="submit">מחיקה</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    public function handle_save_lookup() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'dc_lookup_action' ) ) {
            wp_die( 'Nonce error' );
        }

        $type = isset( $_POST['lookup_type'] ) ? sanitize_key( $_POST['lookup_type'] ) : '';
        $name = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
        if ( ! in_array( $type, array( 'location', 'farm' ), true ) || $name === '' ) {
            wp_safe_redirect( admin_url( 'admin.php?page=dc-servers-settings' ) );
            exit;
        }

        global $wpdb;
        $table = $type === 'location' ? $wpdb->prefix . 'dc_server_locations' : $wpdb->prefix . 'dc_server_farms';
        $wpdb->replace( $table, array( 'name' => $name, 'updated_at' => current_time( 'mysql' ) ), array( '%s','%s' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=dc-servers-settings' ) );
        exit;
    }

    public function handle_delete_lookup() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'dc_lookup_action' ) ) {
            wp_die( 'Nonce error' );
        }

        $type = isset( $_POST['lookup_type'] ) ? sanitize_key( $_POST['lookup_type'] ) : '';
        $id   = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! in_array( $type, array( 'location', 'farm' ), true ) || ! $id ) {
            wp_safe_redirect( admin_url( 'admin.php?page=dc-servers-settings' ) );
            exit;
        }

        global $wpdb;
        $table = $type === 'location' ? $wpdb->prefix . 'dc_server_locations' : $wpdb->prefix . 'dc_server_farms';
        $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=dc-servers-settings' ) );
        exit;
    }

    public function render_shortcode( $atts ) {
        $search  = isset( $_GET['dc_s_search'] ) ? sanitize_text_field( $_GET['dc_s_search'] ) : '';
        $orderby = isset( $_GET['dc_s_orderby'] ) ? sanitize_key( $_GET['dc_s_orderby'] ) : 'server_name';
        $order   = isset( $_GET['dc_s_order'] ) ? sanitize_key( $_GET['dc_s_order'] )   : 'ASC';

        if ( ! class_exists( 'DC_Customers_Manager' ) ) {
            return 'התוסף לניהול לקוחות לא פעיל.';
        }

        $customers         = DC_Customers_Manager::get_all_customers();
        $locations         = $this->get_locations();
        $farms             = $this->get_farms();
        $servers           = $this->get_servers( false, $search, $orderby, $order );
        $deleted_servers   = $this->get_servers( true,  $search, $orderby, $order );
        $errors = get_transient( 'dc_servers_errors' );
        delete_transient( 'dc_servers_errors' );

        ob_start();
        ?>
        <div class="dc-servers-wrap">
            <?php if ( ! empty( $errors ) ) : ?>
                <div class="dc-errors">
                    <?php foreach ( $errors as $e ) : ?>
                        <p><?php echo esc_html( $e ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="get" class="dc-search-form">
                <input type="text" name="dc_s_search" value="<?php echo esc_attr( $search ); ?>" placeholder="חיפוש לפי שרת / IP / לקוח">
                <button type="submit">חיפוש</button>
            </form>

            <div class="dc-form-header">
                <h3 class="dc-form-title">הוספת / עריכת שרת</h3>
                <button type="button" class="dc-btn-primary dc-toggle-form">שרת חדש</button>
            </div>

            <form method="post" class="dc-form-modern dc-form-collapsed">
                <?php wp_nonce_field( 'dc_servers_action' ); ?>
                <input type="hidden" name="dc_servers_action" value="add_or_update">
                <input type="hidden" name="id" value="">

                <div class="dc-form-grid">
                    <div class="dc-field">
                        <label>שם לקוח</label>
                        <input type="text" name="customer_name_search" list="dc-customer-names" autocomplete="off" placeholder="הקלד שם לקוח" required>
                        <datalist id="dc-customer-names">
                            <?php foreach ( $customers as $c ) : ?>
                                <option class="dc-customer-option" data-id="<?php echo esc_attr( $c->id ); ?>" data-number="<?php echo esc_attr( $c->customer_number ); ?>" data-name="<?php echo esc_attr( $c->customer_name ); ?>" value="<?php echo esc_attr( $c->customer_name ); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="dc-field">
                        <label>מספר לקוח</label>
                        <input type="text" name="customer_number_search" list="dc-customer-numbers" autocomplete="off" placeholder="הקלד מספר לקוח" required>
                        <datalist id="dc-customer-numbers">
                            <?php foreach ( $customers as $c ) : ?>
                                <option class="dc-customer-option" data-id="<?php echo esc_attr( $c->id ); ?>" data-number="<?php echo esc_attr( $c->customer_number ); ?>" data-name="<?php echo esc_attr( $c->customer_name ); ?>" value="<?php echo esc_attr( $c->customer_number ); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <input type="hidden" name="customer_id" value="">

                    <div class="dc-field">
                        <label>שם שרת</label>
                        <input type="text" name="server_name" required>
                    </div>

                    <div class="dc-field">
                        <label>IP פנימי</label>
                        <input type="text" name="ip_internal" required>
                    </div>

                    <div class="dc-field">
                        <label>IP WAN</label>
                        <input type="text" name="ip_wan" required>
                    </div>

                    <div class="dc-field">
                        <label>מיקום</label>
                        <select name="location">
                            <option value="">בחר מיקום...</option>
                            <?php foreach ( $locations as $loc ) : ?>
                                <option value="<?php echo esc_attr( $loc->name ); ?>"><?php echo esc_html( $loc->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="dc-field">
                        <label>חווה</label>
                        <select name="farm">
                            <option value="">בחר חווה...</option>
                            <?php foreach ( $farms as $farm ) : ?>
                                <option value="<?php echo esc_attr( $farm->name ); ?>"><?php echo esc_html( $farm->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="dc-form-actions">
                    <button type="submit" class="dc-btn-primary">שמירה</button>
                </div>
            </form>

            <h3>רשימת שרתים</h3>
            <form method="post">
                <?php wp_nonce_field( 'dc_servers_action' ); ?>
                <input type="hidden" name="dc_servers_action" value="soft_delete_bulk">

                <table class="dc-table-modern">
                    <thead>
                        <tr>
                            <th><input type="checkbox" class="dc-select-all"></th>
                            <th><a href="?dc_s_orderby=id&dc_s_order=<?php echo $order === 'ASC' ? 'DESC' : 'ASC'; ?>">ID</a></th>
                            <th><a href="?dc_s_orderby=server_name&dc_s_order=<?php echo $order === 'ASC' ? 'DESC' : 'ASC'; ?>">שם שרת</a></th>
                            <th><a href="?dc_s_orderby=ip_internal&dc_s_order=<?php echo $order === 'ASC' ? 'DESC' : 'ASC'; ?>">IP פנימי</a></th>
                            <th><a href="?dc_s_orderby=ip_wan&dc_s_order=<?php echo $order === 'ASC' ? 'DESC' : 'ASC'; ?>">IP WAN</a></th>
                            <th>לקוח</th>
                            <th>מיקום</th>
                            <th>חווה</th>
                            <th>פעולות</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( $servers ) : ?>
                            <?php foreach ( $servers as $s ) : ?>
                                <tr>
                                    <td><input type="checkbox" name="ids[]" value="<?php echo esc_attr( $s->id ); ?>"></td>
                                    <td><?php echo esc_html( $s->id ); ?></td>
                                    <td><?php echo esc_html( $s->server_name ); ?></td>
                                    <td><?php echo esc_html( $s->ip_internal ); ?></td>
                                    <td><?php echo esc_html( $s->ip_wan ); ?></td>
                                    <td><?php echo esc_html( $s->customer_name . ' (' . $s->customer_number . ')' ); ?></td>
                                    <td><?php echo esc_html( $s->location ); ?></td>
                                    <td><?php echo esc_html( $s->farm ); ?></td>
                                    <td>
                                        <button type="button"
                                                class="dc-btn-secondary dc-edit-server"
                                                data-id="<?php echo esc_attr( $s->id ); ?>"
                                                data-customer_id="<?php echo esc_attr( $s->customer_id ); ?>"
                                                data-customer_name="<?php echo esc_attr( $s->customer_name ); ?>"
                                                data-customer_number="<?php echo esc_attr( $s->customer_number ); ?>"
                                                data-server_name="<?php echo esc_attr( $s->server_name ); ?>"
                                                data-ip_internal="<?php echo esc_attr( $s->ip_internal ); ?>"
                                                data-ip_wan="<?php echo esc_attr( $s->ip_wan ); ?>"
                                                data-location="<?php echo esc_attr( $s->location ); ?>"
                                                data-farm="<?php echo esc_attr( $s->farm ); ?>">
                                            עריכה
                                        </button>

                                        <form method="post" style="display:inline;">
                                            <?php wp_nonce_field( 'dc_servers_action' ); ?>
                                            <input type="hidden" name="dc_servers_action" value="duplicate">
                                            <input type="hidden" name="id" value="<?php echo esc_attr( $s->id ); ?>">
                                            <button type="submit" class="dc-btn-secondary">שיכפול</button>
                                        </form>

                                        <form method="post" style="display:inline;">
                                            <?php wp_nonce_field( 'dc_servers_action' ); ?>
                                            <input type="hidden" name="dc_servers_action" value="soft_delete">
                                            <input type="hidden" name="id" value="<?php echo esc_attr( $s->id ); ?>">
                                            <button type="submit" class="dc-btn-danger">מחיקה</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="9">לא נמצאו שרתים.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <button type="submit" class="dc-btn-danger">מחיקת רשומות מסומנות (לסל מחזור)</button>
            </form>

            <h3>סל מחזור</h3>
            <table class="dc-table-modern dc-trash-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>שם שרת</th>
                        <th>IP פנימי</th>
                        <th>IP WAN</th>
                        <th>לקוח</th>
                        <th>נמחק בתאריך</th>
                        <th>מחיקה לצמיתות</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $deleted_servers as $s ) : ?>
                        <?php if ( ! $s->is_deleted ) continue; ?>
                        <tr>
                            <td><?php echo esc_html( $s->id ); ?></td>
                            <td><?php echo esc_html( $s->server_name ); ?></td>
                            <td><?php echo esc_html( $s->ip_internal ); ?></td>
                            <td><?php echo esc_html( $s->ip_wan ); ?></td>
                            <td><?php echo esc_html( $s->customer_name . ' (' . $s->customer_number . ')' ); ?></td>
                            <td><?php echo esc_html( $s->deleted_at ); ?></td>
                            <td>
                                <form method="post">
                                    <?php wp_nonce_field( 'dc_servers_action' ); ?>
                                    <input type="hidden" name="dc_servers_action" value="delete_permanent">
                                    <input type="hidden" name="id" value="<?php echo esc_attr( $s->id ); ?>">
                                    <button type="submit" class="dc-btn-danger">מחיקה לצמיתות</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <form method="post">
                <?php wp_nonce_field( 'dc_servers_action' ); ?>
                <input type="hidden" name="dc_servers_action" value="delete_permanent_all">
                <button type="submit" class="dc-btn-danger">מחיקת כל סל המחזור לצמיתות</button>
            </form>

            <div class="dc-import-export">
                <h3>ייבוא / ייצוא</h3>
                <div class="dc-import-export-row">
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'dc_servers_action' ); ?>
                        <input type="hidden" name="dc_servers_action" value="import_servers">
                        <input type="file" name="servers_file" accept=".csv, .xls, .xlsx" required>
                        <button type="submit" class="dc-btn-primary">ייבוא CSV / Excel</button>
                    </form>

                    <?php $export_nonce = wp_create_nonce( 'dc_servers_export' ); ?>
                    <a class="dc-btn-secondary" href="<?php echo esc_url( add_query_arg( array( 'dc_servers_export' => 'csv', '_wpnonce' => $export_nonce ) ) ); ?>">ייצוא CSV</a>
                    <a class="dc-btn-secondary" href="<?php echo esc_url( add_query_arg( array( 'dc_servers_export' => 'excel', '_wpnonce' => $export_nonce ) ) ); ?>">ייצוא Excel</a>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function render_trash_shortcode( $atts ) {
        $search  = isset( $_GET['dc_s_search'] ) ? sanitize_text_field( $_GET['dc_s_search'] ) : '';
        $orderby = isset( $_GET['dc_s_orderby'] ) ? sanitize_key( $_GET['dc_s_orderby'] ) : 'server_name';
        $order   = isset( $_GET['dc_s_order'] ) ? sanitize_key( $_GET['dc_s_order'] )   : 'ASC';

        $deleted_servers = $this->get_servers( true, $search, $orderby, $order );

        ob_start();
        ?>
        <div class="dc-servers-wrap">
            <form method="get" class="dc-search-form">
                <input type="text" name="dc_s_search" value="<?php echo esc_attr( $search ); ?>" placeholder="חיפוש לפי שרת / IP / לקוח">
                <button type="submit">חיפוש</button>
            </form>

            <h3>סל מחזור</h3>
            <table class="dc-table-modern dc-trash-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>שם שרת</th>
                        <th>IP פנימי</th>
                        <th>IP WAN</th>
                        <th>לקוח</th>
                        <th>נמחק בתאריך</th>
                        <th>מחיקה לצמיתות</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $deleted_servers as $s ) : ?>
                        <?php if ( ! $s->is_deleted ) continue; ?>
                        <tr>
                            <td><?php echo esc_html( $s->id ); ?></td>
                            <td><?php echo esc_html( $s->server_name ); ?></td>
                            <td><?php echo esc_html( $s->ip_internal ); ?></td>
                            <td><?php echo esc_html( $s->ip_wan ); ?></td>
                            <td><?php echo esc_html( $s->customer_name . ' (' . $s->customer_number . ')' ); ?></td>
                            <td><?php echo esc_html( $s->deleted_at ); ?></td>
                            <td>
                                <form method="post">
                                    <?php wp_nonce_field( 'dc_servers_action' ); ?>
                                    <input type="hidden" name="dc_servers_action" value="delete_permanent">
                                    <input type="hidden" name="id" value="<?php echo esc_attr( $s->id ); ?>">
                                    <button type="submit" class="dc-btn-danger">מחיקה לצמיתות</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <form method="post">
                <?php wp_nonce_field( 'dc_servers_action' ); ?>
                <input type="hidden" name="dc_servers_action" value="delete_permanent_all">
                <button type="submit" class="dc-btn-danger">מחיקת כל סל המחזור לצמיתות</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}

DC_Servers_Manager::instance();
