<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * CRM layer for the Foundation applications registry.
 * Adds priorities, tags, mentions/notifications, smart routing and contacts.
 */
final class LV_CRM_Extensions {
    const DB_VERSION = '7';
    const DB_OPTION = 'lv_crm_extensions_db_version';
    const CONTACTS_SLUG = 'lv-crm-contacts';

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_init', array( $this, 'maybe_upgrade' ), 8 );
        add_action( 'admin_init', array( $this, 'maybe_purge_contact_trash' ), 24 );
        add_action( LV_Applications_Plugin::CLEANUP_HOOK, array( $this, 'purge_contact_trash' ), 20 );
        add_action( 'lv_crm_application_created', array( $this, 'auto_route_application' ), 10, 3 );

        add_action( 'wp_ajax_lv_crm_priority', array( $this, 'ajax_priority' ) );
        add_action( 'wp_ajax_lv_crm_application_tags', array( $this, 'ajax_application_tags' ) );
        add_action( 'wp_ajax_lv_crm_contact_search', array( $this, 'ajax_contact_search' ) );
        add_action( 'wp_ajax_lv_crm_link_contact', array( $this, 'ajax_link_contact' ) );
        add_action( 'wp_ajax_lv_crm_link_account', array( $this, 'ajax_link_account' ) );
        add_action( 'wp_ajax_lv_crm_unlink_contact', array( $this, 'ajax_unlink_contact' ) );
        add_action( 'wp_ajax_lv_crm_transfer_preview', array( $this, 'ajax_transfer_preview' ) );
        add_action( 'wp_ajax_lv_crm_add_contact_note', array( $this, 'ajax_add_contact_note' ) );

        add_action( 'admin_post_lv_crm_save_tags', array( $this, 'save_tags' ) );
        add_action( 'admin_post_lv_crm_save_routing', array( $this, 'save_routing' ) );
        add_action( 'admin_post_lv_crm_transfer_workload', array( $this, 'transfer_workload' ) );
        add_action( 'admin_post_lv_crm_save_contact', array( $this, 'save_contact' ) );
        add_action( 'admin_post_lv_crm_trash_contact', array( $this, 'trash_contact' ) );
        add_action( 'admin_post_lv_crm_restore_contact', array( $this, 'restore_contact' ) );
        add_action( 'admin_post_lv_crm_delete_contact_permanently', array( $this, 'delete_contact_permanently' ) );
        add_action( 'admin_post_lv_crm_bulk_contacts', array( $this, 'bulk_contacts' ) );
        add_action( 'admin_post_lv_crm_undo_contact_trash', array( $this, 'undo_contact_trash' ) );
        add_action( 'admin_post_lv_crm_mark_notification', array( $this, 'mark_notification' ) );
        add_action( 'admin_post_lv_crm_mark_all_notifications', array( $this, 'mark_all_notifications' ) );

