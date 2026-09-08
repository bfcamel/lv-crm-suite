<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Automatic CF7 -> CRM integration for forms with a stable LV form contract.
 */
final class LV_Form_Integration {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Unified public forms are handled only through stable form contracts.
        add_action( 'lv_crm_application_created', array( $this, 'process_application' ), 15, 3 );
    }

    public function process_application( $application_id, $form_id = 0, $form_title = '' ) {
        $app = LV_Applications_Plugin::get_application( $application_id );
        if ( ! $app || ! empty( $app->deleted_at ) ) return;

        $code = sanitize_key( isset( $app->form_code ) ? $app->form_code : '' );
        $schema = isset( $app->form_schema_version ) ? (string) $app->form_schema_version : '';
        $contract = LV_Form_Contracts::get( $code );

        // 0.11.2 intentionally has no heuristic fallback. Every public form
        // must use a registered stable contract.
        if ( ! $contract ) {
            $this->set_sync_status( $application_id, 'invalid_contract' );
            LV_Applications_Plugin::log_event( $application_id, 'form_contract_missing', 'Автоматическая синхронизация контакта пропущена: форма не зарегистрирована в едином контракте CRM.', 0, array( 'form_code' => $code ) );
            return;
        }

        if ( (string) $contract['schema_version'] !== $schema ) {
            $this->set_sync_status( $application_id, 'unsupported_schema' );
            LV_Applications_Plugin::log_event( $application_id, 'form_schema_unsupported', 'Автоматическая синхронизация контакта пропущена: версия схемы формы не поддерживается.', 0, array( 'form_code' => $code, 'schema_version' => $schema ) );
            return;
        }

        $data = LV_Form_Contracts::posted_map_from_application( $app );
        $missing = $this->missing_required_fields( $contract, $data );
        if ( $missing ) {
            $this->set_sync_status( $application_id, 'invalid_contract' );
            LV_Applications_Plugin::log_event( $application_id, 'form_contract_invalid', 'Автоматическая синхронизация контакта пропущена: отсутствуют обязательные поля контракта.', 0, array( 'missing' => $missing, 'form_code' => $code ) );
            return;
        }

        $linked_contacts = LV_CRM_Extensions::application_contacts( $application_id );
        if ( count( $linked_contacts ) > 1 ) {
            $this->set_sync_status( $application_id, 'multiple_linked_contacts' );
            LV_Applications_Plugin::log_event( $application_id, 'contact_sync_ambiguous', 'Автоматическая синхронизация данных формы пропущена: с заявкой уже связано несколько контактов.', 0, array( 'contact_ids' => array_map( 'absint', wp_list_pluck( $linked_contacts, 'id' ) ) ) );
            return;
        }

        $contact_id = $linked_contacts ? absint( $linked_contacts[0]->id ) : 0;
        $created = false;
        if ( $contact_id ) {
            // An explicitly linked application already points to a single contact.
            // Treat that relation as authoritative and use the contract only to
            // enrich profile, tags and consent evidence.
            $this->enrich_contact( $contact_id, $app, $data );
        } else {
            $resolution = $this->resolve_contact( $app, $data );
            if ( ! empty( $resolution['conflict'] ) ) {
                $this->set_sync_status( $application_id, 'contact_conflict' );
                LV_Applications_Plugin::log_event( $application_id, 'contact_match_conflict', 'CRM не стала автоматически объединять контакты: телефон и/или email указывают на разные карточки.', 0, $resolution );
                return;
            }
            $contact_id = absint( $resolution['contact_id'] ?? 0 );
            if ( ! $contact_id ) {
                $contact_id = $this->create_contact( $app, $data );
                $created = (bool) $contact_id;
            } else {
                $this->enrich_contact( $contact_id, $app, $data );
            }
        }

        if ( ! $contact_id ) {
            $this->set_sync_status( $application_id, 'contact_error' );
            LV_Applications_Plugin::log_event( $application_id, 'contact_sync_failed', 'Не удалось автоматически создать или обновить контакт.' );
            return;
        }

        // Keep WordPress account linking separate from CRM contact matching: an
        // account identity may enrich a contact, but never decides an automatic merge.
        $identity_email = sanitize_email( LV_Form_Contracts::scalar_value( $data['contact_email'] ?? '' ) );
        $identity_phone = sanitize_text_field( LV_Form_Contracts::scalar_value( $data['contact_phone'] ?? '' ) );
        LV_CRM_Extensions::maybe_link_wp_account_by_identity( $contact_id, $identity_email ? array( $identity_email ) : array(), $identity_phone ? array( $identity_phone ) : array() );

        if ( ! LV_CRM_Extensions::link_application_to_contact( $application_id, $contact_id, 0, 'form_contract' ) ) {
            $this->set_sync_status( $application_id, 'link_error' );
            LV_Applications_Plugin::log_event( $application_id, 'contact_link_failed', 'Контакт найден, но связать его с заявкой автоматически не удалось.', 0, array( 'contact_id' => $contact_id ) );
            return;
        }

        $this->sync_profile_fields( $contact_id, $contract, $data );
        $this->apply_automatic_tags( $contact_id, $code, $data );
        $consents_ok = $this->sync_consents( $contact_id, $app, $contract, $data );

        if ( ! $consents_ok ) {
            $this->set_sync_status( $application_id, 'synced_with_warnings' );
            LV_Applications_Plugin::log_event( $application_id, 'consent_sync_warning', 'Контакт создан или связан, но не все сведения о согласиях удалось сохранить автоматически.', 0, array( 'contact_id' => $contact_id, 'form_code' => $code ) );
        } else {
            $this->set_sync_status( $application_id, $created ? 'contact_created' : 'contact_linked' );
        }
        LV_CRM_Extensions::contact_log(
            $contact_id,
            $created ? 'created_from_form' : 'form_received',
            ( $created ? 'Контакт автоматически создан' : 'Получена новая заявка' ) . ' из формы «' . ( $contract['title'] ?? $app->form_title ) . '».',
            array( 'application_id' => $application_id, 'form_code' => $code, 'submission_uuid' => (string) ( $app->submission_uuid ?? '' ) )
        );
    }

    private function missing_required_fields( $contract, $data ) {
        $missing = array();
        foreach ( (array) ( $contract['required'] ?? array() ) as $key ) {
            if ( 0 === strpos( $key, 'consent_' ) ) {
                if ( ! LV_Form_Contracts::is_checked( $data[ $key ] ?? '' ) ) $missing[] = $key;
            } elseif ( '' === LV_Form_Contracts::scalar_value( $data[ $key ] ?? '' ) ) {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    private function resolve_contact( $app, $data ) {
        global $wpdb;
        $email = sanitize_email( LV_Form_Contracts::scalar_value( $data['contact_email'] ?? '' ) );
        $phone_raw = sanitize_text_field( LV_Form_Contracts::scalar_value( $data['contact_phone'] ?? '' ) );
        $phone = LV_CRM_Extensions::normalize_phone( $phone_raw );

        $email_ids = array();
        $phone_ids = array();
        if ( $email && is_email( $email ) ) {
            $email_ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT e.contact_id FROM ' . LV_CRM_Extensions::contact_emails_table() . ' e INNER JOIN ' . LV_CRM_Extensions::contacts_table() . ' c ON c.id=e.contact_id WHERE c.deleted_at IS NULL AND e.normalized=%s', strtolower( $email ) ) ) );
        }
        if ( $phone ) {
            $phone_ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT p.contact_id FROM ' . LV_CRM_Extensions::contact_phones_table() . ' p INNER JOIN ' . LV_CRM_Extensions::contacts_table() . ' c ON c.id=p.contact_id WHERE c.deleted_at IS NULL AND p.normalized=%s', $phone ) ) );
        }
        $email_ids = array_values( array_unique( array_filter( $email_ids ) ) );
        $phone_ids = array_values( array_unique( array_filter( $phone_ids ) ) );
        $all = array_values( array_unique( array_merge( $email_ids, $phone_ids ) ) );

        $conflict = false;
        if ( count( $email_ids ) > 1 || count( $phone_ids ) > 1 || count( $all ) > 1 ) $conflict = true;

        return array(
            'contact_id' => ! $conflict && 1 === count( $all ) ? $all[0] : 0,
            'conflict' => $conflict,
            'email' => $email,
            'phone' => $phone,
            'email_contact_ids' => $email_ids,
            'phone_contact_ids' => $phone_ids,
        );
    }

    private function create_contact( $app, $data ) {
        global $wpdb;
        $name = sanitize_text_field( LV_Form_Contracts::scalar_value( $data['contact_name'] ?? '' ) );
        $email = sanitize_email( LV_Form_Contracts::scalar_value( $data['contact_email'] ?? '' ) );
        $phone_value = sanitize_text_field( LV_Form_Contracts::scalar_value( $data['contact_phone'] ?? '' ) );
        if ( ! $name ) $name = $phone_value ?: ( $email ?: ( 'Контакт из формы #' . absint( $app->id ) ) );
        $now = current_time( 'mysql' );
        $curator_id = ! empty( $app->assignee_id ) && LV_Applications_Plugin::eligible_assignee_public( absint( $app->assignee_id ) ) ? absint( $app->assignee_id ) : 0;

        $ok = $wpdb->insert( LV_CRM_Extensions::contacts_table(), array(
            'contact_type' => 'person',
            'display_name' => $name,
            'organization' => '',
            'created_by' => 0,
            'curator_user_id' => $curator_id,
            'linked_wp_user_id' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'last_activity_at' => $now,
        ), array( '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ) );
        if ( ! $ok ) return 0;
        $contact_id = absint( $wpdb->insert_id );

        if ( $phone_value ) $this->add_phone_if_safe( $contact_id, $phone_value );
        if ( $email && is_email( $email ) ) $this->add_email_if_safe( $contact_id, $email );
        return $contact_id;
    }

    private function enrich_contact( $contact_id, $app, $data ) {
        global $wpdb;
        $contact_id = absint( $contact_id );
        if ( ! $contact_id ) return;
        $phone = sanitize_text_field( LV_Form_Contracts::scalar_value( $data['contact_phone'] ?? '' ) );
        $email = sanitize_email( LV_Form_Contracts::scalar_value( $data['contact_email'] ?? '' ) );
        $changed = false;
        if ( $phone ) $changed = $this->add_phone_if_safe( $contact_id, $phone ) || $changed;
        if ( $email && is_email( $email ) ) $changed = $this->add_email_if_safe( $contact_id, $email ) || $changed;
        if ( $changed ) {
            $now = current_time( 'mysql' );
            $wpdb->update( LV_CRM_Extensions::contacts_table(), array( 'updated_at' => $now, 'last_activity_at' => $now ), array( 'id' => $contact_id ), array( '%s', '%s' ), array( '%d' ) );
            LV_CRM_Extensions::contact_log( $contact_id, 'channels_enriched', 'Контактные данные дополнены из новой формы сайта.', array( 'application_id' => absint( $app->id ) ) );
        }
    }

    private function add_phone_if_safe( $contact_id, $value ) {
        global $wpdb;
        $contact_id = absint( $contact_id );
        $value = sanitize_text_field( $value );
        $normalized = LV_CRM_Extensions::normalize_phone( $value );
        if ( ! $normalized ) return false;
        $owners = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT p.contact_id FROM ' . LV_CRM_Extensions::contact_phones_table() . ' p INNER JOIN ' . LV_CRM_Extensions::contacts_table() . ' c ON c.id=p.contact_id WHERE c.deleted_at IS NULL AND p.normalized=%s', $normalized ) ) );
        $owners = array_values( array_unique( array_filter( $owners ) ) );
        if ( $owners && ! in_array( $contact_id, $owners, true ) ) return false;
        if ( in_array( $contact_id, $owners, true ) ) return false;
        $has_primary = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LV_CRM_Extensions::contact_phones_table() . ' WHERE contact_id=%d AND is_primary=1', $contact_id ) );
        return (bool) $wpdb->insert( LV_CRM_Extensions::contact_phones_table(), array( 'contact_id' => $contact_id, 'value' => $value, 'normalized' => $normalized, 'is_primary' => $has_primary ? 0 : 1 ), array( '%d', '%s', '%s', '%d' ) );
    }

    private function add_email_if_safe( $contact_id, $value ) {
        global $wpdb;
        $contact_id = absint( $contact_id );
        $value = sanitize_email( $value );
        $normalized = strtolower( trim( $value ) );
        if ( ! $normalized || ! is_email( $normalized ) ) return false;
        $owners = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT e.contact_id FROM ' . LV_CRM_Extensions::contact_emails_table() . ' e INNER JOIN ' . LV_CRM_Extensions::contacts_table() . ' c ON c.id=e.contact_id WHERE c.deleted_at IS NULL AND e.normalized=%s', $normalized ) ) );
        $owners = array_values( array_unique( array_filter( $owners ) ) );
        if ( $owners && ! in_array( $contact_id, $owners, true ) ) return false;
        if ( in_array( $contact_id, $owners, true ) ) return false;
        $has_primary = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LV_CRM_Extensions::contact_emails_table() . ' WHERE contact_id=%d AND is_primary=1', $contact_id ) );
        return (bool) $wpdb->insert( LV_CRM_Extensions::contact_emails_table(), array( 'contact_id' => $contact_id, 'value' => $value, 'normalized' => $normalized, 'is_primary' => $has_primary ? 0 : 1 ), array( '%d', '%s', '%s', '%d' ) );
    }

    private function sync_profile_fields( $contact_id, $contract, $data ) {
        foreach ( (array) ( $contract['contact_fields'] ?? array() ) as $key => $spec ) {
            if ( ! array_key_exists( $key, $data ) ) continue;
            $value = LV_Form_Contracts::scalar_value( $data[ $key ] );
            if ( '' === $value ) continue;
            $this->upsert_contact_field( $contact_id, $key, $spec['label'] ?? $key, $spec['type'] ?? 'text', $value );
        }
    }

    private function upsert_contact_field( $contact_id, $field_key, $label, $type, $value ) {
        global $wpdb;
        $contact_id = absint( $contact_id );
        $field_key = sanitize_key( $field_key );
        $label = sanitize_text_field( $label );
        $type = sanitize_key( $type );
        $value = sanitize_textarea_field( $value );
        if ( ! $contact_id || ! $field_key || '' === $value ) return false;
        $table = LV_CRM_Extensions::contact_fields_table();
        $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE contact_id=%d AND field_key=%s ORDER BY id ASC LIMIT 1', $contact_id, $field_key ) );
        if ( ! $existing ) {
            // A field edited manually in an older UI can lose its technical key.
            // Re-adopt the row by its stable human label instead of creating a duplicate.
            $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE contact_id=%d AND field_key=%s AND field_label=%s ORDER BY id ASC LIMIT 1', $contact_id, '', $label ) );
            if ( $existing ) $wpdb->update( $table, array( 'field_key'=>$field_key ), array( 'id'=>absint($existing->id) ), array('%s'), array('%d') );
        }
        $now = current_time( 'mysql' );
        if ( $existing ) {
            if ( (string) $existing->field_value === $value && (string) $existing->field_label === $label && (string) $existing->field_type === $type ) return true;
            return false !== $wpdb->update( $table, array( 'field_label' => $label, 'field_type' => $type, 'field_value' => $value, 'updated_at' => $now ), array( 'id' => absint( $existing->id ) ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
        }
        return (bool) $wpdb->insert( $table, array( 'contact_id' => $contact_id, 'field_key' => $field_key, 'field_label' => $label, 'field_type' => $type, 'field_value' => $value, 'created_by' => 0, 'updated_at' => $now ), array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' ) );
    }

    private function apply_automatic_tags( $contact_id, $form_code, $data ) {
        $keys = array();
        $contract = LV_Form_Contracts::get( $form_code );
        if ( $contract ) $keys = (array) ( $contract['auto_tags'] ?? array() );

        if ( 'support_contact' === $form_code ) {
            $interest = function_exists( 'mb_strtolower' ) ? mb_strtolower( LV_Form_Contracts::scalar_value( $data['support_interest'] ?? '' ), 'UTF-8' ) : strtolower( LV_Form_Contracts::scalar_value( $data['support_interest'] ?? '' ) );
            if ( false !== strpos( $interest, 'и то, и другое' ) ) { $keys[] = 'volunteer'; $keys[] = 'donor'; }
            else {
                if ( false !== strpos( $interest, 'волонт' ) ) $keys[] = 'volunteer';
                if ( false !== strpos( $interest, 'пожертв' ) || false !== strpos( $interest, 'донор' ) ) $keys[] = 'donor';
            }
        }

        foreach ( array_values( array_unique( $keys ) ) as $key ) {
            $tag_id = LV_CRM_Extensions::ensure_system_contact_tag( $key );
            if ( $tag_id ) LV_CRM_Extensions::add_contact_tag( $contact_id, $tag_id );
        }
    }

    private function sync_consents( $contact_id, $app, $contract, $data ) {
        $ok = true;
        $base = array(
            'source' => 'website_form',
            'source_application_id' => absint( $app->id ),
            'event_at' => (string) $app->submitted_at,
            'form_code' => (string) $app->form_code,
            'form_schema_version' => (string) $app->form_schema_version,
            'submission_uuid' => (string) $app->submission_uuid,
            'source_url' => (string) $app->source_url,
            'source_ip' => (string) ( $app->source_ip ?? '' ),
            'user_agent' => (string) ( $app->user_agent ?? '' ),
        );

        if ( LV_Form_Contracts::is_checked( $data['consent_personal_data'] ?? '' ) ) {
            $pd = $base;
            $pd['document_version'] = sanitize_text_field( LV_Form_Contracts::scalar_value( $data['consent_pd_version'] ?? '' ) );
            $pd['channels'] = array( 'data_processing' );
            $pd['evidence'] = 'Обязательный чекбокс формы «' . ( $contract['title'] ?? $app->form_title ) . '».';
            if ( ! LV_Consent_Service::set_generic_status( $contact_id, 'personal_data', 'granted', $pd ) ) $ok = false;
        }

        // Important: an unchecked optional box is NOT a revocation. We simply do
        // nothing and keep the previous consent state intact.
        if ( LV_Form_Contracts::is_checked( $data['consent_newsletter'] ?? '' ) ) {
            $marketing = $base;
            $marketing['document_version'] = sanitize_text_field( LV_Form_Contracts::scalar_value( $data['consent_marketing_version'] ?? '' ) );
            $marketing['channels'] = (array) ( $contract['marketing_channels'] ?? array( 'email' ) );
            $marketing['evidence'] = 'Добровольный чекбокс формы «' . ( $contract['title'] ?? $app->form_title ) . '».';
            if ( ! LV_Consent_Service::set_generic_status( $contact_id, 'marketing', 'granted', $marketing ) ) $ok = false;
            if ( ! LV_Consent_Service::sync_marketing_to_current_emails( $contact_id, 'granted', $marketing ) ) $ok = false;
        }
        return $ok;
    }

    private function set_sync_status( $application_id, $status ) {
        global $wpdb;
        $wpdb->update( LV_Applications_Plugin::table_name(), array( 'contact_sync_status' => sanitize_key( $status ) ), array( 'id' => absint( $application_id ) ), array( '%s' ), array( '%d' ) );
    }
}
