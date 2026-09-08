<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class LV_Applications_Table extends WP_List_Table {
    private $plugin;
    private $filters;

    public function __construct( $plugin, $filters ) {
        $this->plugin = $plugin;
        $this->filters = $filters;
        parent::__construct( array( 'singular' => 'application', 'plural' => 'applications', 'ajax' => false ) );
    }

    public function get_columns() {
        $columns = array(
            'cb'        => '<input type="checkbox" />',
            'processed' => 'Статус',
            'priority'  => 'Приоритет',
            'date'      => 'Получена',
            'form'      => 'Форма',
        );
        $columns['assignee'] = 'Ответственный';
        $columns['content'] = 'Контакт и ответы';
        $columns['action'] = '';
        return $columns;
    }

    protected function get_sortable_columns() {
        return array(
            'processed' => array( 'status', false ),
            'priority'  => array( 'priority', false ),
            'date'      => array( 'date', true ),
            'form'      => array( 'form', false ),
            'assignee'  => array( 'assignee', false ),
        );
    }

    public function prepare_items() {
        $per_page = isset( $this->filters['per_page'] ) ? absint( $this->filters['per_page'] ) : 25;
        if ( ! in_array( $per_page, array( 25, 50, 100 ), true ) ) $per_page = 25;
        $current_page = isset( $this->filters['paged'] ) ? max( 1, absint( $this->filters['paged'] ) ) : max( 1, $this->get_pagenum() );
        $total = LV_Applications_Plugin::count_applications( $this->filters );
        $this->items = LV_Applications_Plugin::query_applications( $this->filters, $per_page, ( $current_page - 1 ) * $per_page );
        $this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
        $this->set_pagination_args( array(
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total / $per_page ),
        ) );
    }

    public function no_items() {
        $trash = isset( $this->filters['view'] ) && 'trash' === $this->filters['view'];
        echo '<div class="lv-empty-table"><span class="lv-empty-icon"><span class="dashicons ' . ( $trash ? 'dashicons-trash' : 'dashicons-search' ) . '"></span></span><strong>' . ( $trash ? 'Корзина пуста' : 'Заявок не найдено' ) . '</strong><p>' . ( $trash ? 'Удалённые заявки будут появляться здесь на 7 дней.' : 'Измените фильтры или попробуйте другой поисковый запрос.' ) . '</p></div>';
    }

    protected function column_cb( $item ) {
        return '<input type="checkbox" name="application[]" value="' . esc_attr( $item->id ) . '">';
    }

    protected function column_processed( $item ) {
        $done = (int) $item->processed === 1;
        if ( ! empty( $item->deleted_at ) || ! LV_Applications_Plugin::can_edit_application( $item ) ) {
            $hint = ( empty( $item->deleted_at ) && LV_Applications_Plugin::can_claim_application( $item ) ) ? '<small>Сначала возьмите себе</small>' : '';
            return '<span class="lv-static-status ' . ( $done ? 'is-processed' : '' ) . '"><i></i><span>' . esc_html( $done ? 'Обработано' : 'Не обработано' ) . $hint . '</span></span>';
        }
        return '<label class="lv-status-toggle ' . ( $done ? 'is-processed' : '' ) . '" title="Нажмите, чтобы изменить статус"><input type="checkbox" class="lv-processed-toggle" data-id="' . esc_attr( $item->id ) . '" ' . checked( $done, true, false ) . '><span class="lv-status-mark"><i></i></span><span class="lv-status-text">' . esc_html( $done ? 'Обработано' : 'Не обработано' ) . '</span></label>';
    }

    protected function column_priority( $item ) {
        $current = ! empty( $item->priority ) ? sanitize_key( $item->priority ) : 'normal';
        if ( empty( $item->deleted_at ) && LV_Applications_Plugin::can_edit_application( $item ) ) {
            $html = '<div class="lv-priority-control"><select class="lv-priority-select" data-id="' . esc_attr( $item->id ) . '">';
            foreach ( LV_CRM_Extensions::priority_options() as $key => $opt ) {
                $html .= '<option value="' . esc_attr( $key ) . '" ' . selected( $current, $key, false ) . '>' . esc_html( $opt[0] ) . '</option>';
            }
            return $html . '</select><div class="lv-priority-preview">' . LV_CRM_Extensions::priority_html( $item, true ) . '</div></div>';
        }
        return LV_CRM_Extensions::priority_html( $item, true );
    }

    protected function column_date( $item ) {
        $html = '<div class="lv-date-cell"><strong>' . esc_html( mysql2date( 'd.m.Y', $item->submitted_at ) ) . '</strong><span>' . esc_html( mysql2date( 'H:i', $item->submitted_at ) ) . ' · #' . esc_html( $item->id ) . '</span>';
        if ( ! empty( $item->deleted_at ) ) {
            $days = LV_Applications_Plugin::trash_days_left( $item );
            $html .= '<small>Удаление через ' . esc_html( $days ) . ' дн.</small>';
        }
        return $html . '</div>';
    }

    protected function column_form( $item ) {
        $fields = LV_Applications_Plugin::visible_fields( $item );
        $html = '<div class="lv-form-cell"><span class="lv-form-pill"><span class="dashicons dashicons-feedback"></span><span>' . esc_html( $item->form_title ?: 'Без названия' ) . '</span></span>';
        if ( ! empty( $item->duplicate_of ) ) {
            $html .= '<span class="lv-duplicate-pill" title="Совпадает с ранее полученной идентичной заявкой"><span class="dashicons dashicons-admin-page"></span>Дубль #' . esc_html( $item->duplicate_of ) . '</span>';
        }
        if ( ! empty( $item->deletion_requested_at ) ) $html .= '<span class="lv-delete-request-pill"><span class="dashicons dashicons-flag"></span>Запрошено удаление</span>';
        $file_count = isset( $item->lv_file_count ) ? (int) $item->lv_file_count : count( LV_Applications_Plugin::decode_files( $item ) );
        if ( $file_count ) $html .= '<span class="lv-file-count-pill"><span class="dashicons dashicons-paperclip"></span>' . esc_html( $file_count ) . '</span>';
        $meta = array();
        $meta[] = count( $fields ) . ' ' . LV_Applications_Plugin::plural_fields_public( count( $fields ) );
        if ( $item->source_url ) $meta[] = LV_Applications_Plugin::source_url_label( $item->source_url );
        $html .= '<div class="lv-form-meta">' . esc_html( implode( ' · ', $meta ) ) . '</div>';
        $html .= LV_CRM_Extensions::tags_html( isset( $item->lv_tags ) ? $item->lv_tags : LV_CRM_Extensions::get_application_tags( $item->id ) );
        $html .= '</div>';
        return $html;
    }

    protected function column_assignee( $item ) {
        if ( ! empty( $item->deleted_at ) ) {
            return '<div class="lv-assignee-static"><span class="lv-assignee-avatar"><span class="dashicons dashicons-admin-users"></span></span><div><strong>' . esc_html( LV_Applications_Plugin::assignee_name( $item->assignee_id ) ) . '</strong><span>' . esc_html( LV_Applications_Plugin::assignee_role_label( $item->assignee_id ) ) . '</span></div></div>';
        }
        if ( LV_Applications_Plugin::is_manager() ) {
            $html = '<select class="lv-assignee-select lv-assignee-select--table" data-id="' . esc_attr( $item->id ) . '"><option value="0">Не назначен</option>';
            foreach ( LV_Applications_Plugin::eligible_assignees() as $user ) {
                $html .= '<option value="' . esc_attr( $user->ID ) . '" ' . selected( absint( $item->assignee_id ), absint( $user->ID ), false ) . '>' . esc_html( $user->display_name ) . '</option>';
            }
            return '<div class="lv-assignee-control">' . $html . '</select><small class="lv-assignee-feedback"></small></div>';
        }
        if ( LV_Applications_Plugin::can_claim_application( $item ) ) {
            return '<div class="lv-unassigned-inline"><span><i></i>Не назначена</span><button type="button" class="button lv-claim-button" data-id="' . esc_attr( $item->id ) . '" data-user-id="' . esc_attr( get_current_user_id() ) . '"><span class="dashicons dashicons-plus-alt2"></span>Взять себе</button><small class="lv-assignee-feedback"></small></div>';
        }
        return '<div class="lv-assignee-static is-mine"><span class="lv-assignee-avatar"><span class="dashicons dashicons-admin-users"></span></span><div><strong>Вы</strong><span>' . esc_html( LV_Applications_Plugin::assignee_role_label( $item->assignee_id ) ) . '</span></div></div>';
    }

    protected function column_content( $item ) {
        $fields = LV_Applications_Plugin::visible_fields( $item );
        $primary = LV_Applications_Plugin::primary_fields( $item );
        $used = array();
        $html = '<div class="lv-content-cell">';

        if ( $primary ) {
            $heading = LV_Applications_Plugin::application_heading( $item );
            $html .= '<div class="lv-primary-preview"><strong class="lv-primary-title">' . esc_html( wp_html_excerpt( $heading, 72, '…' ) ) . '</strong><div class="lv-primary-chips">';
            foreach ( array( 'phone', 'email', 'organization', 'subject' ) as $key ) {
                if ( empty( $primary[ $key ] ) ) continue;
                $field = $primary[ $key ];
                $name = isset( $field['name'] ) ? (string) $field['name'] : '';
                $used[ $name ] = true;
                $value = LV_Applications_Plugin::value_to_string( isset( $field['value'] ) ? $field['value'] : '' );
                if ( '' === trim( $value ) ) continue;
                $icon = 'dashicons-info-outline';
                if ( 'phone' === $key ) $icon = 'dashicons-phone';
                if ( 'email' === $key ) $icon = 'dashicons-email-alt';
                if ( 'organization' === $key ) $icon = 'dashicons-building';
                $html .= '<span><span class="dashicons ' . esc_attr( $icon ) . '"></span>' . esc_html( wp_html_excerpt( preg_replace( '/\s+/u', ' ', $value ), 54, '…' ) ) . '</span>';
            }
            if ( ! empty( $primary['name']['name'] ) ) $used[ $primary['name']['name'] ] = true;
            $html .= '</div></div>';
        }

        $extra = array();
        foreach ( $fields as $field ) {
            $name = isset( $field['name'] ) ? (string) $field['name'] : '';
            if ( isset( $used[ $name ] ) ) continue;
            $value = trim( LV_Applications_Plugin::value_to_string( isset( $field['value'] ) ? $field['value'] : '' ) );
            if ( '' === $value ) continue;
            $extra[] = array( 'label' => LV_Applications_Plugin::display_field_label( $item, $field ), 'value' => $value );
            if ( count( $extra ) >= 2 ) break;
        }
        if ( $extra ) {
            $html .= '<div class="lv-preview-grid">';
            foreach ( $extra as $field ) {
                $html .= '<div class="lv-preview-field"><span>' . esc_html( $field['label'] ) . '</span><strong>' . esc_html( wp_html_excerpt( preg_replace( '/\s+/u', ' ', $field['value'] ), 96, '…' ) ) . '</strong></div>';
            }
            $html .= '</div>';
        }
        if ( ! $primary && ! $extra ) $html .= '<span class="lv-muted">Нет заполненных полей</span>';
        return $html . '</div>';
    }

    protected function column_action( $item ) {
        $redirect = LV_Applications_Plugin::filters_url( $this->filters );
        $view_url = add_query_arg( array_merge(
            LV_Applications_Plugin::filters_query_args( $this->filters ),
            array( 'page' => LV_Applications_Plugin::PAGE_SLUG, 'action' => 'view', 'application_id' => $item->id )
        ), admin_url( 'admin.php' ) );
        $html = '<div class="lv-row-actions"><button type="button" class="lv-quick-view" data-id="' . esc_attr( $item->id ) . '" title="Быстрый просмотр"><span class="dashicons dashicons-visibility"></span><span>Просмотр</span></button><a class="lv-open-icon" href="' . esc_url( $view_url ) . '" title="Открыть полную карточку"><span class="dashicons dashicons-external"></span></a>';
        if ( ! empty( $item->deleted_at ) ) {
            if ( current_user_can( 'lv_restore_applications' ) ) $html .= '<a class="lv-row-restore" href="' . esc_url( LV_Applications_Plugin::action_url( 'restore', $item->id, $redirect ) ) . '" title="Восстановить"><span class="dashicons dashicons-undo"></span></a>';
            if ( current_user_can( 'lv_purge_applications' ) ) $html .= '<a class="lv-row-delete lv-delete-permanent" href="' . esc_url( LV_Applications_Plugin::action_url( 'delete', $item->id, $redirect ) ) . '" title="Удалить навсегда"><span class="dashicons dashicons-trash"></span></a>';
        } else {
            if ( current_user_can( 'lv_trash_applications' ) ) {
                $html .= '<a class="lv-row-delete lv-trash-link" href="' . esc_url( LV_Applications_Plugin::action_url( 'trash', $item->id, $redirect ) ) . '" title="В корзину"><span class="dashicons dashicons-trash"></span></a>';
            } elseif ( current_user_can( 'lv_request_application_deletion' ) && LV_Applications_Plugin::can_edit_application( $item ) ) {
                if ( empty( $item->deletion_requested_at ) ) $html .= '<a class="lv-row-delete lv-request-delete" href="' . esc_url( LV_Applications_Plugin::action_url( 'request_delete', $item->id, $redirect ) ) . '" title="Запросить удаление"><span class="dashicons dashicons-flag"></span></a>';
                elseif ( absint( $item->deletion_requested_by ) === get_current_user_id() ) $html .= '<a class="lv-row-restore" href="' . esc_url( LV_Applications_Plugin::action_url( 'cancel_delete_request', $item->id, $redirect ) ) . '" title="Отменить запрос"><span class="dashicons dashicons-dismiss"></span></a>';
            }
        }
        return $html . '</div>';
    }

    protected function column_default( $item, $column_name ) {
        return '';
    }
}
