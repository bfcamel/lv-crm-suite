<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared query layer for CRM contacts.
 * The list, counters, segments and exports must all use this class so the
 * same filter definition always yields the same set of contacts.
 */
final class LV_Contact_Query {
    private $filters = array();

    public function __construct( $filters = array() ) {
        $this->filters = self::sanitize_filters( $filters );
    }

    public static function defaults() {
        return array(
            'search' => '',
            'types' => array(),
            'curator' => '',
            'tags_include' => array(),
            'tags_mode' => 'any',
            'tags_exclude' => array(),
            'email_state' => '',
            'phone_state' => '',
            'consent_status' => '',
            'created_from' => '',
            'created_to' => '',
            'updated_from' => '',
            'updated_to' => '',
            'has_applications' => '',
            'applications_min' => '',
            'applications_max' => '',
            'segment_id' => 0,
            'view' => 'active',
            'orderby' => 'updated',
            'order' => 'DESC',
        );
    }

    public static function sanitize_filters( $input ) {
        $input = is_array( $input ) ? $input : array();
        $out = self::defaults();
        $out['search'] = sanitize_text_field( wp_unslash( isset( $input['search'] ) ? $input['search'] : ( isset( $input['s'] ) ? $input['s'] : '' ) ) );

        $types = isset( $input['types'] ) ? (array) $input['types'] : ( isset( $input['type'] ) ? (array) $input['type'] : array() );
        $out['types'] = array_values( array_intersect( array_map( 'sanitize_key', $types ), array( 'person', 'organization' ) ) );

        $curator = sanitize_text_field( wp_unslash( $input['curator'] ?? '' ) );
        if ( in_array( $curator, array( '', 'mine', 'none' ), true ) || ctype_digit( $curator ) ) {
            $out['curator'] = $curator;
        }

        $include = isset( $input['tags_include'] ) ? (array) $input['tags_include'] : ( isset( $input['tags'] ) ? (array) $input['tags'] : ( isset( $input['tag'] ) && $input['tag'] ? array( $input['tag'] ) : array() ) );
        $out['tags_include'] = array_values( array_unique( array_filter( array_map( 'absint', $include ) ) ) );
        $mode = sanitize_key( $input['tags_mode'] ?? 'any' );
        $out['tags_mode'] = in_array( $mode, array( 'any', 'all' ), true ) ? $mode : 'any';
        $exclude = isset( $input['tags_exclude'] ) ? (array) $input['tags_exclude'] : ( isset( $input['exclude_tags'] ) ? (array) $input['exclude_tags'] : array() );
        $out['tags_exclude'] = array_values( array_unique( array_filter( array_map( 'absint', $exclude ) ) ) );

        $email_state = sanitize_key( $input['email_state'] ?? '' );
        $out['email_state'] = in_array( $email_state, array( '', 'has', 'none', 'valid', 'multiple' ), true ) ? $email_state : '';
        $phone_state = sanitize_key( $input['phone_state'] ?? '' );
        $out['phone_state'] = in_array( $phone_state, array( '', 'has', 'none' ), true ) ? $phone_state : '';
        $consent = sanitize_key( $input['consent_status'] ?? '' );
        $out['consent_status'] = in_array( $consent, array( '', 'granted', 'unknown', 'revoked' ), true ) ? $consent : '';

        foreach ( array( 'created_from', 'created_to', 'updated_from', 'updated_to' ) as $key ) {
            $raw = sanitize_text_field( wp_unslash( $input[ $key ] ?? '' ) );
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) $out[ $key ] = $raw;
        }

