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

    public function render_shortcode( $atts ) {
        $search  = isset( $_GET['dc_s_search'] ) ? sanitize_text_field( $_GET['dc_s_search'] ) : '';
        $orderby = isset( $_GET['dc_s_orderby'] ) ? sanitize_key( $_GET['dc_s_orderby'] ) : 'server_name';
        $order   = isset( $_GET['dc_s_order'] ) ? sanitize_key( $_GET['dc_s_order'] )   : 'ASC';

        if ( ! class_exists( 'DC_Customers_Manager' ) ) {
            return 'התוסף לניהול לקוחות לא פעיל.';
        }

        $customers         = DC_Customers_Manager::get_all_customers();
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
                        <label>לקוח</label>
                        <select name="customer_id" required>
                            <option value="">בחר לקוח...</option>
                            <?php foreach ( $customers as $c ) : ?>
                                <option value="<?php echo esc_attr( $c->id ); ?>">
                                    <?php echo esc_html( $c->customer_name . ' (' . $c->customer_number . ')' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

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
                        <input type="text" name="location">
                    </div>

                    <div class="dc-field">
                        <label>חווה</label>
                        <input type="text" name="farm">
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
