<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Consent service.
 *
 * Stores contact-level consent types (personal_data / marketing) together with
 * document version, source and immutable evidence history. Email-level marketing
 * state is retained as the enforcement layer for safe mailing export.
 */
final class LV_Consent_Service {
    public static function table_name(){ global $wpdb; return $wpdb->prefix . 'lv_crm_consents'; }
    public static function log_table_name(){ global $wpdb; return $wpdb->prefix . 'lv_crm_consent_log'; }

    public static function status_options(){
        return array( 'unknown' => 'Неизвестно', 'granted' => 'Получено', 'revoked' => 'Отозвано' );
    }
    public static function source_options(){
        return array( 'manual'=>'Вручную', 'website_form'=>'Форма сайта', 'paper'=>'Бумажное согласие', 'import'=>'Импорт', 'other'=>'Другое' );
    }
    public static function consent_type_options(){
        return array( 'personal_data'=>'Обработка персональных данных', 'marketing'=>'Информационные и рекламные сообщения' );
    }
    public static function channel_labels(){
        return array( 'email'=>'E-mail', 'phone'=>'Телефон', 'sms'=>'SMS', 'messenger'=>'Мессенджеры', 'data_processing'=>'Обработка данных' );
    }

    // ------------------------------------------------------------------
    // E-mail level marketing status used by safe mailing export
    // ------------------------------------------------------------------

    public static function get_for_email( $email_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM '.self::table_name().' WHERE contact_email_id=%d AND channel=%s ORDER BY id DESC LIMIT 1', absint($email_id), 'email' ) );
    }

    public static function get_for_contact( $contact_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT c.*,e.value email_value,e.normalized email_normalized,e.is_primary FROM '.self::table_name().' c INNER JOIN '.LV_CRM_Extensions::contact_emails_table().' e ON e.id=c.contact_email_id WHERE c.contact_id=%d AND c.channel=%s ORDER BY e.is_primary DESC,e.id ASC', absint($contact_id), 'email' ) );
        $map=array(); foreach($rows as$r)$map[(int)$r->contact_email_id]=$r; return $map;
    }

