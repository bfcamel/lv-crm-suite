<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Batch load calculator used by smart routing and workload transfer. */
final class LV_Routing_Load_Provider {
    public static function load( $user_ids, $form_ids = array() ) {
        global $wpdb;
        $user_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $user_ids ) ) ) );
        $form_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $form_ids ) ) ) );
        $result = array();
        foreach ( $user_ids as $uid ) {
            $result[ $uid ] = array( 'unprocessed' => 0, 'active' => 0, 'forms' => array() );
            foreach ( $form_ids as $fid ) {
                $result[ $uid ]['forms'][ $fid ] = 0;
            }
        }
        if ( ! $user_ids ) {
            return $result;
        }

        $in_users = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
        $since = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 30 * DAY_IN_SECONDS ) );
        $args = array_merge( array( $since ), $user_ids );
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT assignee_id, '
            . 'SUM(CASE WHEN processed=0 THEN 1 ELSE 0 END) unprocessed, '
            . 'SUM(CASE WHEN submitted_at>=%s THEN 1 ELSE 0 END) active '
            . 'FROM ' . LV_Applications_Plugin::table_name()
            . " WHERE deleted_at IS NULL AND assignee_id IN ({$in_users}) GROUP BY assignee_id",
            $args
        ) );
        foreach ( (array) $rows as $row ) {
            $uid = (int) $row->assignee_id;
            if ( isset( $result[ $uid ] ) ) {
                $result[ $uid ]['unprocessed'] = (int) $row->unprocessed;
                $result[ $uid ]['active'] = (int) $row->active;
            }
        }

        if ( $form_ids ) {
            $in_forms = implode( ',', array_fill( 0, count( $form_ids ), '%d' ) );
            $args = array_merge( $user_ids, $form_ids );
            $form_rows = $wpdb->get_results( $wpdb->prepare(
                'SELECT assignee_id,form_id,COUNT(*) total FROM ' . LV_Applications_Plugin::table_name()
                . " WHERE deleted_at IS NULL AND processed=0 AND assignee_id IN ({$in_users}) AND form_id IN ({$in_forms}) GROUP BY assignee_id,form_id",
                $args
            ) );
            foreach ( (array) $form_rows as $row ) {
                $uid = (int) $row->assignee_id;
                $fid = (int) $row->form_id;
                if ( isset( $result[ $uid ] ) ) {
                    $result[ $uid ]['forms'][ $fid ] = (int) $row->total;
                }
            }
        }
        return $result;
    }

    public static function for_form( $matrix, $form_id ) {
        $out = array();
        foreach ( (array) $matrix as $uid => $load ) {
            $out[ (int) $uid ] = array(
                'unprocessed' => isset( $load['unprocessed'] ) ? (int) $load['unprocessed'] : 0,
                'active' => isset( $load['active'] ) ? (int) $load['active'] : 0,
                'form' => isset( $load['forms'][ $form_id ] ) ? (int) $load['forms'][ $form_id ] : 0,
            );
        }
        return $out;
    }
}
