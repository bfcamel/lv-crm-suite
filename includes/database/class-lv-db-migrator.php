<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Patch-level migration coordinator. Schema cleanup and maintenance are run only
 * in the admin/activation context, never on a normal front-end request.
 */
final class LV_DB_Migrator {
    const PATCH_VERSION = '0.11.3';
    const PATCH_OPTION  = 'lv_crm_patch_version';
    const ACL_VERSION   = '3';
    const ACL_OPTION    = 'lv_crm_acl_version';
    const TAG_VERSION = '1';
    const TAG_OPTION = 'lv_crm_system_tags_version';
    const PHONE_VERSION = '1';
    const PHONE_OPTION = 'lv_crm_phone_normalization_version';
    const PHONE_CURSOR_OPTION = 'lv_crm_phone_normalization_cursor_v011';

    public static function maybe_upgrade() {
        if ( ! is_admin() ) return;
        self::ensure_acl();
        self::ensure_system_tags();
        self::ensure_phone_normalization();

        if ( self::PATCH_VERSION !== (string) get_option( self::PATCH_OPTION, '' ) ) {
            self::cleanup_obsolete_schema();
            if ( self::cleanup_orphans_batch() ) update_option( self::PATCH_OPTION, self::PATCH_VERSION, false );
        }
    }

    public static function on_activate() {
        self::ensure_acl( true );
        LV_Applications_Plugin::ensure_private_storage();
        self::ensure_system_tags( true );
        self::ensure_phone_normalization( true );
        self::cleanup_obsolete_schema();
        if ( self::cleanup_orphans_batch() ) update_option( self::PATCH_OPTION, self::PATCH_VERSION, false );
    }

    public static function ensure_acl( $force = false ) {
        if ( ! $force && self::ACL_VERSION === (string) get_option( self::ACL_OPTION, '' ) ) return;
        LV_Applications_Plugin::ensure_role_caps();
        update_option( self::ACL_OPTION, self::ACL_VERSION, false );
    }

    public static function ensure_system_tags( $force = false ) {
        if ( ! class_exists( 'LV_CRM_Extensions' ) ) return;
        if ( ! $force && self::TAG_VERSION === (string) get_option( self::TAG_OPTION, '' ) ) return;
        LV_CRM_Extensions::ensure_system_contact_tag( 'volunteer' );
        LV_CRM_Extensions::ensure_system_contact_tag( 'donor' );
        update_option( self::TAG_OPTION, self::TAG_VERSION, false );
    }

    public static function ensure_phone_normalization( $force = false ) {
        if ( ! class_exists( 'LV_CRM_Extensions' ) ) return true;
        if ( ! $force && self::PHONE_VERSION === (string) get_option( self::PHONE_OPTION, '' ) ) return true;
        global $wpdb;
        $table=LV_CRM_Extensions::contact_phones_table();
        $cursor=$force?0:absint(get_option(self::PHONE_CURSOR_OPTION,0));
        if($force)delete_option(self::PHONE_CURSOR_OPTION);
        $limit=500;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,value,normalized FROM {$table} WHERE id>%d ORDER BY id ASC LIMIT %d",$cursor,$limit));
        if(!$rows){delete_option(self::PHONE_CURSOR_OPTION);update_option(self::PHONE_OPTION,self::PHONE_VERSION,false);return true;}
        $last=$cursor;
        foreach((array)$rows as$row){
            $last=max($last,absint($row->id));
            $normalized=LV_CRM_Extensions::normalize_phone($row->value?:$row->normalized);
            if((string)$row->normalized!==$normalized)$wpdb->update($table,array('normalized'=>$normalized),array('id'=>absint($row->id)),array('%s'),array('%d'));
        }
        if(count($rows)<$limit){delete_option(self::PHONE_CURSOR_OPTION);update_option(self::PHONE_OPTION,self::PHONE_VERSION,false);return true;}
        update_option(self::PHONE_CURSOR_OPTION,$last,false);return false;
    }

    /**
     * Remove schema that belonged only to the retired pre-contract application
     * import path. There are no compatibility readers for this column anymore.
     */
    public static function cleanup_obsolete_schema() {
        global $wpdb;
        $apps = LV_Applications_Plugin::table_name();
        $column = $wpdb->get_var( "SHOW COLUMNS FROM {$apps} LIKE 'legacy_flamingo_id'" );
        if ( ! $column ) return;

        $index = $wpdb->get_var( "SHOW INDEX FROM {$apps} WHERE Key_name='legacy_flamingo_id'" );
        if ( $index ) $wpdb->query( "ALTER TABLE {$apps} DROP INDEX legacy_flamingo_id" );
        $wpdb->query( "ALTER TABLE {$apps} DROP COLUMN legacy_flamingo_id" );
    }

    public static function cleanup_orphans_batch( $limit = 500 ) {
        global $wpdb;
        $limit = max( 50, min( 2000, absint( $limit ) ) );
        $apps = LV_Applications_Plugin::table_name();
        $contacts = LV_CRM_Extensions::contacts_table();
        $at = LV_CRM_Extensions::application_tags_table();
        $ac = LV_CRM_Extensions::application_contacts_table();
        $ct = LV_CRM_Extensions::contact_tags_table();

        $deleted = array();
        $deleted[] = (int) $wpdb->query( "DELETE x FROM {$at} x LEFT JOIN {$apps} a ON a.id=x.application_id WHERE a.id IS NULL LIMIT {$limit}" );
        $deleted[] = (int) $wpdb->query( "DELETE x FROM {$ac} x LEFT JOIN {$apps} a ON a.id=x.application_id WHERE a.id IS NULL LIMIT {$limit}" );
        $deleted[] = (int) $wpdb->query( "DELETE x FROM {$ac} x LEFT JOIN {$contacts} c ON c.id=x.contact_id WHERE c.id IS NULL LIMIT {$limit}" );
        $deleted[] = (int) $wpdb->query( "DELETE x FROM {$ct} x LEFT JOIN {$contacts} c ON c.id=x.contact_id WHERE c.id IS NULL LIMIT {$limit}" );
        foreach ( $deleted as $count ) if ( $count >= $limit ) return false;
        return true;
    }
}
