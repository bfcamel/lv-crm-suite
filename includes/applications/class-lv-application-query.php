<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read/query layer for applications. All public forms use stable CRM contracts;
 * this class owns filtered list reads, counts and list hydration.
 */
final class LV_Application_Query {
    private static function prepare( $sql, $params ) {
        global $wpdb;
        return $params ? $wpdb->prepare( $sql, $params ) : $sql;
    }

    public static function get( $filters, $limit = 25, $offset = 0 ) {
        global $wpdb;
        $params = array();
        $where = LV_Applications_Plugin::build_where( $filters, $params );
        $order = isset( $filters['order'] ) && 'ASC' === $filters['order'] ? 'ASC' : 'DESC';
        $orderby = isset( $filters['orderby'] ) ? $filters['orderby'] : 'date';
        $order_column = 'submitted_at';

        if ( 'form' === $orderby ) {
            $order_column = 'form_title';
        } elseif ( 'status' === $orderby ) {
            $order_column = 'processed';
        } elseif ( 'assignee' === $orderby ) {
            $order_column = 'assignee_id';
        }

        if ( 'priority' === $orderby ) {
            $priority_order = 'ASC' === $order
                ? "FIELD(priority,'low','normal','high','urgent')"
                : "FIELD(priority,'urgent','high','normal','low')";
            $sql = 'SELECT * FROM ' . LV_Applications_Plugin::table_name() . " WHERE {$where} ORDER BY {$priority_order} ASC, submitted_at DESC, id DESC";
        } else {
            $sql = 'SELECT * FROM ' . LV_Applications_Plugin::table_name() . " WHERE {$where} ORDER BY {$order_column} {$order}, submitted_at DESC, id DESC";
        }

        if ( $limit > 0 ) {
            $sql .= ' LIMIT %d OFFSET %d';
            $params[] = absint( $limit );
            $params[] = absint( $offset );
        }

        $rows = $wpdb->get_results( self::prepare( $sql, $params ) );
        return self::hydrate_rows( $rows );
    }


    public static function ids( $filters, $limit = 0, $offset = 0 ) {
        global $wpdb;
        $params = array();
        $where = LV_Applications_Plugin::build_where( $filters, $params );
        $order = isset( $filters['order'] ) && 'ASC' === $filters['order'] ? 'ASC' : 'DESC';
        $orderby = isset( $filters['orderby'] ) ? $filters['orderby'] : 'date';
        $order_column = 'submitted_at';
        if ( 'form' === $orderby ) $order_column = 'form_title';
        elseif ( 'status' === $orderby ) $order_column = 'processed';
        elseif ( 'assignee' === $orderby ) $order_column = 'assignee_id';

        if ( 'priority' === $orderby ) {
            $priority_order = 'ASC' === $order
                ? "FIELD(priority,'low','normal','high','urgent')"
                : "FIELD(priority,'urgent','high','normal','low')";
            $sql = 'SELECT id FROM ' . LV_Applications_Plugin::table_name() . " WHERE {$where} ORDER BY {$priority_order} ASC, submitted_at DESC, id DESC";
        } else {
            $sql = 'SELECT id FROM ' . LV_Applications_Plugin::table_name() . " WHERE {$where} ORDER BY {$order_column} {$order}, submitted_at DESC, id DESC";
        }
        if ( $limit > 0 ) {
            $sql .= ' LIMIT %d OFFSET %d';
            $params[] = absint( $limit );
            $params[] = absint( $offset );
        }
        return array_map( 'intval', $wpdb->get_col( self::prepare( $sql, $params ) ) );
    }

    public static function count( $filters ) {
        global $wpdb;
        $params = array();
        $where = LV_Applications_Plugin::build_where( $filters, $params );
        $sql = 'SELECT COUNT(*) FROM ' . LV_Applications_Plugin::table_name() . " WHERE {$where}";
        return (int) $wpdb->get_var( self::prepare( $sql, $params ) );
    }

    public static function summary( $filters ) {
        global $wpdb;
        $base = $filters;
        $base['status'] = '';
        $params = array();
        $where = LV_Applications_Plugin::build_where( $base, $params );
        $sql = 'SELECT COUNT(*) total, '
            . 'COALESCE(SUM(CASE WHEN processed=1 THEN 1 ELSE 0 END),0) processed, '
            . 'COALESCE(SUM(CASE WHEN processed=0 THEN 1 ELSE 0 END),0) unprocessed '
            . 'FROM ' . LV_Applications_Plugin::table_name() . " WHERE {$where}";
        $row = $wpdb->get_row( self::prepare( $sql, $params ) );
        return array(
            'total' => $row ? (int) $row->total : 0,
            'processed' => $row ? (int) $row->processed : 0,
            'unprocessed' => $row ? (int) $row->unprocessed : 0,
        );
    }