        $has_apps = sanitize_key( $input['has_applications'] ?? '' );
        $out['has_applications'] = in_array( $has_apps, array( '', 'yes', 'no' ), true ) ? $has_apps : '';
        foreach ( array( 'applications_min', 'applications_max' ) as $key ) {
            if ( '' !== (string) ( $input[ $key ] ?? '' ) && is_numeric( $input[ $key ] ) ) $out[ $key ] = max( 0, absint( $input[ $key ] ) );
        }
        $out['segment_id'] = absint( $input['segment_id'] ?? ( $input['segment'] ?? 0 ) );
        $view = sanitize_key( $input['view'] ?? 'active' );
        $out['view'] = in_array( $view, array( 'active', 'trash' ), true ) ? $view : 'active';
        $orderby = sanitize_key( $input['orderby'] ?? 'updated' );
        $out['orderby'] = in_array( $orderby, array( 'updated', 'name', 'curator', 'applications', 'last_application' ), true ) ? $orderby : 'updated';
        $out['order'] = isset( $input['order'] ) && 'asc' === strtolower( sanitize_key( (string) $input['order'] ) ) ? 'ASC' : 'DESC';
        return $out;
    }

    public function filters() { return $this->filters; }

    public function has_active_filters() {
        $f = $this->filters;
        unset( $f['segment_id'], $f['view'], $f['orderby'], $f['order'] );
        foreach ( $f as $key => $value ) {
            if ( is_array( $value ) && $value ) return true;
            if ( ! is_array( $value ) && '' !== (string) $value && ! ( 'tags_mode' === $key && 'any' === $value ) ) return true;
        }
        return false;
    }

    private function sql_parts() {
        global $wpdb;
        $c = LV_CRM_Extensions::contacts_table();
        $emails = LV_CRM_Extensions::contact_emails_table();
        $phones = LV_CRM_Extensions::contact_phones_table();
        $fields = LV_CRM_Extensions::contact_fields_table();
        $ct = LV_CRM_Extensions::contact_tags_table();
        $links = LV_CRM_Extensions::application_contacts_table();
        $consents = LV_Consent_Service::table_name();

        $where = array( '1=1' );
        $params = array();
        $f = $this->filters;
        $where[] = ( 'trash' === $f['view'] ) ? 'c.deleted_at IS NOT NULL' : 'c.deleted_at IS NULL';

        if ( $f['search'] ) {
            $like = '%' . $wpdb->esc_like( $f['search'] ) . '%';
            $digits = LV_CRM_Extensions::normalize_phone( $f['search'] );
            if ( strlen( $digits ) < 5 ) $digits = '';
            $parts = array(
                'c.display_name LIKE %s',
                'c.organization LIKE %s',
                "EXISTS (SELECT 1 FROM {$emails} qe WHERE qe.contact_id=c.id AND qe.value LIKE %s)",
                "EXISTS (SELECT 1 FROM {$fields} qf WHERE qf.contact_id=c.id AND (qf.field_label LIKE %s OR qf.field_value LIKE %s))",
            );
            array_push( $params, $like, $like, $like, $like, $like );
            if ( $digits ) {
                $parts[] = "EXISTS (SELECT 1 FROM {$phones} qp WHERE qp.contact_id=c.id AND qp.normalized LIKE %s)";
                $params[] = '%' . $wpdb->esc_like( $digits ) . '%';
            }
            if ( ctype_digit( ltrim( $f['search'], '#' ) ) ) {
                $parts[] = 'c.id=%d';
                $params[] = absint( ltrim( $f['search'], '#' ) );
            }
            $where[] = '(' . implode( ' OR ', $parts ) . ')';
        }

        if ( $f['types'] ) {
            $ph = implode( ',', array_fill( 0, count( $f['types'] ), '%s' ) );
            $where[] = "c.contact_type IN ({$ph})";
            foreach ( $f['types'] as $type ) $params[] = $type;
        }

        if ( 'mine' === $f['curator'] ) {
            $where[] = 'c.curator_user_id=%d'; $params[] = get_current_user_id();
        } elseif ( 'none' === $f['curator'] ) {
            $where[] = 'c.curator_user_id=0';
        } elseif ( ctype_digit( (string) $f['curator'] ) ) {
            $where[] = 'c.curator_user_id=%d'; $params[] = absint( $f['curator'] );
        }

        if ( $f['tags_include'] ) {
            if ( 'all' === $f['tags_mode'] ) {
                foreach ( $f['tags_include'] as $tag_id ) {
                    $where[] = "EXISTS (SELECT 1 FROM {$ct} ti WHERE ti.contact_id=c.id AND ti.tag_id=%d)";
                    $params[] = $tag_id;
                }
            } else {
                $ph = implode( ',', array_fill( 0, count( $f['tags_include'] ), '%d' ) );
                $where[] = "EXISTS (SELECT 1 FROM {$ct} ti WHERE ti.contact_id=c.id AND ti.tag_id IN ({$ph}))";
                foreach ( $f['tags_include'] as $tag_id ) $params[] = $tag_id;
            }
        }
        if ( $f['tags_exclude'] ) {
            $ph = implode( ',', array_fill( 0, count( $f['tags_exclude'] ), '%d' ) );
            $where[] = "NOT EXISTS (SELECT 1 FROM {$ct} tx WHERE tx.contact_id=c.id AND tx.tag_id IN ({$ph}))";
            foreach ( $f['tags_exclude'] as $tag_id ) $params[] = $tag_id;
        }

        if ( 'has' === $f['email_state'] ) {
            $where[] = "EXISTS (SELECT 1 FROM {$emails} ee WHERE ee.contact_id=c.id AND ee.normalized<>'')";
        } elseif ( 'none' === $f['email_state'] ) {
            $where[] = "NOT EXISTS (SELECT 1 FROM {$emails} ee WHERE ee.contact_id=c.id AND ee.normalized<>'')";
        } elseif ( 'valid' === $f['email_state'] ) {
            $where[] = "EXISTS (SELECT 1 FROM {$emails} ee WHERE ee.contact_id=c.id AND ee.normalized REGEXP '^[^@[:space:]]+@[^@[:space:]]+\\.[^@[:space:]]+$')";
        } elseif ( 'multiple' === $f['email_state'] ) {
            $where[] = "(SELECT COUNT(*) FROM {$emails} ee WHERE ee.contact_id=c.id AND ee.normalized<>'') > 1";
        }

        if ( 'has' === $f['phone_state'] ) {
            $where[] = "EXISTS (SELECT 1 FROM {$phones} pp WHERE pp.contact_id=c.id AND pp.normalized<>'')";
        } elseif ( 'none' === $f['phone_state'] ) {
            $where[] = "NOT EXISTS (SELECT 1 FROM {$phones} pp WHERE pp.contact_id=c.id AND pp.normalized<>'')";
        }

        if ( 'granted' === $f['consent_status'] ) {
            $where[] = "(EXISTS (SELECT 1 FROM {$consents} cs WHERE cs.contact_id=c.id AND cs.channel='contact' AND cs.consent_type='marketing' AND cs.status='granted') OR (NOT EXISTS (SELECT 1 FROM {$consents} cg WHERE cg.contact_id=c.id AND cg.channel='contact' AND cg.consent_type='marketing') AND EXISTS (SELECT 1 FROM {$consents} ce WHERE ce.contact_id=c.id AND ce.channel='email' AND ce.status='granted')))";
        } elseif ( 'revoked' === $f['consent_status'] ) {
            $where[] = "(EXISTS (SELECT 1 FROM {$consents} cs WHERE cs.contact_id=c.id AND cs.channel='contact' AND cs.consent_type='marketing' AND cs.status='revoked') OR (NOT EXISTS (SELECT 1 FROM {$consents} cg WHERE cg.contact_id=c.id AND cg.channel='contact' AND cg.consent_type='marketing') AND EXISTS (SELECT 1 FROM {$consents} ce WHERE ce.contact_id=c.id AND ce.channel='email' AND ce.status='revoked')))";
        } elseif ( 'unknown' === $f['consent_status'] ) {
            $where[] = "NOT EXISTS (SELECT 1 FROM {$consents} cs WHERE cs.contact_id=c.id AND ((cs.channel='contact' AND cs.consent_type='marketing' AND cs.status IN ('granted','revoked')) OR (cs.channel='email' AND cs.status IN ('granted','revoked'))))";
        }

        if ( $f['created_from'] ) { $where[] = 'c.created_at >= %s'; $params[] = $f['created_from'] . ' 00:00:00'; }
        if ( $f['created_to'] ) { $where[] = 'c.created_at <= %s'; $params[] = $f['created_to'] . ' 23:59:59'; }
        if ( $f['updated_from'] ) { $where[] = 'c.updated_at >= %s'; $params[] = $f['updated_from'] . ' 00:00:00'; }
        if ( $f['updated_to'] ) { $where[] = 'c.updated_at <= %s'; $params[] = $f['updated_to'] . ' 23:59:59'; }

        if ( 'yes' === $f['has_applications'] ) $where[] = "EXISTS (SELECT 1 FROM {$links} la WHERE la.contact_id=c.id)";
        elseif ( 'no' === $f['has_applications'] ) $where[] = "NOT EXISTS (SELECT 1 FROM {$links} la WHERE la.contact_id=c.id)";
        if ( '' !== (string) $f['applications_min'] ) { $where[] = "(SELECT COUNT(*) FROM {$links} la WHERE la.contact_id=c.id) >= %d"; $params[] = absint( $f['applications_min'] ); }
        if ( '' !== (string) $f['applications_max'] ) { $where[] = "(SELECT COUNT(*) FROM {$links} la WHERE la.contact_id=c.id) <= %d"; $params[] = absint( $f['applications_max'] ); }

        return array( 'table' => $c, 'where' => implode( ' AND ', $where ), 'params' => $params );
    }

    private function prepare( $sql, $params ) {
        global $wpdb;
        return $params ? $wpdb->prepare( $sql, $params ) : $sql;
    }

    public function count() {
        global $wpdb;
        $p = $this->sql_parts();
        return (int) $wpdb->get_var( $this->prepare( "SELECT COUNT(*) FROM {$p['table']} c WHERE {$p['where']}", $p['params'] ) );
    }

    private function order_sql() {
        $f = $this->filters;
        $direction = 'ASC' === $f['order'] ? 'ASC' : 'DESC';
        $links = LV_CRM_Extensions::application_contacts_table();
        $apps = LV_Applications_Plugin::table_name();
        switch ( $f['orderby'] ) {
            case 'name':
                return "c.display_name {$direction}, c.id {$direction}";
            case 'curator':
                return "c.curator_user_id {$direction}, c.display_name ASC, c.id DESC";
            case 'applications':
                return "(SELECT COUNT(*) FROM {$links} qla WHERE qla.contact_id=c.id) {$direction}, c.updated_at DESC, c.id DESC";
            case 'last_application':
                return "(SELECT MAX(qa.submitted_at) FROM {$links} qll INNER JOIN {$apps} qa ON qa.id=qll.application_id WHERE qll.contact_id=c.id) {$direction}, c.updated_at DESC, c.id DESC";
            case 'updated':
            default:
                return "c.updated_at {$direction}, c.id {$direction}";
        }
    }

    public function ids( $limit = 0, $offset = 0 ) {
        global $wpdb;
        $p = $this->sql_parts();
        $sql = "SELECT c.id FROM {$p['table']} c WHERE {$p['where']} ORDER BY " . $this->order_sql();
        $params = $p['params'];
        if ( $limit > 0 ) { $sql .= ' LIMIT %d OFFSET %d'; $params[] = absint( $limit ); $params[] = absint( $offset ); }
        return array_map( 'intval', $wpdb->get_col( $this->prepare( $sql, $params ) ) );
    }

    public function get( $limit = 30, $offset = 0 ) {
        global $wpdb;
        $p = $this->sql_parts();
        $sql = "SELECT c.* FROM {$p['table']} c WHERE {$p['where']} ORDER BY " . $this->order_sql();
        $params = $p['params'];
        if ( $limit > 0 ) { $sql .= ' LIMIT %d OFFSET %d'; $params[] = absint( $limit ); $params[] = absint( $offset ); }
        $rows = $wpdb->get_results( $this->prepare( $sql, $params ) );
        return self::hydrate_rows( $rows );
    }

    /**
     * Hydrates all related list data in a fixed number of queries, eliminating
     * the per-contact N+1 pattern of older releases.
     */
    public static function hydrate_rows( $rows ) {
        global $wpdb;
        if ( ! $rows ) return array();
        $ids = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
        $in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $emails = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LV_CRM_Extensions::contact_emails_table() . " WHERE contact_id IN ({$in}) ORDER BY contact_id,is_primary DESC,id ASC", $ids ) );
        $phones = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LV_CRM_Extensions::contact_phones_table() . " WHERE contact_id IN ({$in}) ORDER BY contact_id,is_primary DESC,id ASC", $ids ) );
        $tag_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT x.contact_id,t.* FROM ' . LV_CRM_Extensions::contact_tags_table() . ' x INNER JOIN ' . LV_CRM_Extensions::tags_table() . " t ON t.id=x.tag_id WHERE x.contact_id IN ({$in}) ORDER BY t.name", $ids ) );
        $app_counts = $wpdb->get_results( $wpdb->prepare( 'SELECT contact_id,COUNT(*) cnt,MAX(a.submitted_at) last_application_at FROM ' . LV_CRM_Extensions::application_contacts_table() . ' l INNER JOIN ' . LV_Applications_Plugin::table_name() . " a ON a.id=l.application_id WHERE l.contact_id IN ({$in}) GROUP BY contact_id", $ids ), OBJECT_K );
        $consent_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LV_Consent_Service::table_name() . " WHERE channel='email' AND contact_id IN ({$in})", $ids ) );
        $generic_consent_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LV_Consent_Service::table_name() . " WHERE channel='contact' AND consent_type IN ('personal_data','marketing') AND contact_id IN ({$in})", $ids ) );

        $email_map = $phone_map = $tag_map = $consent_map = $consent_email_map = $generic_consent_map = array();
        foreach ( $emails as $r ) $email_map[ (int) $r->contact_id ][] = $r;
        foreach ( $phones as $r ) $phone_map[ (int) $r->contact_id ][] = $r;
        foreach ( $tag_rows as $r ) $tag_map[ (int) $r->contact_id ][] = $r;
        foreach ( $consent_rows as $r ) { $consent_map[ (int) $r->contact_id ][ $r->status ] = ( $consent_map[ (int) $r->contact_id ][ $r->status ] ?? 0 ) + 1; $consent_email_map[ (int) $r->contact_email_id ] = $r; }
        foreach ( $generic_consent_rows as $r ) $generic_consent_map[ (int) $r->contact_id ][ $r->consent_type ] = $r;
        foreach ( $email_map as $contact_id => $contact_emails ) foreach ( $contact_emails as $email ) $email->lv_consent = $consent_email_map[ (int) $email->id ] ?? null;

        foreach ( $rows as $row ) {
            $id = (int) $row->id;
            $row->lv_emails = $email_map[ $id ] ?? array();
            $row->lv_phones = $phone_map[ $id ] ?? array();
            $row->lv_tags = $tag_map[ $id ] ?? array();
            $row->lv_application_count = isset( $app_counts[ $id ] ) ? (int) $app_counts[ $id ]->cnt : 0;
            $row->lv_last_application_at = isset( $app_counts[ $id ] ) ? $app_counts[ $id ]->last_application_at : null;
            $row->lv_generic_consents = $generic_consent_map[ $id ] ?? array();
            $row->lv_marketing_consent = $row->lv_generic_consents['marketing'] ?? null;
            $row->lv_personal_data_consent = $row->lv_generic_consents['personal_data'] ?? null;
            $row->lv_consent_summary = $row->lv_marketing_consent ? array( $row->lv_marketing_consent->status => 1 ) : ( $consent_map[ $id ] ?? array() );
        }
        return $rows;
    }

    public static function query_args_from_filters( $filters ) {
        $f = self::sanitize_filters( $filters );
        $args = array();
        if ( $f['search'] ) $args['s'] = $f['search'];
        if ( $f['types'] ) $args['type'] = $f['types'];
        if ( $f['curator'] ) $args['curator'] = $f['curator'];
        if ( $f['tags_include'] ) $args['tags'] = $f['tags_include'];
        if ( 'any' !== $f['tags_mode'] ) $args['tags_mode'] = $f['tags_mode'];
        if ( $f['tags_exclude'] ) $args['exclude_tags'] = $f['tags_exclude'];
        foreach ( array( 'email_state','phone_state','consent_status','created_from','created_to','updated_from','updated_to','has_applications','applications_min','applications_max' ) as $key ) {
            if ( '' !== (string) $f[ $key ] ) $args[ $key ] = $f[ $key ];
        }
        if ( $f['segment_id'] ) $args['segment'] = $f['segment_id'];
        if ( 'trash' === $f['view'] ) $args['view'] = 'trash';
        if ( 'updated' !== $f['orderby'] ) $args['orderby'] = $f['orderby'];
        if ( 'ASC' === $f['order'] ) $args['order'] = 'asc';
        return $args;
    }
}
