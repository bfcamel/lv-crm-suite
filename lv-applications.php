<?php
/**
 * Plugin Name: Люди и Верблюды — CRM фонда
 * Description: CRM фонда на базе Contact Form 7: заявки, умное распределение, контакты, кураторы, метки, история и экспорт.
 * Version: 0.11.3
 * Author: БФ «Люди и Верблюды»
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: lv-applications
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'LV_APPS_VERSION', '0.11.3' );
define( 'LV_APPS_FILE', __FILE__ );
define( 'LV_APPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'LV_APPS_URL', plugin_dir_url( __FILE__ ) );

require_once LV_APPS_DIR . 'includes/class-lv-xlsx-writer.php';
require_once LV_APPS_DIR . 'includes/support/class-lv-request-cache.php';
require_once LV_APPS_DIR . 'includes/class-lv-applications-table.php';
require_once LV_APPS_DIR . 'includes/integration/class-lv-form-contracts.php';
require_once LV_APPS_DIR . 'includes/class-lv-crm-extensions.php';
require_once LV_APPS_DIR . 'includes/consent/class-lv-consent-service.php';
require_once LV_APPS_DIR . 'includes/contacts/class-lv-contact-query.php';
require_once LV_APPS_DIR . 'includes/contacts/class-lv-contact-segments.php';
require_once LV_APPS_DIR . 'includes/applications/class-lv-application-query.php';
require_once LV_APPS_DIR . 'includes/routing/class-lv-routing-load-provider.php';
require_once LV_APPS_DIR . 'includes/export/class-lv-contact-exporter.php';
require_once LV_APPS_DIR . 'includes/export/class-lv-contact-export-controller.php';
require_once LV_APPS_DIR . 'includes/database/class-lv-db-migrator.php';
require_once LV_APPS_DIR . 'includes/integration/class-lv-form-integration.php';

final class LV_Applications_Plugin {
    const PAGE_SLUG = 'lv-applications';
    const DB_VERSION = '7';
    const DB_VERSION_OPTION = 'lv_applications_db_version';
    const SAVED_FILTERS_META = 'lv_apps_saved_filters_v1';
    const CLEANUP_HOOK = 'lv_apps_daily_cleanup';
    const TRASH_DAYS = 7;
    const TRASH_BATCH_SIZE = 100;

    private static $instance = null;
    private $hook_suffix = '';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ), 5 );
        add_action( 'admin_init', array( 'LV_DB_Migrator', 'maybe_upgrade' ), 12 );
        add_action( 'admin_init', array( $this, 'maybe_purge_trash' ), 20 );
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_bar_menu', array( $this, 'register_admin_bar_notice' ), 85 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        add_action( 'wpcf7_submit', array( $this, 'capture_cf7_submission' ), 30, 2 );

        add_action( 'wp_ajax_lv_apps_toggle_processed', array( $this, 'ajax_toggle_processed' ) );
        add_action( 'wp_ajax_lv_apps_quick_view', array( $this, 'ajax_quick_view' ) );
        add_action( 'wp_ajax_lv_apps_assign', array( $this, 'ajax_assign' ) );
        add_action( 'wp_ajax_lv_apps_add_note', array( $this, 'ajax_add_note' ) );


        add_action( 'admin_post_lv_apps_bulk_status', array( $this, 'bulk_status' ) );
        add_action( 'admin_post_lv_apps_trash', array( $this, 'trash_application' ) );
        add_action( 'admin_post_lv_apps_restore', array( $this, 'restore_application' ) );
        add_action( 'admin_post_lv_apps_delete_permanently', array( $this, 'delete_application_permanently' ) );

        add_action( 'admin_post_lv_apps_export', array( $this, 'export_data' ) );
        add_action( 'admin_post_lv_apps_save_filter', array( $this, 'save_filter' ) );
        add_action( 'admin_post_lv_apps_delete_filter', array( $this, 'delete_filter' ) );
        add_action( 'admin_post_lv_apps_request_delete', array( $this, 'request_deletion' ) );
        add_action( 'admin_post_lv_apps_cancel_delete_request', array( $this, 'cancel_deletion_request' ) );
        add_action( 'admin_post_lv_apps_empty_trash', array( $this, 'empty_trash' ) );
        add_action( 'admin_post_lv_apps_undo_trash', array( $this, 'undo_application_trash' ) );
        add_action( 'admin_post_lv_apps_download_file', array( $this, 'download_file' ) );
        add_action( 'admin_post_lv_apps_export_log', array( $this, 'export_action_log' ) );

        add_action( self::CLEANUP_HOOK, array( $this, 'purge_trash' ) );
    }

    public static function activate() {
        self::create_tables();
        LV_CRM_Extensions::install_schema();
        LV_DB_Migrator::on_activate();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
        if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled( self::CLEANUP_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CLEANUP_HOOK );
        }
    }

    public function maybe_upgrade_db() {
        if ( self::DB_VERSION !== (string) get_option( self::DB_VERSION_OPTION, '' ) ) {
            self::create_tables();
            if ( $this->backfill_search_and_fingerprints() ) {
                update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
            }
        }
        if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
        }
    }

    private static function create_tables() {
        global $wpdb;
        $table = self::table_name();
        $log_table = self::log_table_name();
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            form_title VARCHAR(255) NOT NULL DEFAULT '',
            form_code VARCHAR(80) NOT NULL DEFAULT '',
            form_schema_version VARCHAR(30) NOT NULL DEFAULT '',
            submission_uuid CHAR(36) NOT NULL DEFAULT '',
            contact_sync_status VARCHAR(40) NOT NULL DEFAULT '',
            submitted_at DATETIME NOT NULL,
            processed TINYINT(1) NOT NULL DEFAULT 0,
            fields_json LONGTEXT NOT NULL,
            search_text LONGTEXT NULL,
            source_url TEXT NULL,
            source_ip VARCHAR(100) NOT NULL DEFAULT '',
            user_agent TEXT NULL,
            submission_status VARCHAR(40) NOT NULL DEFAULT '',
            fingerprint CHAR(64) NOT NULL DEFAULT '',
            duplicate_of BIGINT UNSIGNED NULL,
            assignee_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            files_json LONGTEXT NULL,
            deletion_requested_at DATETIME NULL,
            deletion_requested_by BIGINT UNSIGNED NULL,
            deleted_at DATETIME NULL,
            deleted_by BIGINT UNSIGNED NULL,
            PRIMARY KEY  (id),
            KEY form_id (form_id),
            KEY form_code (form_code),
            KEY submission_uuid (submission_uuid),
            KEY contact_sync_status (contact_sync_status),
            KEY processed (processed),
            KEY submitted_at (submitted_at),
            KEY duplicate_of (duplicate_of),
            KEY fingerprint (fingerprint),
            KEY assignee_id (assignee_id),
            KEY deletion_requested_at (deletion_requested_at),
            KEY deleted_at (deleted_at)
        ) {$charset_collate};";
        dbDelta( $sql );

        $log_sql = "CREATE TABLE {$log_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(50) NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_role VARCHAR(80) NOT NULL DEFAULT '',
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY application_id (application_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta( $log_sql );

        $notes_table = self::notes_table_name();
        $notes_sql = "CREATE TABLE {$notes_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id BIGINT UNSIGNED NOT NULL,
            message LONGTEXT NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_role VARCHAR(80) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY application_id (application_id),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta( $notes_sql );
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'lv_applications';
    }

    public static function log_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'lv_application_log';
    }

    public static function notes_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'lv_application_notes';
    }

    public function capability() {
        return 'lv_view_applications';
    }

    public static function ensure_role_caps() {
        $sets = array(
            'contributor' => array( 'lv_view_applications', 'lv_update_applications', 'lv_add_application_notes', 'lv_request_application_deletion', 'lv_export_applications', 'lv_claim_applications' ),
            'author' => array( 'lv_view_applications', 'lv_update_applications', 'lv_add_application_notes', 'lv_request_application_deletion', 'lv_export_applications', 'lv_claim_applications' ),
            'editor' => array( 'lv_view_applications', 'lv_update_applications', 'lv_add_application_notes', 'lv_request_application_deletion', 'lv_export_applications', 'lv_assign_applications', 'lv_trash_applications', 'lv_export_contacts', 'lv_export_mailing', 'lv_manage_contact_segments', 'lv_manage_consents', 'lv_trash_contacts' ),
            'administrator' => array( 'lv_view_applications', 'lv_update_applications', 'lv_add_application_notes', 'lv_request_application_deletion', 'lv_export_applications', 'lv_assign_applications', 'lv_trash_applications', 'lv_restore_applications', 'lv_purge_applications', 'lv_export_application_log', 'lv_export_contacts', 'lv_export_mailing', 'lv_manage_contact_segments', 'lv_manage_consents', 'lv_trash_contacts', 'lv_restore_contacts', 'lv_purge_contacts' ),
        );
        foreach ( $sets as $role_name => $caps ) {
            $role = get_role( $role_name );
            if ( ! $role ) continue;
            foreach ( $caps as $cap ) $role->add_cap( $cap );
        }
    }

    public static function is_manager() {
        return current_user_can( 'lv_assign_applications' );
    }

    public static function is_admin_manager() {
        return current_user_can( 'lv_purge_applications' );
    }

    public static function current_role_slug() {
        $user = wp_get_current_user();
        foreach ( array( 'administrator', 'editor', 'author', 'contributor' ) as $role ) {
            if ( in_array( $role, (array) $user->roles, true ) ) return $role;
        }
        return isset( $user->roles[0] ) ? (string) $user->roles[0] : '';
    }

    public static function role_label( $slug ) {
        $map = array( 'administrator' => 'Администратор', 'editor' => 'Редактор', 'author' => 'Автор', 'contributor' => 'Участник' );
        return isset( $map[ $slug ] ) ? $map[ $slug ] : ( $slug ? $slug : 'Система' );
    }

    public static function can_access_application( $app ) {
        if ( ! $app || ! current_user_can( 'lv_view_applications' ) ) return false;
        if ( self::is_manager() ) return true;
        if ( ! empty( $app->deleted_at ) ) return false;
        $assignee = absint( $app->assignee_id );
        return 0 === $assignee || $assignee === get_current_user_id();
    }

    public static function can_edit_application( $app ) {
        if ( ! $app || ! current_user_can( 'lv_update_applications' ) || ! empty( $app->deleted_at ) ) return false;
        if ( self::is_manager() ) return true;
        return absint( $app->assignee_id ) === get_current_user_id();
    }

    public static function can_claim_application( $app ) {
        return $app && empty( $app->deleted_at ) && current_user_can( 'lv_claim_applications' ) && 0 === absint( $app->assignee_id );
    }

    public static function eligible_assignees() {
        $key = 'users.eligible_assignees';
        if ( class_exists( 'LV_Request_Cache' ) && LV_Request_Cache::has( $key ) ) {
            return LV_Request_Cache::get( $key );
        }
        $users = get_users( array( 'role__in' => array( 'contributor', 'author', 'editor', 'administrator' ), 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
        if ( class_exists( 'LV_Request_Cache' ) ) LV_Request_Cache::set( $key, $users );
        return $users;
    }

    public function register_menu() {
        $count = self::count_unprocessed_active();
        $bubble = $count > 0 ? ' <span class="awaiting-mod count-' . absint( $count ) . '"><span class="pending-count">' . esc_html( number_format_i18n( $count ) ) . '</span></span>' : '';
        $this->hook_suffix = add_menu_page(
            'CRM фонда',
            'CRM фонда' . $bubble,
            $this->capability(),
            self::PAGE_SLUG,
            array( $this, 'render_page' ),
            'dashicons-groups',
            26
        );
        add_submenu_page(
            self::PAGE_SLUG,
            'Заявки — CRM фонда',
            'Заявки',
            $this->capability(),
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
        add_submenu_page(
            self::PAGE_SLUG,
            'Контакты — CRM фонда',
            'Контакты',
            $this->capability(),
            LV_CRM_Extensions::CONTACTS_SLUG,
            array( LV_CRM_Extensions::instance(), 'render_contacts_page' )
        );
    }

    public function register_admin_bar_notice( $bar ) {
        if ( ! is_admin_bar_showing() || ! current_user_can( $this->capability() ) ) {
            return;
        }
        $count = self::count_unprocessed_active();
        if ( $count < 1 ) {
            return;
        }
        $bar->add_node( array(
            'id'    => 'lv-applications-unprocessed',
            'title' => '<span class="ab-icon dashicons dashicons-email-alt2"></span><span class="ab-label">Заявки: ' . esc_html( number_format_i18n( $count ) ) . '</span>',
            'href'  => add_query_arg( array( 'page' => self::PAGE_SLUG, 'lv_status' => 'unprocessed' ), admin_url( 'admin.php' ) ),
            'meta'  => array( 'title' => 'Необработанные заявки фонда' ),
        ) );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( false === strpos( (string) $hook, 'lv-applications' ) && false === strpos( (string) $hook, 'lv-crm-contacts' ) ) {
            return;
        }
        wp_enqueue_style( 'lv-applications-admin', LV_APPS_URL . 'assets/admin.css', array(), LV_APPS_VERSION );
        wp_enqueue_style( 'lv-applications-components', LV_APPS_URL . 'assets/admin-components.css', array( 'lv-applications-admin' ), LV_APPS_VERSION );
        wp_enqueue_script( 'lv-applications-admin', LV_APPS_URL . 'assets/admin.js', array( 'jquery' ), LV_APPS_VERSION, true );
        wp_localize_script( 'lv-applications-admin', 'LVApps', array(
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'toggleNonce'      => wp_create_nonce( 'lv_apps_toggle_processed' ),
            'quickViewNonce'   => wp_create_nonce( 'lv_apps_quick_view' ),
            'assignNonce'      => wp_create_nonce( 'lv_apps_assign' ),
            'noteNonce'        => wp_create_nonce( 'lv_apps_add_note' ),
            'priorityNonce'    => wp_create_nonce( 'lv_crm_priority' ),
            'tagsNonce'        => wp_create_nonce( 'lv_crm_application_tags' ),
            'contactSearchNonce' => wp_create_nonce( 'lv_crm_contact_search' ),
            'contactLinkNonce' => wp_create_nonce( 'lv_crm_link_contact' ),
            'contactAccountNonce' => wp_create_nonce( 'lv_crm_link_account' ),
            'contactNoteNonce' => wp_create_nonce( 'lv_crm_add_contact_note' ),
            'transferNonce'    => wp_create_nonce( 'lv_crm_transfer_preview' ),
            'contactExportNonce' => wp_create_nonce( 'lv_crm_contact_export' ),
            'mentionUsers'     => LV_CRM_Extensions::mention_users(),
            'error'            => 'Не удалось выполнить действие. Попробуйте ещё раз.',
            'trashConfirm'     => 'Переместить заявку в корзину? Она будет храниться 7 дней.',
            'deleteConfirm'    => 'Удалить заявку навсегда? Восстановить её будет невозможно.',
            'bulkTrashConfirm' => 'Переместить выбранные заявки в корзину?',
        ) );
    }

    public function cf7_ready() {
        return class_exists( 'WPCF7_Submission' );
    }

    public function capture_cf7_submission( $contact_form, $result ) {
        if ( ! is_object( $contact_form ) || ! class_exists( 'WPCF7_Submission' ) ) {
            return;
        }
        $status = is_array( $result ) && isset( $result['status'] ) ? sanitize_key( $result['status'] ) : '';
        if ( ! in_array( $status, array( 'mail_sent', 'mail_failed' ), true ) ) {
            return;
        }
        $submission = WPCF7_Submission::get_instance();
        if ( ! $submission ) {
            return;
        }
        $posted = $submission->get_posted_data();
        if ( ! is_array( $posted ) ) {
            return;
        }

        $template = method_exists( $contact_form, 'prop' ) ? (string) $contact_form->prop( 'form' ) : '';
        $form_contract = class_exists( 'LV_Form_Contracts' ) ? LV_Form_Contracts::resolve_submission( $contact_form, $posted ) : array( 'form_code'=>'', 'schema_version'=>'', 'warnings'=>array() );
        // Service identifiers and document revisions are defined by the
        // server-side CF7 template. Never persist a browser-tampered hidden
        // value when a canonical template default exists.
        if ( class_exists( 'LV_Form_Contracts' ) ) {
            if ( ! empty( $form_contract['form_code'] ) ) $posted['form_code'] = $form_contract['form_code'];
            if ( '' !== (string) ( $form_contract['schema_version'] ?? '' ) ) $posted['form_schema_version'] = $form_contract['schema_version'];
            foreach ( array( 'consent_pd_version', 'consent_marketing_version' ) as $service_key ) {
                $canonical = LV_Form_Contracts::hidden_default_from_template( $template, $service_key );
                if ( '' !== $canonical ) $posted[ $service_key ] = $canonical;
            }
        }
        $fields = array();
        $seen = array();

        if ( method_exists( $contact_form, 'scan_form_tags' ) ) {
            foreach ( (array) $contact_form->scan_form_tags() as $tag ) {
                $name = isset( $tag->name ) ? (string) $tag->name : '';
                if ( '' === $name || isset( $seen[ $name ] ) || ! array_key_exists( $name, $posted ) ) {
                    continue;
                }
                $seen[ $name ] = true;
                $fields[] = array(
                    'name'  => $name,
                    'label' => self::field_label( $template, $name ),
                    'value' => self::normalize_value( $posted[ $name ] ),
                );
            }
        }

        foreach ( $posted as $name => $value ) {
            $name = (string) $name;
            if ( isset( $seen[ $name ] ) ) {
                continue;
            }
            $fields[] = array(
                'name'  => $name,
                'label' => self::humanize_name( $name ),
                'value' => self::normalize_value( $value ),
            );
        }

        $form_id = method_exists( $contact_form, 'id' ) ? absint( $contact_form->id() ) : 0;
        $form_title = method_exists( $contact_form, 'title' ) ? (string) $contact_form->title() : 'Без названия';
        $source_url = method_exists( $submission, 'get_meta' ) ? esc_url_raw( (string) $submission->get_meta( 'url' ) ) : '';
        $source_ip = method_exists( $submission, 'get_meta' ) ? sanitize_text_field( (string) $submission->get_meta( 'remote_ip' ) ) : '';
        if ( ! $source_ip && isset( $_SERVER['REMOTE_ADDR'] ) ) $source_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        $user_agent = method_exists( $submission, 'get_meta' ) ? sanitize_textarea_field( (string) $submission->get_meta( 'user_agent' ) ) : '';
        if ( ! $user_agent && isset( $_SERVER['HTTP_USER_AGENT'] ) ) $user_agent = sanitize_textarea_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
        $submission_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'lv-', true );
        $uploaded_files = method_exists( $submission, 'uploaded_files' ) ? (array) $submission->uploaded_files() : array();
        $fingerprint = self::make_fingerprint( $form_id, $fields );

        global $wpdb;
        // Protect against accidental double-submit / repeated hook execution.
        if ( $fingerprint ) {
            $very_recent = $wpdb->get_var( $wpdb->prepare(
                'SELECT id FROM ' . self::table_name() . ' WHERE fingerprint = %s AND deleted_at IS NULL AND submitted_at >= %s ORDER BY id DESC LIMIT 1',
                $fingerprint,
                self::site_mysql_minus( 15 )
            ) );
            if ( $very_recent ) {
                self::log_event( absint( $very_recent ), 'duplicate_suppressed', 'Повторная идентичная отправка в течение 15 секунд не была сохранена.' );
                return;
            }
        }

        $duplicate_of = 0;
        if ( $fingerprint ) {
            $duplicate_of = absint( $wpdb->get_var( $wpdb->prepare(
                'SELECT id FROM ' . self::table_name() . ' WHERE fingerprint = %s AND deleted_at IS NULL AND submitted_at >= %s ORDER BY id DESC LIMIT 1',
                $fingerprint,
                self::site_mysql_minus( DAY_IN_SECONDS )
            ) ) );
        }

        $inserted = $wpdb->insert( self::table_name(), array(
            'form_id'             => $form_id,
            'form_title'          => $form_title,
            'form_code'           => sanitize_key( $form_contract['form_code'] ?? '' ),
            'form_schema_version' => sanitize_text_field( $form_contract['schema_version'] ?? '' ),
            'submission_uuid'     => sanitize_text_field( $submission_uuid ),
            'contact_sync_status' => '',
            'submitted_at'        => current_time( 'mysql' ),
            'processed'           => 0,
            'fields_json'         => wp_json_encode( $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'search_text'         => self::build_search_text( $fields ),
            'source_url'          => $source_url,
            'source_ip'           => $source_ip,
            'user_agent'          => $user_agent,
            'submission_status'   => $status,
            'fingerprint'         => $fingerprint,
            'duplicate_of'        => $duplicate_of ?: null,
            'assignee_id'         => 0,
            'files_json'          => '[]',
        ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ) );

        if ( $inserted ) {
            $id = absint( $wpdb->insert_id );
            $stored_files = self::persist_uploaded_files( $id, $uploaded_files );
            if ( $stored_files ) {
                $file_names = array(); foreach ( $stored_files as $f ) if ( ! empty( $f['original_name'] ) ) $file_names[] = $f['original_name'];
                $search_text = trim( self::build_search_text( $fields ) . ' ' . implode( ' ', $file_names ) );
                $wpdb->update( self::table_name(), array( 'files_json' => wp_json_encode( $stored_files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'search_text' => $search_text ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
            }
            self::log_event( $id, 'created', 'Заявка получена из формы «' . $form_title . '».' );
            if ( ! empty( $form_contract['warnings'] ) ) {
                self::log_event( $id, 'form_contract_warning', 'CRM обнаружила расхождение в служебных идентификаторах формы.', 0, array( 'warnings'=>(array)$form_contract['warnings'], 'form_code'=>$form_contract['form_code'] ?? '', 'schema_version'=>$form_contract['schema_version'] ?? '' ) );
            }
            if ( $stored_files ) self::log_event( $id, 'files_saved', 'Сохранены файлы из формы: ' . count( $stored_files ) . '.' );
            if ( $duplicate_of ) {
                self::log_event( $id, 'duplicate_detected', 'Возможный дубль заявки #' . $duplicate_of . '.', 0, array( 'duplicate_of' => $duplicate_of ) );
            }
            do_action( 'lv_crm_application_created', $id, $form_id, $form_title );
        }
    }

    private static function field_label( $template, $name ) {
        if ( $template ) {
            $needle = preg_quote( $name, '~' );
            $pattern = '~<label\\b[^>]*>((?:(?!<label\\b|</label>).)*)\\[[^\\]]+\\s+' . $needle . '(?:\\s[^\\]]*)?\\]((?:(?!<label\\b|</label>).)*)</label>~isu';
            if ( preg_match( $pattern, $template, $m ) ) {
                $text = self::clean_label_text( $m[1] . ' ' . $m[2] );
                if ( self::label_is_reasonable( $text ) ) {
                    return $text;
                }
            }
            if ( preg_match( '~(.{0,500})\\[[^\\]]+\\s+' . $needle . '(?:\\s[^\\]]*)?\\]~isu', $template, $m ) ) {
                if ( preg_match_all( '~<label\\b[^>]*>(?:(?!<label\\b|</label>).)*</label>~isu', $m[1], $labels ) && ! empty( $labels[0] ) ) {
                    $candidate = self::clean_label_text( end( $labels[0] ) );
                    if ( self::label_is_reasonable( $candidate ) ) {
                        return $candidate;
                    }
                }
            }
        }
        return self::humanize_name( $name );
    }

    private static function clean_label_text( $text ) {
        $text = preg_replace( '~\\[[^\\]]+\\]~u', ' ', (string) $text );
        $text = wp_strip_all_tags( $text );
        $text = preg_replace( '/\\s+/u', ' ', $text );
        return trim( $text, " \\t\\n\\r\\0\\x0B:*" );
    }

    private static function label_is_reasonable( $label ) {
        $label = trim( wp_strip_all_tags( (string) $label ) );
        if ( '' === $label ) {
            return false;
        }
        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $label, 'UTF-8' ) : strlen( $label );
        return $length <= 80;
    }

    private static function current_form_label_map( $form_id ) {
        static $cache = array();
        $form_id = absint( $form_id );
        if ( ! $form_id || ! class_exists( 'WPCF7_ContactForm' ) ) {
            return array();
        }
        if ( isset( $cache[ $form_id ] ) ) {
            return $cache[ $form_id ];
        }
        $map = array();
        $form = WPCF7_ContactForm::get_instance( $form_id );
        if ( $form && method_exists( $form, 'prop' ) && method_exists( $form, 'scan_form_tags' ) ) {
            $template = (string) $form->prop( 'form' );
            foreach ( (array) $form->scan_form_tags() as $tag ) {
                $name = isset( $tag->name ) ? (string) $tag->name : '';
                if ( '' !== $name ) {
                    $map[ $name ] = self::field_label( $template, $name );
                }
            }
        }
        $cache[ $form_id ] = $map;
        return $map;
    }

    public static function display_field_label( $application, $field ) {
        $name = isset( $field['name'] ) ? (string) $field['name'] : '';
        $saved = isset( $field['label'] ) ? trim( (string) $field['label'] ) : '';
        if ( $name && is_object( $application ) && ! empty( $application->form_id ) ) {
            $map = self::current_form_label_map( $application->form_id );
            if ( isset( $map[ $name ] ) && self::label_is_reasonable( $map[ $name ] ) ) {
                return $map[ $name ];
            }
        }
        if ( self::label_is_reasonable( $saved ) ) {
            return $saved;
        }
        return $name ? self::humanize_name( $name ) : 'Поле';
    }

    private static function humanize_name( $name ) {
        $label = preg_replace( '/[-_]+/u', ' ', (string) $name );
        $label = preg_replace( '/\\s+/u', ' ', $label );
        $label = trim( $label );
        if ( function_exists( 'mb_convert_case' ) ) {
            return mb_convert_case( $label, MB_CASE_TITLE, 'UTF-8' );
        }
        return ucfirst( $label );
    }

    public static function normalize_value( $value ) {
        if ( is_bool( $value ) || is_numeric( $value ) || is_string( $value ) || null === $value ) {
            return $value;
        }
        if ( is_array( $value ) ) {
            $result = array();
            foreach ( $value as $k => $v ) {
                $result[ $k ] = self::normalize_value( $v );
            }
            return $result;
        }
        return (string) $value;
    }

    public static function value_to_string( $value ) {
        if ( is_bool( $value ) ) {
            return $value ? 'Да' : 'Нет';
        }
        if ( null === $value ) {
            return '';
        }
        if ( is_scalar( $value ) ) {
            return (string) $value;
        }
        if ( is_array( $value ) ) {
            $flat = array();
            array_walk_recursive( $value, static function ( $item ) use ( &$flat ) {
                if ( is_bool( $item ) ) {
                    $flat[] = $item ? 'Да' : 'Нет';
                } elseif ( null !== $item && '' !== trim( (string) $item ) ) {
                    $flat[] = trim( (string) $item );
                }
            } );
            return implode( ', ', $flat );
        }
        return '';
    }

    public static function decode_fields( $json ) {
        $fields = json_decode( (string) $json, true );
        return is_array( $fields ) ? $fields : array();
    }

    public static function is_service_field( $name ) {
        $name = strtolower( trim( (string) $name ) );
        if ( '' === $name ) {
            return true;
        }
        $exact = array(
            '_wpnonce', '_wp_http_referer', 'g-recaptcha-response', 'h-captcha-response',
            'cf-turnstile-response', 'wpcf7_recaptcha_response', 'wpcf7_turnstile_response',
            'form_code', 'form_schema_version', 'consent_pd_version', 'consent_marketing_version',
        );
        if ( in_array( $name, $exact, true ) || 0 === strpos( $name, '_wpcf7' ) ) {
            return true;
        }
        return (bool) apply_filters( 'lv_applications_is_service_field', false, $name );
    }

    public static function visible_fields( $application ) {
        if ( is_object( $application ) && isset( $application->lv_visible_fields ) && is_array( $application->lv_visible_fields ) ) {
            return $application->lv_visible_fields;
        }
        $fields = self::decode_fields( is_object( $application ) ? $application->fields_json : '' );
        $result = array();
        foreach ( $fields as $field ) {
            $name = isset( $field['name'] ) ? (string) $field['name'] : '';
            if ( self::is_service_field( $name ) ) {
                continue;
            }
            $result[] = $field;
        }
        return $result;
    }

    public static function primary_fields_from_visible( $application, $visible_fields ) {
        $out = array( 'name' => null, 'organization' => null, 'phone' => null, 'email' => null, 'subject' => null );
        foreach ( (array) $visible_fields as $field ) {
            $value = trim( self::value_to_string( isset( $field['value'] ) ? $field['value'] : '' ) );
            if ( '' === $value ) continue;
            $name = isset( $field['name'] ) ? sanitize_key( (string) $field['name'] ) : '';
            if ( ! $out['name'] && 'contact_name' === $name ) { $out['name'] = $field; continue; }
            if ( ! $out['phone'] && 'contact_phone' === $name ) { $out['phone'] = $field; continue; }
            if ( ! $out['email'] && 'contact_email' === $name && is_email( $value ) ) { $out['email'] = $field; continue; }
            if ( ! $out['organization'] && 'contact_organization' === $name ) { $out['organization'] = $field; continue; }
            if ( ! $out['subject'] && in_array( $name, array( 'request_type', 'support_interest' ), true ) ) $out['subject'] = $field;
        }
        return array_filter( $out );
    }

    public static function primary_fields( $application ) {
        if ( is_object( $application ) && isset( $application->lv_primary_fields ) && is_array( $application->lv_primary_fields ) ) {
            return $application->lv_primary_fields;
        }
        return self::primary_fields_from_visible( $application, self::visible_fields( $application ) );
    }

    public static function application_heading_from_primary( $application, $primary ) {
        foreach ( array( 'name', 'organization', 'subject' ) as $key ) {
            if ( isset( $primary[ $key ] ) ) {
                $value = trim( self::value_to_string( $primary[ $key ]['value'] ) );
                if ( '' !== $value ) {
                    return $value;
                }
            }
        }
        return $application->form_title ?: 'Заявка #' . absint( $application->id );
    }

    public static function application_heading( $application ) {
        if ( is_object( $application ) && isset( $application->lv_heading ) ) {
            return (string) $application->lv_heading;
        }
        return self::application_heading_from_primary( $application, self::primary_fields( $application ) );
    }

    public static function field_value_html( $field, $application = null ) {
        $value = array_key_exists( 'value', $field ) ? $field['value'] : '';
        $raw_name = isset( $field['name'] ) ? (string) $field['name'] : '';
        $name = strtolower( $raw_name );
        $label = strtolower( self::display_field_label( $application, $field ) );
        if ( $application && $raw_name ) {
            $files = self::files_for_field( $application, $raw_name );
            if ( $files ) return self::files_html( $application, $files );
        }
        if ( is_array( $value ) ) {
            $flat = array();
            array_walk_recursive( $value, static function ( $item ) use ( &$flat ) {
                if ( is_bool( $item ) ) {
                    $flat[] = $item ? 'Да' : 'Нет';
                } elseif ( null !== $item && '' !== trim( (string) $item ) ) {
                    $flat[] = trim( (string) $item );
                }
            } );
            if ( empty( $flat ) ) {
                return '<span class="lv-empty-value">Не заполнено</span>';
            }
            $html = '<div class="lv-value-tags">';
            foreach ( $flat as $item ) {
                $html .= '<span>' . esc_html( $item ) . '</span>';
            }
            return $html . '</div>';
        }
        $text = trim( self::value_to_string( $value ) );
        if ( '' === $text ) {
            return '<span class="lv-empty-value">Не заполнено</span>';
        }
        if ( ( false !== strpos( $name, 'email' ) || false !== strpos( $label, 'email' ) || false !== strpos( $label, 'e-mail' ) || false !== strpos( $label, 'почт' ) ) && is_email( $text ) ) {
            return '<a class="lv-value-link" href="mailto:' . esc_attr( $text ) . '">' . esc_html( $text ) . '</a>';
        }
        if ( false !== strpos( $name, 'phone' ) || false !== strpos( $name, 'tel' ) || false !== strpos( $label, 'телефон' ) ) {
            $phone = preg_replace( '/[^0-9+]/', '', $text );
            if ( $phone ) {
                return '<a class="lv-value-link" href="tel:' . esc_attr( $phone ) . '">' . esc_html( $text ) . '</a>';
            }
        }
        return nl2br( esc_html( $text ) );
    }

    private static function build_search_text( $fields ) {
        $parts = array();
        foreach ( (array) $fields as $field ) {
            $name = isset( $field['name'] ) ? (string) $field['name'] : '';
            if ( self::is_service_field( $name ) ) {
                continue;
            }
            $label = isset( $field['label'] ) ? (string) $field['label'] : '';
            $value = self::value_to_string( isset( $field['value'] ) ? $field['value'] : '' );
            $parts[] = $name;
            $parts[] = $label;
            $parts[] = $value;
            $digits = preg_replace( '/\\D+/', '', $value );
            if ( strlen( $digits ) >= 5 ) {
                $parts[] = $digits;
            }
        }
        $text = implode( ' ', array_filter( $parts ) );
        $text = preg_replace( '/\\s+/u', ' ', $text );
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
    }

    private static function make_fingerprint( $form_id, $fields ) {
        $canonical = array();
        foreach ( (array) $fields as $field ) {
            $name = isset( $field['name'] ) ? (string) $field['name'] : '';
            if ( '' === $name || self::is_service_field( $name ) ) {
                continue;
            }
            $value = self::value_to_string( isset( $field['value'] ) ? $field['value'] : '' );
            $value = trim( preg_replace( '/\\s+/u', ' ', $value ) );
            if ( '' === $value ) {
                continue;
            }
            $canonical[ $name ] = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
        }
        if ( ! $canonical ) {
            return '';
        }
        ksort( $canonical, SORT_STRING );
        return hash( 'sha256', absint( $form_id ) . '|' . wp_json_encode( $canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
    }

    private function backfill_search_and_fingerprints() {
        global $wpdb;
        $batch_size = 300;
        $rows = $wpdb->get_results( 'SELECT id, form_id, fields_json FROM ' . self::table_name() . " WHERE search_text IS NULL OR search_text = '' OR fingerprint = '' ORDER BY id ASC LIMIT " . $batch_size );
        foreach ( (array) $rows as $row ) {
            $fields = self::decode_fields( $row->fields_json );
            $wpdb->update( self::table_name(), array(
                'search_text' => self::build_search_text( $fields ),
                'fingerprint' => self::make_fingerprint( $row->form_id, $fields ),
            ), array( 'id' => absint( $row->id ) ), array( '%s', '%s' ), array( '%d' ) );
        }
        if ( count( $rows ) < $batch_size ) {
            return true;
        }
        return ! (bool) $wpdb->get_var( 'SELECT id FROM ' . self::table_name() . " WHERE search_text IS NULL OR search_text = '' OR fingerprint = '' LIMIT 1" );
    }

    public static function submission_status_label( $status ) {
        switch ( (string) $status ) {
            case 'mail_sent': return array( 'Получено', 'ok' );
            case 'mail_failed': return array( 'Получено, письмо не отправлено', 'warn' );
            case 'imported': return array( 'Импортировано', 'muted' );
            default: return array( $status ? $status : '—', 'muted' );
        }
    }

    public static function contact_sync_status_label( $status ) {
        switch ( sanitize_key( (string) $status ) ) {
            case 'contact_created': return array( 'Контакт создан', 'ok' );
            case 'contact_linked': return array( 'Контакт связан', 'ok' );
            case 'contact_conflict': return array( 'Нужна проверка контакта', 'warn' );
            case 'invalid_contract': return array( 'Ошибка полей формы', 'warn' );
            case 'unsupported_schema': return array( 'Неизвестная версия формы', 'warn' );
            case 'contact_error': return array( 'Ошибка создания контакта', 'warn' );
            case 'link_error': return array( 'Ошибка связи контакта', 'warn' );
            case 'synced_with_warnings': return array( 'Связано с предупреждениями', 'warn' );
            case 'multiple_linked_contacts': return array( 'Несколько связанных контактов', 'warn' );
            default: return array( $status ? $status : 'Ожидает синхронизации', 'muted' );
        }
    }

    public static function source_url_label( $url ) {
        $parts = wp_parse_url( (string) $url );
        if ( ! is_array( $parts ) ) {
            return (string) $url;
        }
        $host = isset( $parts['host'] ) ? $parts['host'] : '';
        $path = isset( $parts['path'] ) && '/' !== $parts['path'] ? untrailingslashit( $parts['path'] ) : '/';
        return trim( $host . $path );
    }

    public static function get_application( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', absint( $id ) ) );
        if ( ! $row || ! class_exists( 'LV_Application_Query' ) ) {
            return $row;
        }
        $rows = LV_Application_Query::hydrate_rows( array( $row ) );
        return $rows ? $rows[0] : $row;
    }

    public static function request_filters() {
        $status = isset( $_REQUEST['lv_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['lv_status'] ) ) : '';
        if ( ! in_array( $status, array( '', 'processed', 'unprocessed' ), true ) ) $status = '';

        $view = isset( $_REQUEST['lv_view'] ) ? sanitize_key( wp_unslash( $_REQUEST['lv_view'] ) ) : 'active';
        if ( ! in_array( $view, array( 'active', 'trash' ), true ) ) $view = 'active';
        if ( ! self::is_manager() ) $view = 'active';

        $order = isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ? 'ASC' : 'DESC';
        $orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'date';
        if ( ! in_array( $orderby, array( 'date', 'form', 'status', 'assignee', 'priority' ), true ) ) $orderby = 'date';

        $assignee = '';
        if ( isset( $_REQUEST['lv_assignee'] ) ) {
            $raw = sanitize_text_field( wp_unslash( $_REQUEST['lv_assignee'] ) );
            if ( in_array( $raw, array( 'mine', 'unassigned' ), true ) ) {
                $assignee = $raw;
            } elseif ( self::is_manager() && ctype_digit( $raw ) && absint( $raw ) > 0 ) {
                $assignee = (string) absint( $raw );
            }
        }

        $priority = isset( $_REQUEST['lv_priority'] ) ? sanitize_key( wp_unslash( $_REQUEST['lv_priority'] ) ) : '';
        if ( $priority && ! isset( LV_CRM_Extensions::priority_options()[ $priority ] ) ) $priority = '';

        $per_page = isset( $_REQUEST['per_page'] ) ? absint( $_REQUEST['per_page'] ) : absint( get_user_meta( get_current_user_id(), 'lv_apps_per_page', true ) );
        if ( ! in_array( $per_page, array( 25, 50, 100 ), true ) ) $per_page = 25;
        if ( isset( $_REQUEST['per_page'] ) ) update_user_meta( get_current_user_id(), 'lv_apps_per_page', $per_page );

        return array(
            'form'      => isset( $_REQUEST['lv_form'] ) ? absint( $_REQUEST['lv_form'] ) : 0,
            'status'    => $status,
            'priority'  => $priority,
            'tag'       => isset( $_REQUEST['lv_tag'] ) ? absint( $_REQUEST['lv_tag'] ) : 0,
            'date_from' => self::sanitize_date( isset( $_REQUEST['lv_date_from'] ) ? wp_unslash( $_REQUEST['lv_date_from'] ) : '' ),
            'date_to'   => self::sanitize_date( isset( $_REQUEST['lv_date_to'] ) ? wp_unslash( $_REQUEST['lv_date_to'] ) : '' ),
            'search'    => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
            'orderby'   => $orderby,
            'order'     => $order,
            'view'      => $view,
            'assignee'  => $assignee,
            'paged'     => isset( $_REQUEST['paged'] ) ? max( 1, absint( $_REQUEST['paged'] ) ) : 1,
            'per_page'  => $per_page,
            'ids'       => array(),
        );
    }

    private static function sanitize_date( $date ) {
        $date = trim( (string) $date );
        if ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $date ) ) {
            $p = array_map( 'intval', explode( '-', $date ) );
            if ( checkdate( $p[1], $p[2], $p[0] ) ) {
                return $date;
            }
        }
        return '';
    }

    public static function get_forms() {
        $cache_key = 'applications.forms.' . ( self::is_manager() ? 'manager' : 'user.' . get_current_user_id() );
        if ( class_exists( 'LV_Request_Cache' ) && LV_Request_Cache::has( $cache_key ) ) {
            return LV_Request_Cache::get( $cache_key );
        }
        global $wpdb;
        if ( self::is_manager() ) {
            $forms = $wpdb->get_results( 'SELECT form_id, MAX(form_title) AS form_title, COUNT(*) AS total FROM ' . self::table_name() . ' GROUP BY form_id ORDER BY form_title ASC' );
        } else {
            $forms = $wpdb->get_results( $wpdb->prepare( 'SELECT form_id, MAX(form_title) AS form_title, COUNT(*) AS total FROM ' . self::table_name() . ' WHERE deleted_at IS NULL AND (assignee_id = 0 OR assignee_id = %d) GROUP BY form_id ORDER BY form_title ASC', get_current_user_id() ) );
        }
        if ( class_exists( 'LV_Request_Cache' ) ) {
            LV_Request_Cache::set( $cache_key, $forms );
        }
        return $forms;
    }

    public static function build_where( $filters, &$params ) {
        global $wpdb;
        $where = array();
        $params = array();
        $where[] = isset( $filters['view'] ) && 'trash' === $filters['view'] && self::is_manager() ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';
        if ( ! self::is_manager() ) {
            if ( ! empty( $filters['assignee'] ) && 'mine' === $filters['assignee'] ) {
                $where[] = 'assignee_id = %d';
                $params[] = get_current_user_id();
            } elseif ( ! empty( $filters['assignee'] ) && 'unassigned' === $filters['assignee'] ) {
                $where[] = 'assignee_id = 0';
            } else {
                $where[] = '(assignee_id = 0 OR assignee_id = %d)';
                $params[] = get_current_user_id();
            }
        } elseif ( ! empty( $filters['assignee'] ) ) {
            if ( 'mine' === $filters['assignee'] ) {
                $where[] = 'assignee_id = %d';
                $params[] = get_current_user_id();
            } elseif ( 'unassigned' === $filters['assignee'] ) {
                $where[] = 'assignee_id = 0';
            } elseif ( ctype_digit( (string) $filters['assignee'] ) ) {
                $where[] = 'assignee_id = %d';
                $params[] = absint( $filters['assignee'] );
            }
        }
        if ( ! empty( $filters['form'] ) ) {
            $where[] = 'form_id = %d';
            $params[] = absint( $filters['form'] );
        }
        if ( 'processed' === $filters['status'] ) {
            $where[] = 'processed = 1';
        } elseif ( 'unprocessed' === $filters['status'] ) {
            $where[] = 'processed = 0';
        }
        if ( ! empty( $filters['priority'] ) ) {
            $where[] = 'priority = %s';
            $params[] = sanitize_key( $filters['priority'] );
        }
        if ( ! empty( $filters['tag'] ) ) {
            $where[] = 'EXISTS (SELECT 1 FROM ' . LV_CRM_Extensions::application_tags_table() . ' lat WHERE lat.application_id = ' . self::table_name() . '.id AND lat.tag_id = %d)';
            $params[] = absint( $filters['tag'] );
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where[] = 'submitted_at >= %s';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where[] = 'submitted_at <= %s';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if ( ! empty( $filters['ids'] ) && is_array( $filters['ids'] ) ) {
            $ids = array_values( array_filter( array_map( 'absint', $filters['ids'] ) ) );
            if ( $ids ) {
                $where[] = 'id IN (' . implode( ',', $ids ) . ')';
            }
        }
        if ( ! empty( $filters['search'] ) ) {
            $search = trim( $filters['search'] );
            if ( preg_match( '/^#?(\d+)$/', $search, $id_match ) ) {
                $id_search = $id_match[1];
                $where[] = '(id = %d OR search_text LIKE %s OR form_title LIKE %s)';
                $params[] = absint( $id_search );
                $like = '%' . $wpdb->esc_like( $id_search ) . '%';
                $params[] = $like;
                $params[] = $like;
            } else {
                $tokens = preg_split( '/\\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY );
                $tokens = array_slice( $tokens, 0, 6 );
                foreach ( $tokens as $token ) {
                    $like = '%' . $wpdb->esc_like( function_exists( 'mb_strtolower' ) ? mb_strtolower( $token, 'UTF-8' ) : strtolower( $token ) ) . '%';
                    $digits = preg_replace( '/\\D+/', '', $token );
                    if ( strlen( $digits ) >= 5 ) {
                        $where[] = '(search_text LIKE %s OR search_text LIKE %s OR form_title LIKE %s)';
                        $params[] = $like;
                        $params[] = '%' . $wpdb->esc_like( $digits ) . '%';
                        $params[] = $like;
                    } else {
                        $where[] = '(search_text LIKE %s OR form_title LIKE %s)';
                        $params[] = $like;
                        $params[] = $like;
                    }
                }
            }
        }
        return implode( ' AND ', $where );
    }

    public static function query_applications( $filters, $limit = 25, $offset = 0 ) {
        return LV_Application_Query::get( $filters, $limit, $offset );
    }

    public static function application_ids( $filters, $limit = 0, $offset = 0 ) {
        return LV_Application_Query::ids( $filters, $limit, $offset );
    }

    public static function count_applications( $filters ) {
        return LV_Application_Query::count( $filters );
    }

    public static function count_unprocessed_active() {
        $cache_key = 'applications.unprocessed.' . ( self::is_manager() ? 'manager' : 'user.' . get_current_user_id() );
        if ( class_exists( 'LV_Request_Cache' ) && LV_Request_Cache::has( $cache_key ) ) {
            return (int) LV_Request_Cache::get( $cache_key );
        }
        global $wpdb;
        if ( self::is_manager() ) {
            $count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE deleted_at IS NULL AND processed = 0' );
        } else {
            $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE deleted_at IS NULL AND processed = 0 AND (assignee_id = 0 OR assignee_id = %d)', get_current_user_id() ) );
        }
        if ( class_exists( 'LV_Request_Cache' ) ) {
            LV_Request_Cache::set( $cache_key, $count );
        }
        return $count;
    }

    public static function count_trash() {
        if ( ! self::is_manager() ) return 0;
        $cache_key = 'applications.trash.count';
        if ( class_exists( 'LV_Request_Cache' ) && LV_Request_Cache::has( $cache_key ) ) {
            return (int) LV_Request_Cache::get( $cache_key );
        }
        global $wpdb;
        $count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE deleted_at IS NOT NULL' );
        if ( class_exists( 'LV_Request_Cache' ) ) LV_Request_Cache::set( $cache_key, $count );
        return $count;
    }

    public static function summary_counts( $filters ) {
        return LV_Application_Query::summary( $filters );
    }

    public static function log_event( $application_id, $event_type, $message, $user_id = null, $meta = array() ) {
        global $wpdb;
        if ( null === $user_id ) {
            $user_id = get_current_user_id();
        }
        $role = '';
        if ( $user_id ) {
            $u = get_userdata( absint( $user_id ) );
            if ( $u ) {
                foreach ( array( 'administrator', 'editor', 'author', 'contributor' ) as $r ) { if ( in_array( $r, (array) $u->roles, true ) ) { $role = $r; break; } }
                if ( ! $role && ! empty( $u->roles[0] ) ) $role = (string) $u->roles[0];
            }
        }
        $wpdb->insert( self::log_table_name(), array(
            'application_id' => absint( $application_id ),
            'event_type'     => sanitize_key( $event_type ),
            'message'        => wp_strip_all_tags( (string) $message ),
            'user_id'        => absint( $user_id ),
            'user_role'      => $role,
            'meta_json'      => $meta ? wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null,
            'created_at'     => current_time( 'mysql' ),
        ), array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' ) );
    }

    public static function get_logs( $application_id, $limit = 30 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::log_table_name() . ' WHERE application_id = %d ORDER BY created_at DESC, id DESC LIMIT %d',
            absint( $application_id ), absint( $limit )
        ) );
    }

    public static function log_actor( $log ) {
        if ( ! empty( $log->user_id ) ) {
            $user = get_userdata( absint( $log->user_id ) );
            if ( $user ) {
                return $user->display_name;
            }
        }
        return 'Система';
    }

    public static function log_role_label( $log ) {
        $role = isset( $log->user_role ) ? (string) $log->user_role : '';
        if ( ! $role && ! empty( $log->user_id ) ) {
            $u = get_userdata( absint( $log->user_id ) );
            if ( $u ) foreach ( array( 'administrator', 'editor', 'author', 'contributor' ) as $r ) if ( in_array( $r, (array) $u->roles, true ) ) { $role = $r; break; }
        }
        return self::role_label( $role );
    }

    public static function get_notes( $application_id, $limit = 100 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::notes_table_name() . ' WHERE application_id = %d ORDER BY created_at DESC, id DESC LIMIT %d', absint( $application_id ), absint( $limit ) ) );
    }

    public static function note_actor( $note ) {
        $u = ! empty( $note->user_id ) ? get_userdata( absint( $note->user_id ) ) : false;
        return $u ? $u->display_name : 'Система';
    }

    public static function action_url( $action, $id, $redirect = '' ) {
        $map = array(
            'trash'   => 'lv_apps_trash',
            'restore' => 'lv_apps_restore',
            'delete'  => 'lv_apps_delete_permanently',
            'request_delete' => 'lv_apps_request_delete',
            'cancel_delete_request' => 'lv_apps_cancel_delete_request',
        );
        if ( ! isset( $map[ $action ] ) ) {
            return '#';
        }
        $args = array( 'action' => $map[ $action ], 'application_id' => absint( $id ) );
        if ( $redirect ) {
            $args['redirect_to'] = $redirect;
        }
        return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), $map[ $action ] . '_' . absint( $id ) );
    }

    public static function trash_days_left( $application ) {
        if ( empty( $application->deleted_at ) ) {
            return self::TRASH_DAYS;
        }
        try {
            $deleted = new DateTimeImmutable( $application->deleted_at, wp_timezone() );
            $expires = $deleted->modify( '+' . self::TRASH_DAYS . ' days' );
            $seconds = $expires->getTimestamp() - current_datetime()->getTimestamp();
            return max( 0, (int) ceil( $seconds / DAY_IN_SECONDS ) );
        } catch ( Exception $e ) {
            return self::TRASH_DAYS;
        }
    }

    private static function site_mysql_minus( $seconds ) {
        $dt = current_datetime();
        $dt = $dt->modify( '-' . absint( $seconds ) . ' seconds' );
        return $dt->format( 'Y-m-d H:i:s' );
    }

    public function maybe_purge_trash() {
        // WP-Cron is the primary cleanup mechanism. The admin request is only a cheap
        // fallback when cron has not completed for more than 36 hours.
        $last = (int) get_option( 'lv_apps_last_trash_purge', 0 );
        if ( $last && ( time() - $last ) < 36 * HOUR_IN_SECONDS ) {
            return;
        }
        $this->purge_trash();
    }

    public function purge_trash() {
        global $wpdb;
        $threshold = self::site_mysql_minus( self::TRASH_DAYS * DAY_IN_SECONDS );
        $apps = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table_name() . ' WHERE deleted_at IS NOT NULL AND deleted_at <= %s ORDER BY deleted_at ASC,id ASC LIMIT %d',
            $threshold,
            self::TRASH_BATCH_SIZE
        ) );
        foreach ( (array) $apps as $app ) {
            self::log_event( $app->id, 'auto_deleted', 'Заявка окончательно удалена после 7 дней в корзине.', 0 );
            $this->permanently_delete_application( $app );
        }
        update_option( 'lv_apps_last_trash_purge', time(), false );
    }

    public static function count_deletion_requests() {
        global $wpdb;
        if ( self::is_manager() ) {
            return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE deleted_at IS NULL AND deletion_requested_at IS NOT NULL' );
        }
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE deleted_at IS NULL AND deletion_requested_at IS NOT NULL AND assignee_id = %d', get_current_user_id() ) );
    }

    public static function assignee_name( $id ) {
        $id = absint( $id );
        if ( ! $id ) return 'Не назначен';
        $key = 'user.name.' . $id;
        if ( class_exists( 'LV_Request_Cache' ) && LV_Request_Cache::has( $key ) ) return LV_Request_Cache::get( $key );
        $user = get_userdata( $id );
        $name = $user ? $user->display_name : 'Пользователь #' . $id;
        if ( class_exists( 'LV_Request_Cache' ) ) LV_Request_Cache::set( $key, $name );
        return $name;
    }

    public static function assignee_role_label( $id ) {
        $id = absint( $id );
        if ( ! $id ) return '';
        $key = 'user.role_label.' . $id;
        if ( class_exists( 'LV_Request_Cache' ) && LV_Request_Cache::has( $key ) ) return LV_Request_Cache::get( $key );
        $user = get_userdata( $id );
        if ( ! $user ) return '';
        $label = '';
        foreach ( array( 'administrator', 'editor', 'author', 'contributor' ) as $role ) {
            if ( in_array( $role, (array) $user->roles, true ) ) {
                $label = self::role_label( $role );
                break;
            }
        }
        if ( ! $label && ! empty( $user->roles[0] ) ) $label = self::role_label( $user->roles[0] );
        if ( class_exists( 'LV_Request_Cache' ) ) LV_Request_Cache::set( $key, $label );
        return $label;
    }

    private static function eligible_assignee( $user_id ) {
        $user = get_userdata( absint( $user_id ) );
        if ( ! $user ) return false;
        return (bool) array_intersect( array( 'contributor', 'author', 'editor', 'administrator' ), (array) $user->roles );
    }

    public static function eligible_assignee_public( $user_id ) {
        return self::eligible_assignee( $user_id );
    }

    public function ajax_assign() {
        if ( ! current_user_can( 'lv_assign_applications' ) && ! current_user_can( 'lv_claim_applications' ) ) {
            wp_send_json_error( array( 'message' => 'Недостаточно прав.' ), 403 );
        }
        check_ajax_referer( 'lv_apps_assign', 'nonce' );
        $id = isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0;
        $assignee = isset( $_POST['assignee_id'] ) ? absint( $_POST['assignee_id'] ) : 0;
        $app = self::get_application( $id );
        if ( ! $app || ! self::can_access_application( $app ) || $app->deleted_at ) {
            wp_send_json_error( array( 'message' => 'Заявка недоступна.' ), 404 );
        }

        $old = absint( $app->assignee_id );
        if ( self::is_manager() ) {
            if ( $assignee && ! self::eligible_assignee( $assignee ) ) {
                wp_send_json_error( array( 'message' => 'Выбранный пользователь не может быть ответственным.' ), 400 );
            }
        } else {
            if ( ! current_user_can( 'lv_claim_applications' ) || 0 !== $old || $assignee !== get_current_user_id() ) {
                wp_send_json_error( array( 'message' => 'Вы можете взять себе только нераспределённую заявку.' ), 403 );
            }
        }

        if ( $old === $assignee ) {
            wp_send_json_success( array( 'name' => self::assignee_name( $assignee ), 'role' => self::assignee_role_label( $assignee ), 'claimed' => ! self::is_manager() ) );
        }

        global $wpdb;
        if ( self::is_manager() ) {
            $ok = $wpdb->update( self::table_name(), array( 'assignee_id' => $assignee ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
        } else {
            // Atomic self-claim: if another employee took the application a moment earlier,
            // the conditional UPDATE affects zero rows and we do not overwrite the assignee.
            $ok = $wpdb->update( self::table_name(), array( 'assignee_id' => $assignee ), array( 'id' => $id, 'assignee_id' => 0 ), array( '%d' ), array( '%d', '%d' ) );
            if ( 0 === $ok ) {
                $fresh = self::get_application( $id );
                if ( $fresh && absint( $fresh->assignee_id ) === get_current_user_id() ) {
                    wp_send_json_success( array( 'name' => self::assignee_name( $assignee ), 'role' => self::assignee_role_label( $assignee ), 'claimed' => true, 'unprocessedCount' => self::count_unprocessed_active() ) );
                }
                wp_send_json_error( array( 'message' => 'Эту заявку уже успел взять другой сотрудник. Обновите список.' ), 409 );
            }
        }
        if ( false === $ok ) wp_send_json_error( array( 'message' => 'Не удалось сохранить ответственного.' ), 500 );

        $message = $assignee ? 'Ответственным назначен ' . self::assignee_name( $assignee ) . '.' : 'Ответственный снят.';
        $event = ( ! self::is_manager() && $assignee === get_current_user_id() ) ? 'application_claimed' : 'assignee_changed';
        if ( 'application_claimed' === $event ) $message = self::assignee_name( $assignee ) . ' взял(а) нераспределённую заявку в работу.';
        self::log_event( $id, $event, $message, null, array( 'from' => $old, 'to' => $assignee ) );
        if ( self::is_manager() && $assignee && $assignee !== get_current_user_id() && class_exists( 'LV_CRM_Extensions' ) ) {
            LV_CRM_Extensions::notify( $assignee, 'assignment', 'application', $id, 'Вам назначена заявка #' . $id . '.' );
        }

        wp_send_json_success( array(
            'name' => self::assignee_name( $assignee ),
            'role' => self::assignee_role_label( $assignee ),
            'claimed' => 'application_claimed' === $event,
            'unprocessedCount' => self::count_unprocessed_active(),
        ) );
    }

    public function ajax_add_note() {
        if ( ! current_user_can( 'lv_add_application_notes' ) ) wp_send_json_error( array( 'message' => 'Недостаточно прав.' ), 403 );
        check_ajax_referer( 'lv_apps_add_note', 'nonce' );
        $id = isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0;
        $message = isset( $_POST['message'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) ) : '';
        if ( '' === $message ) wp_send_json_error( array( 'message' => 'Введите текст заметки.' ), 400 );
        $app = self::get_application( $id );
        if ( ! $app || ! self::can_edit_application( $app ) ) wp_send_json_error( array( 'message' => 'Сначала назначьте заявку себе, чтобы добавить заметку.' ), 403 );
        global $wpdb;
        $uid = get_current_user_id();
        $role = self::current_role_slug();
        $ok = $wpdb->insert( self::notes_table_name(), array(
            'entity_type' => 'application',
            'entity_id' => $id,
            'application_id' => $id,
            'message' => $message,
            'user_id' => $uid,
            'user_role' => $role,
            'created_at' => current_time( 'mysql' ),
        ), array( '%s', '%d', '%d', '%s', '%d', '%s', '%s' ) );
        if ( ! $ok ) wp_send_json_error( array( 'message' => 'Не удалось сохранить заметку.' ), 500 );
        self::log_event( $id, 'note_added', 'Добавлена внутренняя заметка.' );
        LV_CRM_Extensions::process_mentions( $message, 'application', $id );
        wp_send_json_success( array( 'html' => $this->notes_html( self::get_notes( $id ) ) ) );
    }

    private function notes_html( $notes ) {
        if ( ! $notes ) return '<div class="lv-notes-empty">Внутренних заметок пока нет.</div>';
        $html = '<div class="lv-notes-list">';
        foreach ( $notes as $note ) {
            $html .= '<article class="lv-note"><header><strong>' . esc_html( self::note_actor( $note ) ) . '</strong><span class="lv-role-chip">' . esc_html( self::role_label( $note->user_role ) ) . '</span><time>' . esc_html( mysql2date( 'd.m.Y H:i', $note->created_at ) ) . '</time></header><p>' . LV_CRM_Extensions::render_mentions( $note->message ) . '</p></article>';
        }
        return $html . '</div>';
    }

    public static function legacy_private_storage_dir() {
        return WP_CONTENT_DIR . '/lv-applications-private';
    }

    public static function private_storage_dir() {
        // One level above the WordPress installation parent keeps static files
        // outside the usual document root on Apache, Nginx and IIS deployments.
        $parent = dirname( dirname( untrailingslashit( WP_CONTENT_DIR ) ) );
        $site_key = substr( hash( 'sha256', untrailingslashit( ABSPATH ) ), 0, 12 );
        $default = trailingslashit( $parent ) . 'lv-applications-private-' . $site_key;
        $dir = (string) apply_filters( 'lv_applications_private_storage_dir', $default );
        return untrailingslashit( wp_normalize_path( $dir ) );
    }

    public static function ensure_private_storage() {
        $dir = self::private_storage_dir();
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) return false;
        if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) return false;
        if ( ! file_exists( $dir . '/index.php' ) ) @file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
        if ( ! file_exists( $dir . '/.htaccess' ) ) @file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
        if ( ! file_exists( $dir . '/web.config' ) ) @file_put_contents( $dir . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' );
        return true;
    }

    private static function stored_file_path( $application_id, $stored_name ) {
        $application_id = absint( $application_id );
        $stored_name = wp_basename( (string) $stored_name );
        foreach ( array_unique( array( self::private_storage_dir(), self::legacy_private_storage_dir() ) ) as $base ) {
            $path = $base . '/' . $application_id . '/' . $stored_name;
            if ( is_file( $path ) && is_readable( $path ) ) return $path;
        }
        return '';
    }

    public static function persist_uploaded_files( $application_id, $uploaded_files ) {
        if ( ! self::ensure_private_storage() ) return array();
        $base = self::private_storage_dir();
        $app_dir = $base . '/' . absint( $application_id );
        if ( ! is_dir( $app_dir ) && ! wp_mkdir_p( $app_dir ) ) return array();
        $result = array();
        foreach ( (array) $uploaded_files as $field_name => $paths ) {
            foreach ( (array) $paths as $path ) {
                if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) continue;
                $original = wp_basename( $path );
                $ext = pathinfo( $original, PATHINFO_EXTENSION );
                $stored = str_replace( '-', '', wp_generate_uuid4() ) . ( $ext ? '.' . sanitize_key( $ext ) : '' );
                $target = $app_dir . '/' . $stored;
                if ( ! @copy( $path, $target ) ) continue;
                @chmod( $target, 0600 );
                $type = wp_check_filetype( $original );
                $result[] = array(
                    'field_name' => (string) $field_name,
                    'original_name' => sanitize_file_name( $original ),
                    'stored_name' => $stored,
                    'size' => (int) @filesize( $target ),
                    'mime' => ! empty( $type['type'] ) ? $type['type'] : 'application/octet-stream',
                );
            }
        }
        return $result;
    }

    public static function decode_files_raw( $json ) {
        if ( empty( $json ) ) return array();
        $files = json_decode( (string) $json, true );
        return is_array( $files ) ? $files : array();
    }

    public static function decode_files( $application ) {
        if ( ! $application ) return array();
        if ( isset( $application->lv_files ) && is_array( $application->lv_files ) ) return $application->lv_files;
        return self::decode_files_raw( isset( $application->files_json ) ? $application->files_json : '' );
    }

    public static function files_for_field( $application, $field_name ) {
        $out = array();
        foreach ( self::decode_files( $application ) as $file ) {
            if ( isset( $file['field_name'] ) && (string) $file['field_name'] === (string) $field_name ) $out[] = $file;
        }
        return $out;
    }

    public static function files_html( $application, $files = null ) {
        if ( null === $files ) $files = self::decode_files( $application );
        if ( ! $files ) return '<span class="lv-empty-value">Файлы не приложены</span>';
        $html = '<div class="lv-file-list">';
        $all_files = self::decode_files( $application );
        foreach ( $files as $file ) {
            $index = 0;
            foreach ( $all_files as $i => $candidate ) { if ( ! empty( $candidate['stored_name'] ) && ! empty( $file['stored_name'] ) && $candidate['stored_name'] === $file['stored_name'] ) { $index = $i; break; } }
            $name = isset( $file['original_name'] ) ? $file['original_name'] : 'Файл';
            $size = ! empty( $file['size'] ) ? size_format( $file['size'], 1 ) : '';
            $url = wp_nonce_url( add_query_arg( array( 'action' => 'lv_apps_download_file', 'application_id' => absint( $application->id ), 'file' => absint( $index ) ), admin_url( 'admin-post.php' ) ), 'lv_apps_download_file_' . absint( $application->id ) . '_' . absint( $index ) );
            $html .= '<a class="lv-file-card" href="' . esc_url( $url ) . '"><span class="dashicons dashicons-media-default"></span><span><strong>' . esc_html( $name ) . '</strong><small>' . esc_html( $size ) . '</small></span><span class="dashicons dashicons-download"></span></a>';
        }
        return $html . '</div>';
    }

    public function download_file() {
        if ( ! current_user_can( $this->capability() ) ) wp_die( 'Недостаточно прав.' );
        $id = isset( $_GET['application_id'] ) ? absint( $_GET['application_id'] ) : 0;
        $index = isset( $_GET['file'] ) ? absint( $_GET['file'] ) : -1;
        check_admin_referer( 'lv_apps_download_file_' . $id . '_' . $index );
        $app = self::get_application( $id );
        if ( ! $app || ! self::can_access_application( $app ) ) wp_die( 'Файл недоступен.' );
        $files = self::decode_files( $app );
        if ( ! isset( $files[ $index ]['stored_name'] ) ) wp_die( 'Файл не найден.' );
        $stored = wp_basename( $files[ $index ]['stored_name'] );
        $path = self::stored_file_path( $id, $stored );
        if ( ! $path ) wp_die( 'Файл не найден на сервере.' );
        while ( ob_get_level() ) ob_end_clean();
        nocache_headers();
        header( 'Content-Type: ' . ( ! empty( $files[$index]['mime'] ) ? $files[$index]['mime'] : 'application/octet-stream' ) );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Content-Disposition: attachment; filename="' . rawurlencode( $files[$index]['original_name'] ) . '"; filename*=UTF-8\'\'' . rawurlencode( $files[$index]['original_name'] ) );
        readfile( $path );
        exit;
    }

    private static function delete_application_files( $app ) {
        foreach ( array_unique( array( self::private_storage_dir(), self::legacy_private_storage_dir() ) ) as $base ) {
            $dir = $base . '/' . absint( $app->id );
            if ( is_dir( $dir ) ) {
                foreach ( glob( $dir . '/*' ) ?: array() as $file ) if ( is_file( $file ) ) @unlink( $file );
                @rmdir( $dir );
            }
        }
    }

    private function permanently_delete_application( $app ) {
        if ( ! $app ) return 0;
        global $wpdb;
        $id = absint( $app->id );
        if ( ! $id ) return 0;

        $wpdb->query( 'START TRANSACTION' );
        try {
            $wpdb->update( self::table_name(), array( 'duplicate_of' => null ), array( 'duplicate_of' => $id ), array( '%d' ), array( '%d' ) );
            $wpdb->delete( self::notes_table_name(), array( 'application_id' => $id ), array( '%d' ) );

            if ( class_exists( 'LV_CRM_Extensions' ) ) {
                $wpdb->delete( LV_CRM_Extensions::application_tags_table(), array( 'application_id' => $id ), array( '%d' ) );
                $wpdb->delete( LV_CRM_Extensions::application_contacts_table(), array( 'application_id' => $id ), array( '%d' ) );
                $wpdb->delete( LV_CRM_Extensions::notifications_table(), array( 'entity_type' => 'application', 'entity_id' => $id ), array( '%s', '%d' ) );
            }
            if ( class_exists( 'LV_Consent_Service' ) ) {
                $wpdb->update( LV_Consent_Service::table_name(), array( 'source_application_id' => 0 ), array( 'source_application_id' => $id ), array( '%d' ), array( '%d' ) );
            }

            $deleted = (int) $wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
            if ( ! $deleted ) {
                throw new RuntimeException( 'Application row was not deleted.' );
            }
            $wpdb->delete( self::log_table_name(), array( 'application_id' => $id ), array( '%d' ) );
            $wpdb->query( 'COMMIT' );
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return 0;
        }

        // Files are removed only after the database commit. A failed filesystem cleanup
        // can leave harmless orphan files, while the reverse order could lose a file on rollback.
        self::delete_application_files( $app );
        if ( class_exists( 'LV_Request_Cache' ) ) LV_Request_Cache::flush();
        return $deleted;
    }

    public function request_deletion() {
        if ( ! current_user_can( 'lv_request_application_deletion' ) ) wp_die( 'Недостаточно прав.' );
        $id = isset( $_GET['application_id'] ) ? absint( $_GET['application_id'] ) : 0;
        check_admin_referer( 'lv_apps_request_delete_' . $id );
        $app = self::get_application( $id );
        if ( ! $app || ! self::can_edit_application( $app ) ) wp_die( 'Сначала назначьте заявку себе.' );
        global $wpdb;
        $changed = 0;
        if ( empty( $app->deletion_requested_at ) ) {
            $changed = (int) $wpdb->update( self::table_name(), array( 'deletion_requested_at' => current_time( 'mysql' ), 'deletion_requested_by' => get_current_user_id() ), array( 'id' => $id ), array( '%s', '%d' ), array( '%d' ) );
            if ( $changed ) self::log_event( $id, 'deletion_requested', 'Запрошено удаление заявки.' );
        }
        $redirect = $this->safe_redirect_from_request( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
        wp_safe_redirect( add_query_arg( 'lv_delete_requested', $changed, $redirect ) ); exit;
    }

    public function cancel_deletion_request() {
        if ( ! current_user_can( 'lv_request_application_deletion' ) ) wp_die( 'Недостаточно прав.' );
        $id = isset( $_GET['application_id'] ) ? absint( $_GET['application_id'] ) : 0;
        check_admin_referer( 'lv_apps_cancel_delete_request_' . $id );
        $app = self::get_application( $id );
        if ( ! $app || ! self::can_edit_application( $app ) ) wp_die( 'Сначала назначьте заявку себе.' );
        if ( ! self::is_manager() && absint( $app->deletion_requested_by ) !== get_current_user_id() ) wp_die( 'Отменить запрос может только его автор.' );
        global $wpdb;
        $changed = (int) $wpdb->update( self::table_name(), array( 'deletion_requested_at' => null, 'deletion_requested_by' => null ), array( 'id' => $id ), array( '%s', '%d' ), array( '%d' ) );
        if ( $changed ) self::log_event( $id, 'deletion_request_cancelled', 'Запрос на удаление отменён.' );
        $redirect = $this->safe_redirect_from_request( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
        wp_safe_redirect( $redirect ); exit;
    }

    public function empty_trash() {
        if ( ! current_user_can( 'lv_purge_applications' ) ) wp_die( 'Недостаточно прав.' );
        check_admin_referer( 'lv_apps_empty_trash' );
        global $wpdb;
        $apps = $wpdb->get_results( 'SELECT * FROM ' . self::table_name() . ' WHERE deleted_at IS NOT NULL' );
        $count = 0;
        foreach ( $apps as $app ) $count += $this->permanently_delete_application( $app );
        wp_safe_redirect( add_query_arg( 'lv_deleted', $count, admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&lv_view=trash' ) ) ); exit;
    }

    public function export_action_log() {
        if ( ! current_user_can( 'lv_export_application_log' ) ) wp_die( 'Недостаточно прав.' );
        check_admin_referer( 'lv_apps_export_log' );
        global $wpdb;
        $logs = $wpdb->get_results( 'SELECT l.*, a.form_title FROM ' . self::log_table_name() . ' l LEFT JOIN ' . self::table_name() . ' a ON a.id = l.application_id ORDER BY l.created_at DESC, l.id DESC' );
        $rows = array( array( 'Дата', 'ID заявки', 'Форма', 'Событие', 'Описание', 'Пользователь', 'Роль' ) );
        foreach ( $logs as $log ) {
            $rows[] = array( mysql2date( 'd.m.Y H:i:s', $log->created_at ), $log->application_id, $log->form_title ?: '', $log->event_type, $log->message, self::log_actor( $log ), self::log_role_label( $log ) );
        }
        $writer = new LV_XLSX_Writer();
        $writer->add_sheet( 'Заявки', $rows );
        if ( class_exists( 'LV_CRM_Extensions' ) ) {
            $contact_logs = $wpdb->get_results( 'SELECT l.*, c.display_name FROM ' . LV_CRM_Extensions::contact_log_table() . ' l LEFT JOIN ' . LV_CRM_Extensions::contacts_table() . ' c ON c.id = l.contact_id ORDER BY l.created_at DESC, l.id DESC' );
            $contact_rows = array( array( 'Дата', 'ID контакта', 'Контакт', 'Событие', 'Описание', 'Пользователь', 'Роль' ) );
            foreach ( $contact_logs as $log ) {
                $user = $log->user_id ? get_userdata( absint( $log->user_id ) ) : false;
                $contact_rows[] = array(
                    mysql2date( 'd.m.Y H:i:s', $log->created_at ),
                    $log->contact_id,
                    $log->display_name ?: '',
                    $log->event_type,
                    $log->message,
                    $user ? $user->display_name : 'Система',
                    self::role_label( $log->user_role ),
                );
            }
            $writer->add_sheet( 'Контакты', $contact_rows );
        }
        if ( class_exists( 'LV_Contact_Exporter' ) ) {
            $export_logs = $wpdb->get_results( 'SELECT * FROM ' . LV_Contact_Exporter::export_log_table() . ' ORDER BY created_at DESC, id DESC' );
            $export_rows = array( array( 'Дата', 'Пользователь', 'Профиль', 'Режим', 'Сегмент', 'Контактов', 'Строк', 'Файл', 'Фильтры' ) );
            foreach ( $export_logs as $log ) {
                $user = $log->user_id ? get_userdata( absint( $log->user_id ) ) : false;
                $export_rows[] = array( mysql2date( 'd.m.Y H:i:s', $log->created_at ), $user ? $user->display_name : 'Система', $log->profile, $log->mode, $log->segment_name, $log->contacts_count, $log->rows_count, $log->filename, $log->filters_json );
            }
            $writer->add_sheet( 'Выгрузки', $export_rows );
        }
        if ( class_exists( 'LV_Consent_Service' ) ) {
            $consent_logs = $wpdb->get_results( 'SELECT * FROM ' . LV_Consent_Service::log_table_name() . ' ORDER BY created_at DESC, id DESC' );
            $consent_rows = array( array( 'Дата', 'ID контакта', 'Вид согласия', 'Канал', 'Email', 'Статус', 'Источник', 'Версия документа', 'Код формы', 'UUID отправки', 'Связанная заявка', 'Подтверждение', 'Пользователь' ) );
            foreach ( $consent_logs as $log ) {
                $user = $log->user_id ? get_userdata( absint( $log->user_id ) ) : false;
                $consent_rows[] = array( mysql2date( 'd.m.Y H:i:s', $log->created_at ), $log->contact_id, $log->consent_type ?? '', $log->channel ?? '', $log->email_value, $log->status, $log->source, $log->document_version ?? '', $log->form_code ?? '', $log->submission_uuid ?? '', $log->source_application_id, $log->evidence, $user ? $user->display_name : 'Система' );
            }
            $writer->add_sheet( 'Согласия', $consent_rows );
        }
        $writer->download( 'zhurnal-crm-' . wp_date( 'Y-m-d-His' ) . '.xlsx' );
    }

    public function render_page() {
        if ( ! current_user_can( $this->capability() ) ) {
            wp_die( 'Недостаточно прав.' );
        }
        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
        if ( 'view' === $action ) {
            $this->render_application_view();
            return;
        }
        $screen = isset( $_GET['lv_screen'] ) ? sanitize_key( wp_unslash( $_GET['lv_screen'] ) ) : 'list';
        if ( 'dashboard' === $screen ) {
            $this->render_dashboard();
            return;
        }
        if ( 'routing' === $screen ) {
            LV_CRM_Extensions::instance()->render_routing();
            return;
        }
        if ( 'tags' === $screen ) {
            LV_CRM_Extensions::instance()->render_tags();
            return;
        }
        if ( 'notifications' === $screen ) {
            LV_CRM_Extensions::instance()->render_notifications();
            return;
        }

        $filters = self::request_filters();
        $table = new LV_Applications_Table( $this, $filters );
        $table->prepare_items();
        $counts = self::summary_counts( $filters );
        $trash_count = self::count_trash();
        $saved_filters = $this->get_saved_filters();

        echo '<div class="wrap lv-applications-wrap">';
        echo '<header class="lv-page-head"><div class="lv-head-copy"><span class="lv-kicker">CRM фонда · Реестр обращений</span><div class="lv-title-row"><h1>Заявки</h1>';
        echo $this->cf7_ready() ? '<span class="lv-live-badge"><i></i> Приём заявок активен</span>' : '<span class="lv-live-badge is-offline"><i></i> CF7 не активен</span>';
        echo '</div><p>Рабочая очередь обращений с быстрым просмотром, поиском, журналом действий и безопасной корзиной.</p></div>';
        echo '<div class="lv-head-actions"><a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'lv_screen' => 'dashboard' ), admin_url( 'admin.php' ) ) ) . '"><span class="dashicons dashicons-chart-area"></span><span>Обзор</span></a>';
        if ( current_user_can( 'lv_export_applications' ) ) echo '<button type="button" class="button lv-export-primary lv-open-export"><span class="dashicons dashicons-download"></span><span>Экспорт</span></button>';
        if ( current_user_can( 'lv_export_application_log' ) ) { $journal = wp_nonce_url( admin_url( 'admin-post.php?action=lv_apps_export_log' ), 'lv_apps_export_log' ); echo '<a class="button" href="' . esc_url( $journal ) . '"><span class="dashicons dashicons-media-spreadsheet"></span><span>Журнал</span></a>'; }
        echo '</div></header>';

        if ( ! $this->cf7_ready() ) {
            echo '<div class="lv-alert lv-alert--warning"><span class="dashicons dashicons-warning"></span><div><strong>Contact Form 7 не активен</strong><p>Существующие записи доступны, но новые заявки не будут поступать.</p></div></div>';
        }
        $this->render_messages();

        $this->render_workspace_nav( $filters, 'list' );

        echo '<div class="lv-summary-grid">';
        if ( 'trash' === $filters['view'] ) {
            $this->summary_card( 'В корзине', $counts['total'], 'dashicons-trash', 'is-trash' );
            $this->summary_card( 'Были не обработаны', $counts['unprocessed'], 'dashicons-clock', 'is-new' );
            $this->summary_card( 'Были обработаны', $counts['processed'], 'dashicons-yes-alt', 'is-done' );
        } else {
            $this->summary_card( 'Заявок в выборке', $counts['total'], 'dashicons-forms', '' );
            $this->summary_card( 'Ждут обработки', $counts['unprocessed'], 'dashicons-clock', 'is-new' );
            $this->summary_card( 'Обработано', $counts['processed'], 'dashicons-yes-alt', 'is-done' );
        }
        echo '</div>';

        echo '<section class="lv-panel lv-filter-panel">';
        echo '<div class="lv-section-head"><div><span class="dashicons dashicons-filter"></span><strong>Фильтры и поиск</strong></div><span>Поиск понимает имя, email, телефон без форматирования, текст ответа и ID заявки</span></div>';
        $this->render_saved_filters( $saved_filters, $filters );
        echo '<form method="get" class="lv-filter-form"><input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '"><input type="hidden" name="lv_view" value="' . esc_attr( $filters['view'] ) . '">';
        $this->render_filters( $filters );
        echo '</form>';
        $this->render_save_filter_form( $filters );
        echo '</section>';

        echo '<section class="lv-panel lv-table-panel">';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lv-bulk-form">';
        echo '<input type="hidden" name="action" value="lv_apps_bulk_status">';
        echo '<input type="hidden" name="selection_scope" value="page">';
        echo '<input type="hidden" name="selected_ids" value="">';
        echo '<input type="hidden" name="lv_view" value="' . esc_attr( $filters['view'] ) . '">';
        echo '<input type="hidden" name="lv_form" value="' . esc_attr( $filters['form'] ) . '"><input type="hidden" name="lv_status" value="' . esc_attr( $filters['status'] ) . '"><input type="hidden" name="lv_priority" value="' . esc_attr( $filters['priority'] ) . '"><input type="hidden" name="lv_tag" value="' . esc_attr( $filters['tag'] ) . '"><input type="hidden" name="lv_assignee" value="' . esc_attr( isset( $filters['assignee'] ) ? $filters['assignee'] : '' ) . '"><input type="hidden" name="lv_date_from" value="' . esc_attr( $filters['date_from'] ) . '"><input type="hidden" name="lv_date_to" value="' . esc_attr( $filters['date_to'] ) . '"><input type="hidden" name="s" value="' . esc_attr( $filters['search'] ) . '"><input type="hidden" name="orderby" value="' . esc_attr( $filters['orderby'] ) . '"><input type="hidden" name="order" value="' . esc_attr( 'ASC' === $filters['order'] ? 'asc' : 'desc' ) . '"><input type="hidden" name="paged" value="' . esc_attr( $filters['paged'] ) . '"><input type="hidden" name="per_page" value="' . esc_attr( $filters['per_page'] ) . '">';
        wp_nonce_field( 'lv_apps_bulk_status' );

        $per_page_base = remove_query_arg( array( 'paged', 'per_page' ), self::filters_url( $filters ) );
        echo '<div class="lv-table-toolbar"><div class="lv-toolbar-left"><strong>' . ( 'trash' === $filters['view'] ? 'Корзина' : 'Список заявок' ) . '</strong><span>' . esc_html( number_format_i18n( $counts['total'] ) ) . ' в текущей выборке</span></div><div class="lv-table-tools"><label class="lv-per-page-label">На странице <select class="lv-per-page" data-base-url="' . esc_url( $per_page_base ) . '">';
        foreach ( array( 25, 50, 100 ) as $size ) echo '<option value="' . esc_attr( $size ) . '" ' . selected( $filters['per_page'], $size, false ) . '>' . esc_html( $size ) . '</option>';
        echo '</select></label></div></div>';

        echo '<div class="lv-selection-toolbar" hidden data-total="' . esc_attr( $counts['total'] ) . '">';
        echo '<div class="lv-selection-main"><strong class="lv-selection-count">Выбрано: 0</strong><span class="lv-selection-scope-text">Выбраны записи на текущей странице.</span><button type="button" class="button-link lv-select-all-filtered">Выбрать все ' . esc_html( number_format_i18n( $counts['total'] ) ) . ' по фильтрам</button><button type="button" class="button-link lv-clear-application-selection">Снять выделение</button></div>';
        echo '<div class="lv-selection-actions"><select name="bulk_status" class="lv-application-bulk-action"><option value="">Действие с выбранными</option>';
        if ( 'trash' === $filters['view'] ) {
            if ( current_user_can( 'lv_restore_applications' ) ) echo '<option value="restore">Восстановить</option>';
            if ( current_user_can( 'lv_purge_applications' ) ) echo '<option value="delete_permanently">Удалить навсегда</option>';
        } else {
            echo '<option value="processed">Отметить обработанными</option><option value="unprocessed">Отметить необработанными</option>';
            echo '<optgroup label="Приоритет"><option value="priority_urgent">Срочный</option><option value="priority_high">Высокий</option><option value="priority_normal">Обычный</option><option value="priority_low">Низкий</option></optgroup>';
            if ( self::is_manager() ) {
                echo '<optgroup label="Ответственный"><option value="assignee_0">Снять ответственного</option><option value="assignee_self">Назначить меня</option>';
                foreach ( self::eligible_assignees() as $user ) echo '<option value="assignee_' . esc_attr( $user->ID ) . '">' . esc_html( $user->display_name ) . '</option>';
                echo '</optgroup>';
            } elseif ( current_user_can( 'lv_claim_applications' ) ) {
                echo '<option value="assignee_self">Взять выбранные себе</option>';
            }
            $bulk_tags = LV_CRM_Extensions::get_tags( 'application' );
            if ( $bulk_tags ) {
                echo '<optgroup label="Добавить метку">';
                foreach ( $bulk_tags as $tag ) echo '<option value="tag_add_' . esc_attr( $tag->id ) . '">' . esc_html( $tag->name ) . '</option>';
                echo '</optgroup><optgroup label="Снять метку">';
                foreach ( $bulk_tags as $tag ) echo '<option value="tag_remove_' . esc_attr( $tag->id ) . '">' . esc_html( $tag->name ) . '</option>';
                echo '</optgroup>';
            }
            if ( ! self::is_manager() && current_user_can( 'lv_request_application_deletion' ) ) echo '<option value="request_delete">Запросить удаление</option>';
            if ( current_user_can( 'lv_trash_applications' ) ) echo '<option value="trash">Переместить в корзину</option>';
        }
        echo '</select><button class="button button-primary">Применить</button></div></div>';
        echo '<div class="lv-table-scroll" role="region" aria-label="Таблица заявок" tabindex="0">';
        $table->display();
        echo '</div>';
        echo '</form></section>';

        if ( 'active' === $filters['view'] ) {
        } else {
            echo '<div class="lv-trash-note"><span class="dashicons dashicons-clock"></span><div><strong>Автоматическая очистка включена</strong><p>Записи удаляются навсегда через 7 дней после перемещения в корзину. Восстановление доступно только администраторам.</p></div>';
            if ( current_user_can( 'lv_purge_applications' ) ) { $empty = wp_nonce_url( admin_url( 'admin-post.php?action=lv_apps_empty_trash' ), 'lv_apps_empty_trash' ); echo '<a class="button lv-empty-trash" href="' . esc_url( $empty ) . '"><span class="dashicons dashicons-trash"></span>Очистить корзину</a>'; }
            echo '</div>';
        }

        if ( current_user_can( 'lv_export_applications' ) ) $this->render_export_modal( $filters );
        echo '<div class="lv-drawer-backdrop" hidden></div><aside class="lv-quick-drawer" role="dialog" aria-modal="true" aria-label="Быстрый просмотр заявки" aria-hidden="true" tabindex="-1"><div class="lv-drawer-shell"><button type="button" class="lv-drawer-close" aria-label="Закрыть быстрый просмотр"><span class="dashicons dashicons-no-alt"></span></button><div class="lv-drawer-content"><div class="lv-drawer-loading"><span class="spinner is-active"></span><p>Загрузка заявки…</p></div></div></div></aside>';
        echo '</div>';
    }

    public function render_workspace_nav( $filters = array(), $screen = 'list' ) {
        $filters = wp_parse_args( $filters, array(
            'view'     => 'active',
            'assignee' => '',
        ) );
        $base = admin_url( 'admin.php' );
        $is_application_screen = in_array( $screen, array( 'list', 'application' ), true );
        echo '<nav class="lv-workspace-tabs" aria-label="Разделы CRM">';
        echo '<a class="' . ( 'dashboard' === $screen ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'lv_screen' => 'dashboard' ), $base ) ) . '"><span class="dashicons dashicons-chart-area"></span>Обзор</a>';
        if ( self::is_manager() ) {
            $is_all = $is_application_screen && 'active' === $filters['view'] && empty( $filters['assignee'] );
            echo '<a class="' . ( $is_all ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG ), $base ) ) . '"><span class="dashicons dashicons-forms"></span>Все заявки</a>';
            echo '<a class="' . ( $is_application_screen && 'mine' === $filters['assignee'] ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'lv_assignee' => 'mine' ), $base ) ) . '"><span class="dashicons dashicons-admin-users"></span>Мои</a>';
            echo '<a class="' . ( $is_application_screen && 'unassigned' === $filters['assignee'] ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'lv_assignee' => 'unassigned' ), $base ) ) . '"><span class="dashicons dashicons-universal-access-alt"></span>Нераспределённые</a>';
            echo '<a class="' . ( 'routing' === $screen ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'lv_screen' => 'routing' ), $base ) ) . '"><span class="dashicons dashicons-randomize"></span>Распределение</a>';
            echo '<a class="' . ( 'tags' === $screen ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'lv_screen' => 'tags' ), $base ) ) . '"><span class="dashicons dashicons-tag"></span>Метки</a>';
            echo '<a class="' . ( $is_application_screen && 'trash' === $filters['view'] ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'lv_view' => 'trash' ), $base ) ) . '"><span class="dashicons dashicons-trash"></span>Корзина <b>' . esc_html( number_format_i18n( self::count_trash() ) ) . '</b></a>';
        } else {
            $is_available = $is_application_screen && empty( $filters['assignee'] );
            echo '<a class="' . ( $is_available ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG ), $base ) ) . '"><span class="dashicons dashicons-inbox"></span>Доступные</a>';
            echo '<a class="' . ( $is_application_screen && 'mine' === $filters['assignee'] ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'lv_assignee' => 'mine' ), $base ) ) . '"><span class="dashicons dashicons-admin-users"></span>Мои</a>';
            echo '<a class="' . ( $is_application_screen && 'unassigned' === $filters['assignee'] ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'lv_assignee' => 'unassigned' ), $base ) ) . '"><span class="dashicons dashicons-universal-access-alt"></span>Нераспределённые</a>';
        }
        if ( class_exists( 'LV_CRM_Extensions' ) && LV_CRM_Extensions::can_view_contacts() ) {
            echo '<a class="' . ( 'contacts' === $screen ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => LV_CRM_Extensions::CONTACTS_SLUG ), $base ) ) . '"><span class="dashicons dashicons-groups"></span>Контакты</a>';
        }
        $notifications = LV_CRM_Extensions::unread_notifications_count();
        echo '<a class="' . ( 'notifications' === $screen ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'lv_screen' => 'notifications' ), $base ) ) . '"><span class="dashicons dashicons-bell"></span>Уведомления' . ( $notifications ? ' <b>' . esc_html( $notifications ) . '</b>' : '' ) . '</a>';
        echo '</nav>';
    }

    private function render_dashboard() {
        $filters = array( 'form' => 0, 'status' => '', 'priority' => '', 'tag' => 0, 'date_from' => '', 'date_to' => '', 'search' => '', 'orderby' => 'date', 'order' => 'DESC', 'view' => 'active', 'assignee' => '', 'ids' => array() );
        $counts = self::summary_counts( $filters );
        $recent = self::query_applications( $filters, 8, 0 );
        $unprocessed_filters = $filters;
        $unprocessed_filters['status'] = 'unprocessed';
        $oldest = self::query_applications( array_merge( $unprocessed_filters, array( 'order' => 'ASC' ) ), 5, 0 );
        // Aggregate directly in SQL instead of loading an arbitrary 2000 full applications.
        // The dashboard remains exact regardless of database size.
        $form_stats = LV_Application_Query::group_counts( $filters, 'form', 8 );
        $assignee_stats = LV_Application_Query::group_counts( $filters, 'assignee', 8 );
        $ownership = LV_Application_Query::ownership_counts( $filters, get_current_user_id() );
        $mine = (int) $ownership['mine'];
        $unassigned = (int) $ownership['unassigned'];
        $delete_requests = self::count_deletion_requests();

        echo '<div class="wrap lv-applications-wrap lv-dashboard-wrap">';
        echo '<header class="lv-page-head"><div class="lv-head-copy"><span class="lv-kicker">Люди и Верблюды · Рабочий центр</span><div class="lv-title-row"><h1>Обзор CRM</h1><span class="lv-live-badge"><i></i> Система активна</span></div><p>Очередь обращений, доступные заявки и текущая нагрузка команды.</p></div><div class="lv-head-actions"><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '"><span class="dashicons dashicons-inbox"></span>Открыть заявки</a>';
        if ( current_user_can( 'lv_export_application_log' ) ) {
            $log_url = wp_nonce_url( admin_url( 'admin-post.php?action=lv_apps_export_log' ), 'lv_apps_export_log' );
            echo '<a class="button" href="' . esc_url( $log_url ) . '"><span class="dashicons dashicons-media-spreadsheet"></span>Журнал XLSX</a>';
        }
        echo '</div></header>';
        $this->render_workspace_nav( $filters, 'dashboard' );

        global $wpdb;
        $contacts_total = class_exists( 'LV_Contact_Query' ) ? ( new LV_Contact_Query( array( 'view' => 'active' ) ) )->count() : 0;
        $contacts_mine = class_exists( 'LV_CRM_Extensions' ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LV_CRM_Extensions::contacts_table() . ' WHERE curator_user_id = %d', get_current_user_id() ) ) : 0;
        $routing_enabled = ( self::is_manager() && class_exists( 'LV_CRM_Extensions' ) ) ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LV_CRM_Extensions::routing_rules_table() . ' WHERE enabled = 1' ) : 0;

        echo '<div class="lv-dashboard-stats">';
        $this->summary_card( self::is_manager() ? 'Всего активных' : 'Доступно мне', $counts['total'], 'dashicons-forms', '' );
        $this->summary_card( 'Ждут обработки', $counts['unprocessed'], 'dashicons-clock', 'is-new' );
        $this->summary_card( 'Обработано', $counts['processed'], 'dashicons-yes-alt', 'is-done' );
        $this->summary_card( 'Мои заявки', $mine, 'dashicons-admin-users', '' );
        $this->summary_card( 'Без ответственного', $unassigned, 'dashicons-universal-access-alt', 'is-new' );
        $this->summary_card( 'Запросы на удаление', $delete_requests, 'dashicons-trash', 'is-trash' );
        $this->summary_card( 'Контакты', $contacts_total, 'dashicons-groups', '' );
        $this->summary_card( 'Я куратор', $contacts_mine, 'dashicons-businessperson', '' );
        if ( self::is_manager() ) $this->summary_card( 'Автораспределение', $routing_enabled, 'dashicons-randomize', 'is-done' );
        echo '</div>';

        echo '<div class="lv-dashboard-grid"><section class="lv-panel lv-dashboard-panel"><div class="lv-panel-title"><span class="dashicons dashicons-clock"></span><h2>Последние заявки</h2><span>' . esc_html( count( $recent ) ) . '</span></div><div class="lv-dashboard-list">';
        if ( ! $recent ) echo '<div class="lv-log-empty">Заявок пока нет.</div>';
        foreach ( $recent as $app ) {
            $url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'action' => 'view', 'application_id' => $app->id ), admin_url( 'admin.php' ) );
            echo '<a class="lv-dashboard-row" href="' . esc_url( $url ) . '"><span class="lv-dashboard-status ' . ( $app->processed ? 'is-done' : 'is-new' ) . '"></span><div><strong>' . esc_html( self::application_heading( $app ) ) . '</strong><small>' . esc_html( $app->form_title ) . ' · ' . esc_html( mysql2date( 'd.m H:i', $app->submitted_at ) ) . '</small></div><span class="dashicons dashicons-arrow-right-alt2"></span></a>';
        }
        echo '</div></section>';

        echo '<section class="lv-panel lv-dashboard-panel"><div class="lv-panel-title"><span class="dashicons dashicons-chart-bar"></span><h2>По формам</h2></div><div class="lv-metric-list">';
        $max = $form_stats ? max( $form_stats ) : 1; $n = 0;
        foreach ( $form_stats as $name => $count ) { if ( $n++ >= 8 ) break; $pct = max( 4, (int) round( $count / $max * 100 ) ); echo '<div class="lv-metric"><div><span>' . esc_html( $name ) . '</span><strong>' . esc_html( $count ) . '</strong></div><i><b style="width:' . esc_attr( $pct ) . '%"></b></i></div>'; }
        if ( ! $form_stats ) echo '<div class="lv-log-empty">Нет данных.</div>';
        echo '</div></section>';

        echo '<section class="lv-panel lv-dashboard-panel"><div class="lv-panel-title"><span class="dashicons dashicons-warning"></span><h2>Самые старые необработанные</h2></div><div class="lv-dashboard-list">';
        if ( ! $oldest ) echo '<div class="lv-log-empty">Нет необработанных заявок.</div>';
        foreach ( $oldest as $app ) {
            $url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'action' => 'view', 'application_id' => $app->id ), admin_url( 'admin.php' ) );
            $age = human_time_diff( strtotime( $app->submitted_at ), current_time( 'timestamp' ) );
            echo '<a class="lv-dashboard-row" href="' . esc_url( $url ) . '"><span class="lv-dashboard-status is-new"></span><div><strong>' . esc_html( self::application_heading( $app ) ) . '</strong><small>' . esc_html( $app->form_title ) . ' · ' . esc_html( $age ) . ' назад</small></div><span class="dashicons dashicons-arrow-right-alt2"></span></a>';
        }
        echo '</div></section>';

        if ( self::is_manager() ) {
            echo '<section class="lv-panel lv-dashboard-panel"><div class="lv-panel-title"><span class="dashicons dashicons-groups"></span><h2>Распределение по сотрудникам</h2></div><div class="lv-metric-list">';
            $users = array(); foreach ( self::eligible_assignees() as $u ) $users[ $u->ID ] = $u->display_name;
            $max = $assignee_stats ? max( $assignee_stats ) : 1; $n=0;
            foreach ( $assignee_stats as $uid => $count ) { if ( $n++ >= 8 ) break; $name = $uid ? ( isset( $users[$uid] ) ? $users[$uid] : 'Пользователь #' . $uid ) : 'Не распределено'; $pct=max(4,(int)round($count/$max*100)); echo '<div class="lv-metric"><div><span>' . esc_html( $name ) . '</span><strong>' . esc_html( $count ) . '</strong></div><i><b style="width:' . esc_attr( $pct ) . '%"></b></i></div>'; }
            echo '</div></section>';
        }
        echo '</div></div>';
    }

    private function render_messages() {
        $map = array(
            'lv_trashed'  => array( 'success', 'Перемещено в корзину', 'Записей: %d. Они будут удалены через 7 дней.' ),
            'lv_restored' => array( 'success', 'Заявки восстановлены', 'Восстановлено: %d.' ),
            'lv_deleted'  => array( 'success', 'Удалено навсегда', 'Удалено: %d.' ),
            'lv_filter_saved' => array( 'success', 'Фильтр сохранён', 'Теперь он доступен в блоке сохранённых фильтров.' ),
            'lv_delete_requested' => array( 'success', 'Запрос на удаление создан', 'Заявок помечено: %d. Редактор или администратор сможет переместить их в корзину.' ),
        );
        foreach ( $map as $key => $data ) {
            if ( ! isset( $_GET[ $key ] ) ) continue;
            $value = absint( $_GET[ $key ] );
            $text = false !== strpos( $data[2], '%d' ) ? sprintf( $data[2], $value ) : $data[2];
            echo '<div class="lv-alert lv-alert--' . esc_attr( $data[0] ) . '"><span class="dashicons dashicons-yes-alt"></span><div><strong>' . esc_html( $data[1] ) . '</strong><p>' . esc_html( $text ) . '</p>';
            if ( 'lv_trashed' === $key && ! empty( $_GET['lv_undo_token'] ) ) {
                $token = sanitize_key( wp_unslash( $_GET['lv_undo_token'] ) );
                $undo = wp_nonce_url(
                    add_query_arg( array( 'action'=>'lv_apps_undo_trash', 'token'=>$token ), admin_url( 'admin-post.php' ) ),
                    'lv_apps_undo_trash_' . $token
                );
                echo '<p class="lv-alert-actions"><a class="button button-small" href="' . esc_url( $undo ) . '"><span class="dashicons dashicons-undo"></span>Отменить</a><a href="' . esc_url( add_query_arg( array( 'page'=>self::PAGE_SLUG, 'lv_view'=>'trash' ), admin_url( 'admin.php' ) ) ) . '">Открыть корзину</a></p>';
            }
            echo '</div></div>';
        }

        if ( ! empty( $_GET['lv_bulk_done'] ) ) {
            $selected = absint( $_GET['lv_bulk_selected'] ?? 0 );
            $changed = absint( $_GET['lv_bulk_changed'] ?? 0 );
            $skipped = absint( $_GET['lv_bulk_skipped'] ?? 0 );
            echo '<div class="lv-alert lv-alert--success"><span class="dashicons dashicons-yes-alt"></span><div><strong>Массовое действие выполнено</strong><p>Выбрано: ' . esc_html( $selected ) . ' · изменено: ' . esc_html( $changed ) . ( $skipped ? ' · пропущено: ' . esc_html( $skipped ) : '' ) . '.</p></div></div>';
        }
        elseif ( isset( $_GET['lv_bulk_selected'] ) ) {
            $selected = absint( $_GET['lv_bulk_selected'] );
            $changed = absint( $_GET['lv_bulk_changed'] ?? 0 );
            $skipped = absint( $_GET['lv_bulk_skipped'] ?? 0 );
            echo '<div class="lv-alert lv-alert--info"><span class="dashicons dashicons-list-view"></span><div><strong>Результат массовой операции</strong><p>Выбрано: ' . esc_html( $selected ) . ' · изменено: ' . esc_html( $changed ) . ( $skipped ? ' · пропущено: ' . esc_html( $skipped ) : '' ) . '.</p></div></div>';
        }
        if ( ! empty( $_GET['lv_bulk_empty'] ) ) {
            echo '<div class="lv-alert lv-alert--warning"><span class="dashicons dashicons-warning"></span><div><strong>Ничего не выбрано</strong><p>Отметьте строки или выберите всю текущую выборку по фильтрам.</p></div></div>';
        }
    }

    private function summary_card( $label, $value, $icon, $class ) {
        echo '<div class="lv-stat-card ' . esc_attr( $class ) . '"><div class="lv-stat-icon"><span class="dashicons ' . esc_attr( $icon ) . '"></span></div><div><span>' . esc_html( $label ) . '</span><strong>' . esc_html( number_format_i18n( $value ) ) . '</strong></div></div>';
    }

    private function render_filters( $filters ) {
        $forms = self::get_forms();
        echo '<div class="lv-filter-grid">';
        echo '<div class="lv-control"><label>Форма</label><select name="lv_form"><option value="0">Все формы</option>';
        foreach ( $forms as $form ) {
            echo '<option value="' . esc_attr( $form->form_id ) . '" ' . selected( absint( $filters['form'] ), absint( $form->form_id ), false ) . '>' . esc_html( $form->form_title ?: 'Без названия' ) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="lv-control"><label>Статус</label><select name="lv_status"><option value="" ' . selected( $filters['status'], '', false ) . '>Все</option><option value="unprocessed" ' . selected( $filters['status'], 'unprocessed', false ) . '>Не обработано</option><option value="processed" ' . selected( $filters['status'], 'processed', false ) . '>Обработано</option></select></div>';
        echo '<div class="lv-control"><label>Приоритет</label><select name="lv_priority"><option value="">Все</option>';
        foreach ( LV_CRM_Extensions::priority_options() as $key => $opt ) echo '<option value="' . esc_attr( $key ) . '" ' . selected( $filters['priority'], $key, false ) . '>' . esc_html( $opt[0] ) . '</option>';
        echo '</select></div>';
        echo '<div class="lv-control"><label>Метка</label><select name="lv_tag"><option value="0">Все метки</option>';
        foreach ( LV_CRM_Extensions::get_tags( 'application' ) as $tag ) echo '<option value="' . esc_attr( $tag->id ) . '" ' . selected( absint( $filters['tag'] ), absint( $tag->id ), false ) . '>' . esc_html( $tag->name ) . '</option>';
        echo '</select></div>';
        echo '<div class="lv-control"><label>Ответственный</label><select name="lv_assignee"><option value="">' . ( self::is_manager() ? 'Все' : 'Доступные мне' ) . '</option><option value="unassigned" ' . selected( $filters['assignee'], 'unassigned', false ) . '>Не распределены</option><option value="mine" ' . selected( $filters['assignee'], 'mine', false ) . '>Мои заявки</option>';
        if ( self::is_manager() ) {
            foreach ( self::eligible_assignees() as $user ) echo '<option value="' . esc_attr( $user->ID ) . '" ' . selected( (string) $filters['assignee'], (string) $user->ID, false ) . '>' . esc_html( $user->display_name ) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="lv-control"><label>Дата от</label><input type="date" name="lv_date_from" value="' . esc_attr( $filters['date_from'] ) . '"></div>';
        echo '<div class="lv-control"><label>Дата до</label><input type="date" name="lv_date_to" value="' . esc_attr( $filters['date_to'] ) . '"></div>';
        echo '<div class="lv-control lv-control--search"><label>Поиск</label><div class="lv-search-input"><span class="dashicons dashicons-search"></span><input type="search" name="s" value="' . esc_attr( $filters['search'] ) . '" placeholder="Имя, +7 987…, email, текст или #123"></div></div>';
        echo '<div class="lv-filter-buttons"><button class="button button-primary">Применить</button><a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'lv_view' => $filters['view'] ), admin_url( 'admin.php' ) ) ) . '">Сбросить</a></div>';
        echo '</div>';
    }

    private function get_saved_filters() {
        $saved = get_user_meta( get_current_user_id(), self::SAVED_FILTERS_META, true );
        return is_array( $saved ) ? $saved : array();
    }

    private function render_saved_filters( $saved, $filters ) {
        echo '<div class="lv-saved-filters"><div class="lv-saved-label"><span class="dashicons dashicons-star-filled"></span><span>Сохранённые</span></div><div class="lv-saved-list">';
        if ( ! $saved ) {
            echo '<span class="lv-saved-empty">Пока нет — сохраните часто используемую выборку</span>';
        } else {
            foreach ( $saved as $id => $item ) {
                if ( empty( $item['name'] ) || empty( $item['filters'] ) || ! is_array( $item['filters'] ) ) {
                    continue;
                }
                $url = self::filters_url( $item['filters'] );
                $delete = wp_nonce_url( add_query_arg( array( 'action' => 'lv_apps_delete_filter', 'filter_id' => $id ), admin_url( 'admin-post.php' ) ), 'lv_apps_delete_filter_' . $id );
                echo '<span class="lv-saved-chip"><a href="' . esc_url( $url ) . '">' . esc_html( $item['name'] ) . '</a><a class="lv-saved-delete" href="' . esc_url( $delete ) . '" title="Удалить сохранённый фильтр">×</a></span>';
            }
        }
        echo '</div><button type="button" class="button-link lv-show-save-filter"><span class="dashicons dashicons-plus-alt2"></span>Сохранить текущий</button></div>';
    }

    private function render_save_filter_form( $filters ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lv-save-filter-form" hidden><input type="hidden" name="action" value="lv_apps_save_filter">';
        wp_nonce_field( 'lv_apps_save_filter' );
        foreach ( array( 'form' => 'lv_form', 'status' => 'lv_status', 'priority' => 'lv_priority', 'tag' => 'lv_tag', 'date_from' => 'lv_date_from', 'date_to' => 'lv_date_to', 'search' => 's', 'view' => 'lv_view', 'assignee' => 'lv_assignee' ) as $key => $name ) {
            echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( isset( $filters[ $key ] ) ? $filters[ $key ] : '' ) . '">';
        }
        echo '<div><label for="lv-filter-name">Название фильтра</label><input id="lv-filter-name" name="filter_name" type="text" maxlength="60" required placeholder="Например, Новые волонтёры"><button class="button button-primary">Сохранить</button><button type="button" class="button lv-cancel-save-filter">Отмена</button></div></form>';
    }

    public function save_filter() {
        if ( ! current_user_can( $this->capability() ) ) {
            wp_die( 'Недостаточно прав.' );
        }
        check_admin_referer( 'lv_apps_save_filter' );
        $name = isset( $_POST['filter_name'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_name'] ) ) : '';
        if ( '' === $name ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
            exit;
        }
        $filters = self::request_filters();
        $saved = $this->get_saved_filters();
        $id = 'f_' . substr( wp_generate_uuid4(), 0, 8 );
        $saved[ $id ] = array( 'name' => $name, 'filters' => $filters );
        if ( count( $saved ) > 20 ) {
            $saved = array_slice( $saved, -20, null, true );
        }
        update_user_meta( get_current_user_id(), self::SAVED_FILTERS_META, $saved );
        wp_safe_redirect( add_query_arg( 'lv_filter_saved', 1, self::filters_url( $filters ) ) );
        exit;
    }

    public function delete_filter() {
        if ( ! current_user_can( $this->capability() ) ) {
            wp_die( 'Недостаточно прав.' );
        }
        $id = isset( $_GET['filter_id'] ) ? sanitize_key( wp_unslash( $_GET['filter_id'] ) ) : '';
        check_admin_referer( 'lv_apps_delete_filter_' . $id );
        $saved = $this->get_saved_filters();
        if ( isset( $saved[ $id ] ) ) {
            unset( $saved[ $id ] );
            update_user_meta( get_current_user_id(), self::SAVED_FILTERS_META, $saved );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
        exit;
    }

    public static function filters_query_args( $filters ) {
        $args = array();
        $map = array(
            'form' => 'lv_form', 'status' => 'lv_status', 'priority' => 'lv_priority',
            'tag' => 'lv_tag', 'date_from' => 'lv_date_from', 'date_to' => 'lv_date_to',
            'search' => 's', 'view' => 'lv_view', 'assignee' => 'lv_assignee',
            'orderby' => 'orderby', 'paged' => 'paged', 'per_page' => 'per_page',
        );
        foreach ( $map as $key => $arg ) {
            if ( ! isset( $filters[ $key ] ) ) continue;
            $value = $filters[ $key ];
            if ( '' === (string) $value ) continue;
            if ( 'view' === $key && 'active' === $value ) continue;
            if ( 'paged' === $key && absint( $value ) <= 1 ) continue;
            if ( 'per_page' === $key && 25 === absint( $value ) ) continue;
            $args[ $arg ] = $value;
        }
        if ( isset( $filters['order'] ) && 'ASC' === $filters['order'] ) $args['order'] = 'asc';
        return $args;
    }

    public static function filters_url( $filters ) {
        return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), self::filters_query_args( $filters ) ), admin_url( 'admin.php' ) );
    }

    private function render_application_view() {
        $id = isset( $_GET['application_id'] ) ? absint( $_GET['application_id'] ) : 0;
        $app = self::get_application( $id );
        if ( ! $app || ! self::can_access_application( $app ) ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>Заявка не найдена или у вас нет доступа.</p></div></div>';
            return;
        }
        $fields = self::visible_fields( $app );
        $files = self::decode_files( $app );
        $notes = self::get_notes( $app->id );
        $logs = self::get_logs( $app->id );

        $context_filters = self::request_filters();
        $context_filters['view'] = $app->deleted_at ? 'trash' : 'active';
        $back = self::filters_url( $context_filters );
        if ( ! empty( $_GET['return_to'] ) ) {
            $candidate = esc_url_raw( wp_unslash( $_GET['return_to'] ) );
            $back = wp_validate_redirect( $candidate, $back );
        }
        $status = self::submission_status_label( $app->submission_status );

        $sequence = self::application_ids( $context_filters, 0, 0 );
        $position = array_search( absint( $app->id ), $sequence, true );
        $prev_id = false !== $position && $position > 0 ? absint( $sequence[ $position - 1 ] ) : 0;
        $next_id = false !== $position && $position < count( $sequence ) - 1 ? absint( $sequence[ $position + 1 ] ) : 0;
        $detail_args = self::filters_query_args( $context_filters );

        echo '<div class="wrap lv-applications-wrap lv-detail-wrap"><div class="lv-detail-nav"><a href="' . esc_url( $back ) . '"><span class="dashicons dashicons-arrow-left-alt2"></span>К списку заявок</a><div class="lv-detail-pager">';
        if ( $prev_id ) echo '<a href="' . esc_url( add_query_arg( array_merge( $detail_args, array( 'page'=>self::PAGE_SLUG, 'action'=>'view', 'application_id'=>$prev_id, 'return_to'=>$back ) ), admin_url( 'admin.php' ) ) ) . '"><span class="dashicons dashicons-arrow-left-alt"></span>Предыдущая</a>';
        if ( $next_id ) echo '<a href="' . esc_url( add_query_arg( array_merge( $detail_args, array( 'page'=>self::PAGE_SLUG, 'action'=>'view', 'application_id'=>$next_id, 'return_to'=>$back ) ), admin_url( 'admin.php' ) ) ) . '">Следующая<span class="dashicons dashicons-arrow-right-alt"></span></a>';
        echo '</div></div>';
        echo '<header class="lv-detail-head"><div><span class="lv-kicker">' . esc_html( $app->form_title ) . ' · #' . esc_html( $app->id ) . '</span><h1>' . esc_html( self::application_heading( $app ) ) . '</h1><p>Получена ' . esc_html( mysql2date( 'd.m.Y в H:i', $app->submitted_at ) ) . '</p></div>';
        if ( $app->deleted_at ) {
            echo '<span class="lv-trash-badge"><span class="dashicons dashicons-trash"></span>В корзине · ' . esc_html( self::trash_days_left( $app ) ) . ' дн. до удаления</span>';
        } else {
            $done = (int) $app->processed === 1;
            if ( self::can_edit_application( $app ) ) {
                echo '<label class="lv-switch-card ' . ( $done ? 'is-processed' : '' ) . '"><input type="checkbox" class="lv-processed-toggle" data-id="' . esc_attr( $app->id ) . '" ' . checked( $done, true, false ) . '><span class="lv-switch"><i></i></span><span class="lv-switch-label">' . esc_html( $done ? 'Обработано' : 'Не обработано' ) . '</span></label>';
            } else {
                echo '<div class="lv-readonly-status ' . ( $done ? 'is-processed' : '' ) . '"><span class="lv-readonly-dot"></span><div><strong>' . esc_html( $done ? 'Обработано' : 'Не обработано' ) . '</strong><small>Возьмите заявку себе, чтобы изменить статус</small></div></div>';
            }
        }
        echo '</header>';
        $this->render_workspace_nav( array( 'view' => $app->deleted_at ? 'trash' : 'active' ), 'application' );

        if ( $app->duplicate_of ) {
            $dup_url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'action' => 'view', 'application_id' => absint( $app->duplicate_of ) ), admin_url( 'admin.php' ) );
            echo '<div class="lv-alert lv-alert--duplicate"><span class="dashicons dashicons-admin-page"></span><div><strong>Возможный дубль</strong><p>Похожая идентичная заявка уже поступала в течение суток: <a href="' . esc_url( $dup_url ) . '">#' . esc_html( $app->duplicate_of ) . '</a>.</p></div></div>';
        }

        if ( ! empty( $app->deletion_requested_at ) ) {
            $requester = $app->deletion_requested_by ? get_userdata( absint( $app->deletion_requested_by ) ) : false;
            echo '<div class="lv-alert lv-alert--warning"><span class="dashicons dashicons-trash"></span><div><strong>Запрошено удаление</strong><p>' . esc_html( $requester ? $requester->display_name : 'Пользователь' ) . ' запросил удаление ' . esc_html( mysql2date( 'd.m.Y в H:i', $app->deletion_requested_at ) ) . '.</p></div></div>';
        }

        echo '<div class="lv-detail-grid"><main class="lv-detail-main">';
        echo '<section class="lv-panel lv-answer-panel"><div class="lv-panel-title"><span class="dashicons dashicons-editor-table"></span><h2>Ответы формы</h2><span>' . esc_html( count( $fields ) ) . ' ' . esc_html( self::plural_fields_public( count( $fields ) ) ) . '</span></div><div class="lv-answer-cards">';
        foreach ( $fields as $field ) {
            $label = self::display_field_label( $app, $field );
            $value = self::value_to_string( isset( $field['value'] ) ? $field['value'] : '' );
            $wide = strlen( $value ) > 120 || false !== strpos( $value, "\n" );
            echo '<article class="lv-answer-card ' . ( $wide ? 'is-wide' : '' ) . '"><div class="lv-answer-key"><strong>' . esc_html( $label ) . '</strong><code>' . esc_html( isset( $field['name'] ) ? $field['name'] : '' ) . '</code></div><div class="lv-answer-value">' . self::field_value_html( $field, $app ) . '</div></article>';
        }
        echo '</div></section>';

        if ( $files ) echo '<section class="lv-panel lv-files-panel"><div class="lv-panel-title"><span class="dashicons dashicons-paperclip"></span><h2>Файлы формы</h2><span>' . esc_html( count( $files ) ) . '</span></div><div class="lv-files-body">' . self::files_html( $app, $files ) . '</div></section>';

        echo '<section class="lv-panel lv-application-contacts-section">' . LV_CRM_Extensions::application_contacts_panel( $app ) . '</section>';

        echo '<section class="lv-panel lv-notes-panel"><div class="lv-panel-title"><span class="dashicons dashicons-admin-comments"></span><h2>Комментарии и внутренние заметки</h2><span>' . esc_html( count( $notes ) ) . '</span></div>';
        if ( self::can_edit_application( $app ) ) {
            echo '<div class="lv-note-compose"><textarea class="lv-note-input" rows="3" placeholder="Добавить комментарий или заметку. Введите @ и выберите коллегу из списка…"></textarea><button type="button" class="button button-primary lv-add-note" data-id="' . esc_attr( $app->id ) . '">Добавить заметку</button></div>';
        } elseif ( self::can_claim_application( $app ) ) {
            echo '<div class="lv-claim-hint"><span class="dashicons dashicons-lock"></span><span>Чтобы оставлять заметки и обрабатывать заявку, сначала возьмите её себе.</span></div>';
        }
        echo '<div class="lv-notes-container">' . $this->notes_html( $notes ) . '</div></section>';

        echo '<section class="lv-panel lv-log-panel"><div class="lv-panel-title"><span class="dashicons dashicons-backup"></span><h2>Журнал действий</h2><span>' . esc_html( count( $logs ) ) . ' последних событий</span></div>' . $this->logs_html( $logs ) . '</section>';
        echo '</main><aside class="lv-detail-side">';
        echo '<section class="lv-panel lv-meta-panel"><div class="lv-panel-title"><span class="dashicons dashicons-info-outline"></span><h2>Информация</h2></div><dl>';
        echo '<div><dt>Форма</dt><dd>' . esc_html( $app->form_title ?: '—' ) . '</dd></div><div><dt>ID заявки</dt><dd>#' . esc_html( $app->id ) . '</dd></div><div><dt>Дата и время</dt><dd>' . esc_html( mysql2date( 'd.m.Y H:i:s', $app->submitted_at ) ) . '</dd></div>';
        echo '<div><dt>Ответственный</dt><dd>';
        if ( self::is_manager() && ! $app->deleted_at ) {
            echo '<select class="lv-assignee-select" data-id="' . esc_attr( $app->id ) . '"><option value="0">Не назначен</option>';
            foreach ( self::eligible_assignees() as $user ) echo '<option value="' . esc_attr( $user->ID ) . '" ' . selected( absint( $app->assignee_id ), absint( $user->ID ), false ) . '>' . esc_html( $user->display_name . ' · ' . self::assignee_role_label( $user->ID ) ) . '</option>';
            echo '</select><small class="lv-assignee-feedback"></small>';
        } elseif ( self::can_claim_application( $app ) ) {
            echo '<div class="lv-unassigned-box"><strong>Не назначен</strong><small>Заявка доступна команде</small><button type="button" class="button button-primary lv-claim-button" data-id="' . esc_attr( $app->id ) . '" data-user-id="' . esc_attr( get_current_user_id() ) . '"><span class="dashicons dashicons-yes-alt"></span>Взять себе</button><small class="lv-assignee-feedback"></small></div>';
        } else {
            echo '<div class="lv-assignee-person"><span class="dashicons dashicons-admin-users"></span><div><strong>' . esc_html( self::assignee_name( $app->assignee_id ) ) . '</strong><small>' . esc_html( $app->assignee_id ? self::assignee_role_label( $app->assignee_id ) : 'Не назначен' ) . '</small></div></div>';
        }
        echo '</dd></div>';
        echo '<div><dt>Приоритет</dt><dd>';
        if ( ! $app->deleted_at && self::can_edit_application( $app ) ) {
            echo '<select class="lv-priority-select" data-id="' . esc_attr( $app->id ) . '">';
            foreach ( LV_CRM_Extensions::priority_options() as $pkey => $popt ) echo '<option value="' . esc_attr( $pkey ) . '" ' . selected( $app->priority ?: 'normal', $pkey, false ) . '>' . esc_html( $popt[0] ) . '</option>';
            echo '</select><div class="lv-priority-preview">' . LV_CRM_Extensions::priority_html( $app, true ) . '</div>';
        } else echo LV_CRM_Extensions::priority_html( $app, true );
        echo '</dd></div>';
        $current_tags = array_map( 'intval', wp_list_pluck( LV_CRM_Extensions::get_application_tags( $app->id ), 'id' ) );
        echo '<div><dt>Метки</dt><dd><div class="lv-application-tags-display">' . LV_CRM_Extensions::tags_html( LV_CRM_Extensions::get_application_tags( $app->id ) ) . '</div>';
        if ( ! $app->deleted_at && self::can_edit_application( $app ) ) {
            echo '<select class="lv-application-tags-select" data-id="' . esc_attr( $app->id ) . '" multiple size="' . esc_attr( min( 5, max( 2, count( LV_CRM_Extensions::get_tags( 'application' ) ) ) ) ) . '">';
            foreach ( LV_CRM_Extensions::get_tags( 'application' ) as $tag ) echo '<option value="' . esc_attr( $tag->id ) . '" ' . selected( in_array( (int) $tag->id, $current_tags, true ), true, false ) . '>' . esc_html( $tag->name ) . '</option>';
            echo '</select>';
        }
        echo '</dd></div>';
        echo '<div><dt>Приём CF7</dt><dd><span class="lv-meta-status is-' . esc_attr( $status[1] ) . '">' . esc_html( $status[0] ) . '</span></dd></div>';
        if ( ! empty( $app->form_code ) ) {
            $sync = self::contact_sync_status_label( $app->contact_sync_status ?? '' );
            echo '<div><dt>Код формы CRM</dt><dd><code>' . esc_html( $app->form_code ) . '</code>' . ( ! empty( $app->form_schema_version ) ? ' · v' . esc_html( $app->form_schema_version ) : '' ) . '</dd></div>';
            echo '<div><dt>Синхронизация контакта</dt><dd><span class="lv-meta-status is-' . esc_attr( $sync[1] ) . '">' . esc_html( $sync[0] ) . '</span></dd></div>';
            if ( ! empty( $app->submission_uuid ) ) echo '<div><dt>UUID отправки</dt><dd><code>' . esc_html( $app->submission_uuid ) . '</code></dd></div>';
        }
        if ( $app->source_url ) {
            echo '<div><dt>Страница отправки</dt><dd><a href="' . esc_url( $app->source_url ) . '" target="_blank" rel="noopener">' . esc_html( self::source_url_label( $app->source_url ) ) . ' <span class="dashicons dashicons-external"></span></a></dd></div>';
        }
        if ( $app->deleted_at ) {
            echo '<div><dt>В корзине с</dt><dd>' . esc_html( mysql2date( 'd.m.Y H:i', $app->deleted_at ) ) . '</dd></div>';
        }
        echo '</dl></section>';
        echo '<section class="lv-panel lv-danger-panel"><div class="lv-panel-title"><span class="dashicons dashicons-admin-tools"></span><h2>Действия</h2></div><div class="lv-side-actions">';
        if ( $app->deleted_at ) {
            if ( current_user_can( 'lv_restore_applications' ) ) echo '<a class="button button-primary" href="' . esc_url( self::action_url( 'restore', $app->id, $back ) ) . '">Восстановить</a>';
            if ( current_user_can( 'lv_purge_applications' ) ) echo '<a class="button lv-delete-permanent" href="' . esc_url( self::action_url( 'delete', $app->id, $back ) ) . '">Удалить навсегда</a>';
            if ( ! current_user_can( 'lv_restore_applications' ) ) echo '<span class="lv-muted">Восстановление доступно только администратору.</span>';
        } else {
            if ( current_user_can( 'lv_trash_applications' ) ) echo '<a class="button lv-trash-link" href="' . esc_url( self::action_url( 'trash', $app->id, $back ) ) . '"><span class="dashicons dashicons-trash"></span>В корзину</a>';
            elseif ( current_user_can( 'lv_request_application_deletion' ) && self::can_edit_application( $app ) ) {
                if ( empty( $app->deletion_requested_at ) ) echo '<a class="button lv-request-delete" href="' . esc_url( self::action_url( 'request_delete', $app->id, $back ) ) . '"><span class="dashicons dashicons-flag"></span>Запросить удаление</a>';
                elseif ( absint( $app->deletion_requested_by ) === get_current_user_id() ) echo '<a class="button" href="' . esc_url( self::action_url( 'cancel_delete_request', $app->id, $back ) ) . '">Отменить запрос на удаление</a>';
            }
        }
        echo '</div></section></aside></div></div>';
    }

    private function logs_html( $logs ) {
        if ( ! $logs ) {
            return '<div class="lv-log-empty">Событий пока нет.</div>';
        }
        $html = '<div class="lv-timeline">';
        foreach ( $logs as $log ) {
            $html .= '<div class="lv-timeline-item"><span class="lv-timeline-dot"></span><div><div class="lv-timeline-meta"><strong>' . esc_html( self::log_actor( $log ) ) . '</strong><span class="lv-role-chip">' . esc_html( self::log_role_label( $log ) ) . '</span><span>' . esc_html( mysql2date( 'd.m.Y H:i', $log->created_at ) ) . '</span></div><p>' . esc_html( $log->message ) . '</p></div></div>';
        }
        return $html . '</div>';
    }

    public static function plural_fields_public( $number ) {
        $n = absint( $number ) % 100;
        $n1 = $n % 10;
        if ( $n > 10 && $n < 20 ) return 'полей';
        if ( $n1 > 1 && $n1 < 5 ) return 'поля';
        if ( 1 === $n1 ) return 'поле';
        return 'полей';
    }

    public function ajax_toggle_processed() {
        if ( ! current_user_can( 'lv_update_applications' ) ) {
            wp_send_json_error( array( 'message' => 'Недостаточно прав.' ), 403 );
        }
        check_ajax_referer( 'lv_apps_toggle_processed', 'nonce' );
        $id = isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0;
        $processed = isset( $_POST['processed'] ) && '1' === (string) $_POST['processed'] ? 1 : 0;
        $app = self::get_application( $id );
        if ( ! $app || ! self::can_edit_application( $app ) ) {
            wp_send_json_error( array( 'message' => 'Сначала назначьте заявку себе, чтобы изменить её статус.' ), 403 );
        }
        if ( (int) $app->processed !== $processed ) {
            global $wpdb;
            $wpdb->update( self::table_name(), array( 'processed' => $processed ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
            self::log_event( $id, $processed ? 'status_processed' : 'status_unprocessed', $processed ? 'Заявка отмечена как обработанная.' : 'Статус «Обработано» снят.' );
        }
        wp_send_json_success( array( 'processed' => (bool) $processed, 'label' => $processed ? 'Обработано' : 'Не обработано', 'unprocessedCount' => self::count_unprocessed_active() ) );
    }

    public function ajax_quick_view() {
        if ( ! current_user_can( $this->capability() ) ) {
            wp_send_json_error( array( 'message' => 'Недостаточно прав.' ), 403 );
        }
        check_ajax_referer( 'lv_apps_quick_view', 'nonce' );
        $id = isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0;
        $app = self::get_application( $id );
        if ( ! $app || ! self::can_access_application( $app ) ) {
            wp_send_json_error( array( 'message' => 'Заявка не найдена или недоступна.' ), 404 );
        }
        $return_to = isset( $_POST['return_to'] ) ? esc_url_raw( wp_unslash( $_POST['return_to'] ) ) : '';
        $return_to = $return_to ? wp_validate_redirect( $return_to, '' ) : '';
        wp_send_json_success( array( 'html' => $this->quick_view_html( $app, $return_to ) ) );
    }

    private function quick_view_html( $app, $return_to = '' ) {
        $fields = self::visible_fields( $app );
        $files = self::decode_files( $app );
        $primary = self::primary_fields( $app );
        $notes = self::get_notes( $app->id, 5 );
        $logs = self::get_logs( $app->id, 8 );
        $view_url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'action' => 'view', 'application_id' => $app->id ), admin_url( 'admin.php' ) );
        if ( $return_to ) $view_url = add_query_arg( 'return_to', $return_to, $view_url );
        ob_start();
        ?>
        <div class="lv-qv-head">
            <div>
                <span class="lv-qv-form"><?php echo esc_html( $app->form_title ); ?> · #<?php echo esc_html( $app->id ); ?></span>
                <h2><?php echo esc_html( self::application_heading( $app ) ); ?></h2>
                <p><?php echo esc_html( mysql2date( 'd.m.Y в H:i', $app->submitted_at ) ); ?><?php if ( $app->source_url ) : ?> · <a href="<?php echo esc_url( $app->source_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( self::source_url_label( $app->source_url ) ); ?></a><?php endif; ?></p>
            </div>
            <?php if ( ! $app->deleted_at ) : $done = (int) $app->processed === 1; ?>
                <?php if ( self::can_edit_application( $app ) ) : ?>
                    <label class="lv-switch-card lv-switch-card--light <?php echo $done ? 'is-processed' : ''; ?>"><input type="checkbox" class="lv-processed-toggle" data-id="<?php echo esc_attr( $app->id ); ?>" <?php checked( $done ); ?>><span class="lv-switch"><i></i></span><span class="lv-switch-label"><?php echo esc_html( $done ? 'Обработано' : 'Не обработано' ); ?></span></label>
                <?php else : ?>
                    <div class="lv-readonly-status lv-readonly-status--drawer <?php echo $done ? 'is-processed' : ''; ?>"><span class="lv-readonly-dot"></span><div><strong><?php echo esc_html( $done ? 'Обработано' : 'Не обработано' ); ?></strong><small>Только просмотр</small></div></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ( $app->duplicate_of ) : ?>
            <div class="lv-qv-warning"><span class="dashicons dashicons-admin-page"></span><div><strong>Возможный дубль</strong><span>Совпадает с заявкой #<?php echo esc_html( $app->duplicate_of ); ?></span></div></div>
        <?php endif; ?>
        <?php if ( ! empty( $app->deletion_requested_at ) ) : ?>
            <div class="lv-qv-warning is-delete"><span class="dashicons dashicons-trash"></span><div><strong>Запрошено удаление</strong><span><?php echo esc_html( mysql2date( 'd.m.Y H:i', $app->deletion_requested_at ) ); ?></span></div></div>
        <?php endif; ?>
        <div class="lv-qv-assignee"><span>Ответственный</span>
            <?php if ( self::is_manager() && ! $app->deleted_at ) : ?>
                <select class="lv-assignee-select" data-id="<?php echo esc_attr( $app->id ); ?>"><option value="0">Не назначен</option><?php foreach ( self::eligible_assignees() as $user ) : ?><option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( absint( $app->assignee_id ), absint( $user->ID ) ); ?>><?php echo esc_html( $user->display_name . ' · ' . self::assignee_role_label( $user->ID ) ); ?></option><?php endforeach; ?></select><small class="lv-assignee-feedback"></small>
            <?php elseif ( self::can_claim_application( $app ) ) : ?>
                <button type="button" class="button button-primary lv-claim-button" data-id="<?php echo esc_attr( $app->id ); ?>" data-user-id="<?php echo esc_attr( get_current_user_id() ); ?>"><span class="dashicons dashicons-yes-alt"></span>Взять себе</button><small class="lv-assignee-feedback"></small>
            <?php else : ?><strong><?php echo esc_html( self::assignee_name( $app->assignee_id ) ); ?></strong><?php endif; ?>
        </div>
        <div class="lv-qv-crm-row">
            <div><span>Приоритет</span><?php if ( ! $app->deleted_at && self::can_edit_application( $app ) ) : ?><select class="lv-priority-select" data-id="<?php echo esc_attr( $app->id ); ?>"><?php foreach ( LV_CRM_Extensions::priority_options() as $pkey => $popt ) : ?><option value="<?php echo esc_attr( $pkey ); ?>" <?php selected( $app->priority ?: 'normal', $pkey ); ?>><?php echo esc_html( $popt[0] ); ?></option><?php endforeach; ?></select><div class="lv-priority-preview"><?php echo LV_CRM_Extensions::priority_html( $app, true ); ?></div><?php else : ?><?php echo LV_CRM_Extensions::priority_html( $app, true ); ?><?php endif; ?></div>
            <div><span>Метки</span><div class="lv-application-tags-display"><?php echo LV_CRM_Extensions::tags_html( LV_CRM_Extensions::get_application_tags( $app->id ) ); ?></div></div>
        </div>
        <?php if ( $primary ) : ?>
            <div class="lv-qv-primary">
                <?php foreach ( $primary as $key => $field ) : $value = self::value_to_string( $field['value'] ); ?>
                    <div><span><?php echo esc_html( self::display_field_label( $app, $field ) ); ?></span><strong><?php echo wp_kses_post( self::field_value_html( $field, $app ) ); ?></strong></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="lv-qv-section-head"><h3>Все ответы</h3><span><?php echo esc_html( count( $fields ) ); ?> <?php echo esc_html( self::plural_fields_public( count( $fields ) ) ); ?></span></div>
        <div class="lv-qv-fields">
            <?php foreach ( $fields as $field ) : ?>
                <div class="lv-qv-field"><span><?php echo esc_html( self::display_field_label( $app, $field ) ); ?></span><div><?php echo wp_kses_post( self::field_value_html( $field, $app ) ); ?></div></div>
            <?php endforeach; ?>
        </div>
        <?php if ( $files ) : ?><div class="lv-qv-section-head"><h3>Файлы</h3><span><?php echo esc_html( count( $files ) ); ?></span></div><div class="lv-qv-files"><?php echo self::files_html( $app, $files ); ?></div><?php endif; ?>
        <div class="lv-qv-contacts"><?php echo LV_CRM_Extensions::application_contacts_panel( $app, true ); ?></div>
        <div class="lv-qv-section-head"><h3>Внутренние заметки</h3><span><?php echo esc_html( count( $notes ) ); ?></span></div>
        <?php if ( self::can_edit_application( $app ) ) : ?>
            <div class="lv-note-compose lv-note-compose--compact"><textarea class="lv-note-input" rows="2" placeholder="Комментарий, @ — упомянуть коллегу…"></textarea><button type="button" class="button lv-add-note" data-id="<?php echo esc_attr( $app->id ); ?>">Добавить</button></div>
        <?php elseif ( self::can_claim_application( $app ) ) : ?>
            <div class="lv-claim-hint lv-claim-hint--drawer"><span class="dashicons dashicons-lock"></span><span>Возьмите заявку себе, чтобы добавлять заметки.</span></div>
        <?php endif; ?><div class="lv-notes-container"><?php echo $this->notes_html( $notes ); ?></div>
        <div class="lv-qv-section-head"><h3>Последние действия</h3><span><?php echo esc_html( count( $logs ) ); ?></span></div>
        <?php echo $this->logs_html( $logs ); ?>
        <div class="lv-qv-footer">
            <a class="button button-primary" href="<?php echo esc_url( $view_url ); ?>">Открыть полную карточку</a>
            <?php if ( $app->deleted_at ) : ?>
                <?php if ( current_user_can( 'lv_restore_applications' ) ) : ?><a class="button" href="<?php echo esc_url( self::action_url( 'restore', $app->id, $return_to ) ); ?>">Восстановить</a><?php endif; ?>
                <?php if ( current_user_can( 'lv_purge_applications' ) ) : ?><a class="button lv-delete-permanent" href="<?php echo esc_url( self::action_url( 'delete', $app->id, $return_to ) ); ?>">Удалить навсегда</a><?php endif; ?>
            <?php else : ?>
                <?php if ( current_user_can( 'lv_trash_applications' ) ) : ?><a class="button lv-trash-link" href="<?php echo esc_url( self::action_url( 'trash', $app->id, $return_to ) ); ?>">В корзину</a>
                <?php elseif ( current_user_can( 'lv_request_application_deletion' ) && self::can_edit_application( $app ) && empty( $app->deletion_requested_at ) ) : ?><a class="button" href="<?php echo esc_url( self::action_url( 'request_delete', $app->id, $return_to ) ); ?>">Запросить удаление</a><?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function bulk_status() {
        if ( ! current_user_can( $this->capability() ) ) wp_die( 'Недостаточно прав.' );
        check_admin_referer( 'lv_apps_bulk_status' );

        $mode = isset( $_POST['bulk_status'] ) ? sanitize_key( wp_unslash( $_POST['bulk_status'] ) ) : '';
        $filters = self::request_filters();
        $scope = isset( $_POST['selection_scope'] ) && 'filtered' === sanitize_key( wp_unslash( $_POST['selection_scope'] ) ) ? 'filtered' : 'page';

        if ( 'filtered' === $scope ) {
            $ids = self::application_ids( $filters, 0, 0 );
        } else {
            $ids = isset( $_POST['selected_ids'] )
                ? array_values( array_unique( array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['selected_ids'] ) ) ) ) ) ) )
                : array();
            if ( ! $ids && isset( $_POST['application'] ) && is_array( $_POST['application'] ) ) {
                $ids = array_values( array_unique( array_filter( array_map( 'absint', $_POST['application'] ) ) ) );
            }
        }
        if ( ! $ids ) {
            wp_safe_redirect( add_query_arg( 'lv_bulk_empty', 1, self::filters_url( $filters ) ) );
            exit;
        }

        $allowed = array( 'processed', 'unprocessed', 'priority_low', 'priority_normal', 'priority_high', 'priority_urgent' );
        if ( current_user_can( 'lv_request_application_deletion' ) && ! self::is_manager() ) $allowed[] = 'request_delete';
        if ( current_user_can( 'lv_trash_applications' ) ) $allowed[] = 'trash';
        if ( current_user_can( 'lv_restore_applications' ) ) $allowed[] = 'restore';
        if ( current_user_can( 'lv_purge_applications' ) ) $allowed[] = 'delete_permanently';

        $is_assignment = 0 === strpos( $mode, 'assignee_' );
        $is_tag_add = 0 === strpos( $mode, 'tag_add_' );
        $is_tag_remove = 0 === strpos( $mode, 'tag_remove_' );
        if ( ! in_array( $mode, $allowed, true ) && ! $is_assignment && ! $is_tag_add && ! $is_tag_remove ) {
            wp_die( 'Недостаточно прав для этого действия.' );
        }

        $target_assignee = null;
        if ( $is_assignment ) {
            $raw = substr( $mode, 9 );
            if ( 'self' === $raw ) {
                $target_assignee = get_current_user_id();
            } elseif ( ctype_digit( $raw ) ) {
                $target_assignee = absint( $raw );
            } else {
                wp_die( 'Некорректный ответственный.' );
            }
            if ( 0 !== $target_assignee && ! self::eligible_assignee_public( $target_assignee ) ) wp_die( 'Некорректный ответственный.' );
            if ( ! self::is_manager() && $target_assignee !== get_current_user_id() ) wp_die( 'Недостаточно прав для назначения.' );
        }

        $target_tag = 0;
        if ( $is_tag_add || $is_tag_remove ) {
            $prefix = $is_tag_add ? 'tag_add_' : 'tag_remove_';
            $target_tag = absint( substr( $mode, strlen( $prefix ) ) );
            $allowed_tags = array_map( 'intval', wp_list_pluck( LV_CRM_Extensions::get_tags( 'application' ), 'id' ) );
            if ( ! $target_tag || ! in_array( $target_tag, $allowed_tags, true ) ) wp_die( 'Некорректная метка.' );
        }

        global $wpdb;
        $changed = 0;
        $skipped = 0;
        $trashed_ids = array();

        foreach ( $ids as $id ) {
            $app = self::get_application( $id );
            if ( ! $app || ! self::can_access_application( $app ) ) { $skipped++; continue; }

            if ( in_array( $mode, array( 'processed', 'unprocessed' ), true ) ) {
                if ( $app->deleted_at || ! current_user_can( 'lv_update_applications' ) || ! self::can_edit_application( $app ) ) { $skipped++; continue; }
                $value = 'processed' === $mode ? 1 : 0;
                if ( (int) $app->processed !== $value ) {
                    $ok = (int) $wpdb->update( self::table_name(), array( 'processed' => $value ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
                    $changed += $ok;
                    if ( $ok ) self::log_event( $id, $value ? 'status_processed' : 'status_unprocessed', $value ? 'Заявка отмечена как обработанная массовым действием.' : 'Статус «Обработано» снят массовым действием.' );
                }
            } elseif ( 0 === strpos( $mode, 'priority_' ) ) {
                if ( $app->deleted_at || ! current_user_can( 'lv_update_applications' ) || ! self::can_edit_application( $app ) ) { $skipped++; continue; }
                $priority = substr( $mode, 9 );
                if ( ! array_key_exists( $priority, LV_CRM_Extensions::priority_options() ) ) { $skipped++; continue; }
                if ( (string) $app->priority !== $priority ) {
                    $ok = (int) $wpdb->update( self::table_name(), array( 'priority' => $priority ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
                    $changed += $ok;
                    if ( $ok ) self::log_event( $id, 'priority_changed', 'Приоритет изменён массовым действием: ' . LV_CRM_Extensions::priority_label( $priority ) . '.' );
                }
            } elseif ( $is_assignment ) {
                if ( $app->deleted_at ) { $skipped++; continue; }
                if ( self::is_manager() ) {
                    if ( absint( $app->assignee_id ) !== absint( $target_assignee ) ) {
                        $ok = (int) $wpdb->update( self::table_name(), array( 'assignee_id' => absint( $target_assignee ) ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
                        $changed += $ok;
                        if ( $ok ) self::log_event( $id, 'assignee_changed', $target_assignee ? 'Ответственный массово изменён: ' . self::assignee_name( $target_assignee ) . '.' : 'Ответственный массово снят.' );
                    }
                } elseif ( $target_assignee === get_current_user_id() && self::can_claim_application( $app ) ) {
                    $ok = (int) $wpdb->update( self::table_name(), array( 'assignee_id' => get_current_user_id() ), array( 'id' => $id, 'assignee_id' => 0 ), array( '%d' ), array( '%d', '%d' ) );
                    $changed += $ok;
                    if ( $ok ) self::log_event( $id, 'claimed', 'Заявка взята сотрудником массовым действием.' );
                } else {
                    $skipped++;
                }
            } elseif ( $is_tag_add || $is_tag_remove ) {
                if ( $app->deleted_at || ! self::can_edit_application( $app ) ) { $skipped++; continue; }
                if ( $is_tag_add ) {
                    $exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LV_CRM_Extensions::application_tags_table() . ' WHERE application_id=%d AND tag_id=%d', $id, $target_tag ) );
                    if ( ! $exists ) {
                        $ok = $wpdb->insert( LV_CRM_Extensions::application_tags_table(), array( 'application_id'=>$id, 'tag_id'=>$target_tag ), array('%d','%d') );
                        if ( $ok ) { $changed++; self::log_event( $id, 'tags_changed', 'Метка добавлена массовым действием.' ); }
                    }
                } else {
                    $ok = $wpdb->delete( LV_CRM_Extensions::application_tags_table(), array( 'application_id'=>$id, 'tag_id'=>$target_tag ), array('%d','%d') );
                    if ( $ok ) { $changed += (int) $ok; self::log_event( $id, 'tags_changed', 'Метка снята массовым действием.' ); }
                }
            } elseif ( 'request_delete' === $mode && ! self::is_manager() && ! $app->deleted_at && self::can_edit_application( $app ) ) {
                if ( empty( $app->deletion_requested_at ) ) {
                    $ok = (int) $wpdb->update( self::table_name(), array( 'deletion_requested_at' => current_time( 'mysql' ), 'deletion_requested_by' => get_current_user_id() ), array( 'id' => $id ), array( '%s', '%d' ), array( '%d' ) );
                    $changed += $ok;
                    if ( $ok ) self::log_event( $id, 'deletion_requested', 'Сотрудник запросил удаление заявки.' );
                }
            } elseif ( 'trash' === $mode && current_user_can( 'lv_trash_applications' ) && ! $app->deleted_at ) {
                $ok = $this->move_to_trash( $app );
                $changed += $ok;
                if ( $ok ) $trashed_ids[] = $id;
            } elseif ( 'restore' === $mode && current_user_can( 'lv_restore_applications' ) && $app->deleted_at ) {
                $changed += $this->restore_from_trash( $app );
            } elseif ( 'delete_permanently' === $mode && current_user_can( 'lv_purge_applications' ) && $app->deleted_at ) {
                $changed += $this->permanently_delete_application( $app );
            } else {
                $skipped++;
            }
        }

        if ( class_exists( 'LV_Request_Cache' ) ) LV_Request_Cache::flush();
        $skipped = max( $skipped, count( $ids ) - $changed );

        $redirect = self::filters_url( $filters );
        $redirect = add_query_arg( array(
            'lv_bulk_selected' => count( $ids ),
            'lv_bulk_changed'  => $changed,
            'lv_bulk_skipped'  => $skipped,
        ), $redirect );

        if ( 'trash' === $mode ) {
            $redirect = add_query_arg( 'lv_trashed', $changed, $redirect );
            if ( $trashed_ids ) {
                $token = $this->store_application_undo_batch( $trashed_ids, self::filters_url( $filters ) );
                if ( $token ) $redirect = add_query_arg( 'lv_undo_token', $token, $redirect );
            }
        } elseif ( 'restore' === $mode ) $redirect = add_query_arg( 'lv_restored', $changed, $redirect );
        elseif ( 'delete_permanently' === $mode ) $redirect = add_query_arg( 'lv_deleted', $changed, $redirect );
        elseif ( 'request_delete' === $mode ) $redirect = add_query_arg( 'lv_delete_requested', $changed, $redirect );
        else $redirect = add_query_arg( 'lv_bulk_done', 1, $redirect );

        wp_safe_redirect( $redirect );
        exit;
    }

    private function store_application_undo_batch( $ids, $return_to = '' ) {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
        if ( ! $ids ) return '';
        $token = substr( hash( 'sha256', wp_generate_uuid4() . '|' . microtime( true ) . '|' . get_current_user_id() ), 0, 24 );
        $safe = $return_to ? wp_validate_redirect( esc_url_raw( $return_to ), admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) : '';
        set_transient( 'lv_apps_undo_' . get_current_user_id() . '_' . $token, array( 'ids'=>$ids, 'return_to'=>$safe ), 10 * MINUTE_IN_SECONDS );
        return $token;
    }

    public function undo_application_trash() {
        if ( ! current_user_can( 'lv_restore_applications' ) && ! current_user_can( 'lv_trash_applications' ) ) wp_die( 'Недостаточно прав.' );
        $token = isset( $_GET['token'] ) ? sanitize_key( wp_unslash( $_GET['token'] ) ) : '';
        if ( ! $token ) wp_die( 'Недействительная ссылка отмены.' );
        check_admin_referer( 'lv_apps_undo_trash_' . $token );
        $key = 'lv_apps_undo_' . get_current_user_id() . '_' . $token;
        $payload = get_transient( $key );
        delete_transient( $key );
        if ( ! is_array( $payload ) || empty( $payload['ids'] ) ) wp_die( 'Срок действия ссылки отмены истёк.' );
        $ids = (array) $payload['ids'];

        $restored = 0;
        foreach ( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) as $id ) {
            $app = self::get_application( $id );
            if ( $app && ! empty( $app->deleted_at ) ) $restored += $this->restore_from_trash( $app );
        }
        if ( class_exists( 'LV_Request_Cache' ) ) LV_Request_Cache::flush();
        $return = ! empty( $payload['return_to'] ) ? wp_validate_redirect( $payload['return_to'], admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) : admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        wp_safe_redirect( add_query_arg( 'lv_restored', $restored, $return ) );
        exit;
    }

    private function move_to_trash( $app ) {
        global $wpdb;
        $ok = (int) $wpdb->update( self::table_name(), array( 'deleted_at' => current_time( 'mysql' ), 'deleted_by' => get_current_user_id(), 'deletion_requested_at' => null, 'deletion_requested_by' => null ), array( 'id' => absint( $app->id ) ), array( '%s', '%d', '%s', '%d' ), array( '%d' ) );
        if ( $ok ) self::log_event( $app->id, 'trashed', 'Заявка перемещена в корзину. Автоудаление через 7 дней.' );
        return $ok;
    }

    private function restore_from_trash( $app ) {
        global $wpdb;
        $ok = (int) $wpdb->update( self::table_name(), array( 'deleted_at' => null, 'deleted_by' => null ), array( 'id' => absint( $app->id ) ), array( '%s', '%d' ), array( '%d' ) );
        if ( $ok ) self::log_event( $app->id, 'restored', 'Заявка восстановлена из корзины.' );
        return $ok;
    }

    public function trash_application() {
        if ( ! current_user_can( 'lv_trash_applications' ) ) wp_die( 'Недостаточно прав.' );
        $id = isset( $_GET['application_id'] ) ? absint( $_GET['application_id'] ) : 0;
        check_admin_referer( 'lv_apps_trash_' . $id );
        $app = self::get_application( $id );
        if ( ! $app || ! self::can_access_application( $app ) ) wp_die( 'Заявка недоступна.' );
        $changed = ! $app->deleted_at ? $this->move_to_trash( $app ) : 0;

        // A destructive single-record action always lands in the trash so the
        // result is immediately visible and reversible.
        $redirect = add_query_arg( array(
            'page' => self::PAGE_SLUG,
            'lv_view' => 'trash',
            'lv_trashed' => $changed,
            'lv_highlight' => $id,
        ), admin_url( 'admin.php' ) );
        if ( $changed ) {
            $token = $this->store_application_undo_batch( array( $id ), $this->safe_redirect_from_request( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
            if ( $token ) $redirect = add_query_arg( 'lv_undo_token', $token, $redirect );
        }
        wp_safe_redirect( $redirect );
        exit;
    }

    public function restore_application() {
        if ( ! current_user_can( 'lv_restore_applications' ) ) wp_die( 'Недостаточно прав.' );
        $id = isset( $_GET['application_id'] ) ? absint( $_GET['application_id'] ) : 0;
        check_admin_referer( 'lv_apps_restore_' . $id );
        $app = self::get_application( $id );
        $changed = $app && $app->deleted_at ? $this->restore_from_trash( $app ) : 0;
        $redirect = add_query_arg( array( 'page'=>self::PAGE_SLUG, 'lv_restored'=>$changed, 'lv_highlight'=>$id ), admin_url( 'admin.php' ) );
        wp_safe_redirect( $redirect );
        exit;
    }

    public function delete_application_permanently() {
        if ( ! current_user_can( 'lv_purge_applications' ) ) wp_die( 'Недостаточно прав.' );
        $id = isset( $_GET['application_id'] ) ? absint( $_GET['application_id'] ) : 0;
        check_admin_referer( 'lv_apps_delete_permanently_' . $id );
        $app = self::get_application( $id );
        if ( ! $app || ! $app->deleted_at ) wp_die( 'Сначала переместите заявку в корзину.' );
        $deleted = $this->permanently_delete_application( $app );
        $redirect = $this->safe_redirect_from_request( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&lv_view=trash' ) );
        wp_safe_redirect( add_query_arg( 'lv_deleted', $deleted, $redirect ) );
        exit;
    }

    private function safe_redirect_from_request( $default ) {
        $redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
        return $redirect ? wp_validate_redirect( $redirect, $default ) : $default;
    }

    private function render_export_modal( $filters ) {
        $forms = self::get_forms();
        $catalog = $this->get_field_catalog( $filters );
        echo '<div class="lv-modal-backdrop" hidden></div><section class="lv-export-modal" role="dialog" aria-modal="true" aria-label="Экспорт заявок" aria-hidden="true" tabindex="-1"><div class="lv-modal-card"><header><div><span class="lv-kicker">Экспорт данных</span><h2>Сформировать выгрузку</h2><p>Экспорт учитывает текущие фильтры. Можно выгрузить только выбранные строки и выбрать нужные поля.</p></div><button type="button" class="lv-modal-close" aria-label="Закрыть окно экспорта"><span class="dashicons dashicons-no-alt"></span></button></header>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lv-export-form"><input type="hidden" name="action" value="lv_apps_export">';
        wp_nonce_field( 'lv_apps_export' );
        foreach ( array( 'form' => 'lv_form', 'status' => 'lv_status', 'priority' => 'lv_priority', 'tag' => 'lv_tag', 'date_from' => 'lv_date_from', 'date_to' => 'lv_date_to', 'search' => 's', 'view' => 'lv_view', 'assignee' => 'lv_assignee', 'orderby' => 'orderby' ) as $key => $name ) {
            echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( isset( $filters[ $key ] ) ? $filters[ $key ] : '' ) . '">';
        }
        echo '<input type="hidden" name="order" value="' . esc_attr( 'ASC' === $filters['order'] ? 'asc' : 'desc' ) . '"><input type="hidden" name="selected_ids" value="">';
        echo '<div class="lv-export-grid">';
        echo '<fieldset><legend>Что выгружать</legend><label class="lv-radio-card"><input type="radio" name="export_scope" value="filtered" checked><span><strong>Текущую выборку</strong><small>Все записи, подходящие под фильтры</small></span></label><label class="lv-radio-card"><input type="radio" name="export_scope" value="selected"><span><strong>Только выбранные</strong><small class="lv-selected-count">Сейчас выбрано: 0</small></span></label></fieldset>';
        echo '<fieldset><legend>Формат</legend><label class="lv-radio-card"><input type="radio" name="export_format" value="xlsx" checked><span><strong>Excel XLSX</strong><small>Рекомендуется, поддерживает листы</small></span></label><label class="lv-radio-card"><input type="radio" name="export_format" value="csv"><span><strong>CSV UTF-8</strong><small>Один универсальный лист, Excel читает кириллицу</small></span></label></fieldset>';
        echo '<fieldset><legend>Структура Excel</legend><label class="lv-radio-card"><input type="radio" name="export_layout" value="by_form" checked><span><strong>По листу на форму</strong><small>У каждой формы свои колонки</small></span></label><label class="lv-radio-card"><input type="radio" name="export_layout" value="single"><span><strong>Один общий лист</strong><small>Все поля объединяются в одной таблице</small></span></label></fieldset>';
        echo '<fieldset><legend>Состав полей</legend><label class="lv-radio-card"><input type="radio" name="field_mode" value="all" checked><span><strong>Все поля формы</strong><small>Кроме служебных полей CF7</small></span></label><label class="lv-radio-card"><input type="radio" name="field_mode" value="primary"><span><strong>Только основные</strong><small>Имя, организация, телефон, email, тема</small></span></label><label class="lv-radio-card"><input type="radio" name="field_mode" value="custom"><span><strong>Выбрать вручную</strong><small>Точная настройка колонок</small></span></label></fieldset>';
        echo '</div>';
        echo '<div class="lv-export-options"><label><input type="checkbox" name="include_meta" value="1" checked> Добавить ID, дату, форму, статус и источник</label><div class="lv-export-form-filter"><label>Ограничить одной формой</label><select name="export_form"><option value="0">Как в текущем фильтре</option>';
        if ( empty( $filters['form'] ) ) { foreach ( $forms as $form ) echo '<option value="' . esc_attr( $form->form_id ) . '">' . esc_html( $form->form_title ?: 'Без названия' ) . '</option>'; }
        echo '</select></div></div>';
        echo '<div class="lv-custom-fields" hidden><div class="lv-custom-fields-head"><strong>Поля для выгрузки</strong><button type="button" class="button-link lv-check-all-fields">Выбрать все</button><button type="button" class="button-link lv-uncheck-all-fields">Снять все</button></div>';
        if ( ! $catalog ) {
            echo '<p class="lv-muted">В текущей выборке нет полей.</p>';
        } else {
            foreach ( $catalog as $form_id => $group ) {
                echo '<details open><summary>' . esc_html( $group['title'] ) . '</summary><div class="lv-field-check-grid">';
                foreach ( $group['fields'] as $name => $label ) {
                    $key = $form_id . '::' . $name;
                    echo '<label><input type="checkbox" name="export_fields[]" value="' . esc_attr( $key ) . '" checked><span>' . esc_html( $label ) . '</span></label>';
                }
                echo '</div></details>';
            }
        }
        echo '</div>';
        echo '<footer><button type="button" class="button lv-modal-close-secondary">Отмена</button><button class="button button-primary lv-export-submit"><span class="dashicons dashicons-download"></span>Скачать файл</button></footer></form></div></section>';
    }

    private function get_field_catalog( $filters ) {
        $apps = self::query_applications( $filters, 1000, 0 );
        $catalog = array();
        foreach ( $apps as $app ) {
            $fid = (string) $app->form_id;
            if ( ! isset( $catalog[ $fid ] ) ) $catalog[ $fid ] = array( 'title' => $app->form_title ?: 'Без названия', 'fields' => array() );
            foreach ( self::visible_fields( $app ) as $field ) {
                $name = isset( $field['name'] ) ? (string) $field['name'] : '';
                if ( '' === $name || isset( $catalog[ $fid ]['fields'][ $name ] ) ) continue;
                $catalog[ $fid ]['fields'][ $name ] = self::display_field_label( $app, $field );
            }
        }
        return $catalog;
    }

    public function export_data() {
        if ( ! current_user_can( 'lv_export_applications' ) ) wp_die( 'Недостаточно прав.' );
        check_admin_referer( 'lv_apps_export' );
        $filters = self::request_filters();
        $scope = isset( $_POST['export_scope'] ) ? sanitize_key( wp_unslash( $_POST['export_scope'] ) ) : 'filtered';
        if ( 'selected' === $scope ) {
            $raw_ids = isset( $_POST['selected_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['selected_ids'] ) ) : '';
            $filters['ids'] = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
            if ( ! $filters['ids'] ) wp_die( 'Для экспорта не выбрано ни одной заявки.' );
        }
        $export_form = isset( $_POST['export_form'] ) ? absint( $_POST['export_form'] ) : 0;
        if ( $export_form ) $filters['form'] = $export_form;
        $format = isset( $_POST['export_format'] ) && 'csv' === sanitize_key( wp_unslash( $_POST['export_format'] ) ) ? 'csv' : 'xlsx';
        $layout = isset( $_POST['export_layout'] ) && 'single' === sanitize_key( wp_unslash( $_POST['export_layout'] ) ) ? 'single' : 'by_form';
        $field_mode = isset( $_POST['field_mode'] ) ? sanitize_key( wp_unslash( $_POST['field_mode'] ) ) : 'all';
        if ( ! in_array( $field_mode, array( 'all', 'primary', 'custom' ), true ) ) $field_mode = 'all';
        $include_meta = ! empty( $_POST['include_meta'] );
        $selected_fields = isset( $_POST['export_fields'] ) && is_array( $_POST['export_fields'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['export_fields'] ) ) : array();
        $apps = self::query_applications( $filters, 0, 0 );
        if ( ! $apps ) wp_die( 'В выбранной выборке нет заявок.' );

        $filename_base = 'zayavki-' . wp_date( 'Y-m-d-His' );
        if ( 'csv' === $format ) {
            $this->download_csv( $filename_base . '.csv', $this->export_rows_unified_iter( $apps, $field_mode, $selected_fields, $include_meta ) );
            return;
        }

        $writer = new LV_XLSX_Writer();
        if ( 'single' === $layout ) {
            $writer->add_sheet_stream( 'Заявки', function() use ( $apps, $field_mode, $selected_fields, $include_meta ) {
                return $this->export_rows_unified_iter( $apps, $field_mode, $selected_fields, $include_meta );
            } );
        } else {
            $groups = array();
            foreach ( $apps as $app ) {
                $key = (string) $app->form_id;
                if ( ! isset( $groups[ $key ] ) ) $groups[ $key ] = array( 'name' => $app->form_title ?: 'Без названия', 'apps' => array() );
                $groups[ $key ]['apps'][] = $app;
            }
            foreach ( $groups as $group ) {
                $group_apps = $group['apps'];
                $writer->add_sheet_stream( $group['name'], function() use ( $group_apps, $field_mode, $selected_fields, $include_meta ) {
                    return $this->export_rows_form_iter( $group_apps, $field_mode, $selected_fields, $include_meta );
                } );
            }
        }
        $writer->download( $filename_base . '.xlsx' );
    }

    private function export_field_allowed( $app, $field, $mode, $selected_fields ) {
        $name = isset( $field['name'] ) ? (string) $field['name'] : '';
        if ( '' === $name || self::is_service_field( $name ) ) return false;
        if ( 'all' === $mode ) return true;
        if ( 'custom' === $mode ) return in_array( $app->form_id . '::' . $name, $selected_fields, true );
        $primary = self::primary_fields( $app );
        foreach ( $primary as $p ) {
            if ( isset( $p['name'] ) && $p['name'] === $name ) return true;
        }
        return false;
    }

    private function export_field_value( $app, $field ) {
        $name = isset( $field['name'] ) ? (string) $field['name'] : '';
        $files = $name ? self::files_for_field( $app, $name ) : array();
        if ( $files ) {
            $names = array(); foreach ( $files as $file ) if ( ! empty( $file['original_name'] ) ) $names[] = $file['original_name'];
            return implode( ', ', $names );
        }
        return self::value_to_string( isset( $field['value'] ) ? $field['value'] : '' );
    }

    private function export_rows_form_iter( $apps, $mode, $selected_fields, $include_meta ) {
        $columns = array();
        $labels = array();
        foreach ( $apps as $app ) {
            foreach ( self::visible_fields( $app ) as $field ) {
                if ( ! $this->export_field_allowed( $app, $field, $mode, $selected_fields ) ) continue;
                $name = (string) $field['name'];
                if ( ! isset( $columns[ $name ] ) ) {
                    $columns[ $name ] = true;
                    $labels[ $name ] = self::display_field_label( $app, $field );
                }
            }
        }
        $header = $include_meta ? array( 'ID', 'Дата', 'Форма', 'Обработано', 'Приоритет', 'Метки', 'Ответственный', 'Связанные контакты', 'Источник', 'Дубль заявки' ) : array();
        foreach ( array_keys( $columns ) as $name ) $header[] = $labels[ $name ];
        yield $header;

        foreach ( $apps as $app ) {
            $map = array();
            foreach ( self::visible_fields( $app ) as $field ) {
                if ( isset( $field['name'] ) && $this->export_field_allowed( $app, $field, $mode, $selected_fields ) ) {
                    $map[ $field['name'] ] = $this->export_field_value( $app, $field );
                }
            }
            $tags = isset( $app->lv_tags ) ? (array) $app->lv_tags : LV_CRM_Extensions::get_application_tags( $app->id );
            $contacts = isset( $app->lv_contacts ) ? (array) $app->lv_contacts : LV_CRM_Extensions::application_contacts( $app->id );
            $row = $include_meta ? array( $app->id, mysql2date( 'd.m.Y H:i', $app->submitted_at ), $app->form_title, $app->processed ? 'Да' : 'Нет', LV_CRM_Extensions::priority_label( $app->priority ?: 'normal' ), implode( ', ', wp_list_pluck( $tags, 'name' ) ), self::assignee_name( $app->assignee_id ), implode( ', ', wp_list_pluck( $contacts, 'display_name' ) ), $app->source_url, $app->duplicate_of ? '#' . $app->duplicate_of : '' ) : array();
            foreach ( array_keys( $columns ) as $name ) $row[] = isset( $map[ $name ] ) ? $map[ $name ] : '';
            yield $row;
        }
    }

    private function build_export_rows_form( $apps, $mode, $selected_fields, $include_meta ) {
        return iterator_to_array( $this->export_rows_form_iter( $apps, $mode, $selected_fields, $include_meta ), false );
    }

    private function export_rows_unified_iter( $apps, $mode, $selected_fields, $include_meta ) {
        $form_ids = array();
        foreach ( $apps as $app ) $form_ids[ (string) $app->form_id ] = true;
        $multi_form = count( $form_ids ) > 1;
        $columns = array();
        $labels = array();

        foreach ( $apps as $app ) {
            foreach ( self::visible_fields( $app ) as $field ) {
                if ( ! $this->export_field_allowed( $app, $field, $mode, $selected_fields ) ) continue;
                $name = (string) $field['name'];
                $key = $multi_form ? $app->form_id . '::' . $name : $name;
                if ( isset( $columns[ $key ] ) ) continue;
                $columns[ $key ] = array( 'name' => $name, 'form_id' => $app->form_id );
                $label = self::display_field_label( $app, $field );
                $labels[ $key ] = $multi_form ? ( $app->form_title . ' — ' . $label ) : $label;
            }
        }

        $header = $include_meta ? array( 'ID', 'Дата', 'Форма', 'Обработано', 'Приоритет', 'Метки', 'Ответственный', 'Связанные контакты', 'Источник', 'Дубль заявки' ) : array();
        foreach ( array_keys( $columns ) as $key ) $header[] = $labels[ $key ];
        yield $header;

        foreach ( $apps as $app ) {
            $map = array();
            foreach ( self::visible_fields( $app ) as $field ) {
                if ( ! $this->export_field_allowed( $app, $field, $mode, $selected_fields ) ) continue;
                $name = (string) $field['name'];
                $key = $multi_form ? $app->form_id . '::' . $name : $name;
                $map[ $key ] = $this->export_field_value( $app, $field );
            }
            $tags = isset( $app->lv_tags ) ? (array) $app->lv_tags : LV_CRM_Extensions::get_application_tags( $app->id );
            $contacts = isset( $app->lv_contacts ) ? (array) $app->lv_contacts : LV_CRM_Extensions::application_contacts( $app->id );
            $row = $include_meta ? array( $app->id, mysql2date( 'd.m.Y H:i', $app->submitted_at ), $app->form_title, $app->processed ? 'Да' : 'Нет', LV_CRM_Extensions::priority_label( $app->priority ?: 'normal' ), implode( ', ', wp_list_pluck( $tags, 'name' ) ), self::assignee_name( $app->assignee_id ), implode( ', ', wp_list_pluck( $contacts, 'display_name' ) ), $app->source_url, $app->duplicate_of ? '#' . $app->duplicate_of : '' ) : array();
            foreach ( array_keys( $columns ) as $key ) $row[] = isset( $map[ $key ] ) ? $map[ $key ] : '';
            yield $row;
        }
    }

    private function build_export_rows_unified( $apps, $mode, $selected_fields, $include_meta ) {
        return iterator_to_array( $this->export_rows_unified_iter( $apps, $mode, $selected_fields, $include_meta ), false );
    }

    private static function sanitize_export_cell( $value ) {
        if ( is_bool( $value ) ) $value = $value ? 'Да' : 'Нет';
        if ( is_array( $value ) ) $value = implode( ', ', array_map( 'strval', $value ) );
        $value = (string) $value;
        // Keep ordinary international phone numbers readable, while neutralising
        // cells that spreadsheet applications could evaluate as formulas.
        if ( preg_match( '/^[=+\-@]/u', $value ) && ! preg_match( '/^\+[0-9\s()\-]+$/u', $value ) ) $value = "'" . $value;
        return $value;
    }

    private function download_csv( $filename, $rows ) {
        while ( ob_get_level() ) ob_end_clean();
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
        echo "\xEF\xBB\xBF";
        $out = fopen( 'php://output', 'w' );
        foreach ( $rows as $row ) fputcsv( $out, array_map( array( __CLASS__, 'sanitize_export_cell' ), $row ), ';' );
        fclose( $out );
        exit;
    }


}

register_activation_hook( __FILE__, array( 'LV_Applications_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LV_Applications_Plugin', 'deactivate' ) );
LV_Applications_Plugin::instance();
LV_CRM_Extensions::instance();
LV_Form_Integration::instance();
LV_Contact_Export_Controller::instance();