    public static function ownership_counts( $filters, $user_id ) {
        global $wpdb;
        $base = $filters;
        $base['status'] = '';
        $base['assignee'] = '';
        $params = array();
        $where = LV_Applications_Plugin::build_where( $base, $params );
        $sql = 'SELECT '
            . 'COALESCE(SUM(CASE WHEN assignee_id=%d THEN 1 ELSE 0 END),0) mine, '
            . 'COALESCE(SUM(CASE WHEN assignee_id=0 THEN 1 ELSE 0 END),0) unassigned '
            . 'FROM ' . LV_Applications_Plugin::table_name() . " WHERE {$where}";
        array_unshift( $params, absint( $user_id ) );
        $row = $wpdb->get_row( self::prepare( $sql, $params ) );
        return array(
            'mine' => $row ? (int) $row->mine : 0,
            'unassigned' => $row ? (int) $row->unassigned : 0,
        );
    }

    public static function group_counts( $filters, $dimension, $limit = 0 ) {
        global $wpdb;
        $base = $filters;
        $base['status'] = '';
        $params = array();
        $where = LV_Applications_Plugin::build_where( $base, $params );
        if ( 'assignee' === $dimension ) {
            $sql = 'SELECT assignee_id group_key, COUNT(*) total FROM ' . LV_Applications_Plugin::table_name() . " WHERE {$where} GROUP BY assignee_id ORDER BY total DESC";
        } else {
            $sql = 'SELECT form_title group_key, COUNT(*) total FROM ' . LV_Applications_Plugin::table_name() . " WHERE {$where} GROUP BY form_title ORDER BY total DESC";
        }
        if ( $limit > 0 ) {
            $sql .= ' LIMIT %d';
            $params[] = absint( $limit );
        }
        $rows = $wpdb->get_results( self::prepare( $sql, $params ) );
        $out = array();
        foreach ( (array) $rows as $row ) {
            $key = 'assignee' === $dimension ? (int) $row->group_key : ( (string) $row->group_key ?: 'Без названия' );
            $out[ $key ] = (int) $row->total;
        }
        return $out;
    }

    /**
     * Adds parsed fields, primary fields, files, tags and linked contacts in a fixed
     * number of queries. The rendered list therefore does not grow DB queries per row.
     */
    public static function hydrate_rows( $rows ) {
        global $wpdb;
        if ( ! $rows ) {
            return array();
        }

        $ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'id' ) ) ) );
        if ( ! $ids ) {
            return $rows;
        }

        $tag_map = array();
        $contact_map = array();

        if ( class_exists( 'LV_CRM_Extensions' ) ) {
            // Keep IN() clauses bounded for large exports while retaining a fixed
            // query count per batch instead of per rendered row.
            foreach ( array_chunk( $ids, 300 ) as $chunk ) {
                $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
                $tag_rows = $wpdb->get_results( $wpdb->prepare(
                    'SELECT x.application_id,t.* FROM ' . LV_CRM_Extensions::application_tags_table() . ' x '
                    . 'INNER JOIN ' . LV_CRM_Extensions::tags_table() . ' t ON t.id=x.tag_id '
                    . "WHERE x.application_id IN ({$placeholders}) ORDER BY t.name",
                    $chunk
                ) );
                foreach ( (array) $tag_rows as $tag ) {
                    $tag_map[ (int) $tag->application_id ][] = $tag;
                }

                $contact_rows = $wpdb->get_results( $wpdb->prepare(
                    'SELECT l.application_id,c.id,c.display_name,c.contact_type,c.curator_user_id,l.relation_type,l.is_primary '
                    . 'FROM ' . LV_CRM_Extensions::application_contacts_table() . ' l '
                    . 'INNER JOIN ' . LV_CRM_Extensions::contacts_table() . ' c ON c.id=l.contact_id AND c.deleted_at IS NULL '
                    . "WHERE l.application_id IN ({$placeholders}) ORDER BY l.application_id,l.is_primary DESC,c.display_name",
                    $chunk
                ) );
                foreach ( (array) $contact_rows as $contact ) {
                    $contact_map[ (int) $contact->application_id ][] = $contact;
                }
            }
        }

        foreach ( $rows as $row ) {
            $row->lv_fields = LV_Applications_Plugin::decode_fields( $row->fields_json );
            $row->lv_visible_fields = self::visible_fields_from_parsed( $row->lv_fields );
            $row->lv_files = LV_Applications_Plugin::decode_files_raw( $row->files_json );
            $row->lv_file_count = count( $row->lv_files );
            $row->lv_tags = isset( $tag_map[ (int) $row->id ] ) ? $tag_map[ (int) $row->id ] : array();
            $row->lv_contacts = isset( $contact_map[ (int) $row->id ] ) ? $contact_map[ (int) $row->id ] : array();
            $row->lv_primary_fields = LV_Applications_Plugin::primary_fields_from_visible( $row, $row->lv_visible_fields );
            $row->lv_heading = LV_Applications_Plugin::application_heading_from_primary( $row, $row->lv_primary_fields );
        }
        return $rows;
    }

    private static function visible_fields_from_parsed( $fields ) {
        $visible = array();
        foreach ( (array) $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $name = isset( $field['name'] ) ? (string) $field['name'] : '';
            if ( LV_Applications_Plugin::is_service_field( $name ) ) {
                continue;
            }
            $visible[] = $field;
        }
        return $visible;
    }
}