    public static function set_status( $contact_id, $email_id, $status, $data=array() ) {
        global $wpdb;
        $status = self::sanitize_status( $status );
        $contact_id=absint($contact_id);$email_id=absint($email_id);
        $email=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.LV_CRM_Extensions::contact_emails_table().' WHERE id=%d AND contact_id=%d',$email_id,$contact_id));
        if(!$email)return false;

        $meta=self::normalize_meta($data);
        $source_app=$meta['source_application_id'];
        if($source_app&&!self::application_is_linked($source_app,$contact_id))$source_app=0;
        $meta['source_application_id']=$source_app;
        $event_at=self::event_at($data);
        $event_date=mysql2date('Y-m-d',$event_at);
        $now=current_time('mysql');
        $existing=self::get_for_email($email_id);
        if(!$existing&&'unknown'===$status)return true;

        $obtained_at=null;$revoked_at=null;
        if('granted'===$status){
            if($existing&&'granted'===$existing->status&&$existing->obtained_at&&mysql2date('Y-m-d',$existing->obtained_at)===$event_date&&self::same_evidence_context($existing,$meta,wp_json_encode(array('email'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)))$obtained_at=$existing->obtained_at;
            else$obtained_at=$event_at;
        }elseif($existing&&$existing->obtained_at)$obtained_at=$existing->obtained_at;
        if('revoked'===$status){
            if($existing&&'revoked'===$existing->status&&$existing->revoked_at&&mysql2date('Y-m-d',$existing->revoked_at)===$event_date&&self::same_evidence_context($existing,$meta,wp_json_encode(array('email'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)))$revoked_at=$existing->revoked_at;
            else$revoked_at=$event_at;
        }

        $row=array(
            'contact_id'=>$contact_id,'contact_email_id'=>$email_id,'channel'=>'email','consent_type'=>'marketing_email','status'=>$status,
            'obtained_at'=>$obtained_at,'source'=>$meta['source'],'source_application_id'=>$meta['source_application_id'],'evidence'=>$meta['evidence'],
            'document_version'=>$meta['document_version'],'channels_json'=>wp_json_encode(array('email'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'form_code'=>$meta['form_code'],'form_schema_version'=>$meta['form_schema_version'],'submission_uuid'=>$meta['submission_uuid'],
            'source_url'=>$meta['source_url'],'source_ip'=>$meta['source_ip'],'user_agent'=>$meta['user_agent'],
            'revoked_at'=>$revoked_at,'updated_by'=>get_current_user_id(),'updated_at'=>$now
        );

        if($existing&&self::same_state($existing,$row))return true;

        $wpdb->query('START TRANSACTION');
        try{
            if($existing){
                $ok=$wpdb->update(self::table_name(),$row,array('id'=>$existing->id));
            }else{
                $row['created_at']=$now;
                $ok=$wpdb->insert(self::table_name(),$row);
            }
            if(false===$ok)throw new RuntimeException('Consent state write failed.');

            $logged=self::insert_log($contact_id,$email_id,$email->value,'email','marketing_email',$status,$meta,$event_at);
            if(!$logged)throw new RuntimeException('Consent audit write failed.');

            if(class_exists('LV_CRM_Extensions')){
                LV_CRM_Extensions::contact_log($contact_id,'consent_changed','Согласие на email для '.$email->value.': '.self::status_options()[$status].'.',array('email_id'=>$email_id,'status'=>$status,'source'=>$meta['source'],'document_version'=>$meta['document_version']));
            }
            self::touch_contact($contact_id);
            $wpdb->query('COMMIT');
        }catch(Throwable $e){
            $wpdb->query('ROLLBACK');
            return false;
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Contact-level consent model
    // ------------------------------------------------------------------

    public static function get_generic( $contact_id, $consent_type ) {
        global $wpdb;
        $consent_type=sanitize_key($consent_type);
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM '.self::table_name().' WHERE contact_id=%d AND contact_email_id=0 AND channel=%s AND consent_type=%s ORDER BY id DESC LIMIT 1',
            absint($contact_id),'contact',$consent_type
        ));
    }

    public static function get_generic_for_contact( $contact_id ) {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare(
            'SELECT * FROM '.self::table_name().' WHERE contact_id=%d AND contact_email_id=0 AND channel=%s AND consent_type IN (%s,%s) ORDER BY id ASC',
            absint($contact_id),'contact','personal_data','marketing'
        ));
        $map=array();foreach((array)$rows as$r)$map[$r->consent_type]=$r;return$map;
    }

    public static function set_generic_status( $contact_id, $consent_type, $status, $data=array() ) {
        global $wpdb;
        $contact_id=absint($contact_id);$consent_type=sanitize_key($consent_type);$status=self::sanitize_status($status);
        if(!$contact_id||!array_key_exists($consent_type,self::consent_type_options()))return false;
        $contact=LV_CRM_Extensions::get_contact($contact_id);if(!$contact)return false;

        $meta=self::normalize_meta($data);
        if($meta['source_application_id']&&!self::application_is_linked($meta['source_application_id'],$contact_id))$meta['source_application_id']=0;
        $channels=self::sanitize_channels($data['channels']??array());
        $channels_json=wp_json_encode($channels,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $event_at=self::event_at($data);$event_date=mysql2date('Y-m-d',$event_at);$now=current_time('mysql');
        $existing=self::get_generic($contact_id,$consent_type);
        if(!$existing&&'unknown'===$status)return true;

        $obtained_at=$existing?$existing->obtained_at:null;$revoked_at=$existing?$existing->revoked_at:null;
        if('granted'===$status){
            // Saving the contact card without changing a consent must not turn
            // the save time into a new consent event. Preserve the original
            // timestamp when the same granted state is saved for the same day.
            if($existing&&'granted'===$existing->status&&$existing->obtained_at&&mysql2date('Y-m-d',$existing->obtained_at)===$event_date&&self::same_evidence_context($existing,$meta,$channels_json))$obtained_at=$existing->obtained_at;
            else$obtained_at=$event_at;
            $revoked_at=null;
        }elseif('revoked'===$status){
            if($existing&&'revoked'===$existing->status&&$existing->revoked_at&&mysql2date('Y-m-d',$existing->revoked_at)===$event_date&&self::same_evidence_context($existing,$meta,$channels_json))$revoked_at=$existing->revoked_at;
            else$revoked_at=$event_at;
        }elseif('unknown'===$status){$revoked_at=null;}

        $row=array(
            'contact_id'=>$contact_id,'contact_email_id'=>0,'channel'=>'contact','consent_type'=>$consent_type,'status'=>$status,
            'obtained_at'=>$obtained_at,'source'=>$meta['source'],'source_application_id'=>$meta['source_application_id'],'evidence'=>$meta['evidence'],
            'document_version'=>$meta['document_version'],'channels_json'=>$channels_json,'form_code'=>$meta['form_code'],'form_schema_version'=>$meta['form_schema_version'],
            'submission_uuid'=>$meta['submission_uuid'],'source_url'=>$meta['source_url'],'source_ip'=>$meta['source_ip'],'user_agent'=>$meta['user_agent'],
            'revoked_at'=>$revoked_at,'updated_by'=>get_current_user_id(),'updated_at'=>$now
        );
        if($existing&&self::same_state($existing,$row))return true;

        $wpdb->query('START TRANSACTION');
        try{
            if($existing)$ok=$wpdb->update(self::table_name(),$row,array('id'=>absint($existing->id)));
            else{$row['created_at']=$now;$ok=$wpdb->insert(self::table_name(),$row);}
            if(false===$ok)throw new RuntimeException('Generic consent state write failed.');
            if(!self::insert_log($contact_id,0,'','contact',$consent_type,$status,$meta,$event_at,$channels_json))throw new RuntimeException('Generic consent audit write failed.');
            if(class_exists('LV_CRM_Extensions')){
                LV_CRM_Extensions::contact_log($contact_id,'consent_changed',(self::consent_type_options()[$consent_type]??$consent_type).': '.self::status_options()[$status].'.',array('consent_type'=>$consent_type,'status'=>$status,'source'=>$meta['source'],'form_code'=>$meta['form_code'],'document_version'=>$meta['document_version']));
            }
            self::touch_contact($contact_id);$wpdb->query('COMMIT');
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return false;}
        return true;
    }

    /**
     * Bridge a generic marketing decision to email-level states for addresses
     * that exist at the time of the decision. This preserves the strict mailing
     * exporter and does not retroactively consent future email addresses.
     */
    public static function sync_marketing_to_current_emails( $contact_id, $status, $data=array() ) {
        $emails=LV_CRM_Extensions::contact_emails(absint($contact_id));
        $ok=true;
        foreach((array)$emails as$email){
            if(!self::set_status($contact_id,$email->id,$status,$data))$ok=false;
        }
        return$ok;
    }

    public static function generic_history( $contact_id, $limit=100 ) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM '.self::log_table_name().' WHERE contact_id=%d AND consent_type IN (%s,%s) ORDER BY created_at DESC,id DESC LIMIT %d',
            absint($contact_id),'personal_data','marketing',max(1,absint($limit))
        ));
    }

    public static function decode_channels( $row ) {
        if(!$row||empty($row->channels_json))return array();
        $v=json_decode((string)$row->channels_json,true);return is_array($v)?array_values(array_filter(array_map('sanitize_key',$v))):array();
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    private static function sanitize_status($status){$status=sanitize_key($status);return array_key_exists($status,self::status_options())?$status:'unknown';}

    private static function sanitize_channels($channels){
        $out=array();foreach((array)$channels as$c){$c=sanitize_key($c);if($c)$out[]=$c;}return array_values(array_unique($out));
    }

    private static function normalize_meta($data){
        $source=sanitize_key($data['source']??'manual');if(!array_key_exists($source,self::source_options()))$source='other';
        return array(
            'source'=>$source,
            'source_application_id'=>absint($data['source_application_id']??0),
            'evidence'=>sanitize_textarea_field($data['evidence']??''),
            'document_version'=>sanitize_text_field($data['document_version']??''),
            'form_code'=>sanitize_key($data['form_code']??''),
            'form_schema_version'=>sanitize_text_field($data['form_schema_version']??''),
            'submission_uuid'=>sanitize_text_field($data['submission_uuid']??''),
            'source_url'=>esc_url_raw($data['source_url']??''),
            'source_ip'=>sanitize_text_field($data['source_ip']??''),
            'user_agent'=>sanitize_textarea_field($data['user_agent']??''),
        );
    }

    private static function event_at($data){
        $raw=sanitize_text_field($data['event_at']??'');
        if($raw&&preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',$raw))return$raw;
        $date=sanitize_text_field($data['event_date']??'');
        if($date&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))return$date.' '.current_time('H:i:s');
        return current_time('mysql');
    }

    private static function application_is_linked($application_id,$contact_id){
        global$wpdb;return(bool)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.LV_CRM_Extensions::application_contacts_table().' WHERE application_id=%d AND contact_id=%d',absint($application_id),absint($contact_id)));
    }

    private static function same_evidence_context($existing,$meta,$channels_json){
        $pairs=array(
            'source'=>$meta['source'],'source_application_id'=>$meta['source_application_id'],'evidence'=>$meta['evidence'],
            'document_version'=>$meta['document_version'],'channels_json'=>$channels_json,'form_code'=>$meta['form_code'],
            'form_schema_version'=>$meta['form_schema_version'],'submission_uuid'=>$meta['submission_uuid'],'source_url'=>$meta['source_url'],
            'source_ip'=>$meta['source_ip'],'user_agent'=>$meta['user_agent'],
        );
        foreach($pairs as$key=>$value)if((string)($existing->$key??'')!==(string)$value)return false;
        return true;
    }

    private static function same_state($existing,$row){
        foreach(array('status','source','source_application_id','evidence','document_version','channels_json','form_code','form_schema_version','submission_uuid','source_url','source_ip','user_agent','obtained_at','revoked_at') as$key){
            if((string)($existing->$key??'')!==(string)($row[$key]??''))return false;
        }
        return true;
    }

    private static function insert_log($contact_id,$email_id,$email_value,$channel,$consent_type,$status,$meta,$event_at,$channels_json=''){
        global$wpdb;
        if(''===$channels_json)$channels_json=wp_json_encode(self::sanitize_channels(array('email'===$channel?'email':$channel)),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return $wpdb->insert(self::log_table_name(),array(
            'contact_id'=>absint($contact_id),'contact_email_id'=>absint($email_id),'email_value'=>sanitize_email($email_value),
            'channel'=>sanitize_key($channel),'consent_type'=>sanitize_key($consent_type),'status'=>self::sanitize_status($status),'source'=>$meta['source'],
            'source_application_id'=>absint($meta['source_application_id']),'evidence'=>$meta['evidence'],'document_version'=>$meta['document_version'],
            'channels_json'=>$channels_json,'form_code'=>$meta['form_code'],'form_schema_version'=>$meta['form_schema_version'],'submission_uuid'=>$meta['submission_uuid'],
            'source_url'=>$meta['source_url'],'source_ip'=>$meta['source_ip'],'user_agent'=>$meta['user_agent'],'event_at'=>$event_at,
            'user_id'=>get_current_user_id(),'created_at'=>current_time('mysql')
        ));
    }

    public static function delete_current_for_email( $email_id ) {
        global $wpdb;
        return $wpdb->delete(self::table_name(),array('contact_email_id'=>absint($email_id),'channel'=>'email'),array('%d','%s'));
    }

    public static function touch_contact($contact_id){
        global $wpdb;$now=current_time('mysql');
        return $wpdb->update(LV_CRM_Extensions::contacts_table(),array('updated_at'=>$now,'last_activity_at'=>$now),array('id'=>absint($contact_id)),array('%s','%s'),array('%d'));
    }
}