        add_action( 'admin_bar_menu', array( $this, 'admin_bar_notifications' ), 86 );
    }

    public function maybe_upgrade() {
        if ( self::DB_VERSION !== (string) get_option( self::DB_OPTION, '' ) ) {
            self::install_schema();
            update_option( self::DB_OPTION, self::DB_VERSION, false );
        }
    }

    public static function install_schema() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cc = $wpdb->get_charset_collate();

        // Application priority is kept on the application row for fast filtering/sorting.
        $app_table = LV_Applications_Plugin::table_name();
        $col = $wpdb->get_var( "SHOW COLUMNS FROM {$app_table} LIKE 'priority'" );
        if ( ! $col ) $wpdb->query( "ALTER TABLE {$app_table} ADD priority VARCHAR(16) NOT NULL DEFAULT 'normal' AFTER processed, ADD KEY priority (priority)" );

        $tags = self::tags_table();
        dbDelta( "CREATE TABLE {$tags} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(120) NOT NULL,
            color VARCHAR(20) NOT NULL DEFAULT '#5f7c82',
            scope VARCHAR(20) NOT NULL DEFAULT 'both',
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY slug (slug), KEY scope (scope)
        ) {$cc};" );

        $at = self::application_tags_table();
        dbDelta( "CREATE TABLE {$at} (
            application_id BIGINT UNSIGNED NOT NULL,
            tag_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (application_id, tag_id), KEY tag_id (tag_id)
        ) {$cc};" );

        $ct = self::contact_tags_table();
        dbDelta( "CREATE TABLE {$ct} (
            contact_id BIGINT UNSIGNED NOT NULL,
            tag_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (contact_id, tag_id), KEY tag_id (tag_id)
        ) {$cc};" );

        $notifications = self::notifications_table();
        dbDelta( "CREATE TABLE {$notifications} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(40) NOT NULL DEFAULT '',
            entity_type VARCHAR(30) NOT NULL DEFAULT '',
            entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            message TEXT NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            read_at DATETIME NULL,
            PRIMARY KEY (id), KEY user_id (user_id), KEY read_at (read_at), KEY entity (entity_type,entity_id)
        ) {$cc};" );

        $rules = self::routing_rules_table();
        dbDelta( "CREATE TABLE {$rules} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT UNSIGNED NOT NULL,
            form_title VARCHAR(255) NOT NULL DEFAULT '',
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY form_id (form_id), KEY enabled (enabled)
        ) {$cc};" );

        $members = self::routing_members_table();
        dbDelta( "CREATE TABLE {$members} (
            rule_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            weight DECIMAL(6,2) NOT NULL DEFAULT 1.00,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_assigned_at DATETIME NULL,
            PRIMARY KEY (rule_id,user_id), KEY user_id (user_id), KEY enabled (enabled)
        ) {$cc};" );

        $contacts = self::contacts_table();
        dbDelta( "CREATE TABLE {$contacts} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_type VARCHAR(20) NOT NULL DEFAULT 'person',
            display_name VARCHAR(255) NOT NULL,
            organization VARCHAR(255) NOT NULL DEFAULT '',
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            curator_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            linked_wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_activity_at DATETIME NULL,
            deleted_at DATETIME NULL,
            deleted_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id), KEY created_by (created_by), KEY curator_user_id (curator_user_id), KEY linked_wp_user_id (linked_wp_user_id), KEY contact_type (contact_type), KEY updated_at (updated_at), KEY last_activity_at (last_activity_at), KEY deleted_at (deleted_at), KEY display_name (display_name(100))
        ) {$cc};" );
        // Ensure every contact has a usable activity timestamp so inactivity
        // filters never start from an artificial NULL value.
        $wpdb->query( "UPDATE {$contacts} SET last_activity_at=updated_at WHERE last_activity_at IS NULL" );

        $emails = self::contact_emails_table();
        dbDelta( "CREATE TABLE {$emails} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id BIGINT UNSIGNED NOT NULL,
            value VARCHAR(255) NOT NULL,
            normalized VARCHAR(255) NOT NULL DEFAULT '',
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), KEY contact_id (contact_id), KEY contact_normalized (contact_id,normalized(100)), KEY normalized (normalized(120))
        ) {$cc};" );

        $phones = self::contact_phones_table();
        dbDelta( "CREATE TABLE {$phones} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id BIGINT UNSIGNED NOT NULL,
            value VARCHAR(80) NOT NULL,
            normalized VARCHAR(40) NOT NULL DEFAULT '',
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), KEY contact_id (contact_id), KEY contact_normalized (contact_id,normalized), KEY normalized (normalized)
        ) {$cc};" );

        $fields = self::contact_fields_table();
        dbDelta( "CREATE TABLE {$fields} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id BIGINT UNSIGNED NOT NULL,
            field_key VARCHAR(100) NOT NULL DEFAULT '',
            field_label VARCHAR(180) NOT NULL,
            field_type VARCHAR(30) NOT NULL DEFAULT 'text',
            field_value LONGTEXT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY contact_id (contact_id), KEY field_key (field_key), KEY field_label (field_label(100))
        ) {$cc};" );

        $links = self::application_contacts_table();
        dbDelta( "CREATE TABLE {$links} (
            application_id BIGINT UNSIGNED NOT NULL,
            contact_id BIGINT UNSIGNED NOT NULL,
            relation_type VARCHAR(30) NOT NULL DEFAULT 'primary',
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (application_id,contact_id), KEY contact_id (contact_id), KEY is_primary (is_primary)
        ) {$cc};" );

        $contact_log = self::contact_log_table();
        dbDelta( "CREATE TABLE {$contact_log} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(50) NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_role VARCHAR(80) NOT NULL DEFAULT '',
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY contact_id (contact_id), KEY created_at (created_at)
        ) {$cc};" );


        $segments = LV_Contact_Segments::table_name();
        dbDelta( "CREATE TABLE {$segments} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(180) NOT NULL,
            filters_json LONGTEXT NOT NULL,
            visibility VARCHAR(20) NOT NULL DEFAULT 'private',
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY created_by (created_by), KEY visibility (visibility), KEY name (name(100))
        ) {$cc};" );

        $consents = LV_Consent_Service::table_name();
        dbDelta( "CREATE TABLE {$consents} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id BIGINT UNSIGNED NOT NULL,
            contact_email_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            channel VARCHAR(30) NOT NULL DEFAULT 'email',
            consent_type VARCHAR(40) NOT NULL DEFAULT 'marketing_email',
            status VARCHAR(20) NOT NULL DEFAULT 'unknown',
            obtained_at DATETIME NULL,
            source VARCHAR(40) NOT NULL DEFAULT 'manual',
            source_application_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            evidence TEXT NULL,
            document_version VARCHAR(80) NOT NULL DEFAULT '',
            channels_json LONGTEXT NULL,
            form_code VARCHAR(80) NOT NULL DEFAULT '',
            form_schema_version VARCHAR(30) NOT NULL DEFAULT '',
            submission_uuid CHAR(36) NOT NULL DEFAULT '',
            source_url TEXT NULL,
            source_ip VARCHAR(100) NOT NULL DEFAULT '',
            user_agent TEXT NULL,
            revoked_at DATETIME NULL,
            updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY consent_identity (contact_id,contact_email_id,channel,consent_type), KEY contact_status (contact_id,status), KEY consent_type (consent_type), KEY status_channel (status,channel), KEY source_application_id (source_application_id)
        ) {$cc};" );

        // Remove the obsolete e-mail-only uniqueness constraint if it is still
        // present after an upgrade; contact-level consent rows need email_id=0.
        $obsolete_consent_index = $wpdb->get_var( "SHOW INDEX FROM {$consents} WHERE Key_name='email_channel'" );
        if ( $obsolete_consent_index ) $wpdb->query( "ALTER TABLE {$consents} DROP INDEX email_channel" );
        $wpdb->query( "UPDATE {$consents} SET consent_type='marketing_email' WHERE consent_type='' OR consent_type IS NULL" );

        $consent_log = LV_Consent_Service::log_table_name();
        dbDelta( "CREATE TABLE {$consent_log} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id BIGINT UNSIGNED NOT NULL,
            contact_email_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            email_value VARCHAR(255) NOT NULL DEFAULT '',
            channel VARCHAR(30) NOT NULL DEFAULT '',
            consent_type VARCHAR(40) NOT NULL DEFAULT 'marketing_email',
            status VARCHAR(20) NOT NULL DEFAULT 'unknown',
            source VARCHAR(40) NOT NULL DEFAULT 'manual',
            source_application_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            evidence TEXT NULL,
            document_version VARCHAR(80) NOT NULL DEFAULT '',
            channels_json LONGTEXT NULL,
            form_code VARCHAR(80) NOT NULL DEFAULT '',
            form_schema_version VARCHAR(30) NOT NULL DEFAULT '',
            submission_uuid CHAR(36) NOT NULL DEFAULT '',
            source_url TEXT NULL,
            source_ip VARCHAR(100) NOT NULL DEFAULT '',
            user_agent TEXT NULL,
            event_at DATETIME NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY contact_id (contact_id), KEY contact_email_id (contact_email_id), KEY consent_type (consent_type), KEY submission_uuid (submission_uuid), KEY created_at (created_at)
        ) {$cc};" );

        $export_log = LV_Contact_Exporter::export_log_table();
        dbDelta( "CREATE TABLE {$export_log} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            export_type VARCHAR(30) NOT NULL DEFAULT 'contacts',
            profile VARCHAR(50) NOT NULL DEFAULT '',
            mode VARCHAR(30) NOT NULL DEFAULT 'current',
            filters_json LONGTEXT NULL,
            segment_name VARCHAR(180) NOT NULL DEFAULT '',
            contacts_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            rows_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            filename VARCHAR(255) NOT NULL DEFAULT '',
            stats_json LONGTEXT NULL,
            PRIMARY KEY (id), KEY user_id (user_id), KEY created_at (created_at), KEY profile (profile)
        ) {$cc};" );

        // Extend the shared notes table so it can also hold comments for contacts.
        $notes = LV_Applications_Plugin::notes_table_name();
        if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$notes} LIKE 'entity_type'" ) ) {
            $wpdb->query( "ALTER TABLE {$notes} ADD entity_type VARCHAR(20) NOT NULL DEFAULT 'application' AFTER id, ADD entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER entity_type, ADD KEY entity (entity_type,entity_id)" );
            $wpdb->query( "UPDATE {$notes} SET entity_type='application', entity_id=application_id WHERE entity_id=0" );
        }
    }

    public static function tags_table(){ global $wpdb; return $wpdb->prefix.'lv_crm_tags'; }
    public static function application_tags_table(){ global $wpdb; return $wpdb->prefix.'lv_crm_application_tags'; }
    public static function contact_tags_table(){ global $wpdb; return $wpdb->prefix.'lv_crm_contact_tags'; }
    public static function notifications_table(){ global $wpdb; return $wpdb->prefix.'lv_crm_notifications'; }
    public static function routing_rules_table(){ global $wpdb; return $wpdb->prefix.'lv_crm_routing_rules'; }
    public static function routing_members_table(){ global $wpdb; return $wpdb->prefix.'lv_crm_routing_members'; }
    public static function contacts_table(){ global $wpdb; return $wpdb->prefix.'lv_crm_contacts'; }
    public static function contact_emails_table(){ global $wpdb; return $wpdb->prefix.'lv_crm_contact_emails'; }
    public static function contact_phones_table(){ global $wpdb; return $wpdb->prefix.'lv_crm_contact_phones'; }
    public static function contact_fields_table(){ global $wpdb; return $wpdb->prefix.'lv_crm_contact_fields'; }
    public static function application_contacts_table(){ global $wpdb; return $wpdb->prefix.'lv_crm_application_contacts'; }
    public static function contact_log_table(){ global $wpdb; return $wpdb->prefix.'lv_crm_contact_log'; }

    public static function priority_options() {
        return array(
            'low' => array( 'Низкий', 'dashicons-arrow-down-alt2' ),
            'normal' => array( 'Обычный', 'dashicons-minus' ),
            'high' => array( 'Высокий', 'dashicons-arrow-up-alt2' ),
            'urgent' => array( 'Срочный', 'dashicons-warning' ),
        );
    }
    public static function priority_label( $key ) { $o=self::priority_options(); return isset($o[$key])?$o[$key][0]:'Обычный'; }
    public static function priority_html( $app, $compact=false ) {
        $key = ! empty($app->priority) ? sanitize_key($app->priority) : 'normal';
        $o=self::priority_options(); if(!isset($o[$key]))$key='normal';
        return '<span class="lv-priority lv-priority--'.esc_attr($key).($compact?' is-compact':'').'"><span class="dashicons '.esc_attr($o[$key][1]).'"></span>'.esc_html($o[$key][0]).'</span>';
    }

    public static function get_tags( $scope='application' ) {
        $scope = 'contact' === $scope ? 'contact' : 'application';
        $cache_key = 'crm.tags.' . $scope;
        if ( class_exists( 'LV_Request_Cache' ) && LV_Request_Cache::has( $cache_key ) ) {
            return LV_Request_Cache::get( $cache_key );
        }
        global $wpdb;
        $where = 'contact' === $scope ? "scope IN ('contact','both')" : "scope IN ('application','both')";
        $rows = $wpdb->get_results( 'SELECT * FROM ' . self::tags_table() . " WHERE {$where} ORDER BY name ASC" );
        if ( class_exists( 'LV_Request_Cache' ) ) LV_Request_Cache::set( $cache_key, $rows );
        return $rows;
    }

    public static function get_application_tags( $application_id ) {
        $application_id = absint( $application_id );
        $key = 'crm.application_tags.' . $application_id;
        if ( class_exists( 'LV_Request_Cache' ) && LV_Request_Cache::has( $key ) ) return LV_Request_Cache::get( $key );
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT t.* FROM '.self::tags_table().' t INNER JOIN '.self::application_tags_table().' x ON x.tag_id=t.id WHERE x.application_id=%d ORDER BY t.name', $application_id ) );
        if ( class_exists( 'LV_Request_Cache' ) ) LV_Request_Cache::set( $key, $rows );
        return $rows;
    }

    public static function get_contact_tags( $contact_id ) {
        $contact_id = absint( $contact_id );
        $key = 'crm.contact_tags.' . $contact_id;
        if ( class_exists( 'LV_Request_Cache' ) && LV_Request_Cache::has( $key ) ) return LV_Request_Cache::get( $key );
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT t.* FROM '.self::tags_table().' t INNER JOIN '.self::contact_tags_table().' x ON x.tag_id=t.id WHERE x.contact_id=%d ORDER BY t.name', $contact_id ) );
        if ( class_exists( 'LV_Request_Cache' ) ) LV_Request_Cache::set( $key, $rows );
        return $rows;
    }


    /** Stable system tags used by automatic form integration. */
    public static function ensure_system_contact_tag( $system_key ) {
        global $wpdb;
        $system_key = sanitize_key( $system_key );
        $defs = array(
            'volunteer' => array( 'name' => 'Волонтер', 'color' => '#5f7c82' ),
            'donor' => array( 'name' => 'Донор', 'color' => '#5f7c82' ),
        );
        if ( ! isset( $defs[ $system_key ] ) ) return 0;
        $table = self::tags_table();
        $tag = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE slug=%s LIMIT 1', $system_key ) );
        if ( ! $tag ) {
            $tag = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE LOWER(name)=LOWER(%s) ORDER BY id ASC LIMIT 1', $defs[ $system_key ]['name'] ) );
            if ( $tag ) {
                $slug_owner = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $table . ' WHERE slug=%s AND id<>%d LIMIT 1', $system_key, $tag->id ) ) );
                if ( ! $slug_owner ) $wpdb->update( $table, array( 'slug' => $system_key ), array( 'id' => absint( $tag->id ) ), array( '%s' ), array( '%d' ) );
            }
        }
        if ( ! $tag ) {
            $ok = $wpdb->insert( $table, array( 'name'=>$defs[$system_key]['name'], 'slug'=>$system_key, 'color'=>$defs[$system_key]['color'], 'scope'=>'contact', 'created_by'=>0, 'created_at'=>current_time('mysql') ), array( '%s','%s','%s','%s','%d','%s' ) );
            if ( ! $ok ) return 0;
            $tag = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE id=%d', absint( $wpdb->insert_id ) ) );
        }
        if ( $tag && 'application' === $tag->scope ) $wpdb->update( $table, array( 'scope'=>'both' ), array( 'id'=>absint($tag->id) ), array('%s'), array('%d') );
        if ( class_exists( 'LV_Request_Cache' ) ) { LV_Request_Cache::forget( 'crm.tags.contact' ); LV_Request_Cache::forget( 'crm.tags.application' ); }
        return $tag ? absint( $tag->id ) : 0;
    }

    public static function add_contact_tag( $contact_id, $tag_id ) {
        global $wpdb;
        $contact_id = absint( $contact_id ); $tag_id = absint( $tag_id );
        if ( ! $contact_id || ! $tag_id ) return false;
        $exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::contact_tags_table() . ' WHERE contact_id=%d AND tag_id=%d', $contact_id, $tag_id ) );
        if ( $exists ) return true;
        $ok = $wpdb->insert( self::contact_tags_table(), array( 'contact_id'=>$contact_id, 'tag_id'=>$tag_id ), array('%d','%d') );
        if ( $ok ) {
            if ( class_exists( 'LV_Request_Cache' ) ) LV_Request_Cache::forget( 'crm.contact_tags.' . $contact_id );
            self::contact_log( $contact_id, 'tag_added_automatically', 'Метка добавлена автоматически по источнику контакта.', array( 'tag_id'=>$tag_id ) );
        }
        return (bool) $ok;
    }

    public static function tags_html( $tags ) {
        if(!$tags)return '<span class="lv-tag-empty">Без меток</span>';
        $html='<span class="lv-tag-list">';
        foreach($tags as $tag){ $html.='<span class="lv-tag-chip" style="--tag:'.esc_attr($tag->color).'">'.esc_html($tag->name).'</span>'; }
        return $html.'</span>';
    }

    public function ajax_priority() {
        if ( ! current_user_can('lv_update_applications') ) wp_send_json_error(array('message'=>'Недостаточно прав.'),403);
        check_ajax_referer('lv_crm_priority','nonce');
        $id=absint($_POST['application_id']??0); $priority=sanitize_key(wp_unslash($_POST['priority']??''));
        if(!isset(self::priority_options()[$priority]))wp_send_json_error(array('message'=>'Неизвестный приоритет.'),400);
        $app=LV_Applications_Plugin::get_application($id);
        if(!$app||!LV_Applications_Plugin::can_edit_application($app))wp_send_json_error(array('message'=>'Нет доступа к изменению заявки.'),403);
        global $wpdb; $old=!empty($app->priority)?$app->priority:'normal';
        $wpdb->update(LV_Applications_Plugin::table_name(),array('priority'=>$priority),array('id'=>$id),array('%s'),array('%d'));
        if($old!==$priority)LV_Applications_Plugin::log_event($id,'priority_changed','Приоритет изменён: '.self::priority_label($old).' → '.self::priority_label($priority).'.');
        wp_send_json_success(array('label'=>self::priority_label($priority),'html'=>self::priority_html((object)array('priority'=>$priority),true)));
    }

    public function ajax_application_tags() {
        check_ajax_referer('lv_crm_application_tags','nonce');
        $id=absint($_POST['application_id']??0); $app=LV_Applications_Plugin::get_application($id);
        if(!$app||!LV_Applications_Plugin::can_edit_application($app))wp_send_json_error(array('message'=>'Нет доступа к изменению заявки.'),403);
        $ids=isset($_POST['tag_ids'])?(array)$_POST['tag_ids']:array(); $ids=array_values(array_filter(array_map('absint',$ids)));
        $allowed=array_map('intval',wp_list_pluck(self::get_tags('application'),'id')); $ids=array_values(array_intersect($ids,$allowed));
        global $wpdb; $wpdb->delete(self::application_tags_table(),array('application_id'=>$id),array('%d'));
        foreach($ids as $tid)$wpdb->insert(self::application_tags_table(),array('application_id'=>$id,'tag_id'=>$tid),array('%d','%d'));
        LV_Applications_Plugin::log_event($id,'tags_changed','Изменены метки заявки.');
        wp_send_json_success(array('html'=>self::tags_html(self::get_application_tags($id))));
    }

    public function save_tags() {
        if(!LV_Applications_Plugin::is_manager())wp_die('Недостаточно прав.');
        check_admin_referer('lv_crm_save_tags');
        global $wpdb;

        $delete_id=absint($_POST['delete_tag']??0);
        $edit_id=absint($_POST['edit_tag']??0);
        $name=sanitize_text_field(wp_unslash($_POST['name']??''));
        $scope=sanitize_key(wp_unslash($_POST['scope']??'both'));
        $color=sanitize_hex_color(wp_unslash($_POST['color']??''));
        if(!in_array($scope,array('application','contact','both'),true))$scope='both';
        if(!$color)$color='#5f7c82';

        if($delete_id){
            $tag=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::tags_table().' WHERE id=%d',$delete_id));
            if(!$tag)wp_die('Метка не найдена.');
            if(in_array((string)$tag->slug,array('volunteer','donor'),true))wp_die('Системную метку интеграции форм нельзя удалить. Можно изменить её название и цвет.');
            $wpdb->delete(self::application_tags_table(),array('tag_id'=>$delete_id),array('%d'));
            $wpdb->delete(self::contact_tags_table(),array('tag_id'=>$delete_id),array('%d'));
            $wpdb->delete(self::tags_table(),array('id'=>$delete_id),array('%d'));
        }elseif($edit_id){
            $tag=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::tags_table().' WHERE id=%d',$edit_id));
            if(!$tag)wp_die('Метка не найдена.');
            if(!$name)wp_die('Укажите название метки.');
            $is_system=in_array((string)$tag->slug,array('volunteer','donor'),true);
            if($is_system&&'application'===$scope)$scope='contact';
            // Slug/system identifiers stay stable. Renaming a label must never break automations.
            $wpdb->update(self::tags_table(),array('name'=>$name,'color'=>$color,'scope'=>$scope),array('id'=>$edit_id),array('%s','%s','%s'),array('%d'));
        }elseif($name){
            $slug=sanitize_title($name);if(!$slug)$slug='tag-'.time();$base=$slug;$i=2;
            while($wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::tags_table().' WHERE slug=%s',$slug)))$slug=$base.'-'.$i++;
            $wpdb->insert(self::tags_table(),array('name'=>$name,'slug'=>$slug,'color'=>$color,'scope'=>$scope,'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')),array('%s','%s','%s','%s','%d','%s'));
        }
        if(class_exists('LV_Request_Cache'))LV_Request_Cache::flush();
        wp_safe_redirect(add_query_arg(array('page'=>LV_Applications_Plugin::PAGE_SLUG,'lv_screen'=>'tags','lv_saved'=>1),admin_url('admin.php')));exit;
    }

    public static function user_full_name( $user ) {
        if ( is_numeric( $user ) ) $user = get_userdata( absint( $user ) );
        if ( ! $user ) return '';
        $first = trim( (string) get_user_meta( $user->ID, 'first_name', true ) );
        $last  = trim( (string) get_user_meta( $user->ID, 'last_name', true ) );
        $name = trim( $first . ' ' . $last );
        return $name !== '' ? $name : ( $user->display_name ?: $user->user_login );
    }
    public static function mention_users() {
        $users=LV_Applications_Plugin::eligible_assignees(); $out=array();
        foreach($users as $u){ $full=get_userdata($u->ID); if(!$full)continue; $out[]=array('id'=>$u->ID,'login'=>$full->user_login,'name'=>self::user_full_name($full),'role'=>LV_Applications_Plugin::assignee_role_label($u->ID)); }
        return $out;
    }
    public static function render_mentions( $message ) {
        $parts = preg_split( '/(@[A-Za-z0-9._-]+)/u', (string) $message, -1, PREG_SPLIT_DELIM_CAPTURE );
        $html = '';
        foreach ( (array) $parts as $part ) {
            if ( preg_match( '/^@([A-Za-z0-9._-]+)$/u', $part, $m ) ) {
                $u = get_user_by( 'login', $m[1] );
                if ( $u && array_intersect( array('contributor','author','editor','administrator'), (array) $u->roles ) ) {
                    $html .= '<span class="lv-mention-pill" data-user-id="'.esc_attr($u->ID).'" title="@'.esc_attr($u->user_login).' · '.esc_attr(LV_Applications_Plugin::assignee_role_label($u->ID)).'">@'.esc_html(self::user_full_name($u)).'</span>';
                    continue;
                }
            }
            $html .= nl2br( esc_html( $part ) );
        }
        return $html;
    }
    public static function process_mentions( $message, $entity_type, $entity_id ) {
        if(!preg_match_all('/(?<![A-Za-z0-9._-])@([A-Za-z0-9._-]+)/u',(string)$message,$m))return;
        $logins=array_unique($m[1]); foreach($logins as $login){$u=get_user_by('login',$login);if(!$u||$u->ID===get_current_user_id())continue;
            if(!array_intersect(array('contributor','author','editor','administrator'),(array)$u->roles))continue;
            self::notify($u->ID,'mention',$entity_type,$entity_id,self::user_full_name(wp_get_current_user()).' упомянул(а) вас: '.wp_trim_words($message,18,'…'));
        }
    }
    public static function notify($user_id,$type,$entity_type,$entity_id,$message){global $wpdb;$user_id=absint($user_id);$wpdb->insert(self::notifications_table(),array('user_id'=>$user_id,'type'=>sanitize_key($type),'entity_type'=>sanitize_key($entity_type),'entity_id'=>absint($entity_id),'message'=>sanitize_text_field($message),'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')),array('%d','%s','%s','%d','%s','%d','%s'));if(class_exists('LV_Request_Cache'))LV_Request_Cache::forget('crm.unread_notifications.'.$user_id);}
    public static function unread_notifications_count($uid=0){
        global $wpdb;
        $uid=$uid?:get_current_user_id();
        $key='crm.unread_notifications.'.absint($uid);
        if(class_exists('LV_Request_Cache')&&LV_Request_Cache::has($key))return(int)LV_Request_Cache::get($key);
        $count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::notifications_table().' WHERE user_id=%d AND read_at IS NULL',$uid));
        if(class_exists('LV_Request_Cache'))LV_Request_Cache::set($key,$count);
        return$count;
    }
    public function admin_bar_notifications($bar){if(!is_admin_bar_showing()||!current_user_can('lv_view_applications'))return;$n=self::unread_notifications_count();if(!$n)return;$bar->add_node(array('id'=>'lv-crm-notifications','title'=>'<span class="ab-icon dashicons dashicons-bell"></span><span class="ab-label">'.esc_html($n).'</span>','href'=>add_query_arg(array('page'=>LV_Applications_Plugin::PAGE_SLUG,'lv_screen'=>'notifications'),admin_url('admin.php')),'meta'=>array('title'=>'Уведомления CRM фонда')));}
    public function mark_notification(){if(!current_user_can('lv_view_applications'))wp_die('Недостаточно прав.');$id=absint($_GET['notification_id']??0);check_admin_referer('lv_crm_notification_'.$id);global$wpdb;$n=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::notifications_table().' WHERE id=%d AND user_id=%d',$id,get_current_user_id()));if($n)$wpdb->update(self::notifications_table(),array('read_at'=>current_time('mysql')),array('id'=>$id),array('%s'),array('%d'));$url=admin_url('admin.php?page='.LV_Applications_Plugin::PAGE_SLUG);if($n&&'application'===$n->entity_type)$url=add_query_arg(array('page'=>LV_Applications_Plugin::PAGE_SLUG,'action'=>'view','application_id'=>$n->entity_id),admin_url('admin.php'));if($n&&'contact'===$n->entity_type)$url=add_query_arg(array('page'=>self::CONTACTS_SLUG,'action'=>'view','contact_id'=>$n->entity_id),admin_url('admin.php'));wp_safe_redirect($url);exit;}
    public function mark_all_notifications(){if(!current_user_can('lv_view_applications'))wp_die('Недостаточно прав.');check_admin_referer('lv_crm_mark_all_notifications');global$wpdb;$wpdb->query($wpdb->prepare('UPDATE '.self::notifications_table().' SET read_at=%s WHERE user_id=%d AND read_at IS NULL',current_time('mysql'),get_current_user_id()));wp_safe_redirect(add_query_arg(array('page'=>LV_Applications_Plugin::PAGE_SLUG,'lv_screen'=>'notifications'),admin_url('admin.php')));exit;}

    public function render_notifications() {
        global $wpdb;$items=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::notifications_table().' WHERE user_id=%d ORDER BY created_at DESC,id DESC LIMIT 100',get_current_user_id()));
        echo '<div class="wrap lv-applications-wrap"><header class="lv-page-head"><div class="lv-head-copy"><span class="lv-kicker">CRM фонда · Команда</span><div class="lv-title-row"><h1>Уведомления</h1></div><p>Упоминания и рабочие события внутри CRM — без почтовых уведомлений.</p></div><div class="lv-head-actions"><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=lv_crm_mark_all_notifications'),'lv_crm_mark_all_notifications')).'">Отметить всё прочитанным</a></div></header>';
        LV_Applications_Plugin::instance()->render_workspace_nav(array(),'notifications');
        echo '<section class="lv-panel lv-notification-list">';if(!$items)echo'<div class="lv-empty-state"><span class="dashicons dashicons-bell"></span><h2>Уведомлений нет</h2></div>';
        foreach($items as$n){$url=wp_nonce_url(add_query_arg(array('action'=>'lv_crm_mark_notification','notification_id'=>$n->id),admin_url('admin-post.php')),'lv_crm_notification_'.$n->id);echo'<a class="lv-notification-item '.($n->read_at?'':'is-unread').'" href="'.esc_url($url).'"><span class="lv-notification-icon dashicons dashicons-bell"></span><div><strong>'.esc_html($n->message).'</strong><small>'.esc_html(mysql2date('d.m.Y H:i',$n->created_at)).'</small></div>'.(!$n->read_at?'<i></i>':'').'</a>';}
        echo'</section></div>';
    }

    public function render_tags() {
        if ( ! LV_Applications_Plugin::is_manager() ) wp_die( 'Недостаточно прав.' );
        global $wpdb;
        $all = $wpdb->get_results( 'SELECT * FROM ' . self::tags_table() . ' ORDER BY name ASC' );
        echo '<div class="wrap lv-applications-wrap"><header class="lv-page-head"><div class="lv-head-copy"><span class="lv-kicker">CRM фонда · Классификация</span><div class="lv-title-row"><h1>Метки</h1></div><p>Создавайте и редактируйте метки без изменения их технических идентификаторов. Перед удалением CRM показывает, сколько записей используют метку.</p></div></header>';
        LV_Applications_Plugin::instance()->render_workspace_nav(array(),'tags');
        echo '<div class="lv-tags-layout"><section class="lv-panel"><div class="lv-panel-title"><span class="dashicons dashicons-tag"></span><h2>Создать метку</h2></div><form class="lv-tag-create" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="lv_crm_save_tags">'; wp_nonce_field('lv_crm_save_tags');
        echo '<div class="lv-control"><label>Название</label><input name="name" required maxlength="100" placeholder="Например, Донор"></div><div class="lv-control"><label>Где использовать</label><select name="scope"><option value="both">Заявки и контакты</option><option value="application">Только заявки</option><option value="contact">Только контакты</option></select></div><div class="lv-control"><label>Цвет</label><input type="color" name="color" value="#5f7c82"></div><button class="button button-primary">Добавить метку</button></form></section>';
        echo '<section class="lv-panel"><div class="lv-panel-title"><span class="dashicons dashicons-category"></span><h2>Все метки</h2><span>'.esc_html(count($all)).'</span></div><div class="lv-tag-admin-list">';
        if(!$all) echo '<div class="lv-empty-mini">Меток пока нет.</div>';
        foreach($all as$t){
            $app_count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::application_tags_table().' WHERE tag_id=%d',$t->id));
            $contact_count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::contact_tags_table().' WHERE tag_id=%d',$t->id));
            $usage=$app_count+$contact_count;
            $is_system=in_array((string)$t->slug,array('volunteer','donor'),true);
            $system_badge=$is_system?'<em class="lv-system-tag-badge">Системная</em>':'';
            echo '<details class="lv-tag-admin-row lv-tag-admin-edit"><summary><span class="lv-tag-chip" style="--tag:'.esc_attr($t->color).'">'.esc_html($t->name).'</span><span>'.esc_html($t->scope==='both'?'Заявки и контакты':($t->scope==='contact'?'Контакты':'Заявки')).$system_badge.'</span><small>'.esc_html($usage).' использований</small><span class="dashicons dashicons-edit"></span></summary>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="lv-tag-edit-form"><input type="hidden" name="action" value="lv_crm_save_tags"><input type="hidden" name="edit_tag" value="'.esc_attr($t->id).'">';wp_nonce_field('lv_crm_save_tags');
            echo '<div class="lv-control"><label>Название</label><input name="name" required maxlength="100" value="'.esc_attr($t->name).'"></div><div class="lv-control"><label>Где использовать</label><select name="scope"><option value="both" '.selected($t->scope,'both',false).'>Заявки и контакты</option>';
            if(!$is_system)echo '<option value="application" '.selected($t->scope,'application',false).'>Только заявки</option>';
            echo '<option value="contact" '.selected($t->scope,'contact',false).'>Только контакты</option></select></div><div class="lv-control"><label>Цвет</label><input type="color" name="color" value="'.esc_attr($t->color).'"></div><button class="button button-primary">Сохранить</button></form>';
            if($is_system){
                echo '<div class="lv-tag-delete-form lv-system-tag-note">Используется автоматической интеграцией форм. Технический идентификатор: <code>'.esc_html($t->slug).'</code>.</div></details>';
            }else{
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="lv-tag-delete-form"><input type="hidden" name="action" value="lv_crm_save_tags"><input type="hidden" name="delete_tag" value="'.esc_attr($t->id).'">';wp_nonce_field('lv_crm_save_tags');echo '<button class="button-link-delete" data-confirm="'.esc_attr('Удалить метку «'.$t->name.'»? Она будет снята с '.$app_count.' заявок и '.$contact_count.' контактов.').'">Удалить метку</button></form></details>';
            }
        }
        echo '</div></section></div></div>';
    }

    // ----------------------------- Smart routing -----------------------------
    public static function rule_for_form($form_id){global$wpdb;return$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::routing_rules_table().' WHERE form_id=%d',absint($form_id)));}
    public static function routing_members($rule_id,$only_enabled=false){global$wpdb;$sql=$wpdb->prepare('SELECT * FROM '.self::routing_members_table().' WHERE rule_id=%d',absint($rule_id));if($only_enabled)$sql.=' AND enabled=1';return$wpdb->get_results($sql.' ORDER BY user_id');}
    private static function user_loads($user_ids,$form_id=0){
        $form_ids=$form_id?array(absint($form_id)):array();
        $matrix=LV_Routing_Load_Provider::load($user_ids,$form_ids);
        return LV_Routing_Load_Provider::for_form($matrix,absint($form_id));
    }
    private static function select_candidate($members,$form_id,$sim_loads=null){$ids=array_map('intval',wp_list_pluck($members,'user_id'));$loads=is_array($sim_loads)?$sim_loads:self::user_loads($ids,$form_id);$maxU=max(1,max(array_column($loads,'unprocessed')));$maxA=max(1,max(array_column($loads,'active')));$maxF=max(1,max(array_column($loads,'form')));$best=null;$bestScore=null;
        foreach($members as$m){$uid=absint($m->user_id);if(!LV_Applications_Plugin::eligible_assignee_public($uid))continue;$weight=max(.1,(float)$m->weight);$l=$loads[$uid]??array('unprocessed'=>0,'active'=>0,'form'=>0);$score=((.70*$l['unprocessed']/$maxU)+(.20*$l['active']/$maxA)+(.10*$l['form']/$maxF))/$weight;$stamp=$m->last_assigned_at?strtotime($m->last_assigned_at):0;if(null===$bestScore||$score<$bestScore-.00001||(abs($score-$bestScore)<.00001&&$stamp<$best['stamp'])){$bestScore=$score;$best=array('user_id'=>$uid,'score'=>$score,'stamp'=>$stamp,'loads'=>$l,'weight'=>$weight);}}
        return$best;
    }
    public function auto_route_application($application_id,$form_id,$form_title){$rule=self::rule_for_form($form_id);if(!$rule||!$rule->enabled)return;$members=self::routing_members($rule->id,true);if(!$members)return;$choice=self::select_candidate($members,$form_id);if(!$choice)return;global$wpdb;$ok=$wpdb->update(LV_Applications_Plugin::table_name(),array('assignee_id'=>$choice['user_id']),array('id'=>absint($application_id),'assignee_id'=>0),array('%d'),array('%d','%d'));if($ok){$wpdb->update(self::routing_members_table(),array('last_assigned_at'=>current_time('mysql')),array('rule_id'=>$rule->id,'user_id'=>$choice['user_id']),array('%s'),array('%d','%d'));LV_Applications_Plugin::log_event($application_id,'auto_assigned','Автоматически назначено: '.LV_Applications_Plugin::assignee_name($choice['user_id']).'.',0,array('algorithm'=>'weighted_load','score'=>$choice['score'],'loads'=>$choice['loads'],'weight'=>$choice['weight']));self::notify($choice['user_id'],'assignment','application',$application_id,'Вам автоматически назначена новая заявка #'.$application_id.'.');}}

    public function save_routing(){
        if(!LV_Applications_Plugin::is_manager())wp_die('Недостаточно прав.');
        check_admin_referer('lv_crm_save_routing');
        $form_id=absint($_POST['form_id']??0);
        $title=sanitize_text_field(wp_unslash($_POST['form_title']??''));
        $enabled=!empty($_POST['enabled'])?1:0;
        if(!$form_id)wp_die('Не указана форма.');

        $selected=isset($_POST['members'])?(array)$_POST['members']:array();
        $weights=isset($_POST['weights'])?(array)$_POST['weights']:array();
        $desired=array();
        foreach($selected as$uidRaw){
            $uid=absint($uidRaw);
            if(!$uid||!LV_Applications_Plugin::eligible_assignee_public($uid))continue;
            $w=isset($weights[$uid])?(float)$weights[$uid]:1;
            $desired[$uid]=max(.1,min(5,$w));
        }

        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try{
            $existing=self::rule_for_form($form_id);
            $now=current_time('mysql');
            if($existing){
                $ok=$wpdb->update(self::routing_rules_table(),array('form_title'=>$title,'enabled'=>$enabled,'updated_at'=>$now),array('id'=>$existing->id),array('%s','%d','%s'),array('%d'));
                if(false===$ok)throw new RuntimeException('Routing rule update failed.');
                $rule_id=absint($existing->id);
            }else{
                $ok=$wpdb->insert(self::routing_rules_table(),array('form_id'=>$form_id,'form_title'=>$title,'enabled'=>$enabled,'updated_at'=>$now),array('%d','%s','%d','%s'));
                if(!$ok)throw new RuntimeException('Routing rule insert failed.');
                $rule_id=absint($wpdb->insert_id);
            }

            $existingMembers=self::routing_members($rule_id,false);
            $oldBy=array();
            foreach($existingMembers as$m)$oldBy[(int)$m->user_id]=$m;

            foreach($desired as$uid=>$weight){
                if(isset($oldBy[$uid])){
                    $old=$oldBy[$uid];
                    if(abs((float)$old->weight-$weight)>.00001||(int)$old->enabled!==1){
                        $ok=$wpdb->update(self::routing_members_table(),array('weight'=>$weight,'enabled'=>1),array('rule_id'=>$rule_id,'user_id'=>$uid),array('%f','%d'),array('%d','%d'));
                        if(false===$ok)throw new RuntimeException('Routing member update failed.');
                    }
                    unset($oldBy[$uid]);
                }else{
                    $ok=$wpdb->insert(self::routing_members_table(),array('rule_id'=>$rule_id,'user_id'=>$uid,'weight'=>$weight,'enabled'=>1,'last_assigned_at'=>null),array('%d','%d','%f','%d','%s'));
                    if(!$ok)throw new RuntimeException('Routing member insert failed.');
                }
            }
            foreach($oldBy as$uid=>$old){
                $ok=$wpdb->delete(self::routing_members_table(),array('rule_id'=>$rule_id,'user_id'=>$uid),array('%d','%d'));
                if(false===$ok)throw new RuntimeException('Routing member delete failed.');
            }
            $wpdb->query('COMMIT');
        }catch(Throwable $e){
            $wpdb->query('ROLLBACK');
            wp_die('Не удалось сохранить правило распределения. Изменения отменены.');
        }
        if(class_exists('LV_Request_Cache'))LV_Request_Cache::flush();
        wp_safe_redirect(add_query_arg(array('page'=>LV_Applications_Plugin::PAGE_SLUG,'lv_screen'=>'routing','lv_saved'=>1),admin_url('admin.php')));
        exit;
    }

    private static function available_forms_for_routing(){ $forms=array(); foreach(LV_Applications_Plugin::get_forms() as$f)$forms[$f->form_id]=(object)array('form_id'=>$f->form_id,'form_title'=>$f->form_title); if(class_exists('WPCF7_ContactForm')){foreach((array)WPCF7_ContactForm::find() as$f)$forms[$f->id()]=(object)array('form_id'=>$f->id(),'form_title'=>$f->title());} uasort($forms,function($a,$b){return strcasecmp($a->form_title,$b->form_title);});return$forms; }

    public function render_routing(){
        if(!LV_Applications_Plugin::is_manager())wp_die('Недостаточно прав.');
        $forms=self::available_forms_for_routing();$users=LV_Applications_Plugin::eligible_assignees();$user_ids=array_map('intval',wp_list_pluck($users,'ID'));
        echo'<div class="wrap lv-applications-wrap"><header class="lv-page-head"><div class="lv-head-copy"><span class="lv-kicker">CRM фонда · Автоматизация</span><div class="lv-title-row"><h1>Умное распределение</h1><span class="lv-live-badge"><i></i> Баланс нагрузки</span></div><p>Каждый тип заявки можно направить группе сотрудников. Система выбирает получателя по фактической незавершённой нагрузке, общей очереди, специализации по форме и индивидуальному весу.</p></div></header>';
        LV_Applications_Plugin::instance()->render_workspace_nav(array(),'routing');
        echo'<div class="lv-routing-explain"><div><strong>70%</strong><span>необработанные</span></div><div><strong>20%</strong><span>назначено за 30 дней</span></div><div><strong>10%</strong><span>эта форма</span></div><p>Вес регулирует доступную ёмкость сотрудника. При равной оценке выбирается тот, кому автоматическое назначение выполнялось раньше. Ручное назначение всегда имеет приоритет.</p></div>';
        if(!$forms){echo'<section class="lv-panel"><div class="lv-empty-state"><span class="dashicons dashicons-forms"></span><h2>Формы пока не найдены</h2><p>После создания CF7-форм или поступления заявок здесь появятся правила распределения.</p></div></section>';echo'</div>';return;}
        $routing_form_ids=array_map('intval',array_keys($forms));
        $routing_load_matrix=LV_Routing_Load_Provider::load($user_ids,$routing_form_ids);
        echo'<div class="lv-routing-grid">';
        foreach($forms as$f){
            $rule=self::rule_for_form($f->form_id);$members=$rule?self::routing_members($rule->id,false):array();$by=array();foreach($members as$m)$by[$m->user_id]=$m;$loads=LV_Routing_Load_Provider::for_form($routing_load_matrix,absint($f->form_id));
            echo'<form class="lv-routing-card" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="lv_crm_save_routing"><input type="hidden" name="form_id" value="'.esc_attr($f->form_id).'"><input type="hidden" name="form_title" value="'.esc_attr($f->form_title).'">';wp_nonce_field('lv_crm_save_routing');
            echo'<header><div><span>Contact Form 7 · #'.esc_html($f->form_id).'</span><h2>'.esc_html($f->form_title).'</h2></div><label class="lv-routing-toggle"><input type="checkbox" name="enabled" value="1" '.checked($rule&&$rule->enabled,1,false).'><i></i><span>Автораспределение</span></label></header>';
            echo'<div class="lv-routing-members">';
            foreach($users as$u){$m=$by[$u->ID]??null;$l=$loads[$u->ID]??array('unprocessed'=>0,'active'=>0,'form'=>0);$initial=function_exists('mb_substr')?mb_substr($u->display_name,0,1):substr($u->display_name,0,1);
                echo'<div class="lv-routing-member"><label><input type="checkbox" name="members[]" value="'.esc_attr($u->ID).'" '.checked((bool)$m,true,false).'><span class="lv-avatar-letter">'.esc_html($initial).'</span><span><strong>'.esc_html($u->display_name).'</strong><small>'.esc_html(LV_Applications_Plugin::assignee_role_label($u->ID)).' · '.esc_html($l['unprocessed']).' необр. · '.esc_html($l['form']).' этой формы</small></span></label><label class="lv-weight">Вес<input type="number" step="0.1" min="0.1" max="5" name="weights['.esc_attr($u->ID).']" value="'.esc_attr($m?$m->weight:'1.0').'"></label></div>';
            }
            echo'</div><footer><div class="lv-routing-footnote"><span class="dashicons dashicons-info-outline"></span><span>'.($rule&&$rule->enabled?'Новые заявки распределяются автоматически.':'Новые заявки останутся нераспределёнными.').'</span></div><button class="button button-primary">Сохранить правило</button></footer></form>';
        }
        echo'</div>';$this->render_transfer_tool($users);echo'</div>';
    }

    private function render_transfer_tool($users){echo'<section class="lv-panel lv-transfer-panel"><div class="lv-panel-title"><span class="dashicons dashicons-randomize"></span><h2>Передать необработанную нагрузку</h2><span>С учётом текущей загрузки получателей</span></div><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="lv-transfer-form"><input type="hidden" name="action" value="lv_crm_transfer_workload">';wp_nonce_field('lv_crm_transfer_workload');echo'<div class="lv-transfer-grid"><div class="lv-control"><label>От кого</label><select name="source_user" required><option value="">Выберите сотрудника</option>';foreach($users as$u)echo'<option value="'.esc_attr($u->ID).'">'.esc_html($u->display_name).'</option>';echo'</select></div><div class="lv-control"><label>Кому можно передать</label><div class="lv-recipient-checks">';foreach($users as$u)echo'<label><input type="checkbox" name="recipients[]" value="'.esc_attr($u->ID).'"><span>'.esc_html($u->display_name).'</span></label>';echo'</div></div></div><div class="lv-transfer-preview"><span class="dashicons dashicons-info-outline"></span><div><strong>Сначала выполните предварительный расчёт</strong><p>Будут учитываться все необработанные активные заявки выбранного сотрудника и текущая нагрузка получателей.</p></div></div><footer><button type="button" class="button lv-transfer-preview-button">Предварительный расчёт</button><button type="submit" class="button button-primary lv-transfer-submit" disabled>Выполнить перераспределение</button></footer></form></section>';}

    private static function redistribution_plan($source,$recipients){
        global $wpdb;
        $source=absint($source);
        $recipients=array_values(array_unique(array_filter(array_map('absint',$recipients),function($id)use($source){return $id && $id!==$source && LV_Applications_Plugin::eligible_assignee_public($id);})));
        if(!$recipients)return array('apps'=>array(),'assignments'=>array(),'counts'=>array());

        $apps=$wpdb->get_results($wpdb->prepare(
            'SELECT id,form_id,submitted_at FROM '.LV_Applications_Plugin::table_name().' WHERE deleted_at IS NULL AND processed=0 AND assignee_id=%d ORDER BY submitted_at ASC,id ASC',
            $source
        ));
        $counts=array_fill_keys($recipients,0);
        $assign=array();
        if(!$apps)return array('apps'=>array(),'assignments'=>array(),'counts'=>$counts);

        $form_ids=array_values(array_unique(array_map('intval',wp_list_pluck($apps,'form_id'))));
        $matrix=LV_Routing_Load_Provider::load($recipients,$form_ids);
        $planned_total=array_fill_keys($recipients,0);
        $planned_form=array();
        foreach($recipients as$uid){
            $planned_form[$uid]=array();
            foreach($form_ids as$fid)$planned_form[$uid][$fid]=0;
        }

        $rules_by_form=array();
        if($form_ids){
            $ph=implode(',',array_fill(0,count($form_ids),'%d'));
            $rules=$wpdb->get_results($wpdb->prepare(
                'SELECT * FROM '.self::routing_rules_table()." WHERE form_id IN ({$ph})",
                $form_ids
            ));
            foreach((array)$rules as$r)$rules_by_form[(int)$r->form_id]=$r;
        }

        $member_map=array();
        $rule_ids=array_values(array_filter(array_map('intval',wp_list_pluck($rules_by_form,'id'))));
        if($rule_ids){
            $rule_ph=implode(',',array_fill(0,count($rule_ids),'%d'));
            $user_ph=implode(',',array_fill(0,count($recipients),'%d'));
            $args=array_merge($rule_ids,$recipients);
            $rows=$wpdb->get_results($wpdb->prepare(
                'SELECT * FROM '.self::routing_members_table()." WHERE rule_id IN ({$rule_ph}) AND user_id IN ({$user_ph})",
                $args
            ));
            foreach((array)$rows as$m)$member_map[(int)$m->rule_id][(int)$m->user_id]=$m;
        }

        foreach($apps as$app){
            $members=array();
            $sim=array();
            $rule=isset($rules_by_form[(int)$app->form_id])?$rules_by_form[(int)$app->form_id]:null;

            foreach($recipients as$uid){
                $weight=1.0;
                $last=null;
                if($rule && isset($member_map[(int)$rule->id][$uid])){
                    $m=$member_map[(int)$rule->id][$uid];
                    $weight=max(.1,(float)$m->weight);
                    $last=$m->last_assigned_at;
                }
                $members[]=(object)array('user_id'=>$uid,'weight'=>$weight,'last_assigned_at'=>$last);
                $base=$matrix[$uid]??array('unprocessed'=>0,'active'=>0,'forms'=>array());
                $sim[$uid]=array(
                    'unprocessed'=>(int)$base['unprocessed']+$planned_total[$uid],
                    'active'=>(int)$base['active']+$planned_total[$uid],
                    'form'=>(int)($base['forms'][(int)$app->form_id]??0)+$planned_form[$uid][(int)$app->form_id],
                );
            }

            $choice=self::select_candidate($members,$app->form_id,$sim);
            if(!$choice)continue;
            $uid=$choice['user_id'];
            $assign[$app->id]=$uid;
            $counts[$uid]++;
            $planned_total[$uid]++;
            $planned_form[$uid][(int)$app->form_id]++;
        }

        return array('apps'=>$apps,'assignments'=>$assign,'counts'=>$counts);
    }
    public function ajax_transfer_preview(){if(!LV_Applications_Plugin::is_manager())wp_send_json_error(array('message'=>'Недостаточно прав.'),403);check_ajax_referer('lv_crm_transfer_preview','nonce');$source=absint($_POST['source_user']??0);$recipients=(array)($_POST['recipients']??array());$plan=self::redistribution_plan($source,$recipients);$rows=array();foreach($plan['counts'] as$uid=>$count)$rows[]=array('user_id'=>$uid,'name'=>LV_Applications_Plugin::assignee_name($uid),'count'=>$count);wp_send_json_success(array('total'=>count($plan['assignments']),'rows'=>$rows));}
    public function transfer_workload(){if(!LV_Applications_Plugin::is_manager())wp_die('Недостаточно прав.');check_admin_referer('lv_crm_transfer_workload');$source=absint($_POST['source_user']??0);$recipients=(array)($_POST['recipients']??array());$plan=self::redistribution_plan($source,$recipients);global$wpdb;$changed=0;foreach($plan['assignments'] as$appId=>$uid){$ok=$wpdb->update(LV_Applications_Plugin::table_name(),array('assignee_id'=>$uid),array('id'=>$appId,'assignee_id'=>$source,'processed'=>0),array('%d'),array('%d','%d','%d'));if($ok){$changed++;LV_Applications_Plugin::log_event($appId,'workload_transferred','Заявка передана от '.LV_Applications_Plugin::assignee_name($source).' к '.LV_Applications_Plugin::assignee_name($uid).' в рамках перераспределения.');}}wp_safe_redirect(add_query_arg(array('page'=>LV_Applications_Plugin::PAGE_SLUG,'lv_screen'=>'routing','lv_transferred'=>$changed),admin_url('admin.php')));exit;}

    // ----------------------------- Contacts -----------------------------
    public static function can_view_contacts(){return current_user_can('lv_view_applications');}
    public static function is_contact_deleted($contact){return $contact&&!empty($contact->deleted_at);}
    public static function can_access_contact($contact){
        if(!$contact||!self::can_view_contacts())return false;
        if(LV_Applications_Plugin::is_manager())return true;
        if(self::is_contact_deleted($contact))return false;
        $uid=get_current_user_id();
        if(absint($contact->created_by)===$uid||absint($contact->curator_user_id)===$uid)return true;
        global$wpdb;
        return(bool)$wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM '.self::application_contacts_table().' l INNER JOIN '.LV_Applications_Plugin::table_name().' a ON a.id=l.application_id WHERE l.contact_id=%d AND a.deleted_at IS NULL AND (a.assignee_id=0 OR a.assignee_id=%d) LIMIT 1',
            absint($contact->id),$uid
        ));
    }
    public static function can_edit_contact($contact){if(!$contact||!self::can_access_contact($contact)||self::is_contact_deleted($contact))return false;if(LV_Applications_Plugin::is_manager())return true;$uid=get_current_user_id();return absint($contact->created_by)===$uid||absint($contact->curator_user_id)===$uid;}
    public static function can_trash_contact($contact){return $contact&&!self::is_contact_deleted($contact)&&current_user_can('lv_trash_contacts')&&self::can_edit_contact($contact);}
    public static function get_contact($id){global$wpdb;return$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::contacts_table().' WHERE id=%d',absint($id)));}
    public static function contact_trash_days_left($contact){if(!$contact||empty($contact->deleted_at))return 0;$deadline=strtotime($contact->deleted_at.' +7 days');return max(0,(int)ceil(($deadline-current_time('timestamp'))/DAY_IN_SECONDS));}
    private static function contact_admin_action_url($action,$id,$return_to=''){$id=absint($id);$args=array('action'=>'lv_crm_'.$action.'_contact','contact_id'=>$id);if($return_to&&self::safe_admin_return_url($return_to))$args['return_to']=self::safe_admin_return_url($return_to);return wp_nonce_url(add_query_arg($args,admin_url('admin-post.php')),'lv_crm_'.$action.'_contact_'.$id);}
    public static function contact_emails($id){global$wpdb;return$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::contact_emails_table().' WHERE contact_id=%d ORDER BY is_primary DESC,id ASC',absint($id)));}
    public static function contact_phones($id){global$wpdb;return$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::contact_phones_table().' WHERE contact_id=%d ORDER BY is_primary DESC,id ASC',absint($id)));}
    public static function contact_fields($id){global$wpdb;return$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::contact_fields_table().' WHERE contact_id=%d ORDER BY id ASC',absint($id)));}
    public static function contact_logs($id){global$wpdb;return$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::contact_log_table().' WHERE contact_id=%d ORDER BY created_at DESC,id DESC LIMIT 100',absint($id)));}
    public static function contact_log($id,$type,$message,$meta=array()){
        global$wpdb;$id=absint($id);$uid=get_current_user_id();$now=current_time('mysql');
        $wpdb->insert(self::contact_log_table(),array('contact_id'=>$id,'event_type'=>sanitize_key($type),'message'=>sanitize_text_field($message),'user_id'=>$uid,'user_role'=>LV_Applications_Plugin::current_role_slug(),'meta_json'=>$meta?wp_json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,'created_at'=>$now),array('%d','%s','%s','%d','%s','%s','%s'));
        if($id)$wpdb->update(self::contacts_table(),array('last_activity_at'=>$now),array('id'=>$id),array('%s'),array('%d'));
    }
    public static function contact_display_meta($contact){
        if(!$contact)return array('phone'=>'','email'=>'');
        $phones=isset($contact->lv_phones)&&is_array($contact->lv_phones)?$contact->lv_phones:self::contact_phones($contact->id);
        $emails=isset($contact->lv_emails)&&is_array($contact->lv_emails)?$contact->lv_emails:self::contact_emails($contact->id);
        return array('phone'=>$phones?$phones[0]->value:'','email'=>$emails?$emails[0]->value:'');
    }
    public static function contact_notes($id,$limit=100){global$wpdb;return$wpdb->get_results($wpdb->prepare('SELECT * FROM '.LV_Applications_Plugin::notes_table_name().' WHERE entity_type=%s AND entity_id=%d ORDER BY created_at DESC,id DESC LIMIT %d','contact',absint($id),absint($limit)));}
    public static function contact_notes_html($notes){
        if(!$notes)return '<div class="lv-notes-empty">Внутренних комментариев пока нет.</div>';
        $html='<div class="lv-notes-list">';
        foreach($notes as$n){$actor=$n->user_id?LV_Applications_Plugin::assignee_name($n->user_id):'Система';$html.='<article class="lv-note"><header><strong>'.esc_html($actor).'</strong><span class="lv-role-chip">'.esc_html(LV_Applications_Plugin::role_label($n->user_role)).'</span><time>'.esc_html(mysql2date('d.m.Y H:i',$n->created_at)).'</time></header><p>'.self::render_mentions($n->message).'</p></article>';}
        return $html.'</div>';
    }
    public function ajax_add_contact_note(){
        if(!self::can_view_contacts())wp_send_json_error(array('message'=>'Недостаточно прав.'),403);
        check_ajax_referer('lv_crm_add_contact_note','nonce');
        $id=absint($_POST['contact_id']??0);$message=trim(sanitize_textarea_field(wp_unslash($_POST['message']??'')));$contact=self::get_contact($id);
        if(!$contact||!self::can_edit_contact($contact))wp_send_json_error(array('message'=>'Недостаточно прав для комментария к этому контакту.'),403);
        if($message==='')wp_send_json_error(array('message'=>'Введите текст комментария.'),400);
        global$wpdb;$uid=get_current_user_id();$role=LV_Applications_Plugin::current_role_slug();
        $ok=$wpdb->insert(LV_Applications_Plugin::notes_table_name(),array('entity_type'=>'contact','entity_id'=>$id,'application_id'=>0,'message'=>$message,'user_id'=>$uid,'user_role'=>$role,'created_at'=>current_time('mysql')),array('%s','%d','%d','%s','%d','%s','%s'));
        if(!$ok)wp_send_json_error(array('message'=>'Не удалось сохранить комментарий.'),500);
        self::contact_log($id,'note_added','Добавлен внутренний комментарий.');self::process_mentions($message,'contact',$id);
        wp_send_json_success(array('html'=>self::contact_notes_html(self::contact_notes($id))));
    }
    public static function normalize_phone($v){
        $digits=preg_replace('/\D+/','',(string)$v);
        // Russian domestic notation 8XXXXXXXXXX and international +7XXXXXXXXXX
        // are the same subscriber. Canonicalise them to 7XXXXXXXXXX so public
        // forms do not create duplicate contacts solely because of formatting.
        if(11===strlen($digits)&&'8'===$digits[0])$digits='7'.substr($digits,1);
        return$digits;
    }

    private static function application_identity( $app ) {
        $identity = array( 'emails' => array(), 'phones' => array(), 'name' => '', 'organization' => '' );
        if ( ! $app ) return $identity;
        $primary = LV_Applications_Plugin::primary_fields( $app );
        if ( ! empty( $primary['name']['value'] ) ) $identity['name'] = trim( LV_Applications_Plugin::value_to_string( $primary['name']['value'] ) );
        if ( ! empty( $primary['organization']['value'] ) ) $identity['organization'] = trim( LV_Applications_Plugin::value_to_string( $primary['organization']['value'] ) );
        foreach ( LV_Applications_Plugin::visible_fields( $app ) as $field ) {
            $value = trim( LV_Applications_Plugin::value_to_string( $field['value'] ?? '' ) );
            if ( $value === '' ) continue;
            $name = strtolower( (string) ( $field['name'] ?? '' ) );
            $label = strtolower( LV_Applications_Plugin::display_field_label( $app, $field ) );
            $hay = $name . ' ' . $label;
            if ( is_email( $value ) ) $identity['emails'][] = strtolower( $value );
            $phone = self::normalize_phone( $value );
            $phone_semantic = (bool) preg_match( '/phone|telephone|(^|[\s_-])tel($|[\s_-])|телефон|мобил|номер телефона/u', $hay );
            $phone_shape = strlen( $phone ) >= 7 && strlen( $phone ) <= 15 && ( $phone_semantic || preg_match( '/^[+\d][\d\s().-]{6,}$/u', $value ) );
            if ( $phone_shape ) $identity['phones'][] = $phone;
        }
        $identity['emails'] = array_values( array_unique( array_filter( $identity['emails'] ) ) );
        $identity['phones'] = array_values( array_unique( array_filter( $identity['phones'] ) ) );
        return $identity;
    }

    private static function phone_meta_keys() {
        static $keys = null;
        if ( null !== $keys ) return $keys;
        global $wpdb;
        $keys = array( 'phone', 'telephone', 'tel', 'mobile', 'mobile_phone', 'user_phone', 'billing_phone', 'shipping_phone', 'contact_phone' );
        $rows = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE '%phone%' OR meta_key LIKE '%mobile%' OR meta_key LIKE '%telephone%' OR meta_key LIKE '%tel%' LIMIT 150" );
        $keys = array_values( array_unique( array_merge( $keys, array_map( 'strval', (array) $rows ) ) ) );
        return (array) apply_filters( 'lv_crm_user_phone_meta_keys', $keys );
    }

    private static function user_phone_values( $user_id ) {
        $out = array();
        foreach ( self::phone_meta_keys() as $key ) {
            $values = get_user_meta( absint( $user_id ), $key, false );
            foreach ( (array) $values as $v ) {
                if ( is_scalar( $v ) ) {
                    $n = self::normalize_phone( $v );
                    if ( strlen( $n ) >= 7 ) $out[] = $n;
                }
            }
        }
        return array_values( array_unique( $out ) );
    }

    private static function user_phone_index() {
        $cache_key = 'crm.user_phone_index';
        if ( class_exists( 'LV_Request_Cache' ) && LV_Request_Cache::has( $cache_key ) ) {
            return LV_Request_Cache::get( $cache_key );
        }
        global $wpdb;
        $index = array();
        $keys = self::phone_meta_keys();
        if ( $keys ) {
            $ph = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id,meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ({$ph})", $keys ) );
            foreach ( (array) $rows as $row ) {
                $n = self::normalize_phone( $row->meta_value );
                if ( strlen( $n ) < 7 ) continue;
                $index[ $n ][] = absint( $row->user_id );
            }
        }
        foreach ( $index as $phone => $ids ) {
            $index[ $phone ] = array_values( array_unique( array_filter( $ids ) ) );
        }
        if ( class_exists( 'LV_Request_Cache' ) ) LV_Request_Cache::set( $cache_key, $index );
        return $index;
    }

    private static function find_wp_users_by_identity( $emails, $phones ) {
        $ids = array();
        foreach ( (array) $emails as $email ) {
            $u = get_user_by( 'email', $email );
            if ( $u ) $ids[] = absint( $u->ID );
        }
        $phone_index = self::user_phone_index();
        foreach ( array_values( array_unique( array_filter( array_map( array(__CLASS__,'normalize_phone'), (array) $phones ) ) ) ) as $phone ) {
            if ( isset( $phone_index[ $phone ] ) ) {
                $ids = array_merge( $ids, $phone_index[ $phone ] );
            }
        }
        return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
    }

    public static function maybe_link_wp_account_by_identity( $contact_id, $emails, $phones ) {
        global $wpdb;
        $contact_id=absint($contact_id);if(!$contact_id)return 0;
        $contact=self::get_contact($contact_id);if(!$contact)return 0;
        if(!empty($contact->linked_wp_user_id))return absint($contact->linked_wp_user_id);
        $user_ids=self::find_wp_users_by_identity((array)$emails,(array)$phones);
        if(1!==count($user_ids))return 0;
        $uid=absint($user_ids[0]);
        if(!$uid||self::linked_account_contact($uid,$contact_id))return 0;
        $now=current_time('mysql');
        $ok=$wpdb->update(self::contacts_table(),array('linked_wp_user_id'=>$uid,'updated_at'=>$now,'last_activity_at'=>$now),array('id'=>$contact_id),array('%d','%s','%s'),array('%d'));
        if(false!==$ok){self::contact_log($contact_id,'account_linked','Аккаунт WordPress автоматически связан по совпадению email/телефона.');return$uid;}
        return 0;
    }

    private static function search_wp_accounts( $q, $limit = 8 ) {
        global $wpdb;
        $q = trim( (string) $q );
        if ( $q === '' ) return array();
        $like = '%' . $wpdb->esc_like( $q ) . '%';
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT u.ID FROM {$wpdb->users} u LEFT JOIN {$wpdb->usermeta} fn ON fn.user_id=u.ID AND fn.meta_key='first_name' LEFT JOIN {$wpdb->usermeta} ln ON ln.user_id=u.ID AND ln.meta_key='last_name' WHERE u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s OR fn.meta_value LIKE %s OR ln.meta_value LIKE %s OR CONCAT_WS(' ',fn.meta_value,ln.meta_value) LIKE %s OR CONCAT_WS(' ',ln.meta_value,fn.meta_value) LIKE %s ORDER BY u.display_name LIMIT %d",
            $like, $like, $like, $like, $like, $like, $like, max( 12, absint( $limit ) * 2 )
        ) );
        $digits = self::normalize_phone( $q );
        if ( strlen( $digits ) >= 5 ) {
            foreach ( self::user_phone_index() as $phone => $user_ids ) {
                if ( false !== strpos( $phone, $digits ) ) {
                    $ids = array_merge( $ids, $user_ids );
                }
            }
        }
        $out = array();
        foreach ( array_values( array_unique( array_map( 'absint', $ids ) ) ) as $id ) {
            $u = get_userdata( $id );
            if ( ! $u ) continue;
            $out[] = $u;
            if ( count( $out ) >= $limit ) break;
        }
        return $out;
    }

    private static function contacts_by_identity( $emails, $phones ) {
        global $wpdb;
        $ids = array();

        $emails = array_values( array_unique( array_filter( array_map( static function( $email ) {
            return strtolower( trim( (string) $email ) );
        }, (array) $emails ) ) ) );
        if ( $emails ) {
            $ph = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
            $rows = $wpdb->get_col( $wpdb->prepare(
                'SELECT DISTINCT contact_id FROM '.self::contact_emails_table()." WHERE normalized IN ({$ph})",
                $emails
            ) );
            $ids = array_merge( $ids, (array) $rows );
        }

        $phones = array_values( array_unique( array_filter( array_map( array(__CLASS__,'normalize_phone'), (array) $phones ) ) ) );
        if ( $phones ) {
            $ph = implode( ',', array_fill( 0, count( $phones ), '%s' ) );
            $rows = $wpdb->get_col( $wpdb->prepare(
                'SELECT DISTINCT contact_id FROM '.self::contact_phones_table()." WHERE normalized IN ({$ph})",
                $phones
            ) );
            $ids = array_merge( $ids, (array) $rows );
        }

        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
        if ( ! $ids ) return array();
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM '.self::contacts_table()." WHERE deleted_at IS NULL AND id IN ({$ph}) ORDER BY display_name,id",
            $ids
        ) );
        return $rows ?: array();
    }

    public static function link_application_to_contact( $application_id, $contact_id, $actor_id = 0, $reason = 'manual' ) {
        global $wpdb;
        $application_id = absint( $application_id ); $contact_id = absint( $contact_id );
        if ( ! $application_id || ! $contact_id ) return false;
        $table = self::application_contacts_table();
        $exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM '.$table.' WHERE application_id=%d AND contact_id=%d', $application_id, $contact_id ) );
        if ( $exists ) return true;
        $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM '.$table.' WHERE application_id=%d', $application_id ) );
        $ok = $wpdb->insert( $table, array(
            'application_id' => $application_id, 'contact_id' => $contact_id,
            'relation_type' => $count ? 'related' : 'primary', 'is_primary' => $count ? 0 : 1,
            'created_by' => absint( $actor_id ), 'created_at' => current_time('mysql')
        ), array('%d','%d','%s','%d','%d','%s') );
        if ( $ok ) {
            if ( class_exists( 'LV_Request_Cache' ) ) LV_Request_Cache::forget( 'crm.application_contacts.' . $application_id );
            $contact = self::get_contact( $contact_id );
            $automatic = in_array( $reason, array( 'auto', 'form_contract' ), true );
            LV_Applications_Plugin::log_event( $application_id, 'contact_linked', ( $automatic ? 'Автоматически связан контакт «' : 'Связан контакт «' ) . ( $contact ? $contact->display_name : '#'.$contact_id ) . '».', $actor_id, array('contact_id'=>$contact_id,'reason'=>$reason) );
            self::contact_log( $contact_id, 'application_linked', ( $automatic ? 'Автоматически связана' : 'Связана' ) . ' заявка #'.$application_id.'.' );
        }
        return (bool) $ok;
    }

    private static function create_contact_for_wp_user( $user_id, $app = null, $actor_id = 0 ) {
        global $wpdb;
        $user = get_userdata( absint( $user_id ) );
        if ( ! $user ) return 0;
        $existing = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM '.self::contacts_table().' WHERE deleted_at IS NULL AND linked_wp_user_id=%d LIMIT 1', $user->ID ) ) );
        if ( $existing ) return $existing;
        $identity = $app ? self::application_identity( $app ) : array('emails'=>array(),'phones'=>array(),'name'=>'','organization'=>'');
        $name = trim( (string) ( $identity['name'] ?? '' ) );
        if ( $name === '' ) $name = self::user_full_name( $user );
        $org = trim( (string) ( $identity['organization'] ?? '' ) );
        $now = current_time('mysql');
        $wpdb->insert( self::contacts_table(), array(
            'contact_type'=>'person','display_name'=>$name ?: $user->display_name,'organization'=>$org,
            'created_by'=>absint($actor_id),'curator_user_id'=>0,'linked_wp_user_id'=>$user->ID,'created_at'=>$now,'updated_at'=>$now
        ), array('%s','%s','%s','%d','%d','%d','%s','%s') );
        $cid = absint( $wpdb->insert_id );
        if ( ! $cid ) return 0;
        $emails = (array) ( $identity['emails'] ?? array() );
        if ( $user->user_email ) $emails[] = strtolower($user->user_email);
        $emails = array_values(array_unique(array_filter($emails)));
        $first = true; foreach ( $emails as $email ) { if(!is_email($email))continue; $wpdb->insert(self::contact_emails_table(),array('contact_id'=>$cid,'value'=>$email,'normalized'=>strtolower($email),'is_primary'=>$first?1:0),array('%d','%s','%s','%d')); $first=false; }
        $phones = (array) ( $identity['phones'] ?? array() );
        if ( ! $phones ) $phones = self::user_phone_values( $user->ID );
        $first = true; foreach ( array_values(array_unique($phones)) as $phone ) { $n=self::normalize_phone($phone); if(!$n)continue; $wpdb->insert(self::contact_phones_table(),array('contact_id'=>$cid,'value'=>$phone,'normalized'=>$n,'is_primary'=>$first?1:0),array('%d','%s','%s','%d')); $first=false; }
        self::contact_log( $cid, 'created_from_account', 'Контакт автоматически создан на основе аккаунта WordPress «'.self::user_full_name($user).'».' );
        return $cid;
    }

    // Public forms are processed exclusively through LV_Form_Integration and
    // registered stable form contracts; heuristic field matching is not used.

    public static function find_contact_candidates($email,$phone,$exclude=0){global$wpdb;$ids=array();$email=strtolower(trim((string)$email));$phone=self::normalize_phone($phone);if($email){$rows=$wpdb->get_col($wpdb->prepare('SELECT contact_id FROM '.self::contact_emails_table().' WHERE normalized=%s',$email));$ids=array_merge($ids,$rows);}if($phone){$rows=$wpdb->get_col($wpdb->prepare('SELECT contact_id FROM '.self::contact_phones_table().' WHERE normalized=%s',$phone));$ids=array_merge($ids,$rows);}$ids=array_values(array_unique(array_filter(array_map('absint',$ids),function($id)use($exclude){return$id!==absint($exclude);})));$out=array();foreach($ids as$id){$c=self::get_contact($id);if($c&&!self::is_contact_deleted($c))$out[]=$c;}return$out;}
    public static function application_contacts($application_id){
        $application_id=absint($application_id);$key='crm.application_contacts.'.$application_id;
        if(class_exists('LV_Request_Cache')&&LV_Request_Cache::has($key))return LV_Request_Cache::get($key);
        global$wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT c.*,l.relation_type,l.is_primary FROM '.self::contacts_table().' c INNER JOIN '.self::application_contacts_table().' l ON l.contact_id=c.id WHERE c.deleted_at IS NULL AND l.application_id=%d ORDER BY l.is_primary DESC,c.display_name ASC',$application_id));
        $rows=class_exists('LV_Contact_Query')?LV_Contact_Query::hydrate_rows($rows):$rows;
        if(class_exists('LV_Request_Cache'))LV_Request_Cache::set($key,$rows);
        return$rows;
    }
    public static function linked_applications($contact_id){
        global$wpdb;$contact_id=absint($contact_id);$where='l.contact_id=%d';$args=array($contact_id);
        if(!LV_Applications_Plugin::is_manager()){$where.=' AND a.deleted_at IS NULL AND (a.assignee_id=0 OR a.assignee_id=%d)';$args[]=get_current_user_id();}
        return$wpdb->get_results($wpdb->prepare('SELECT a.*,l.relation_type,l.is_primary FROM '.LV_Applications_Plugin::table_name().' a INNER JOIN '.self::application_contacts_table()." l ON l.application_id=a.id WHERE {$where} ORDER BY a.submitted_at DESC,a.id DESC",$args));
    }
    public static function contact_url($id,$return_to=''){$args=array('page'=>self::CONTACTS_SLUG,'action'=>'view','contact_id'=>absint($id));$safe=self::safe_admin_return_url($return_to);if($safe)$args['return_to']=$safe;return add_query_arg($args,admin_url('admin.php'));}
    private static function safe_admin_return_url($url){$url=esc_url_raw((string)$url);if(!$url)return'';$admin=admin_url();return 0===strpos($url,$admin)?$url:'';}
    private static function contact_list_url($filters=array(),$page=1,$per_page=0){$args=LV_Contact_Query::query_args_from_filters($filters);$args['page']=self::CONTACTS_SLUG;if($page>1)$args['paged']=absint($page);if($per_page)$args['per_page']=absint($per_page);return add_query_arg($args,admin_url('admin.php'));}
    private static function contact_sort_url($filters,$orderby,$page=1,$per_page=0){$f=LV_Contact_Query::sanitize_filters($filters);$current=$f['orderby'];$direction=($current===$orderby&&'ASC'===$f['order'])?'desc':'asc';$f['orderby']=$orderby;$f['order']='asc'===$direction?'ASC':'DESC';return self::contact_list_url($f,1,$per_page);}
    private static function contact_sort_label($label,$filters,$orderby){$f=LV_Contact_Query::sanitize_filters($filters);$active=$f['orderby']===$orderby;$arrow=$active?('ASC'===$f['order']?' ↑':' ↓'):'';return esc_html($label.$arrow);}

    private static function application_match_suggestions( $app ) {
        $out = array();
        if ( ! $app ) return $out;
        $identity = self::application_identity( $app );
        foreach ( self::contacts_by_identity( $identity['emails'], $identity['phones'] ) as $c ) {
            if ( ! self::can_access_contact( $c ) ) continue;
            $m = self::contact_display_meta( $c );
            $out[] = array( 'type'=>'contact', 'id'=>$c->id, 'name'=>$c->display_name, 'meta'=>$m['phone'] ?: ( $m['email'] ?: 'Совпадение с контактом CRM' ) );
        }
        if ( LV_Applications_Plugin::is_manager() ) foreach ( self::find_wp_users_by_identity( $identity['emails'], $identity['phones'] ) as $uid ) {
            $u = get_userdata( $uid ); if ( ! $u ) continue;
            global $wpdb;
            $cid = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM '.self::contacts_table().' WHERE deleted_at IS NULL AND linked_wp_user_id=%d LIMIT 1', $uid ) ) );
            if ( $cid ) continue;
            $phone = self::user_phone_values( $uid );
            $out[] = array( 'type'=>'account', 'id'=>$uid, 'name'=>self::user_full_name($u), 'meta'=>'Аккаунт WordPress · '.( $u->user_email ?: ( $phone ? $phone[0] : $u->user_login ) ) );
        }
        return $out;
    }

    public static function application_contacts_panel($app,$compact=false){
        $contacts=self::application_contacts($app->id); $suggestions = $contacts ? array() : self::application_match_suggestions($app); ob_start();
        echo'<div class="lv-contact-link-panel '.($compact?'is-compact':'').'">';
        echo'<div class="lv-contact-link-head"><div><span class="dashicons dashicons-id"></span><div><strong>Контакты</strong><small>Связь заявки с человеком или организацией</small></div></div><span>'.esc_html(count($contacts)).'</span></div>';
        if($contacts){
            echo'<div class="lv-linked-contact-list">';
            foreach($contacts as$c){$m=self::contact_display_meta($c);$account=$c->linked_wp_user_id?get_userdata($c->linked_wp_user_id):false;
                echo'<div class="lv-linked-contact"><span class="lv-contact-avatar">'.esc_html(function_exists('mb_substr')?mb_substr($c->display_name,0,1):substr($c->display_name,0,1)).'</span><div class="lv-linked-contact-copy"><a href="'.esc_url(self::contact_url($c->id)).'"><strong>'.esc_html($c->display_name).'</strong></a><small>'.esc_html($m['phone'] ? $m['phone'] : ($m['email'] ? $m['email'] : ($c->organization ? $c->organization : 'Контакт CRM'))).'</small><div class="lv-linked-contact-meta"><span>Куратор: '.esc_html($c->curator_user_id?LV_Applications_Plugin::assignee_name($c->curator_user_id):'не назначен').'</span>'.($account?'<span class="lv-account-badge"><span class="dashicons dashicons-admin-users"></span>'.esc_html(self::user_full_name($account)).'</span>':'').'</div></div>';
                if(LV_Applications_Plugin::can_edit_application($app))echo'<button type="button" class="lv-unlink-contact" data-application="'.esc_attr($app->id).'" data-contact="'.esc_attr($c->id).'" title="Отвязать" aria-label="Отвязать контакт от заявки"><span class="dashicons dashicons-no-alt"></span></button>';echo'</div>';
            }
            echo'</div>';
        } else {
            echo'<div class="lv-contact-empty"><span class="dashicons dashicons-admin-users"></span><div><strong>Контакт пока не связан</strong><span>CRM может найти контакт или аккаунт по имени, email и телефону.</span></div></div>';
            if($suggestions){
                echo'<div class="lv-contact-suggestions"><div class="lv-contact-suggestion-title"><span class="dashicons dashicons-search"></span><strong>Найдены совпадения по данным заявки</strong></div>';
                foreach($suggestions as$item){
                    $class=$item['type']==='account'?'lv-link-account-suggestion':'lv-contact-search-item';
                    $data=$item['type']==='account'?'data-user="'.esc_attr($item['id']).'"':'data-contact="'.esc_attr($item['id']).'"';
                    echo'<button type="button" class="lv-contact-suggestion '.$class.'" '.$data.' data-application="'.esc_attr($app->id).'"><span class="lv-contact-suggestion-icon dashicons '.($item['type']==='account'?'dashicons-wordpress-alt':'dashicons-id').'"></span><span><strong>'.esc_html($item['name']).'</strong><small>'.esc_html($item['meta']).'</small></span><em>'.($item['type']==='account'?'Создать и связать':'Связать').'</em></button>';
                }
                echo'</div>';
            }
        }
        if(!$app->deleted_at&&LV_Applications_Plugin::can_edit_application($app)){
            echo'<div class="lv-contact-link-actions"><div class="lv-contact-search-wrap"><span class="dashicons dashicons-search lv-contact-search-icon"></span><input type="search" class="lv-contact-search" data-application="'.esc_attr($app->id).'" placeholder="Имя, email, телефон или аккаунт WordPress"><div class="lv-contact-search-results" hidden></div></div><a class="button lv-create-contact-button" href="'.esc_url(add_query_arg(array('page'=>self::CONTACTS_SLUG,'action'=>'edit','from_application'=>$app->id),admin_url('admin.php'))).'"><span class="dashicons dashicons-plus-alt2"></span>Новый контакт</a></div>';
        }
        echo'</div>';return ob_get_clean();
    }

    public function ajax_contact_search(){
        if(!self::can_view_contacts())wp_send_json_error(array('message'=>'Недостаточно прав.'),403);check_ajax_referer('lv_crm_contact_search','nonce');
        $q=sanitize_text_field(wp_unslash($_POST['q']??''));if((function_exists('mb_strlen')?mb_strlen($q,'UTF-8'):strlen($q))<2)wp_send_json_success(array('items'=>array()));
        global$wpdb;$like='%'.$wpdb->esc_like($q).'%';$digits=self::normalize_phone($q);
        $sql='SELECT DISTINCT c.* FROM '.self::contacts_table().' c LEFT JOIN '.self::contact_emails_table().' e ON e.contact_id=c.id LEFT JOIN '.self::contact_phones_table().' p ON p.contact_id=c.id WHERE c.deleted_at IS NULL AND (c.display_name LIKE %s OR c.organization LIKE %s OR e.value LIKE %s'.($digits?' OR p.normalized LIKE %s':'').') ORDER BY c.display_name LIMIT 10';
        $params=array($like,$like,$like);if($digits)$params[]='%'.$wpdb->esc_like($digits).'%';$rows=$wpdb->get_results($wpdb->prepare($sql,$params));$rows=LV_Contact_Query::hydrate_rows($rows);if(!LV_Applications_Plugin::is_manager())$rows=array_values(array_filter($rows,array(__CLASS__,'can_access_contact')));$items=array();$linkedUsers=array();
        foreach($rows as$c){$phone=!empty($c->lv_phones[0]->value)?$c->lv_phones[0]->value:'';$email=!empty($c->lv_emails[0]->value)?$c->lv_emails[0]->value:'';$items[]=array('type'=>'contact','id'=>$c->id,'name'=>$c->display_name,'meta'=>($phone ? $phone : ($email ? $email : ($c->organization?:'Контакт CRM'))),'badge'=>'Контакт CRM','url'=>self::contact_url($c->id));if($c->linked_wp_user_id)$linkedUsers[]=absint($c->linked_wp_user_id);}
        if(LV_Applications_Plugin::is_manager())foreach(self::search_wp_accounts($q,8) as$u){if(in_array(absint($u->ID),$linkedUsers,true))continue;$existingCid=absint($wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::contacts_table().' WHERE deleted_at IS NULL AND linked_wp_user_id=%d LIMIT 1',$u->ID)));if($existingCid){$c=self::get_contact($existingCid);if($c){$hydrated=LV_Contact_Query::hydrate_rows(array($c));$c=$hydrated?$hydrated[0]:$c;$phone=!empty($c->lv_phones[0]->value)?$c->lv_phones[0]->value:'';$email=!empty($c->lv_emails[0]->value)?$c->lv_emails[0]->value:'';$items[]=array('type'=>'contact','id'=>$c->id,'name'=>$c->display_name,'meta'=>$phone?:($email?:'Связан с аккаунтом WordPress'),'badge'=>'Контакт CRM');}continue;}$phones=self::user_phone_values($u->ID);$items[]=array('type'=>'account','id'=>$u->ID,'name'=>self::user_full_name($u),'meta'=>$u->user_email?:($phones?$phones[0]:('@'.$u->user_login)),'badge'=>'Аккаунт WordPress');}
        $seen=array();$unique=array();foreach($items as$item){$k=$item['type'].'-'.$item['id'];if(isset($seen[$k]))continue;$seen[$k]=1;$unique[]=$item;if(count($unique)>=16)break;}
        wp_send_json_success(array('items'=>$unique));
    }

    public function ajax_link_contact(){
        check_ajax_referer('lv_crm_link_contact','nonce');$aid=absint($_POST['application_id']??0);$cid=absint($_POST['contact_id']??0);$app=LV_Applications_Plugin::get_application($aid);$contact=self::get_contact($cid);
        if(!$app||!$contact||!LV_Applications_Plugin::can_edit_application($app)||!self::can_access_contact($contact))wp_send_json_error(array('message'=>'Недостаточно прав.'),403);
        self::link_application_to_contact($aid,$cid,get_current_user_id(),'manual');
        wp_send_json_success(array('html'=>self::application_contacts_panel(LV_Applications_Plugin::get_application($aid),true)));
    }

    public function ajax_link_account(){
        check_ajax_referer('lv_crm_link_account','nonce');$aid=absint($_POST['application_id']??0);$uid=absint($_POST['user_id']??0);$app=LV_Applications_Plugin::get_application($aid);$user=get_userdata($uid);
        if(!$app||!$user||!LV_Applications_Plugin::can_edit_application($app))wp_send_json_error(array('message'=>'Недостаточно прав.'),403);
        global $wpdb;$cid=absint($wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::contacts_table().' WHERE deleted_at IS NULL AND linked_wp_user_id=%d LIMIT 1',$uid)));
        if(!$cid)$cid=self::create_contact_for_wp_user($uid,$app,get_current_user_id());
        if(!$cid)wp_send_json_error(array('message'=>'Не удалось создать контакт для аккаунта.'),500);
        self::link_application_to_contact($aid,$cid,get_current_user_id(),'manual_account');
        wp_send_json_success(array('html'=>self::application_contacts_panel(LV_Applications_Plugin::get_application($aid),true)));
    }
    public function ajax_unlink_contact(){
        check_ajax_referer('lv_crm_link_contact','nonce');$aid=absint($_POST['application_id']??0);$cid=absint($_POST['contact_id']??0);$app=LV_Applications_Plugin::get_application($aid);
        if(!$app||!LV_Applications_Plugin::can_edit_application($app))wp_send_json_error(array('message'=>'Недостаточно прав.'),403);
        global$wpdb;$table=self::application_contacts_table();$wasPrimary=(int)$wpdb->get_var($wpdb->prepare('SELECT is_primary FROM '.$table.' WHERE application_id=%d AND contact_id=%d',$aid,$cid));$wpdb->delete($table,array('application_id'=>$aid,'contact_id'=>$cid),array('%d','%d'));
        if($wasPrimary){$next=(int)$wpdb->get_var($wpdb->prepare('SELECT contact_id FROM '.$table.' WHERE application_id=%d ORDER BY created_at ASC LIMIT 1',$aid));if($next)$wpdb->update($table,array('is_primary'=>1),array('application_id'=>$aid,'contact_id'=>$next),array('%d'),array('%d','%d'));}
        LV_Applications_Plugin::log_event($aid,'contact_unlinked','Контакт #'.$cid.' отвязан от заявки.');self::contact_log($cid,'application_unlinked','Заявка #'.$aid.' отвязана.');wp_send_json_success(array('html'=>self::application_contacts_panel(LV_Applications_Plugin::get_application($aid),true)));
    }

    public function render_contacts_page(){if(!self::can_view_contacts())wp_die('Недостаточно прав.');$action=sanitize_key(wp_unslash($_GET['action']??''));if(in_array($action,array('view','edit'),true)||isset($_GET['from_application'])){$this->render_contact_editor();return;}$this->render_contacts_list();}
    private function render_contacts_list(){
        global $wpdb;
        $segment_id=absint($_GET['segment']??0);$segment=$segment_id?LV_Contact_Segments::get($segment_id):null;
        if($segment&&!LV_Contact_Segments::can_use($segment)){$segment=null;$segment_id=0;}
        $base=($segment&&empty($_GET['filters_submitted']))?LV_Contact_Segments::filters($segment):array();
        $request=array_merge($base,(array)$_GET);$request['segment_id']=$segment_id;
        $filters=LV_Contact_Query::sanitize_filters($request);$view=$filters['view'];$trash_view='trash'===$view;$query=new LV_Contact_Query($filters);
        $page=max(1,absint($_GET['paged']??1));$allowed_per=array(25,50,100);$requested_per=absint($_GET['per_page']??0);if(in_array($requested_per,$allowed_per,true)){update_user_meta(get_current_user_id(),'lv_crm_contacts_per_page',$requested_per);$per=$requested_per;}else{$saved_per=absint(get_user_meta(get_current_user_id(),'lv_crm_contacts_per_page',true));$per=in_array($saved_per,$allowed_per,true)?$saved_per:25;}$count=$query->count();$max_page=max(1,(int)ceil($count/$per));if($page>$max_page)$page=$max_page;$rows=$query->get($per,($page-1)*$per);
        $all_count=(new LV_Contact_Query(array('view'=>'active')))->count();
        $trash_count=LV_Applications_Plugin::is_manager()?(new LV_Contact_Query(array('view'=>'trash')))->count():0;
        $mine_count=(new LV_Contact_Query(array('view'=>'active','curator'=>'mine')))->count();
        $uncurated=(new LV_Contact_Query(array('view'=>'active','curator'=>'none')))->count();
        $segments=LV_Contact_Segments::available();$tags=self::get_tags('contact');$segment_name=$segment?$segment->name:'';
        $advancedActive=$filters['email_state']||$filters['phone_state']||$filters['consent_status']||$filters['created_from']||$filters['created_to']||$filters['updated_from']||$filters['updated_to']||$filters['has_applications']||''!==(string)$filters['applications_min']||''!==(string)$filters['applications_max'];
        $chips=array();if($filters['search'])$chips[]='Поиск: '.$filters['search'];foreach($filters['types'] as$tp)$chips[]=$tp==='organization'?'Организации':'Люди';if($filters['curator']==='mine')$chips[]='Я куратор';elseif($filters['curator']==='none')$chips[]='Без куратора';elseif(ctype_digit((string)$filters['curator']))$chips[]='Куратор: '.LV_Applications_Plugin::assignee_name($filters['curator']);$tagNames=array();foreach($tags as$t)$tagNames[(int)$t->id]=$t->name;foreach($filters['tags_include'] as$tid)if(isset($tagNames[$tid]))$chips[]='Метка: '.$tagNames[$tid];foreach($filters['tags_exclude'] as$tid)if(isset($tagNames[$tid]))$chips[]='Без метки: '.$tagNames[$tid];$emailLabels=array('has'=>'есть','none'=>'нет','valid'=>'корректный','multiple'=>'несколько');$phoneLabels=array('has'=>'есть','none'=>'нет');if($filters['email_state'])$chips[]='Email: '.($emailLabels[$filters['email_state']]??$filters['email_state']);if($filters['phone_state'])$chips[]='Телефон: '.($phoneLabels[$filters['phone_state']]??$filters['phone_state']);if($filters['consent_status'])$chips[]='Согласие: '.(LV_Consent_Service::status_options()[$filters['consent_status']]??$filters['consent_status']);
        $context_url=self::contact_list_url($filters,$page,$per);

        echo'<div class="wrap lv-applications-wrap lv-contacts-wrap">';
        echo'<header class="lv-page-head"><div class="lv-head-copy"><span class="lv-kicker">CRM фонда · Контактная база</span><div class="lv-title-row"><h1>'.($trash_view?'Корзина контактов':'Контакты').'</h1><span class="lv-live-badge"><i></i> '.esc_html(number_format_i18n($count)).' в выборке</span></div><p>'.($trash_view?'Удалённые контакты хранятся 7 дней. Их можно восстановить или удалить навсегда.':'Сегменты, согласия, история обращений, кураторы и безопасный экспорт контактов.').'</p></div><div class="lv-head-actions">';
        if(!$trash_view&&current_user_can('lv_export_contacts'))echo'<button type="button" class="button lv-open-contact-export"><span class="dashicons dashicons-download"></span>Экспорт</button>';
        if(!$trash_view)echo'<a class="button button-primary" href="'.esc_url(add_query_arg(array('page'=>self::CONTACTS_SLUG,'action'=>'edit','return_to'=>$context_url),admin_url('admin.php'))).'"><span class="dashicons dashicons-plus-alt2"></span>Новый контакт</a>';echo'</div></header>';
        LV_Applications_Plugin::instance()->render_workspace_nav(array(),'contacts');
        if(!empty($_GET['saved']))echo'<div class="lv-alert lv-alert--success"><span class="dashicons dashicons-yes-alt"></span><div><strong>Контакт сохранён</strong><p>Изменения применены.</p></div></div>';
        $this->render_contact_messages();
        echo'<div class="lv-contact-view-tabs"><a class="'.(!$trash_view?'is-active':'').'" href="'.esc_url(admin_url('admin.php?page='.self::CONTACTS_SLUG)).'">Активные <span>'.esc_html(number_format_i18n($all_count)).'</span></a><a class="'.($trash_view?'is-active':'').'" href="'.esc_url(add_query_arg(array('page'=>self::CONTACTS_SLUG,'view'=>'trash'),admin_url('admin.php'))).'">Корзина <span>'.esc_html(number_format_i18n($trash_count)).'</span></a></div>';

        echo'<div class="lv-contact-stats"><div><span class="dashicons dashicons-groups"></span><strong>'.esc_html(number_format_i18n($trash_view?$trash_count:$all_count)).'</strong><small>'.($trash_view?'В корзине':'Всего контактов').'</small></div><div><span class="dashicons dashicons-businessperson"></span><strong>'.esc_html(number_format_i18n($mine_count)).'</strong><small>Я куратор</small></div><div><span class="dashicons dashicons-admin-users"></span><strong>'.esc_html(number_format_i18n($uncurated)).'</strong><small>Без куратора</small></div><div><span class="dashicons dashicons-filter"></span><strong>'.esc_html(number_format_i18n($count)).'</strong><small>Текущая выборка</small></div></div>';

        echo'<section class="lv-panel lv-contact-filter lv-contact-filter-v10"><form method="get" class="lv-contact-filter-form"><input type="hidden" name="page" value="'.esc_attr(self::CONTACTS_SLUG).'"><input type="hidden" name="filters_submitted" value="1"><input type="hidden" name="view" value="'.esc_attr($view).'"><input type="hidden" name="orderby" value="'.esc_attr($filters['orderby']).'"><input type="hidden" name="order" value="'.esc_attr(strtolower($filters['order'])).'"><input type="hidden" name="per_page" value="'.esc_attr($per).'">';
        echo'<div class="lv-filter-topline"><div class="lv-control lv-control--search"><label>Поиск</label><div class="lv-search-input"><span class="dashicons dashicons-search"></span><input type="search" name="s" value="'.esc_attr($filters['search']).'" placeholder="Имя, организация, телефон, email, ID или дополнительное поле"></div></div>';
        echo'<div class="lv-control"><label>Сегмент</label><select name="segment"><option value="0">Без сохранённого сегмента</option>';foreach($segments as$sg)echo'<option value="'.esc_attr($sg->id).'" '.selected($segment_id,$sg->id,false).'>'.esc_html($sg->name).($sg->visibility==='shared'?' · общий':'').'</option>';echo'</select></div>';
        echo'<div class="lv-control"><label>Куратор</label><select name="curator"><option value="">Все</option><option value="mine" '.selected($filters['curator'],'mine',false).'>Я куратор</option><option value="none" '.selected($filters['curator'],'none',false).'>Без куратора</option>';if(LV_Applications_Plugin::is_manager())foreach(LV_Applications_Plugin::eligible_assignees() as$u)echo'<option value="'.esc_attr($u->ID).'" '.selected($filters['curator'],(string)$u->ID,false).'>'.esc_html($u->display_name).'</option>';echo'</select></div>';
        echo'<div class="lv-filter-buttons"><button class="button button-primary"><span class="dashicons dashicons-filter"></span>Применить</button><a class="button" href="'.esc_url(add_query_arg(array('page'=>self::CONTACTS_SLUG,'view'=>$view),admin_url('admin.php'))).'">Сбросить</a></div></div>';

        echo'<div class="lv-filter-row lv-filter-row-tags"><div class="lv-control"><label>Тип контакта</label><div class="lv-inline-checks"><label><input type="checkbox" name="type[]" value="person" '.checked(in_array('person',$filters['types'],true),true,false).'>Человек</label><label><input type="checkbox" name="type[]" value="organization" '.checked(in_array('organization',$filters['types'],true),true,false).'>Организация</label></div></div>';
        echo'<div class="lv-control is-grow"><label>Включить метки</label><select name="tags[]" multiple size="'.esc_attr(min(4,max(2,count($tags)))).'">';foreach($tags as$t)echo'<option value="'.esc_attr($t->id).'" '.selected(in_array((int)$t->id,$filters['tags_include'],true),true,false).'>'.esc_html($t->name).'</option>';echo'</select><small>Ctrl/Cmd — несколько значений</small></div>';
        echo'<div class="lv-control"><label>Логика меток</label><select name="tags_mode"><option value="any" '.selected($filters['tags_mode'],'any',false).'>Любая из выбранных</option><option value="all" '.selected($filters['tags_mode'],'all',false).'>Все выбранные</option></select></div>';
        echo'<div class="lv-control is-grow"><label>Исключить метки</label><select name="exclude_tags[]" multiple size="'.esc_attr(min(4,max(2,count($tags)))).'">';foreach($tags as$t)echo'<option value="'.esc_attr($t->id).'" '.selected(in_array((int)$t->id,$filters['tags_exclude'],true),true,false).'>'.esc_html($t->name).'</option>';echo'</select></div></div>';

        echo'<details class="lv-advanced-filters" '.($advancedActive?'open':'').'><summary><span class="dashicons dashicons-admin-settings"></span>Ещё фильтры</summary><div class="lv-advanced-filter-grid">';
        echo'<div class="lv-control"><label>Email</label><select name="email_state"><option value="">Любой</option><option value="has" '.selected($filters['email_state'],'has',false).'>Есть email</option><option value="none" '.selected($filters['email_state'],'none',false).'>Нет email</option><option value="valid" '.selected($filters['email_state'],'valid',false).'>Есть корректный email</option><option value="multiple" '.selected($filters['email_state'],'multiple',false).'>Несколько email</option></select></div>';
        echo'<div class="lv-control"><label>Телефон</label><select name="phone_state"><option value="">Любой</option><option value="has" '.selected($filters['phone_state'],'has',false).'>Есть телефон</option><option value="none" '.selected($filters['phone_state'],'none',false).'>Нет телефона</option></select></div>';
        echo'<div class="lv-control"><label>Согласие на рассылку</label><select name="consent_status"><option value="">Любое</option><option value="granted" '.selected($filters['consent_status'],'granted',false).'>Получено</option><option value="unknown" '.selected($filters['consent_status'],'unknown',false).'>Неизвестно</option><option value="revoked" '.selected($filters['consent_status'],'revoked',false).'>Отозвано</option></select></div>';
        echo'<div class="lv-control"><label>Связанные заявки</label><select name="has_applications"><option value="">Любое количество</option><option value="yes" '.selected($filters['has_applications'],'yes',false).'>Есть</option><option value="no" '.selected($filters['has_applications'],'no',false).'>Нет</option></select></div>';
        echo'<div class="lv-control"><label>Создан с</label><input type="date" name="created_from" value="'.esc_attr($filters['created_from']).'"></div><div class="lv-control"><label>Создан по</label><input type="date" name="created_to" value="'.esc_attr($filters['created_to']).'"></div><div class="lv-control"><label>Обновлён с</label><input type="date" name="updated_from" value="'.esc_attr($filters['updated_from']).'"></div><div class="lv-control"><label>Обновлён по</label><input type="date" name="updated_to" value="'.esc_attr($filters['updated_to']).'"></div>';
        echo'<div class="lv-control"><label>Заявок от</label><input type="number" min="0" name="applications_min" value="'.esc_attr($filters['applications_min']).'"></div><div class="lv-control"><label>Заявок до</label><input type="number" min="0" name="applications_max" value="'.esc_attr($filters['applications_max']).'"></div>';
        echo'</div></details></form>';
        if($chips){echo'<div class="lv-active-filter-chips"><span>Активные фильтры</span>';foreach($chips as$chip)echo'<b>'.esc_html($chip).'</b>';echo'</div>';}

        if($query->has_active_filters()||$segment){echo'<div class="lv-segment-bar"><div><span class="dashicons dashicons-filter"></span><div><strong>'.($segment?'Сегмент: '.esc_html($segment->name):'Фильтр не сохранён').'</strong><small>В выборке '.esc_html(number_format_i18n($count)).' контактов</small></div></div><details><summary class="button">Сохранить сегмент</summary><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="lv-segment-save-form"><input type="hidden" name="action" value="lv_crm_save_segment"><input type="hidden" name="filters" value="'.esc_attr(wp_json_encode($filters,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'">';wp_nonce_field('lv_crm_save_segment');if($segment&&LV_Contact_Segments::can_edit($segment))echo'<input type="hidden" name="segment_id" value="'.esc_attr($segment->id).'">';echo'<input name="segment_name" required placeholder="Название сегмента" value="'.esc_attr($segment?$segment->name:'').'">';if(current_user_can('lv_manage_contact_segments'))echo'<select name="visibility"><option value="private" '.selected($segment?$segment->visibility:'private','private',false).'>Личный</option><option value="shared" '.selected($segment?$segment->visibility:'','shared',false).'>Общий</option></select>';else echo'<input type="hidden" name="visibility" value="private">';echo'<button class="button button-primary">'.($segment?'Обновить':'Сохранить').'</button></form></details>';if($segment&&LV_Contact_Segments::can_edit($segment)){echo'<a class="button-link-delete" href="'.esc_url(wp_nonce_url(add_query_arg(array('action'=>'lv_crm_delete_segment','segment_id'=>$segment->id),admin_url('admin-post.php')),'lv_crm_delete_segment_'.$segment->id)).'" onclick="return confirm(\'Удалить сохранённый сегмент? Контакты не удаляются.\')">Удалить сегмент</a>';}echo'</div>';}
        echo'</section>';

        echo'<section class="lv-panel lv-contacts-table lv-contacts-table-v10">';
        $per_base=self::contact_list_url($filters,1,0);
        echo'<div class="lv-table-tools"><div><strong>'.esc_html(number_format_i18n($count)).' контактов</strong><span> · показано '.esc_html(count($rows)).'</span></div><label class="lv-per-page-label">На странице <select class="lv-contact-per-page" data-base-url="'.esc_url($per_base).'">';foreach(array(25,50,100) as$n)echo'<option value="'.esc_attr($n).'" '.selected($per,$n,false).'>'.esc_html($n).'</option>';echo'</select></label></div>';

        echo'<div class="lv-contact-selection-bar lv-selection-toolbar" hidden data-total="'.esc_attr($count).'"><strong>Выбрано: <span class="lv-selected-count">0</span></strong><button type="button" class="button-link lv-select-all-filtered-contacts" hidden>Выбрать все '.esc_html(number_format_i18n($count)).' в выборке</button>';
        if(!$trash_view&&current_user_can('lv_export_contacts'))echo'<button type="button" class="button lv-open-contact-export" data-export-selected="1"><span class="dashicons dashicons-download"></span>Экспортировать</button>';
        echo'<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="lv-contact-bulk-form"><input type="hidden" name="action" value="lv_crm_bulk_contacts"><input type="hidden" name="selected_ids" value=""><input type="hidden" name="selection_scope" value="page"><input type="hidden" name="view" value="'.esc_attr($view).'"><input type="hidden" name="filters_json" value="'.esc_attr(wp_json_encode($filters,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'"><input type="hidden" name="return_to" value="'.esc_attr($context_url).'">';wp_nonce_field('lv_crm_bulk_contacts');
        echo'<select name="bulk_action" class="lv-contact-bulk-action"><option value="">Действие с выбранными</option>';
        if(!$trash_view){
            if(LV_Applications_Plugin::is_manager()){
                echo'<optgroup label="Куратор"><option value="curator_0">Снять куратора</option><option value="curator_'.esc_attr(get_current_user_id()).'">Назначить меня куратором</option>';
                foreach(LV_Applications_Plugin::eligible_assignees() as$u)echo'<option value="curator_'.esc_attr($u->ID).'">Куратор: '.esc_html($u->display_name).'</option>';echo'</optgroup>';
            }
            if($tags){echo'<optgroup label="Метки">';foreach($tags as$t){echo'<option value="tag_add_'.esc_attr($t->id).'">+ '.esc_html($t->name).'</option><option value="tag_remove_'.esc_attr($t->id).'">− '.esc_html($t->name).'</option>';}echo'</optgroup>';}
            if(current_user_can('lv_trash_contacts'))echo'<option value="trash">Переместить в корзину</option>';
        }else{
            if(current_user_can('lv_restore_contacts'))echo'<option value="restore">Восстановить</option>';
            if(current_user_can('lv_purge_contacts'))echo'<option value="purge">Удалить навсегда</option>';
        }
        echo'</select><button class="button button-primary" type="submit">Применить</button></form><button type="button" class="button-link lv-clear-contact-selection">Снять выделение</button></div>';
        echo'<div class="lv-contact-grid-head"><span class="lv-contact-check"><input type="checkbox" class="lv-contact-select-all" aria-label="Выбрать все контакты на странице"></span><a class="lv-sort-link" href="'.esc_url(self::contact_sort_url($filters,'name',$page,$per)).'">'.self::contact_sort_label('Контакт',$filters,'name').'</a><span>Связь</span><a class="lv-sort-link" href="'.esc_url(self::contact_sort_url($filters,'curator',$page,$per)).'">'.self::contact_sort_label('Куратор',$filters,'curator').'</a><a class="lv-sort-link" href="'.esc_url(self::contact_sort_url($filters,'applications',$page,$per)).'">'.self::contact_sort_label('История',$filters,'applications').'</a><span>Согласие</span><span></span></div>';
        if(!$rows)echo'<div class="lv-empty-state"><span class="dashicons dashicons-admin-users"></span><h2>Контакты не найдены</h2><p>Измените фильтры или создайте новый контакт.</p></div>';
        foreach($rows as$c){$emails=$c->lv_emails??array();$phones=$c->lv_phones??array();$m=array('phone'=>$phones?$phones[0]->value:'','email'=>$emails?$emails[0]->value:'');$initial=function_exists('mb_substr')?mb_substr($c->display_name,0,1):substr($c->display_name,0,1);$cs=$c->lv_consent_summary??array();if(!empty($cs['granted'])&&!empty($cs['revoked'])){$consent='mixed';$consentLabel='Смешанное';}else{$consent=!empty($cs['granted'])?'granted':(!empty($cs['revoked'])?'revoked':'unknown');$consentLabel=LV_Consent_Service::status_options()[$consent];}
            echo'<div class="lv-contact-row'.(absint($_GET['highlight']??0)===(int)$c->id?' is-highlighted':'').'"><div class="lv-contact-check"><input type="checkbox" class="lv-contact-select" value="'.esc_attr($c->id).'" aria-label="Выбрать '.esc_attr($c->display_name).'"></div><a class="lv-contact-main" href="'.esc_url(self::contact_url($c->id,$context_url)).'"><span class="lv-contact-avatar">'.esc_html($initial).'</span><div><strong>'.esc_html($c->display_name).'</strong><small>'.esc_html($c->organization?:($c->contact_type==='organization'?'Организация':'Физическое лицо')).'</small>'.self::tags_html($c->lv_tags??array()).'</div></a><div class="lv-contact-channel"><strong>'.esc_html($m['phone']?:'—').'</strong><small>'.esc_html($m['email']?:'—').'</small></div><div><strong>'.esc_html($c->curator_user_id?LV_Applications_Plugin::assignee_name($c->curator_user_id):'Не назначен').'</strong><small>'.esc_html($c->curator_user_id?LV_Applications_Plugin::assignee_role_label($c->curator_user_id):'').'</small></div><div><strong>'.esc_html((int)$c->lv_application_count).' заявок</strong><small>'.($c->lv_last_application_at?'Последняя '.esc_html(mysql2date('d.m.Y',$c->lv_last_application_at)):'Заявок ещё нет').'</small></div><div><span class="lv-consent-badge is-'.esc_attr($consent).'">'.esc_html($consentLabel).'</span></div><div class="lv-contact-row-actions">';
            if(!$trash_view){echo'<a class="lv-contact-open" href="'.esc_url(self::contact_url($c->id,$context_url)).'" aria-label="Открыть контакт"><span class="dashicons dashicons-arrow-right-alt2"></span></a>';if(current_user_can('lv_trash_contacts')&&self::can_trash_contact($c))echo'<a class="lv-contact-delete-icon" href="'.esc_url(self::contact_admin_action_url('trash',$c->id,$context_url)).'" aria-label="В корзину"><span class="dashicons dashicons-trash"></span></a>';}
            else{if(current_user_can('lv_restore_contacts'))echo'<a class="lv-contact-restore-icon" href="'.esc_url(self::contact_admin_action_url('restore',$c->id,$context_url)).'" aria-label="Восстановить"><span class="dashicons dashicons-undo"></span></a>';if(current_user_can('lv_purge_contacts'))echo'<a class="lv-contact-delete-icon" href="'.esc_url(self::contact_admin_action_url('delete_permanently',$c->id,$context_url)).'" data-lv-confirm="Удалить контакт НАВСЕГДА? Это удалит его контактные данные, заметки и историю согласий." aria-label="Удалить навсегда"><span class="dashicons dashicons-trash"></span></a>';}
            echo'</div></div>';}
        if($count>$per){$args=LV_Contact_Query::query_args_from_filters($filters);$args['page']=self::CONTACTS_SLUG;if($segment_id)$args['filters_submitted']=1;$args['per_page']=$per;$args['paged']='%#%';$base=add_query_arg($args,admin_url('admin.php'));echo'<div class="lv-contact-pagination">'.paginate_links(array('base'=>$base,'format'=>'','current'=>$page,'total'=>ceil($count/$per))).'</div>';}
        echo'</section>';
        echo LV_Contact_Export_Controller::export_modal($filters,$segment_name);
        echo'</div>';
    }

    private function render_contact_messages(){
        $items=array();
        if(!empty($_GET['trashed']))$items[]=array('success','Контакт перемещён в корзину.');
        if(!empty($_GET['restored']))$items[]=array('success','Контакт восстановлен.');
        if(!empty($_GET['purged']))$items[]=array('success','Контакт удалён навсегда.');
        if(isset($_GET['bulk_selected'])){
            $selected=absint($_GET['bulk_selected']);$changed=absint($_GET['bulk_changed']??0);$skipped=absint($_GET['bulk_skipped']??0);
            $msg='Выбрано: '.$selected.'. Изменено: '.$changed.'.';if($skipped)$msg.=' Пропущено: '.$skipped.'.';$items[]=array($changed?'success':'warning',$msg);
        }
        foreach($items as$item){$type='warning'===$item[0]?'warning':'success';$icon='warning'===$type?'dashicons-warning':'dashicons-yes-alt';echo'<div class="lv-alert lv-alert--'.esc_attr($type).'"><span class="dashicons '.esc_attr($icon).'"></span><div><strong>'.('warning'===$type?'Операция не изменила данные':'Готово').'</strong><p>'.esc_html($item[1]).'</p></div></div>';}
        $token=sanitize_key($_GET['undo_token']??'');
        if($token){$url=wp_nonce_url(add_query_arg(array('action'=>'lv_crm_undo_contact_trash','token'=>$token),admin_url('admin-post.php')),'lv_crm_undo_contact_trash_'.$token);echo'<div class="lv-inline-undo"><span>Удаление можно отменить в течение 10 минут.</span><a class="button" href="'.esc_url($url).'">Отменить</a><a href="'.esc_url(add_query_arg(array('page'=>self::CONTACTS_SLUG,'view'=>'trash'),admin_url('admin.php'))).'">Открыть корзину</a></div>';}
    }

    private static function prefill_from_application($application_id){$data=array('name'=>'','organization'=>'','email'=>'','phone'=>'');$app=$application_id?LV_Applications_Plugin::get_application($application_id):null;if(!$app||!LV_Applications_Plugin::can_access_application($app))return$data;$p=LV_Applications_Plugin::primary_fields($app);if(!empty($p['name']))$data['name']=LV_Applications_Plugin::value_to_string($p['name']['value']);if(!empty($p['organization']))$data['organization']=LV_Applications_Plugin::value_to_string($p['organization']['value']);if(!empty($p['email']))$data['email']=LV_Applications_Plugin::value_to_string($p['email']['value']);if(!empty($p['phone']))$data['phone']=LV_Applications_Plugin::value_to_string($p['phone']['value']);return$data;}

    private function render_contact_editor(){
        $id=absint($_GET['contact_id']??0);$contact=$id?self::get_contact($id):null;$from=absint($_GET['from_application']??0);$editing=(bool)$contact;
        if($id&&!$contact)wp_die('Контакт не найден.');if($editing&&!self::can_access_contact($contact))wp_die('Недостаточно прав.');
        $deleted=$editing&&self::is_contact_deleted($contact);$canEdit=!$editing||(!$deleted&&self::can_edit_contact($contact));$pref=self::prefill_from_application($from);
        $emails=$editing?self::contact_emails($id):array();$phones=$editing?self::contact_phones($id):array();$fields=$editing?self::contact_fields($id):array();$tags=$editing?self::get_contact_tags($id):array();
        $linked=$editing?self::linked_applications($id):array();$logs=$editing?self::contact_logs($id):array();$notes=$editing?self::contact_notes($id):array();
        $name=$editing?$contact->display_name:$pref['name'];$org=$editing?$contact->organization:$pref['organization'];$email0=$emails?$emails[0]->value:$pref['email'];$phone0=$phones?$phones[0]->value:$pref['phone'];
        $candidates=!$editing?self::find_contact_candidates($email0,$phone0):array();
        $initial=$name?(function_exists('mb_substr')?mb_substr($name,0,1):substr($name,0,1)):'?';
        $return_to=self::safe_admin_return_url($_GET['return_to']??'');
        if(!$return_to)$return_to=add_query_arg(array('page'=>self::CONTACTS_SLUG,'view'=>$deleted?'trash':'active'),admin_url('admin.php'));
        $context_url=$return_to;
        $prev_id=$next_id=0;
        if($editing){
            $ctx_query=array();$parts=wp_parse_url($context_url);if(!empty($parts['query']))parse_str($parts['query'],$ctx_query);
            $ctx_filters=LV_Contact_Query::sanitize_filters($ctx_query);$ctx_filters['view']=$deleted?'trash':($ctx_filters['view']??'active');
            $sequence=(new LV_Contact_Query($ctx_filters))->ids();$idx=array_search((int)$id,$sequence,true);
            if(false!==$idx){if($idx>0)$prev_id=(int)$sequence[$idx-1];if($idx<count($sequence)-1)$next_id=(int)$sequence[$idx+1];}
        }

        echo'<div class="wrap lv-applications-wrap lv-contact-editor">';
        echo'<header class="lv-detail-head"><div><a class="lv-back-link" href="'.esc_url($context_url).'"><span class="dashicons dashicons-arrow-left-alt2"></span>К списку контактов</a><span class="lv-kicker">'.($editing?'Карточка контакта':'Новый контакт').'</span><h1>'.esc_html($name?:'Новый контакт').'</h1><p>'.($editing?'Создан '.esc_html(mysql2date('d.m.Y H:i',$contact->created_at)).' · #'.esc_html($contact->id):($from?'Создание из заявки #'.esc_html($from):'Добавьте человека или организацию в единую базу фонда.')).'</p>';
        if($editing)echo'<nav class="lv-detail-pager">'.($prev_id?'<a class="button" href="'.esc_url(self::contact_url($prev_id,$context_url)).'">← Предыдущий</a>':'<span></span>').'<a class="button" href="'.esc_url($context_url).'">К списку</a>'.($next_id?'<a class="button" href="'.esc_url(self::contact_url($next_id,$context_url)).'">Следующий →</a>':'<span></span>').'</nav>';
        echo'</div>';
        if($editing){echo'<div class="lv-head-actions">';if($deleted){if(current_user_can('lv_restore_contacts'))echo'<a class="button" href="'.esc_url(self::contact_admin_action_url('restore',$id,$context_url)).'"><span class="dashicons dashicons-undo"></span>Восстановить</a>';if(current_user_can('lv_purge_contacts'))echo'<a class="button lv-danger-soft" href="'.esc_url(self::contact_admin_action_url('delete_permanently',$id,$context_url)).'" data-lv-confirm="Удалить контакт НАВСЕГДА? Это действие нельзя отменить."><span class="dashicons dashicons-trash"></span>Удалить навсегда</a>';}elseif($canEdit){echo'<span class="lv-permission-badge"><span class="dashicons dashicons-edit"></span>Можно редактировать</span>';if(self::can_trash_contact($contact))echo'<a class="button lv-danger-soft" href="'.esc_url(self::contact_admin_action_url('trash',$id,$context_url)).'"><span class="dashicons dashicons-trash"></span>В корзину</a>';}echo'</div>';}echo'</header>';
        LV_Applications_Plugin::instance()->render_workspace_nav(array(),'contacts');
        if($deleted)echo'<div class="lv-alert lv-alert--warning"><span class="dashicons dashicons-trash"></span><div><strong>Контакт находится в корзине</strong><p>Удалён '.esc_html(mysql2date('d.m.Y H:i',$contact->deleted_at)).'. До автоматического удаления осталось '.esc_html(self::contact_trash_days_left($contact)).' дн.</p></div></div>';

        if($candidates){echo'<div class="lv-alert lv-alert--warning"><span class="dashicons dashicons-admin-users"></span><div><strong>Возможно, контакт уже существует</strong><p>Найдены совпадения по телефону или email. CRM никогда не объединяет контакты автоматически.</p><div class="lv-candidate-links">';foreach($candidates as$c)echo'<a href="'.esc_url(self::contact_url($c->id,$context_url)).'">'.esc_html($c->display_name).' #'.esc_html($c->id).'</a>';echo'</div></div></div>';}

        echo'<div class="lv-contact-layout"><main>';
        if($canEdit){
            echo'<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="lv-contact-form lv-dirty-guard-form"><input type="hidden" name="action" value="lv_crm_save_contact"><input type="hidden" name="contact_id" value="'.esc_attr($id).'"><input type="hidden" name="from_application" value="'.esc_attr($from).'"><input type="hidden" name="return_to" value="'.esc_attr($context_url).'">';wp_nonce_field('lv_crm_save_contact');
            echo'<section class="lv-panel"><div class="lv-panel-title"><span class="dashicons dashicons-id"></span><h2>Основная информация</h2></div><div class="lv-contact-form-grid"><div class="lv-control"><label>Тип контакта</label><select name="contact_type"><option value="person" '.selected($editing?$contact->contact_type:'person','person',false).'>Человек</option><option value="organization" '.selected($editing?$contact->contact_type:'person','organization',false).'>Организация</option></select></div><div class="lv-control is-wide"><label>Имя / название</label><input name="display_name" required value="'.esc_attr($name).'" placeholder="Иван Петров или ООО «Ромашка»"></div><div class="lv-control is-wide"><label>Организация / место работы</label><input name="organization" value="'.esc_attr($org).'" placeholder="Необязательно"></div></div></section>';
            echo'<section class="lv-panel"><div class="lv-panel-title"><span class="dashicons dashicons-phone"></span><h2>Контактные данные</h2><span>Можно хранить несколько</span></div><div class="lv-repeaters"><div><label>Телефоны</label><div class="lv-repeater" data-template="phone"><div class="lv-repeat-row"><input name="phones[]" value="'.esc_attr($phone0).'" placeholder="+7 …"><button type="button" class="button-link lv-repeat-remove" aria-label="Удалить телефон">×</button></div>';foreach(array_slice($phones,1) as$p)echo'<div class="lv-repeat-row"><input name="phones[]" value="'.esc_attr($p->value).'"><button type="button" class="button-link lv-repeat-remove" aria-label="Удалить телефон">×</button></div>';echo'</div><button type="button" class="button lv-repeat-add" data-target="phone">+ Телефон</button></div><div><label>Email</label><div class="lv-repeater" data-template="email"><div class="lv-repeat-row"><input type="email" name="emails[]" value="'.esc_attr($email0).'" placeholder="name@example.ru"><button type="button" class="button-link lv-repeat-remove" aria-label="Удалить email">×</button></div>';foreach(array_slice($emails,1) as$e)echo'<div class="lv-repeat-row"><input type="email" name="emails[]" value="'.esc_attr($e->value).'"><button type="button" class="button-link lv-repeat-remove" aria-label="Удалить email">×</button></div>';echo'</div><button type="button" class="button lv-repeat-add" data-target="email">+ Email</button></div></div></section>';
            echo'<section class="lv-panel"><div class="lv-panel-title"><span class="dashicons dashicons-admin-generic"></span><h2>Ответственные и аккаунт</h2></div><div class="lv-contact-form-grid"><div class="lv-control"><label>Куратор контакта</label><select name="curator_user_id"><option value="0">Не назначен</option>';foreach(LV_Applications_Plugin::eligible_assignees() as$u)echo'<option value="'.esc_attr($u->ID).'" '.selected($editing?$contact->curator_user_id:0,$u->ID,false).'>'.esc_html($u->display_name.' · '.LV_Applications_Plugin::assignee_role_label($u->ID)).'</option>';echo'</select><small>Куратор может редактировать карточку независимо от её автора.</small></div><div class="lv-control"><label>Владелец / связанный аккаунт WordPress</label><select name="linked_wp_user_id"><option value="0">Нет связанного аккаунта</option>';foreach(get_users(array('orderby'=>'display_name','order'=>'ASC')) as$u)echo'<option value="'.esc_attr($u->ID).'" '.selected($editing?$contact->linked_wp_user_id:0,$u->ID,false).'>'.esc_html($u->display_name.' · '.$u->user_login).'</option>';echo'</select><small>Это аккаунт самого контакта. Связь не выдаёт права редактирования CRM.</small></div></div></section>';
            echo'<section class="lv-panel"><div class="lv-panel-title"><span class="dashicons dashicons-tag"></span><h2>Метки контакта</h2></div><div class="lv-tag-checkboxes">';$selectedTagIds=array_map('intval',wp_list_pluck($tags,'id'));foreach(self::get_tags('contact') as$t)echo'<label style="--tag:'.esc_attr($t->color).'"><input type="checkbox" name="tag_ids[]" value="'.esc_attr($t->id).'" '.checked(in_array((int)$t->id,$selectedTagIds,true),true,false).'><span>'.esc_html($t->name).'</span></label>';if(!self::get_tags('contact'))echo'<p class="lv-muted">Метки ещё не созданы.</p>';echo'</div></section>';
            echo'<section class="lv-panel"><div class="lv-panel-title"><span class="dashicons dashicons-list-view"></span><h2>Дополнительные сведения</h2><span>Универсальные поля</span></div><p class="lv-panel-help">Добавляйте любые данные, которые нужны для работы фонда: профиль Добро.РФ, предпочтительный способ связи, дата первого визита, размер одежды и т. п. Поля, синхронизируемые с форм сайта, сохраняют скрытый технический идентификатор и продолжают обновляться автоматически.</p><div class="lv-custom-field-list">';if(!$fields)$fields=array((object)array('field_key'=>'','field_label'=>'','field_type'=>'text','field_value'=>''));foreach($fields as$f)echo'<div class="lv-custom-field-row"><input type="hidden" name="custom_key[]" value="'.esc_attr($f->field_key??'').'"><input name="custom_label[]" value="'.esc_attr($f->field_label).'" placeholder="Название поля"><select name="custom_type[]">'.self::field_type_options($f->field_type).'</select><input name="custom_value[]" value="'.esc_attr($f->field_value).'" placeholder="Значение"><button type="button" class="button-link lv-custom-field-remove" aria-label="Удалить дополнительное поле">×</button></div>';echo'</div><button type="button" class="button lv-custom-field-add">+ Добавить поле</button></section><div class="lv-contact-savebar"><a class="button" href="'.esc_url($context_url).'">Отмена</a><button class="button button-primary button-hero lv-save-contact-button" data-saving-text="Сохранение…">'.($editing?'Сохранить изменения':'Создать контакт').'</button></div></form>';
        }else{
            echo'<section class="lv-panel"><div class="lv-panel-title"><span class="dashicons dashicons-lock"></span><h2>Карточка контакта</h2><span>Только просмотр</span></div><div class="lv-readonly-grid">';
            echo'<div><span>Тип</span><strong>'.esc_html($contact->contact_type==='organization'?'Организация':'Человек').'</strong></div>';if($contact->organization)echo'<div><span>Организация</span><strong>'.esc_html($contact->organization).'</strong></div>';
            foreach($phones as$p)echo'<div><span>Телефон</span><strong><a href="tel:'.esc_attr(self::normalize_phone($p->value)).'">'.esc_html($p->value).'</a></strong></div>';
            foreach($emails as$e)echo'<div><span>Email</span><strong><a href="mailto:'.esc_attr($e->value).'">'.esc_html($e->value).'</a></strong></div>';
            foreach($fields as$f)echo'<div class="'.($f->field_type==='textarea'?'is-wide':'').'"><span>'.esc_html($f->field_label).'</span><strong>'.nl2br(esc_html($f->field_value)).'</strong></div>';
            echo'</div><p class="lv-readonly-help">Редактировать карточку могут её создатель, куратор, редакторы и администраторы.</p></section>';
        }

        if($editing)echo LV_Contact_Export_Controller::consent_panel($contact,$emails);

        if($editing){
            echo'<section class="lv-panel lv-notes-panel"><div class="lv-panel-title"><span class="dashicons dashicons-admin-comments"></span><h2>Комментарии и внутренние заметки</h2><span>'.esc_html(count($notes)).'</span></div>';
            if($canEdit)echo'<div class="lv-note-compose"><textarea class="lv-note-input lv-contact-note-input" rows="3" placeholder="Добавьте комментарий. Введите @ и выберите коллегу из списка…"></textarea><button type="button" class="button button-primary lv-add-contact-note" data-contact="'.esc_attr($id).'">Добавить</button></div>';
            echo'<div class="lv-contact-notes-container">'.self::contact_notes_html($notes).'</div></section>';
            echo'<section class="lv-panel"><div class="lv-panel-title"><span class="dashicons dashicons-forms"></span><h2>История заявок</h2><span>'.esc_html(count($linked)).'</span></div><div class="lv-contact-application-history">';if(!$linked)echo'<div class="lv-empty-mini">Заявки пока не связаны.</div>';foreach($linked as$a)echo'<a href="'.esc_url(add_query_arg(array('page'=>LV_Applications_Plugin::PAGE_SLUG,'action'=>'view','application_id'=>$a->id),admin_url('admin.php'))).'"><span>'.esc_html(mysql2date('d.m.Y',$a->submitted_at)).'</span><strong>'.esc_html($a->form_title).' #'.esc_html($a->id).'</strong><small>'.esc_html($a->processed?'Обработано':'Не обработано').' · '.esc_html(LV_Applications_Plugin::assignee_name($a->assignee_id)).'</small></a>';echo'</div></section>';
        }

        echo'</main><aside>';
        if($editing){$m=self::contact_display_meta($contact);echo'<section class="lv-panel lv-contact-summary"><div class="lv-contact-avatar is-large">'.esc_html($initial).'</div><h2>'.esc_html($contact->display_name).'</h2>';if($m['phone'])echo'<p><a href="tel:'.esc_attr(self::normalize_phone($m['phone'])).'">'.esc_html($m['phone']).'</a></p>';if($m['email'])echo'<p><a href="mailto:'.esc_attr($m['email']).'">'.esc_html($m['email']).'</a></p>';echo'<dl><div><dt>Создал</dt><dd>'.esc_html(LV_Applications_Plugin::assignee_name($contact->created_by)).'</dd></div><div><dt>Куратор</dt><dd>'.esc_html($contact->curator_user_id?LV_Applications_Plugin::assignee_name($contact->curator_user_id):'Не назначен').'</dd></div><div><dt>Аккаунт</dt><dd>'.esc_html($contact->linked_wp_user_id?self::user_full_name(get_userdata($contact->linked_wp_user_id)):'Не связан').'</dd></div></dl>'.self::tags_html($tags).'</section>';
            echo'<section class="lv-panel"><div class="lv-panel-title"><span class="dashicons dashicons-backup"></span><h2>История контакта</h2></div><div class="lv-timeline">';if(!$logs)echo'<div class="lv-empty-mini">История пока пуста.</div>';foreach($logs as$l)echo'<div class="lv-timeline-item"><span class="lv-timeline-dot"></span><div><div class="lv-timeline-meta"><strong>'.esc_html($l->user_id?LV_Applications_Plugin::assignee_name($l->user_id):'Система').'</strong><span>'.esc_html(mysql2date('d.m.Y H:i',$l->created_at)).'</span></div><p>'.esc_html($l->message).'</p></div></div>';echo'</div></section>';}
        echo'</aside></div></div>';
    }

    private static function field_type_options($selected){$types=array('text'=>'Строка','textarea'=>'Многострочный текст','number'=>'Число','date'=>'Дата','url'=>'Ссылка','email'=>'Email','phone'=>'Телефон','checkbox'=>'Да / нет');$html='';foreach($types as$k=>$v)$html.='<option value="'.esc_attr($k).'" '.selected($selected,$k,false).'>'.esc_html($v).'</option>';return$html;}

    private static function sync_contact_phones($contact_id,$values){
        global $wpdb;
        $contact_id=absint($contact_id);
        $seen=array();$clean=array();$changed=false;
        foreach((array)$values as$v){
            $v=sanitize_text_field($v);$n=self::normalize_phone($v);
            if(!$n||isset($seen[$n]))continue;
            $seen[$n]=true;$clean[]=array('value'=>$v,'normalized'=>$n);
        }
        $existing=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::contact_phones_table().' WHERE contact_id=%d ORDER BY id',$contact_id));
        $by=array();foreach($existing as$r)$by[$r->normalized]=$r;$keep=array();
        foreach($clean as$i=>$row){
            if(isset($by[$row['normalized']])){
                $eid=(int)$by[$row['normalized']]->id;
                $updated=$wpdb->update(self::contact_phones_table(),array('value'=>$row['value'],'is_primary'=>$i===0?1:0),array('id'=>$eid),array('%s','%d'),array('%d'));
                if($updated>0)$changed=true;
                $keep[]=$eid;
            }else{
                $ok=$wpdb->insert(self::contact_phones_table(),array('contact_id'=>$contact_id,'value'=>$row['value'],'normalized'=>$row['normalized'],'is_primary'=>$i===0?1:0),array('%d','%s','%s','%d'));
                if($ok)$changed=true;
                $keep[]=absint($wpdb->insert_id);
            }
        }
        foreach($existing as$r){
            if(!in_array((int)$r->id,$keep,true)){
                if($wpdb->delete(self::contact_phones_table(),array('id'=>$r->id),array('%d'))) $changed=true;
            }
        }
        return$changed;
    }

    private static function sync_contact_emails($contact_id,$values){
        global $wpdb;
        $contact_id=absint($contact_id);
        $seen=array();$clean=array();$changed=false;
        foreach((array)$values as$v){
            $v=sanitize_email($v);$n=strtolower(trim($v));
            if(!$n||!is_email($n)||isset($seen[$n]))continue;
            $seen[$n]=true;$clean[]=array('value'=>$v,'normalized'=>$n);
        }
        $existing=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::contact_emails_table().' WHERE contact_id=%d ORDER BY id',$contact_id));
        $by=array();foreach($existing as$r)$by[strtolower($r->normalized)]=$r;$keep=array();
        foreach($clean as$i=>$row){
            if(isset($by[$row['normalized']])){
                $eid=(int)$by[$row['normalized']]->id;
                $updated=$wpdb->update(self::contact_emails_table(),array('value'=>$row['value'],'is_primary'=>$i===0?1:0),array('id'=>$eid),array('%s','%d'),array('%d'));
                if($updated>0)$changed=true;
                $keep[]=$eid;
            }else{
                $ok=$wpdb->insert(self::contact_emails_table(),array('contact_id'=>$contact_id,'value'=>$row['value'],'normalized'=>$row['normalized'],'is_primary'=>$i===0?1:0),array('%d','%s','%s','%d'));
                if($ok)$changed=true;
                $keep[]=absint($wpdb->insert_id);
            }
        }
        foreach($existing as$r){
            if(!in_array((int)$r->id,$keep,true)){
                LV_Consent_Service::delete_current_for_email($r->id);
                if($wpdb->delete(self::contact_emails_table(),array('id'=>$r->id),array('%d'))) $changed=true;
            }
        }
        return$changed;
    }

    private static function linked_account_contact($user_id,$exclude_contact=0){
        global$wpdb;$user_id=absint($user_id);if(!$user_id)return 0;$sql='SELECT id FROM '.self::contacts_table().' WHERE deleted_at IS NULL AND linked_wp_user_id=%d';$args=array($user_id);if($exclude_contact){$sql.=' AND id<>%d';$args[]=absint($exclude_contact);}$sql.=' LIMIT 1';return absint($wpdb->get_var($wpdb->prepare($sql,$args)));
    }

    private static function sync_contact_fields($contact_id,$keys,$labels,$types,$values,$now){
        global $wpdb;
        $allowed=array('text','textarea','number','date','url','email','phone','checkbox');
        $existing=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::contact_fields_table().' WHERE contact_id=%d ORDER BY id',$contact_id));
        $existing_by_id=array();$existing_by_signature=array();$existing_by_field_key=array();
        foreach((array)$existing as$row){
            $existing_by_id[(int)$row->id]=$row;
            $signature=hash('sha256',$row->field_label.'|'.$row->field_type.'|'.$row->field_value);
            $existing_by_signature[$signature][]=$row;
            if(!empty($row->field_key))$existing_by_field_key[sanitize_key($row->field_key)]=$row;
        }

        $changed=false;$keep=array();
        foreach((array)$labels as$i=>$label){
            $field_key=sanitize_key($keys[$i]??'');
            $label=sanitize_text_field($label);
            $ft=sanitize_key($types[$i]??'text');
            $fv=sanitize_textarea_field($values[$i]??'');
            if(!$label||''===$fv)continue;
            if(!in_array($ft,$allowed,true))$ft='text';

            $old=null;
            // Prefer the stable machine key. This is what keeps form-synchronised
            // fields connected even when an administrator edits their label/value.
            if($field_key&&!empty($existing_by_field_key[$field_key]))$old=$existing_by_field_key[$field_key];
            if(!$old){
                $signature=hash('sha256',$label.'|'.$ft.'|'.$fv);
                if(!empty($existing_by_signature[$signature]))$old=array_shift($existing_by_signature[$signature]);
            }

            if($old){
                $keep[]=(int)$old->id;
                $new_key=$field_key?:sanitize_key($old->field_key??'');
                if((string)($old->field_key??'')!==$new_key||(string)$old->field_label!==$label||(string)$old->field_type!==$ft||(string)$old->field_value!==$fv){
                    $ok=$wpdb->update(self::contact_fields_table(),array('field_key'=>$new_key,'field_label'=>$label,'field_type'=>$ft,'field_value'=>$fv,'updated_at'=>$now),array('id'=>(int)$old->id),array('%s','%s','%s','%s','%s'),array('%d'));
                    if(false!==$ok&&$ok>0)$changed=true;
                }
                continue;
            }

            $ok=$wpdb->insert(self::contact_fields_table(),array(
                'contact_id'=>$contact_id,'field_key'=>$field_key,'field_label'=>$label,'field_type'=>$ft,
                'field_value'=>$fv,'created_by'=>get_current_user_id(),'updated_at'=>$now
            ),array('%d','%s','%s','%s','%s','%d','%s'));
            if($ok){$changed=true;$keep[]=absint($wpdb->insert_id);}
        }
        foreach((array)$existing as$row){
            if(!in_array((int)$row->id,$keep,true)){
                if($wpdb->delete(self::contact_fields_table(),array('id'=>$row->id),array('%d'))) $changed=true;
            }
        }
        return$changed;
    }

    private static function sync_contact_tags($contact_id,$tag_ids){
        global $wpdb;
        $tag_ids=array_values(array_unique(array_filter(array_map('absint',(array)$tag_ids))));
        $allowed=array_map('intval',wp_list_pluck(self::get_tags('contact'),'id'));
        $desired=array_values(array_intersect($tag_ids,$allowed));
        sort($desired,SORT_NUMERIC);
        $existing=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT tag_id FROM '.self::contact_tags_table().' WHERE contact_id=%d',$contact_id)));
        sort($existing,SORT_NUMERIC);
        $to_add=array_values(array_diff($desired,$existing));
        $to_remove=array_values(array_diff($existing,$desired));
        foreach($to_add as$tid)$wpdb->insert(self::contact_tags_table(),array('contact_id'=>$contact_id,'tag_id'=>$tid),array('%d','%d'));
        foreach($to_remove as$tid)$wpdb->delete(self::contact_tags_table(),array('contact_id'=>$contact_id,'tag_id'=>$tid),array('%d','%d'));
        return(bool)($to_add||$to_remove);
    }

    public function save_contact(){
        if(!self::can_view_contacts())wp_die('Недостаточно прав.');
        check_admin_referer('lv_crm_save_contact');
        global $wpdb;

        $id=absint($_POST['contact_id']??0);
        $contact=$id?self::get_contact($id):null;
        if($contact&&!self::can_edit_contact($contact))wp_die('Недостаточно прав для редактирования контакта.');

        $type=sanitize_key(wp_unslash($_POST['contact_type']??'person'));
        if(!in_array($type,array('person','organization'),true))$type='person';
        $name=sanitize_text_field(wp_unslash($_POST['display_name']??''));
        if(!$name)wp_die('Укажите имя или название контакта.');
        $org=sanitize_text_field(wp_unslash($_POST['organization']??''));

        $curator=absint($_POST['curator_user_id']??0);
        if($curator&&!LV_Applications_Plugin::eligible_assignee_public($curator))$curator=0;
        $linked=absint($_POST['linked_wp_user_id']??0);
        if($linked&&!get_userdata($linked))$linked=0;
        if($linked){
            $conflict=self::linked_account_contact($linked,$id);
            if($conflict)wp_die('Этот аккаунт WordPress уже связан с контактом #'.absint($conflict).'. Сначала откройте существующий контакт и проверьте связь.');
        }

        $now=current_time('mysql');
        $created=false;$main_changed=false;$curator_changed=false;$old_curator=0;
        $wpdb->query('START TRANSACTION');

        try{
            if($contact){
                $old_curator=(int)$contact->curator_user_id;
                $main_changed=(
                    (string)$contact->contact_type!==$type||
                    (string)$contact->display_name!==$name||
                    (string)$contact->organization!==$org||
                    (int)$contact->curator_user_id!==$curator||
                    (int)$contact->linked_wp_user_id!==$linked
                );
                $curator_changed=(int)$contact->curator_user_id!==$curator;
                if($main_changed){
                    $ok=$wpdb->update(self::contacts_table(),array(
                        'contact_type'=>$type,'display_name'=>$name,'organization'=>$org,'curator_user_id'=>$curator,
                        'linked_wp_user_id'=>$linked,'updated_at'=>$now,'last_activity_at'=>$now
                    ),array('id'=>$id),array('%s','%s','%s','%d','%d','%s','%s'),array('%d'));
                    if(false===$ok)throw new RuntimeException('Не удалось обновить контакт.');
                }
            }else{
                $ok=$wpdb->insert(self::contacts_table(),array(
                    'contact_type'=>$type,'display_name'=>$name,'organization'=>$org,'created_by'=>get_current_user_id(),
                    'curator_user_id'=>$curator,'linked_wp_user_id'=>$linked,'created_at'=>$now,'updated_at'=>$now,'last_activity_at'=>$now
                ),array('%s','%s','%s','%d','%d','%d','%s','%s','%s'));
                if(!$ok)throw new RuntimeException('Не удалось создать контакт.');
                $id=absint($wpdb->insert_id);$created=true;$main_changed=true;$curator_changed=$curator>0;
            }

            $phones_changed=self::sync_contact_phones($id,isset($_POST['phones'])?(array)wp_unslash($_POST['phones']):array());
            $emails_changed=self::sync_contact_emails($id,isset($_POST['emails'])?(array)wp_unslash($_POST['emails']):array());
            $keys=isset($_POST['custom_key'])?(array)wp_unslash($_POST['custom_key']):array();
            $labels=isset($_POST['custom_label'])?(array)wp_unslash($_POST['custom_label']):array();
            $types=isset($_POST['custom_type'])?(array)wp_unslash($_POST['custom_type']):array();
            $values=isset($_POST['custom_value'])?(array)wp_unslash($_POST['custom_value']):array();
            $fields_changed=self::sync_contact_fields($id,$keys,$labels,$types,$values,$now);
            $tags_changed=self::sync_contact_tags($id,isset($_POST['tag_ids'])?(array)$_POST['tag_ids']:array());

            if($created){
                self::contact_log($id,'created','Создан контакт «'.$name.'».');
            }elseif($main_changed||$phones_changed||$emails_changed||$fields_changed||$tags_changed){
                self::contact_log($id,'updated','Карточка контакта обновлена.');
            }

            if($curator_changed){
                self::contact_log($id,'curator_changed','Куратор изменён: '.($old_curator?LV_Applications_Plugin::assignee_name($old_curator):'не назначен').' → '.($curator?LV_Applications_Plugin::assignee_name($curator):'не назначен').'.');
                if($curator&&$curator!==get_current_user_id())self::notify($curator,'curator','contact',$id,'Вы назначены куратором контакта «'.$name.'».');
            }

            $from=absint($_POST['from_application']??0);
            if($from){
                $app=LV_Applications_Plugin::get_application($from);
                if($app&&LV_Applications_Plugin::can_edit_application($app)){
                    $exists=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::application_contacts_table().' WHERE application_id=%d',$from));
                    $already=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::application_contacts_table().' WHERE application_id=%d AND contact_id=%d',$from,$id));
                    if(!$already){
                        $ok=$wpdb->insert(self::application_contacts_table(),array(
                            'application_id'=>$from,'contact_id'=>$id,'relation_type'=>$exists?'related':'primary',
                            'is_primary'=>$exists?0:1,'created_by'=>get_current_user_id(),'created_at'=>$now
                        ),array('%d','%d','%s','%d','%d','%s'));
                        if(!$ok)throw new RuntimeException('Не удалось связать контакт с заявкой.');
                        LV_Applications_Plugin::log_event($from,'contact_linked','Создан и связан контакт «'.$name.'».');
                        self::contact_log($id,'application_linked','Связана заявка #'.$from.'.');
                    }
                }
            }

            $wpdb->query('COMMIT');
        }catch(Throwable $e){
            $wpdb->query('ROLLBACK');
            wp_die('Не удалось сохранить контакт. Изменения отменены.');
        }

        if(class_exists('LV_Request_Cache'))LV_Request_Cache::flush();
        $return_to=self::safe_admin_return_url(wp_unslash($_POST['return_to']??''));$args=array('page'=>self::CONTACTS_SLUG,'action'=>'view','contact_id'=>$id,'saved'=>1);if($return_to)$args['return_to']=$return_to;wp_safe_redirect(add_query_arg($args,admin_url('admin.php')));
        exit;
    }


    public function maybe_purge_contact_trash(){
        $last=(int)get_option('lv_crm_last_contact_trash_purge',0);if($last&&(time()-$last)<36*HOUR_IN_SECONDS)return;$this->purge_contact_trash();
    }

    public function purge_contact_trash(){
        global$wpdb;$threshold=date('Y-m-d H:i:s',current_time('timestamp')-(7*DAY_IN_SECONDS));
        $ids=array_map('absint',$wpdb->get_col($wpdb->prepare('SELECT id FROM '.self::contacts_table().' WHERE deleted_at IS NOT NULL AND deleted_at<=%s ORDER BY deleted_at ASC,id ASC LIMIT 100',$threshold)));
        foreach($ids as$id)self::purge_contact_record($id);update_option('lv_crm_last_contact_trash_purge',time(),false);if(class_exists('LV_Request_Cache'))LV_Request_Cache::flush();
    }


    private static function store_contact_undo_batch($ids,$return_to=''){
        $ids=array_values(array_unique(array_filter(array_map('absint',(array)$ids))));if(!$ids)return'';
        $token=wp_generate_password(24,false,false);$key='lv_contact_undo_'.get_current_user_id().'_'.$token;
        set_transient($key,array('ids'=>$ids,'return_to'=>self::safe_admin_return_url($return_to)),10*MINUTE_IN_SECONDS);return$token;
    }

    public function undo_contact_trash(){
        if(!current_user_can('lv_trash_contacts')&&!current_user_can('lv_restore_contacts'))wp_die('Недостаточно прав.');
        $token=sanitize_key($_GET['token']??'');if(!$token)wp_die('Ссылка отмены недействительна.');check_admin_referer('lv_crm_undo_contact_trash_'.$token);
        $key='lv_contact_undo_'.get_current_user_id().'_'.$token;$payload=get_transient($key);if(!$payload||empty($payload['ids']))wp_die('Срок действия отмены истёк.');
        global$wpdb;$now=current_time('mysql');$changed=0;
        foreach((array)$payload['ids'] as$id){$contact=self::get_contact($id);if(!$contact||!self::is_contact_deleted($contact))continue;$ok=$wpdb->update(self::contacts_table(),array('deleted_at'=>null,'deleted_by'=>0,'updated_at'=>$now,'last_activity_at'=>$now),array('id'=>absint($id)),array('%s','%d','%s','%s'),array('%d'));if(false!==$ok){self::contact_log($id,'restored','Удаление контакта отменено.');$changed++;}}
        delete_transient($key);if(class_exists('LV_Request_Cache'))LV_Request_Cache::flush();
        $return=self::safe_admin_return_url($payload['return_to']??'');if(!$return)$return=admin_url('admin.php?page='.self::CONTACTS_SLUG);$return=add_query_arg(array('bulk_selected'=>count((array)$payload['ids']),'bulk_changed'=>$changed,'bulk_skipped'=>max(0,count((array)$payload['ids'])-$changed)),$return);wp_safe_redirect($return);exit;
    }

    public function trash_contact(){
        $id=absint($_GET['contact_id']??0);$contact=self::get_contact($id);
        if(!$contact)wp_die('Контакт не найден.');if(!self::can_trash_contact($contact))wp_die('Недостаточно прав для удаления контакта.');
        check_admin_referer('lv_crm_trash_contact_'.$id);global$wpdb;$now=current_time('mysql');
        self::contact_log($id,'trashed','Контакт перемещён в корзину.');
        $ok=$wpdb->update(self::contacts_table(),array('deleted_at'=>$now,'deleted_by'=>get_current_user_id(),'updated_at'=>$now),array('id'=>$id),array('%s','%d','%s'),array('%d'));
        if(false===$ok)wp_die('Не удалось переместить контакт в корзину.');if(class_exists('LV_Request_Cache'))LV_Request_Cache::flush();
        $return_to=self::safe_admin_return_url($_GET['return_to']??'');$token=self::store_contact_undo_batch(array($id),$return_to);
        wp_safe_redirect(add_query_arg(array('page'=>self::CONTACTS_SLUG,'view'=>'trash','trashed'=>1,'highlight'=>$id,'undo_token'=>$token),admin_url('admin.php')));exit;
    }

    public function restore_contact(){
        $id=absint($_GET['contact_id']??0);$contact=self::get_contact($id);if(!$contact||!self::is_contact_deleted($contact))wp_die('Контакт не найден в корзине.');
        if(!current_user_can('lv_restore_contacts'))wp_die('Недостаточно прав для восстановления контакта.');check_admin_referer('lv_crm_restore_contact_'.$id);global$wpdb;$now=current_time('mysql');
        $ok=$wpdb->update(self::contacts_table(),array('deleted_at'=>null,'deleted_by'=>0,'updated_at'=>$now,'last_activity_at'=>$now),array('id'=>$id),array('%s','%d','%s','%s'),array('%d'));
        if(false===$ok)wp_die('Не удалось восстановить контакт.');self::contact_log($id,'restored','Контакт восстановлен из корзины.');if(class_exists('LV_Request_Cache'))LV_Request_Cache::flush();
        $return=self::safe_admin_return_url($_GET['return_to']??'');if(!$return)$return=admin_url('admin.php?page='.self::CONTACTS_SLUG);$return=add_query_arg(array('restored'=>1,'highlight'=>$id),remove_query_arg(array('view','action','contact_id'),$return));wp_safe_redirect($return);exit;
    }

    private static function purge_contact_record($id){
        global$wpdb;$id=absint($id);$contact=self::get_contact($id);if(!$contact)return false;
        $apps=array_map('absint',$wpdb->get_col($wpdb->prepare('SELECT application_id FROM '.self::application_contacts_table().' WHERE contact_id=%d',$id)));
        $wpdb->query('START TRANSACTION');
        try{
            $wpdb->delete(LV_Consent_Service::log_table_name(),array('contact_id'=>$id),array('%d'));
            $wpdb->delete(LV_Consent_Service::table_name(),array('contact_id'=>$id),array('%d'));
            $wpdb->delete(self::contact_tags_table(),array('contact_id'=>$id),array('%d'));
            $wpdb->delete(self::application_contacts_table(),array('contact_id'=>$id),array('%d'));
            foreach($apps as$aid){$has_primary=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::application_contacts_table().' WHERE application_id=%d AND is_primary=1',$aid));if(!$has_primary)$wpdb->query($wpdb->prepare('UPDATE '.self::application_contacts_table().' SET is_primary=1 WHERE application_id=%d ORDER BY created_at ASC LIMIT 1',$aid));}
            $wpdb->delete(self::contact_fields_table(),array('contact_id'=>$id),array('%d'));
            $wpdb->delete(self::contact_phones_table(),array('contact_id'=>$id),array('%d'));
            $wpdb->delete(self::contact_emails_table(),array('contact_id'=>$id),array('%d'));
            $wpdb->delete(LV_Applications_Plugin::notes_table_name(),array('entity_type'=>'contact','entity_id'=>$id),array('%s','%d'));
            $wpdb->delete(self::notifications_table(),array('entity_type'=>'contact','entity_id'=>$id),array('%s','%d'));
            $wpdb->delete(self::contact_log_table(),array('contact_id'=>$id),array('%d'));
            $ok=$wpdb->delete(self::contacts_table(),array('id'=>$id),array('%d'));if(!$ok)throw new RuntimeException('Contact delete failed');
            $wpdb->query('COMMIT');
        }catch(Throwable$e){$wpdb->query('ROLLBACK');return false;}
        foreach($apps as$aid)if($aid)LV_Applications_Plugin::log_event($aid,'contact_deleted_permanently','Связанный контакт #'.$id.' удалён из CRM навсегда.',get_current_user_id());
        return true;
    }

    public function delete_contact_permanently(){
        $id=absint($_GET['contact_id']??0);$contact=self::get_contact($id);if(!$contact||!self::is_contact_deleted($contact))wp_die('Сначала переместите контакт в корзину.');
        if(!current_user_can('lv_purge_contacts'))wp_die('Недостаточно прав для окончательного удаления контакта.');check_admin_referer('lv_crm_delete_permanently_contact_'.$id);
        if(!self::purge_contact_record($id))wp_die('Не удалось удалить контакт. Изменения отменены.');if(class_exists('LV_Request_Cache'))LV_Request_Cache::flush();
        wp_safe_redirect(add_query_arg(array('page'=>self::CONTACTS_SLUG,'view'=>'trash','purged'=>1),admin_url('admin.php')));exit;
    }

    public function bulk_contacts(){
        if(!self::can_view_contacts())wp_die('Недостаточно прав.');check_admin_referer('lv_crm_bulk_contacts');
        $action=sanitize_key($_POST['bulk_action']??'');if(!$action)wp_die('Не выбрано действие.');
        $scope=sanitize_key($_POST['selection_scope']??'page');$filters_raw=json_decode(wp_unslash($_POST['filters_json']??''),true);$filters=LV_Contact_Query::sanitize_filters(is_array($filters_raw)?$filters_raw:array());
        if('filtered'===$scope){$ids=(new LV_Contact_Query($filters))->ids();}else{$ids=array_values(array_unique(array_filter(array_map('absint',explode(',',sanitize_text_field(wp_unslash($_POST['selected_ids']??'')))))));}
        if(!$ids)wp_die('Не выбраны контакты.');$selected=count($ids);$changed=0;$skipped=0;global$wpdb;$now=current_time('mysql');
        $target_curator=null;$tag_id=0;$tag_mode='';
        if(0===strpos($action,'curator_')){$target_curator=absint(substr($action,8));if(!LV_Applications_Plugin::is_manager())$target_curator=null;if($target_curator&&!LV_Applications_Plugin::eligible_assignee_public($target_curator))$target_curator=null;}
        if(preg_match('/^tag_(add|remove)_(\d+)$/',$action,$m)){$tag_mode=$m[1];$tag_id=absint($m[2]);$allowed=array_map('intval',wp_list_pluck(self::get_tags('contact'),'id'));if(!in_array($tag_id,$allowed,true)){$tag_id=0;$tag_mode='';}}
        $trashed_ids=array();
        foreach($ids as$id){$contact=self::get_contact($id);if(!$contact){$skipped++;continue;}
            if('trash'===$action){if(!self::can_trash_contact($contact)){$skipped++;continue;}self::contact_log($id,'trashed','Контакт перемещён в корзину массовым действием.');$ok=$wpdb->update(self::contacts_table(),array('deleted_at'=>$now,'deleted_by'=>get_current_user_id(),'updated_at'=>$now),array('id'=>$id),array('%s','%d','%s'),array('%d'));if(false!==$ok){$changed++;$trashed_ids[]=$id;}else$skipped++;continue;}
            if('restore'===$action){if(!current_user_can('lv_restore_contacts')||!self::is_contact_deleted($contact)){$skipped++;continue;}$ok=$wpdb->update(self::contacts_table(),array('deleted_at'=>null,'deleted_by'=>0,'updated_at'=>$now,'last_activity_at'=>$now),array('id'=>$id),array('%s','%d','%s','%s'),array('%d'));if(false!==$ok){self::contact_log($id,'restored','Контакт восстановлен массовым действием.');$changed++;}else$skipped++;continue;}
            if('purge'===$action){if(!current_user_can('lv_purge_contacts')||!self::is_contact_deleted($contact)){$skipped++;continue;}if(self::purge_contact_record($id))$changed++;else$skipped++;continue;}
            if(null!==$target_curator&&0===strpos($action,'curator_')){if(!LV_Applications_Plugin::is_manager()||self::is_contact_deleted($contact)){$skipped++;continue;}$old=(int)$contact->curator_user_id;if($old===$target_curator){$skipped++;continue;}$ok=$wpdb->update(self::contacts_table(),array('curator_user_id'=>$target_curator,'updated_at'=>$now,'last_activity_at'=>$now),array('id'=>$id),array('%d','%s','%s'),array('%d'));if($ok>0){self::contact_log($id,'curator_changed','Куратор изменён массовым действием: '.($old?LV_Applications_Plugin::assignee_name($old):'не назначен').' → '.($target_curator?LV_Applications_Plugin::assignee_name($target_curator):'не назначен').'.');$changed++;}else$skipped++;continue;}
            if($tag_id&&$tag_mode){if(!self::can_edit_contact($contact)){$skipped++;continue;}if('add'===$tag_mode){$ok=$wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.self::contact_tags_table().' (contact_id,tag_id) VALUES (%d,%d)',$id,$tag_id));}else{$ok=$wpdb->delete(self::contact_tags_table(),array('contact_id'=>$id,'tag_id'=>$tag_id),array('%d','%d'));}if((int)$ok>0){self::contact_log($id,'tags_changed','Метки контакта изменены массовым действием.');$changed++;}else$skipped++;continue;}
            $skipped++;
        }
        if(class_exists('LV_Request_Cache'))LV_Request_Cache::flush();$return=self::safe_admin_return_url(wp_unslash($_POST['return_to']??''));if(!$return)$return=self::contact_list_url($filters,1,0);
        $args=array('bulk_selected'=>$selected,'bulk_changed'=>$changed,'bulk_skipped'=>$skipped);
        if('trash'===$action){$token=self::store_contact_undo_batch($trashed_ids,$return);if($token)$args['undo_token']=$token;}
        elseif('restore'===$action){$return=remove_query_arg('view',$return);}
        elseif('purge'===$action){$return=add_query_arg('view','trash',$return);}
        wp_safe_redirect(add_query_arg($args,$return));exit;
    }

}
