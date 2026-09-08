<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Very small per-request cache. It deliberately does not persist between HTTP requests,
 * so there is no invalidation problem while repeated lookups inside one admin screen
 * stop hitting the database/WordPress user APIs.
 */
final class LV_Request_Cache {
    private static $data = array();

    public static function get( $key, $default = null ) {
        return array_key_exists( $key, self::$data ) ? self::$data[ $key ] : $default;
    }

    public static function has( $key ) {
        return array_key_exists( $key, self::$data );
    }

    public static function set( $key, $value ) {
        self::$data[ $key ] = $value;
        return $value;
    }

    public static function remember( $key, $callback ) {
        if ( self::has( $key ) ) {
            return self::$data[ $key ];
        }
        return self::set( $key, call_user_func( $callback ) );
    }

    public static function forget( $key ) {
        unset( self::$data[ $key ] );
    }

    public static function flush() {
        self::$data = array();
    }
}
