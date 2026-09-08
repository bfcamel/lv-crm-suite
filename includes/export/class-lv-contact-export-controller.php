<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LV_Contact_Export_Controller {
    private static $instance=null;
    public static function instance(){if(null===self::$instance)self::$instance=new self();return self::$instance;}
    private function __construct(){
        add_action('wp_ajax_lv_crm_export_preview',array($this,'ajax_preview'));
        add_action('admin_post_lv_crm_export_contacts',array($this,'export'));
        add_action('admin_post_lv_crm_save_segment',array($this,'save_segment'));
        add_action('admin_post_lv_crm_delete_segment',array($this,'delete_segment'));
        add_action('admin_post_lv_crm_save_consents',array($this,'save_consents'));
    }

    private static function decode_filters($raw){$raw=wp_unslash((string)$raw);$data=json_decode($raw,true);return LV_Contact_Query::sanitize_filters(is_array($data)?$data:array());}

    public function ajax_preview(){
        if(!current_user_can('lv_export_contacts'))wp_send_json_error(array('message'=>'Недостаточно прав для экспорта контактов.'),403);
        check_ajax_referer('lv_crm_contact_export','nonce');
        $filters=self::decode_filters($_POST['filters']??'{}');
        $mode=sanitize_key($_POST['mode']??'current');
        $selected=isset($_POST['selected'])?array_map('absint',(array)$_POST['selected']):array();
        // Administrator-only override: explicit revocations remain excluded inside the exporter.
        $ignore_consent=!empty($_POST['ignore_consent']) && LV_Applications_Plugin::is_admin_manager();
        $stats=LV_Contact_Exporter::analyze($filters,$mode,$selected,$ignore_consent);
        wp_send_json_success(array('stats'=>$stats,'html'=>self::preview_html($stats)));
    }

    private static function preview_html($s){
        $items=array(
            array('Контактов',$s['contacts']),array('С email',$s['with_email']),array('Без email',$s['without_email']),array('Несколько email',$s['multiple_email']),
            array('Некорректных адресов',$s['invalid_email']),array('Повторов email',$s['duplicate_email']),array('Конфликтных email',$s['conflict_email']),
            array('Согласие получено',$s['consent_granted']),array('Согласие неизвестно',$s['consent_unknown']),array('Согласие отозвано',$s['consent_revoked']),
        );
        $html='<div class="lv-export-preview-grid">';
        foreach($items as$i)$html.='<div><strong>'.esc_html(number_format_i18n($i[1])).'</strong><span>'.esc_html($i[0]).'</span></div>';
        $override=!empty($s['consent_override']);
        $rows=$override?($s['mailing_rows_admin']??0):($s['mailing_rows']??0);
        if($override){
            $title='Административная выгрузка: '.esc_html(number_format_i18n($rows)).' уникальных email';
            $help='Подтверждённое согласие не требуется. Некорректные, конфликтные и явно отозванные адреса всё равно исключаются.';
        }else{
            $title='Для безопасной рассылки: '.esc_html(number_format_i18n($rows)).' уникальных email';
            $help='Только валидные адреса с подтверждённым согласием; отозванные и конфликтные адреса исключаются автоматически.';
        }
        $html.='</div><div class="lv-export-result'.($override?' is-admin-override':'').'"><span class="dashicons '.($override?'dashicons-admin-users':'dashicons-email-alt').'"></span><div><strong>'.$title.'</strong><small>'.esc_html($help).'</small></div></div>';
        return$html;
    }

    public function export(){
        if(!current_user_can('lv_export_contacts'))wp_die('Недостаточно прав для экспорта контактов.');check_admin_referer('lv_crm_export_contacts');
        $profile=sanitize_key($_POST['profile']??'xlsx_working');$profiles=LV_Contact_Exporter::profiles();if(!isset($profiles[$profile]))wp_die('Неизвестный профиль экспорта.');
        if(!empty($profiles[$profile]['mailing'])&&!current_user_can('lv_export_mailing'))wp_die('Недостаточно прав для рассылочной выгрузки.');
        $filters=self::decode_filters($_POST['filters']??'{}');$mode=sanitize_key($_POST['export_mode']??'current');if(!in_array($mode,array('current','selected','all'),true))$mode='current';
        $selected=isset($_POST['selected_ids'])?array_filter(array_map('absint',explode(',',sanitize_text_field(wp_unslash($_POST['selected_ids']))))):array();
        if('selected'===$mode&&!$selected)wp_die('Не выбраны контакты для экспорта.');
        $segment_name=sanitize_text_field(wp_unslash($_POST['segment_name']??''));
        // Never trust the posted flag: only Administrators may bypass the "granted" requirement.
        $ignore_consent=!empty($_POST['ignore_consent']) && LV_Applications_Plugin::is_admin_manager();
        LV_Contact_Exporter::download($profile,$filters,$mode,$selected,$segment_name,$ignore_consent);
    }

    public function save_segment(){
        if(!LV_CRM_Extensions::can_view_contacts())wp_die('Недостаточно прав.');check_admin_referer('lv_crm_save_segment');$name=sanitize_text_field(wp_unslash($_POST['segment_name']??''));if(!$name)wp_die('Укажите название сегмента.');
        $filters=self::decode_filters($_POST['filters']??'{}');$visibility=sanitize_key($_POST['visibility']??'private');$id=LV_Contact_Segments::save($name,$filters,$visibility,absint($_POST['segment_id']??0));if(!$id)wp_die('Не удалось сохранить сегмент.');
        wp_safe_redirect(add_query_arg(array('page'=>LV_CRM_Extensions::CONTACTS_SLUG,'segment'=>$id,'lv_segment_saved'=>1),admin_url('admin.php')));exit;
    }

    public function delete_segment(){
        if(!LV_CRM_Extensions::can_view_contacts())wp_die('Недостаточно прав.');$id=absint($_GET['segment_id']??0);check_admin_referer('lv_crm_delete_segment_'.$id);LV_Contact_Segments::delete($id);wp_safe_redirect(admin_url('admin.php?page='.LV_CRM_Extensions::CONTACTS_SLUG));exit;
    }

    public function save_consents(){
        if(!current_user_can('lv_manage_consents'))wp_die('Недостаточно прав для управления согласиями.');
        check_admin_referer('lv_crm_save_consents');
        $contact_id=absint($_POST['contact_id']??0);$contact=LV_CRM_Extensions::get_contact($contact_id);if(!$contact)wp_die('Контакт не найден.');

        // Contact-level consent states edited from the contact card.
        $g_statuses=isset($_POST['generic_consent_status'])?(array)wp_unslash($_POST['generic_consent_status']):array();
        $g_sources=isset($_POST['generic_consent_source'])?(array)wp_unslash($_POST['generic_consent_source']):array();
        $g_dates=isset($_POST['generic_consent_date'])?(array)wp_unslash($_POST['generic_consent_date']):array();
        $g_apps=isset($_POST['generic_consent_application'])?(array)wp_unslash($_POST['generic_consent_application']):array();
        $g_evidence=isset($_POST['generic_consent_evidence'])?(array)wp_unslash($_POST['generic_consent_evidence']):array();
        $g_versions=isset($_POST['generic_consent_document_version'])?(array)wp_unslash($_POST['generic_consent_document_version']):array();
        $current_generic=LV_Consent_Service::get_generic_for_contact($contact_id);
        $marketing_status_changed=false;
        foreach(array('personal_data','marketing') as$type){
            if(!array_key_exists($type,$g_statuses))continue;
            $app_id=absint($g_apps[$type]??0);$app=$app_id?LV_Applications_Plugin::get_application($app_id):null;
            $existing=$current_generic[$type]??null;
            $channels=$existing?LV_Consent_Service::decode_channels($existing):($type==='marketing'?array('email','phone','sms','messenger'):array('data_processing'));
            $data=array(
                'source'=>$g_sources[$type]??'manual','event_date'=>$g_dates[$type]??'','source_application_id'=>$app_id,
                'evidence'=>$g_evidence[$type]??'','document_version'=>$g_versions[$type]??'','channels'=>$channels,
                'form_code'=>$app?$app->form_code:'','form_schema_version'=>$app?$app->form_schema_version:'','submission_uuid'=>$app?$app->submission_uuid:'',
                'source_url'=>$app?$app->source_url:'','source_ip'=>$app?($app->source_ip??''):'','user_agent'=>$app?($app->user_agent??''):'',
            );
            $status=sanitize_key($g_statuses[$type]);
            $old_status=$existing?sanitize_key($existing->status):'unknown';
            LV_Consent_Service::set_generic_status($contact_id,$type,$status,$data);
            if('marketing'===$type&&$status!==$old_status){
                $marketing_status_changed=true;
                LV_Consent_Service::sync_marketing_to_current_emails($contact_id,$status,$data);
            }
        }

        // Backward-compatible per-email controls remain available for precise
        // address-level overrides and for contacts imported from older versions.
        // If the generic marketing status was changed in this request, the email
        // selects still contain their old rendered values. Do not let those stale
        // values immediately undo the newly selected generic decision.
        $statuses=isset($_POST['consent_status'])?(array)wp_unslash($_POST['consent_status']):array();$sources=isset($_POST['consent_source'])?(array)wp_unslash($_POST['consent_source']):array();$dates=isset($_POST['consent_date'])?(array)wp_unslash($_POST['consent_date']):array();$apps=isset($_POST['consent_application'])?(array)wp_unslash($_POST['consent_application']):array();$evidence=isset($_POST['consent_evidence'])?(array)wp_unslash($_POST['consent_evidence']):array();
        if(!$marketing_status_changed)foreach($statuses as$email_id=>$status){$email_id=absint($email_id);LV_Consent_Service::set_status($contact_id,$email_id,$status,array('source'=>$sources[$email_id]??'manual','event_date'=>$dates[$email_id]??'','source_application_id'=>absint($apps[$email_id]??0),'evidence'=>$evidence[$email_id]??''));}
        wp_safe_redirect(add_query_arg(array('page'=>LV_CRM_Extensions::CONTACTS_SLUG,'action'=>'view','contact_id'=>$contact_id,'consents_saved'=>1),admin_url('admin.php')));exit;
    }

    public static function export_modal($filters,$segment_name=''){
        if(!current_user_can('lv_export_contacts'))return'';$profiles=LV_Contact_Exporter::profiles();ob_start();
        echo'<div class="lv-modal-backdrop" id="lv-contact-export-modal" hidden><div class="lv-modal lv-contact-export-dialog" role="dialog" aria-modal="true" aria-labelledby="lv-export-title"><div class="lv-modal-head"><div><span class="lv-kicker">Контакты</span><h2 id="lv-export-title">Экспорт контактов</h2><p>Экспортируется вся выборка, а не только текущая страница.</p></div><button type="button" class="lv-contact-modal-close" data-lv-close-modal aria-label="Закрыть">×</button></div>';
        echo'<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="lv-contact-export-form"><input type="hidden" name="action" value="lv_crm_export_contacts"><input type="hidden" name="filters" value="'.esc_attr(wp_json_encode(LV_Contact_Query::sanitize_filters($filters),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'"><input type="hidden" name="selected_ids" value=""><input type="hidden" name="segment_name" value="'.esc_attr($segment_name).'">';wp_nonce_field('lv_crm_export_contacts');
        echo'<div class="lv-export-steps"><section><h3>1. Что выгружать</h3><div class="lv-choice-grid"><label><input type="radio" name="export_mode" value="current" checked><span><strong>Текущая выборка</strong><small>Все контакты по активным фильтрам</small></span></label><label class="lv-export-selected-choice"><input type="radio" name="export_mode" value="selected"><span><strong>Выбранные</strong><small>Только отмеченные строки</small></span></label><label><input type="radio" name="export_mode" value="all"><span><strong>Все контакты</strong><small>Без учёта фильтров</small></span></label></div></section>';
        echo'<section><h3>2. Формат</h3><div class="lv-export-profile-list">';foreach($profiles as$key=>$p){if(!empty($p['mailing'])&&!current_user_can('lv_export_mailing'))continue;echo'<label><input type="radio" name="profile" value="'.esc_attr($key).'" data-mailing="'.(!empty($p['mailing'])?'1':'0').'" '.checked('xlsx_working',$key,false).'><span><strong>'.esc_html($p['label']).'</strong><small>'.(!empty($p['mailing'])?'Только разрешённые уникальные email':'Для работы и анализа').'</small></span></label>';}echo'</div>';
        if(LV_Applications_Plugin::is_admin_manager()){
            echo'<div class="lv-consent-override-row" hidden><label class="lv-admin-export-override"><input type="checkbox" name="ignore_consent" value="1"><span><strong>Администратор: не требовать подтверждённое согласие</strong><small>Выгружать валидные email даже со статусом «Неизвестно». Адреса с явно отозванным согласием, конфликты и некорректные email останутся исключёнными.</small></span></label></div>';
        }
        echo'</section>';
        echo'<section><div class="lv-export-preview-head"><h3>3. Проверка выборки</h3><button type="button" class="button lv-export-refresh">Пересчитать</button></div><div class="lv-export-preview" data-empty="Нажмите «Пересчитать», чтобы проверить email, дубли и согласия."><div class="lv-empty-mini">Нажмите «Пересчитать», чтобы проверить email, дубли и согласия.</div></div></section></div>';
        echo'<div class="lv-modal-actions"><button type="button" class="button" data-lv-close-modal>Отмена</button><button class="button button-primary lv-export-submit"><span class="dashicons dashicons-download"></span><span class="lv-export-submit-label">Скачать</span></button></div></form></div></div>';return ob_get_clean();
    }

    public static function consent_panel($contact,$emails){
        if(!$contact)return'';
        $can=current_user_can('lv_manage_consents');$generic=LV_Consent_Service::get_generic_for_contact($contact->id);$email_map=$emails?LV_Consent_Service::get_for_contact($contact->id):array();$apps=LV_CRM_Extensions::linked_applications($contact->id);ob_start();
        echo'<section class="lv-panel lv-consent-panel"><div class="lv-panel-title"><span class="dashicons dashicons-yes-alt"></span><h2>Согласия</h2><span>Автоматически из форм и вручную</span></div><p class="lv-panel-help">CRM хранит вид согласия, источник, связанную заявку, редакцию документа и способ получения. Непоставленный необязательный чекбокс новой формы не отзывает ранее полученное согласие.</p>';
        if($can){echo'<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('lv_crm_save_consents');echo'<input type="hidden" name="action" value="lv_crm_save_consents"><input type="hidden" name="contact_id" value="'.esc_attr($contact->id).'">';}

        echo'<div class="lv-consent-list lv-generic-consent-list">';
        foreach(LV_Consent_Service::consent_type_options() as$type=>$title){$cs=$generic[$type]??null;$status=$cs?$cs->status:'unknown';$source=$cs?$cs->source:'manual';$date=$cs?(($status==='revoked'&&$cs->revoked_at)?mysql2date('Y-m-d',$cs->revoked_at):($cs->obtained_at?mysql2date('Y-m-d',$cs->obtained_at):current_time('Y-m-d'))):current_time('Y-m-d');$channels=$cs?LV_Consent_Service::decode_channels($cs):array();$labels=LV_Consent_Service::channel_labels();$channel_names=array();foreach($channels as$ch)$channel_names[]=$labels[$ch]??$ch;
            echo'<article class="lv-consent-row is-'.esc_attr($status).'"><div class="lv-consent-email"><strong>'.esc_html($title).'</strong><small>'.esc_html($channel_names?implode(', ',$channel_names):($type==='marketing'?'Каналы задаются редакцией согласия':'Обработка данных')).'</small>';
            if($cs&&$cs->form_code)echo'<small>Источник формы: '.esc_html($cs->form_code).($cs->document_version?' · документ '.$cs->document_version:'').'</small>';echo'</div>';
            if($can){echo'<div class="lv-control"><label>Статус</label><select name="generic_consent_status['.esc_attr($type).']">';foreach(LV_Consent_Service::status_options() as$k=>$v)echo'<option value="'.esc_attr($k).'" '.selected($status,$k,false).'>'.esc_html($v).'</option>';echo'</select></div><div class="lv-control"><label>Источник</label><select name="generic_consent_source['.esc_attr($type).']">';foreach(LV_Consent_Service::source_options() as$k=>$v)echo'<option value="'.esc_attr($k).'" '.selected($source,$k,false).'>'.esc_html($v).'</option>';echo'</select></div><div class="lv-control"><label>Дата</label><input type="date" name="generic_consent_date['.esc_attr($type).']" value="'.esc_attr($date).'"></div><div class="lv-control"><label>Версия документа</label><input name="generic_consent_document_version['.esc_attr($type).']" value="'.esc_attr($cs?$cs->document_version:'').'" placeholder="2026-09-01"></div><div class="lv-control"><label>Связанная заявка</label><select name="generic_consent_application['.esc_attr($type).']"><option value="0">Не указана</option>';foreach($apps as$a)echo'<option value="'.esc_attr($a->id).'" '.selected($cs?$cs->source_application_id:0,$a->id,false).'>#'.esc_html($a->id).' · '.esc_html($a->form_title).'</option>';echo'</select></div><div class="lv-control is-wide"><label>Подтверждение / комментарий</label><input name="generic_consent_evidence['.esc_attr($type).']" value="'.esc_attr($cs?$cs->evidence:'').'" placeholder="Где и как получено согласие"></div>';}
            else echo'<div class="lv-consent-readonly"><span class="lv-consent-badge is-'.esc_attr($status).'">'.esc_html(LV_Consent_Service::status_options()[$status]).'</span><small>'.esc_html($cs&&isset(LV_Consent_Service::source_options()[$source])?LV_Consent_Service::source_options()[$source]:'Источник не указан').'</small></div>';
            echo'</article>';
        }
        echo'</div>';

        if($emails){echo'<div class="lv-panel-title lv-consent-email-subtitle"><span class="dashicons dashicons-email-alt"></span><h3>Email для рассылочного экспорта</h3><span>Адресный уровень</span></div><p class="lv-panel-help">При согласии на рассылку из формы CRM автоматически разрешает email, существовавшие у контакта в момент согласия. Адресный статус можно уточнить вручную.</p><div class="lv-consent-list">';foreach($emails as$e){$cs=$email_map[(int)$e->id]??null;$status=$cs?$cs->status:'unknown';$source=$cs?$cs->source:'manual';$date=$cs?(($status==='revoked'&&$cs->revoked_at)?mysql2date('Y-m-d',$cs->revoked_at):($cs->obtained_at?mysql2date('Y-m-d',$cs->obtained_at):current_time('Y-m-d'))):current_time('Y-m-d');
            echo'<article class="lv-consent-row is-'.esc_attr($status).'"><div class="lv-consent-email"><strong>'.esc_html($e->value).'</strong><small>'.($e->is_primary?'Основной email':'Дополнительный email').'</small></div>';
            if($can){echo'<div class="lv-control"><label>Статус</label><select name="consent_status['.esc_attr($e->id).']">';foreach(LV_Consent_Service::status_options() as$k=>$v)echo'<option value="'.esc_attr($k).'" '.selected($status,$k,false).'>'.esc_html($v).'</option>';echo'</select></div><div class="lv-control"><label>Источник</label><select name="consent_source['.esc_attr($e->id).']">';foreach(LV_Consent_Service::source_options() as$k=>$v)echo'<option value="'.esc_attr($k).'" '.selected($source,$k,false).'>'.esc_html($v).'</option>';echo'</select></div><div class="lv-control"><label>Дата</label><input type="date" name="consent_date['.esc_attr($e->id).']" value="'.esc_attr($date).'"></div><div class="lv-control"><label>Связанная заявка</label><select name="consent_application['.esc_attr($e->id).']"><option value="0">Не указана</option>';foreach($apps as$a)echo'<option value="'.esc_attr($a->id).'" '.selected($cs?$cs->source_application_id:0,$a->id,false).'>#'.esc_html($a->id).' · '.esc_html($a->form_title).'</option>';echo'</select></div><div class="lv-control is-wide"><label>Подтверждение / комментарий</label><input name="consent_evidence['.esc_attr($e->id).']" value="'.esc_attr($cs?$cs->evidence:'').'" placeholder="Где и как получено согласие"></div>';}
            else echo'<div class="lv-consent-readonly"><span class="lv-consent-badge is-'.esc_attr($status).'">'.esc_html(LV_Consent_Service::status_options()[$status]).'</span><small>'.esc_html($cs&&isset(LV_Consent_Service::source_options()[$source])?LV_Consent_Service::source_options()[$source]:'Источник не указан').'</small></div>';
            echo'</article>';}
            echo'</div>';}
        if($can)echo'<div class="lv-consent-actions"><button class="button button-primary">Сохранить согласия</button></div></form>';

        $history=LV_Consent_Service::generic_history($contact->id,20);
        if($history){
            echo'<details class="lv-consent-history"><summary>История согласий <span>'.esc_html(count($history)).'</span></summary><div class="lv-consent-history-list">';
            $type_labels=LV_Consent_Service::consent_type_options();$status_labels=LV_Consent_Service::status_options();$source_labels=LV_Consent_Service::source_options();
            foreach($history as$h){
                $when=$h->event_at?:$h->created_at;
                echo'<div class="lv-consent-history-item"><div><strong>'.esc_html($type_labels[$h->consent_type]??$h->consent_type).'</strong><span class="lv-consent-badge is-'.esc_attr($h->status).'">'.esc_html($status_labels[$h->status]??$h->status).'</span></div><small>'.esc_html($when?mysql2date('d.m.Y H:i',$when):'—').' · '.esc_html($source_labels[$h->source]??$h->source);
                if(!empty($h->form_code))echo' · '.esc_html($h->form_code);
                if(!empty($h->document_version))echo' · документ '.esc_html($h->document_version);
                if(!empty($h->source_application_id))echo' · заявка #'.esc_html($h->source_application_id);
                echo'</small>';
                if(!empty($h->evidence))echo'<p>'.esc_html($h->evidence).'</p>';
                echo'</div>';
            }
            echo'</div></details>';
        }
        echo'</section>';return ob_get_clean();
    }

}
