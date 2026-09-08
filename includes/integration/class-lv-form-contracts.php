<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Stable business contracts for public Contact Form 7 forms.
 *
 * CF7 post IDs are intentionally not used as business identifiers: they can
 * change when a form is copied or recreated.  The hidden form_code embedded in
 * the server-side CF7 template is the canonical identifier. The posted value is
 * used only for diagnostic mismatch warnings and can never activate a contract.
 */
final class LV_Form_Contracts {
    const SCHEMA_VERSION = '1';

    public static function all() {
        $contracts = array(
            'need_help' => array(
                'code' => 'need_help',
                'title' => 'Нужна помощь',
                'schema_version' => '1',
                'required' => array( 'contact_name', 'contact_phone', 'consent_personal_data' ),
                'contact_fields' => array(),
                'auto_tags' => array(),
                'marketing_channels' => array( 'email', 'phone', 'sms', 'messenger' ),
            ),
            'support_contact' => array(
                'code' => 'support_contact',
                'title' => 'Поддержать',
                'schema_version' => '1',
                'required' => array( 'contact_name', 'contact_phone', 'consent_personal_data' ),
                'contact_fields' => array(
                    'contact_social' => array( 'label' => 'Соцсеть / мессенджер', 'type' => 'text' ),
                    'support_interest' => array( 'label' => 'Интерес к поддержке', 'type' => 'text' ),
                ),
                'auto_tags' => array(), // Derived from support_interest.
                'marketing_channels' => array( 'email', 'phone', 'sms', 'messenger' ),
            ),
            'volunteer_application' => array(
                'code' => 'volunteer_application',
                'title' => 'Волонтёр',
                'schema_version' => '1',
                'required' => array( 'contact_name', 'contact_phone', 'volunteer_age', 'contact_email', 'consent_personal_data' ),
                'contact_fields' => array(
                    'contact_location' => array( 'label' => 'Город / регион', 'type' => 'text' ),
                    'volunteer_age' => array( 'label' => 'Возраст', 'type' => 'number' ),
                    'volunteer_skills' => array( 'label' => 'Навыки волонтёра', 'type' => 'checkbox' ),
                    'volunteer_format' => array( 'label' => 'Формат участия', 'type' => 'checkbox' ),
                ),
                'auto_tags' => array( 'volunteer' ),
                'marketing_channels' => array( 'email', 'phone', 'sms', 'messenger' ),
            ),
        );
        return (array) apply_filters( 'lv_crm_form_contracts', $contracts );
    }

    public static function get( $code ) {
        $code = sanitize_key( (string) $code );
        $all = self::all();
        return isset( $all[ $code ] ) ? $all[ $code ] : null;
    }

    /** Extract the literal default of a [hidden field "value"] tag. */
    public static function hidden_default_from_template( $template, $field_name ) {
        $template = (string) $template;
        $field_name = preg_quote( (string) $field_name, '~' );
        if ( '' === $template ) return '';

        if ( preg_match( '~\[hidden\*?\s+' . $field_name . '(?:\s+[^\]]*?)?\s+"([^"]*)"(?:\s+[^\]]*)?\]~iu', $template, $m ) ) {
            return sanitize_text_field( $m[1] );
        }
        if ( preg_match( "~\\[hidden\\*?\\s+{$field_name}(?:\\s+[^\\]]*?)?\\s+'([^']*)'(?:\\s+[^\\]]*)?\\]~iu", $template, $m ) ) {
            return sanitize_text_field( $m[1] );
        }
        return '';
    }

    public static function resolve_submission( $contact_form, $posted ) {
        $template = is_object( $contact_form ) && method_exists( $contact_form, 'prop' ) ? (string) $contact_form->prop( 'form' ) : '';
        $posted = is_array( $posted ) ? $posted : array();

        $template_code = self::hidden_default_from_template( $template, 'form_code' );
        $posted_code = isset( $posted['form_code'] ) ? sanitize_key( self::scalar_value( $posted['form_code'] ) ) : '';
        // A hidden input is still browser-controlled. Only the literal default
        // stored in the server-side CF7 template may activate a CRM contract.
        $code = sanitize_key( $template_code );

        $template_schema = self::hidden_default_from_template( $template, 'form_schema_version' );
        $posted_schema = isset( $posted['form_schema_version'] ) ? sanitize_text_field( self::scalar_value( $posted['form_schema_version'] ) ) : '';
        $schema = sanitize_text_field( $template_schema );

        $contract = self::get( $code );
        $warnings = array();
        if ( ! $template_code && $posted_code ) $warnings[] = 'untrusted_posted_form_code_ignored';
        if ( ! $template_schema && $posted_schema ) $warnings[] = 'untrusted_posted_schema_version_ignored';
        if ( $template_code && $posted_code && sanitize_key( $template_code ) !== $posted_code ) {
            $warnings[] = 'posted_form_code_mismatch';
        }
        if ( $template_schema && $posted_schema && (string) $template_schema !== (string) $posted_schema ) {
            $warnings[] = 'posted_schema_version_mismatch';
        }
        if ( $code && ! $contract ) $warnings[] = 'unknown_form_code';
        if ( $contract && (string) $contract['schema_version'] !== (string) $schema ) $warnings[] = 'unsupported_schema_version';

        return array(
            'form_code' => $code,
            'schema_version' => $schema,
            'contract' => $contract,
            'supported' => (bool) ( $contract && (string) $contract['schema_version'] === (string) $schema ),
            'warnings' => $warnings,
            'canonical_from_template' => (bool) $template_code,
        );
    }

    public static function posted_map_from_application( $application ) {
        $map = array();
        if ( ! $application ) return $map;
        foreach ( LV_Applications_Plugin::decode_fields( $application->fields_json ) as $field ) {
            $name = isset( $field['name'] ) ? (string) $field['name'] : '';
            if ( '' === $name ) continue;
            $map[ $name ] = isset( $field['value'] ) ? $field['value'] : '';
        }
        return $map;
    }

    public static function scalar_value( $value ) {
        if ( is_array( $value ) ) {
            $flat = array();
            array_walk_recursive( $value, static function( $item ) use ( &$flat ) {
                if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) $flat[] = trim( (string) $item );
            } );
            return implode( ', ', $flat );
        }
        return is_scalar( $value ) ? trim( (string) $value ) : '';
    }

    public static function is_checked( $value ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $item ) if ( self::is_checked( $item ) ) return true;
            return false;
        }
        $value = strtolower( trim( (string) $value ) );
        return ! in_array( $value, array( '', '0', 'false', 'no', 'нет', 'off' ), true );
    }
}
