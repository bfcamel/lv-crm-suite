<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LV_Contact_Segments {
    public static function table_name(){global$wpdb;return$wpdb->prefix.'lv_crm_contact_segments';}

    public static function get($id){global$wpdb;return$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table_name().' WHERE id=%d',absint($id)));}
    public static function can_use($segment){if(!$segment)return false;return 'shared'===$segment->visibility||absint($segment->created_by)===get_current_user_id()||current_user_can('lv_manage_contact_segments');}
    public static function can_edit($segment){if(!$segment)return false;return absint($segment->created_by)===get_current_user_id()||current_user_can('lv_manage_contact_segments');}
    public static function filters($segment){if(!$segment||!self::can_use($segment))return array();$raw=json_decode((string)$segment->filters_json,true);return LV_Contact_Query::sanitize_filters(is_array($raw)?$raw:array());}
    public static function available(){
        global$wpdb;$uid=get_current_user_id();
        return$wpdb->get_results($wpdb->prepare("SELECT * FROM ".self::table_name()." WHERE visibility='shared' OR created_by=%d ORDER BY visibility DESC,name ASC",$uid));
    }
    public static function save($name,$filters,$visibility='private',$id=0){
        global$wpdb;$name=sanitize_text_field($name);if(!$name)return 0;$visibility=sanitize_key($visibility);
        if('shared'===$visibility&&!current_user_can('lv_manage_contact_segments'))$visibility='private';
        if(!in_array($visibility,array('private','shared'),true))$visibility='private';
        $filters=LV_Contact_Query::sanitize_filters($filters);$filters['segment_id']=0;$now=current_time('mysql');$id=absint($id);
        if($id){$old=self::get($id);if(!$old||!self::can_edit($old))return 0;$wpdb->update(self::table_name(),array('name'=>$name,'filters_json'=>wp_json_encode($filters,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'visibility'=>$visibility,'updated_at'=>$now),array('id'=>$id),array('%s','%s','%s','%s'),array('%d'));return$id;}
        $wpdb->insert(self::table_name(),array('name'=>$name,'filters_json'=>wp_json_encode($filters,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'visibility'=>$visibility,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now),array('%s','%s','%s','%d','%s','%s'));return absint($wpdb->insert_id);
    }
    public static function delete($id){global$wpdb;$segment=self::get($id);if(!$segment||!self::can_edit($segment))return false;return false!==$wpdb->delete(self::table_name(),array('id'=>absint($id)),array('%d'));}
}
