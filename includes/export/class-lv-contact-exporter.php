<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Contact export engine: working/full XLSX, universal/mailing CSV and provider profiles. */
final class LV_Contact_Exporter {
    const BATCH_SIZE = 300;

    public static function export_log_table(){ global $wpdb; return $wpdb->prefix.'lv_crm_export_log'; }

    public static function profiles(){
        return array(
            'xlsx_working'=>array('label'=>'Excel — рабочая таблица','format'=>'xlsx','mailing'=>false),
            'xlsx_full'=>array('label'=>'Excel — полная база','format'=>'xlsx','mailing'=>false),
            'csv_generic'=>array('label'=>'CSV UTF-8 — универсальный','format'=>'csv','mailing'=>false),
            'csv_mailing'=>array('label'=>'Email-рассылка — безопасный CSV','format'=>'csv','mailing'=>true),
            'csv_unisender'=>array('label'=>'UniSender CSV','format'=>'csv','mailing'=>true),
            'csv_mailchimp'=>array('label'=>'Mailchimp CSV','format'=>'csv','mailing'=>true),
        );
    }

    public static function sanitize_cell($value){
        if(is_bool($value))$value=$value?'Да':'Нет';
        if(is_array($value))$value=implode(', ',array_map('strval',$value));
        $value=(string)$value;
        if(preg_match('/^[=+\-@]/u',$value) && !preg_match('/^\+[0-9\s()\-]+$/u',$value))$value="'".$value;
        return $value;
    }

    public static function resolve_ids($filters,$mode='current',$selected_ids=array()){
        $mode=sanitize_key($mode);
        $selected_ids=array_values(array_unique(array_filter(array_map('absint',(array)$selected_ids))));
        if('selected'===$mode)return $selected_ids;
        if('all'===$mode)$filters=array();
        $query=new LV_Contact_Query($filters);
        return $query->ids();
    }

    private static function fetch_rows_by_ids($ids){
        global $wpdb;
        $ids=array_values(array_filter(array_map('absint',(array)$ids)));
        if(!$ids)return array();
        $in=implode(',',array_fill(0,count($ids),'%d'));
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.LV_CRM_Extensions::contacts_table()." WHERE id IN ({$in}) ORDER BY updated_at DESC,id DESC",$ids));
        return LV_Contact_Query::hydrate_rows($rows);
    }

    private static function fetch_custom_fields($ids){
        global $wpdb;
        $ids=array_values(array_filter(array_map('absint',(array)$ids)));
        if(!$ids)return array();
        $in=implode(',',array_fill(0,count($ids),'%d'));
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.LV_CRM_Extensions::contact_fields_table()." WHERE contact_id IN ({$in}) ORDER BY contact_id,id",$ids));
        $map=array();foreach($rows as$r)$map[(int)$r->contact_id][]=$r;return$map;
    }

    private static function fetch_linked_apps($ids){
        global $wpdb;
        $ids=array_values(array_filter(array_map('absint',(array)$ids)));
        if(!$ids)return array();
        $in=implode(',',array_fill(0,count($ids),'%d'));
        $rows=$wpdb->get_results($wpdb->prepare('SELECT l.contact_id,a.id application_id,a.form_title,a.submitted_at,a.processed,a.assignee_id FROM '.LV_CRM_Extensions::application_contacts_table().' l INNER JOIN '.LV_Applications_Plugin::table_name()." a ON a.id=l.application_id WHERE l.contact_id IN ({$in}) ORDER BY l.contact_id,a.submitted_at DESC",$ids));
        $map=array();foreach($rows as$r)$map[(int)$r->contact_id][]=$r;return$map;
    }

    public static function analyze($filters,$mode='current',$selected_ids=array(),$ignore_consent=false){
        $ids=self::resolve_ids($filters,$mode,$selected_ids);
        return self::analyze_ids($ids,$ignore_consent);
    }

    public static function analyze_ids($ids,$ignore_consent=false){
        $ids=array_values(array_unique(array_filter(array_map('absint',(array)$ids))));
        $stats=array(
            'contacts'=>count($ids),'with_email'=>0,'without_email'=>0,'multiple_email'=>0,'email_addresses'=>0,
            'invalid_email'=>0,'duplicate_email'=>0,'conflict_email'=>0,'consent_granted'=>0,'consent_unknown'=>0,
            'consent_revoked'=>0,'mailing_rows'=>0,'mailing_rows_admin'=>0,'active_mailing_rows'=>0,
            'consent_override'=>(bool)$ignore_consent,
        );
        $email_records=array();
        foreach(array_chunk($ids,self::BATCH_SIZE) as$chunk){
            foreach(self::fetch_rows_by_ids($chunk) as$c){
                $emails=$c->lv_emails??array();
                if($emails)$stats['with_email']++;else$stats['without_email']++;
                if(count($emails)>1)$stats['multiple_email']++;
                foreach($emails as$e){
                    $stats['email_addresses']++;
                    $norm=strtolower(trim($e->normalized?:$e->value));
                    $valid=(bool)is_email($norm);
                    if(!$valid)$stats['invalid_email']++;
                    $cs=$e->lv_consent??null;$status=$cs?$cs->status:'unknown';
                    if('granted'===$status)$stats['consent_granted']++;
                    elseif('revoked'===$status)$stats['consent_revoked']++;
                    else$stats['consent_unknown']++;
                    if($norm)$email_records[$norm][]=array('contact_id'=>(int)$c->id,'valid'=>$valid,'status'=>$status);
                }
            }
        }
        foreach($email_records as$records){
            $contacts=array_values(array_unique(array_column($records,'contact_id')));
            if(count($records)>1){
                $stats['duplicate_email']+=count($records)-1;
                if(count($contacts)>1)$stats['conflict_email']+=count($records);
            }
            if(count($contacts)>1)continue;
            $safe=false;$admin=false;$revoked=false;
            foreach($records as$r){
                if('revoked'===$r['status']){$revoked=true;break;}
                if($r['valid']){
                    $admin=true;
                    if('granted'===$r['status'])$safe=true;
                }
            }
            if(!$revoked&&$safe)$stats['mailing_rows']++;
            if(!$revoked&&$admin)$stats['mailing_rows_admin']++;
        }
        $stats['active_mailing_rows']=$ignore_consent?$stats['mailing_rows_admin']:$stats['mailing_rows'];
        return$stats;
    }

    public static function download($profile,$filters,$mode='current',$selected_ids=array(),$segment_name='',$ignore_consent=false){
        $profiles=self::profiles();
        $profile=sanitize_key($profile);
        if(!isset($profiles[$profile]))wp_die('Неизвестный формат экспорта.');
        if('xlsx'===$profiles[$profile]['format']&&!class_exists('ZipArchive'))wp_die('Для экспорта XLSX требуется PHP-расширение ZipArchive.');

        $ids=self::resolve_ids($filters,$mode,$selected_ids);
        $ignore_consent=(bool)$ignore_consent && !empty($profiles[$profile]['mailing']);
        $stats=self::analyze_ids($ids,$ignore_consent);
        $stamp=current_time('Y-m-d_H-i');$base='contacts_'.$stamp;
        $rows_count=!empty($profiles[$profile]['mailing'])?$stats['active_mailing_rows']:count($ids);
        $filename='xlsx_working'===$profile?$base.'.xlsx':('xlsx_full'===$profile?$base.'_full.xlsx':$base.'.csv');
        self::log_export($profile,$filters,$mode,count($ids),$rows_count,$filename,$segment_name,$stats);

        if('xlsx_working'===$profile)self::download_working_xlsx($ids,$filename);
        elseif('xlsx_full'===$profile)self::download_full_xlsx($ids,$filename,$filters,$segment_name);
        else self::download_csv($ids,$profile,$filename,$segment_name,$ignore_consent);
    }

    private static function contact_flat_row($c){
        $emails=$c->lv_emails??array();$phones=$c->lv_phones??array();$tags=$c->lv_tags??array();
        $email_values=wp_list_pluck($emails,'value');$phone_values=wp_list_pluck($phones,'value');$tag_values=wp_list_pluck($tags,'name');
        $primaryConsent=$emails?($emails[0]->lv_consent??null):null;
        $generic=$c->lv_generic_consents??array();$pd=$generic['personal_data']??($c->lv_personal_data_consent??null);$marketing=$generic['marketing']??($c->lv_marketing_consent??null);
        $marketing_channels=$marketing?LV_Consent_Service::decode_channels($marketing):array();$channel_labels=LV_Consent_Service::channel_labels();$channel_names=array();foreach($marketing_channels as$ch)$channel_names[]=$channel_labels[$ch]??$ch;
        return array(
            'ID'=>$c->id,
            'Тип'=>'organization'===$c->contact_type?'Организация':'Человек',
            'Имя / название'=>$c->display_name,
            'Организация'=>$c->organization,
            'Основной email'=>$email_values?$email_values[0]:'',
            'Дополнительные email'=>count($email_values)>1?implode('; ',array_slice($email_values,1)):'',
            'Основной телефон'=>$phone_values?$phone_values[0]:'',
            'Дополнительные телефоны'=>count($phone_values)>1?implode('; ',array_slice($phone_values,1)):'',
            'Метки'=>implode(', ',$tag_values),
            'Куратор'=>$c->curator_user_id?LV_Applications_Plugin::assignee_name($c->curator_user_id):'',
            'Количество заявок'=>(int)($c->lv_application_count??0),
            'Дата последней заявки'=>$c->lv_last_application_at?mysql2date('d.m.Y H:i',$c->lv_last_application_at):'',
            'Согласие на ПД'=>$pd?LV_Consent_Service::status_options()[$pd->status]:'Неизвестно',
            'Дата согласия на ПД'=>$pd&&$pd->obtained_at?mysql2date('d.m.Y H:i',$pd->obtained_at):'',
            'Источник согласия на ПД'=>$pd&&isset(LV_Consent_Service::source_options()[$pd->source])?LV_Consent_Service::source_options()[$pd->source]:'',
            'Версия согласия на ПД'=>$pd?$pd->document_version:'',
            'Согласие на рассылку'=>$marketing?LV_Consent_Service::status_options()[$marketing->status]:'Неизвестно',
            'Дата согласия на рассылку'=>$marketing&&$marketing->obtained_at?mysql2date('d.m.Y H:i',$marketing->obtained_at):'',
            'Источник согласия на рассылку'=>$marketing&&isset(LV_Consent_Service::source_options()[$marketing->source])?LV_Consent_Service::source_options()[$marketing->source]:'',
            'Версия согласия на рассылку'=>$marketing?$marketing->document_version:'',
            'Каналы рассылки'=>implode(', ',$channel_names),
            'Согласие на основной email'=>$primaryConsent?LV_Consent_Service::status_options()[$primaryConsent->status]:'Неизвестно',
            'Дата email-согласия'=>$primaryConsent&&$primaryConsent->obtained_at?mysql2date('d.m.Y',$primaryConsent->obtained_at):'',
            'Источник email-согласия'=>$primaryConsent&&isset(LV_Consent_Service::source_options()[$primaryConsent->source])?LV_Consent_Service::source_options()[$primaryConsent->source]:'',
            'Дата создания'=>mysql2date('d.m.Y H:i',$c->created_at),
            'Дата обновления'=>mysql2date('d.m.Y H:i',$c->updated_at),
            'Последняя активность'=>!empty($c->last_activity_at)?mysql2date('d.m.Y H:i',$c->last_activity_at):'',
        );
    }

    private static function flat_headers(){
        $sample=(object)array(
            'id'=>'','contact_type'=>'person','display_name'=>'','organization'=>'','curator_user_id'=>0,
            'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'),'last_activity_at'=>'',
            'lv_emails'=>array(),'lv_phones'=>array(),'lv_tags'=>array(),'lv_application_count'=>0,'lv_last_application_at'=>null
        );
        return array_keys(self::contact_flat_row($sample));
    }

    private static function download_working_xlsx($ids,$filename){
        $writer=new LV_XLSX_Writer();
        $writer->add_sheet_stream('Контакты',function()use($ids){
            yield self::flat_headers();
            foreach(array_chunk($ids,self::BATCH_SIZE) as$chunk){
                foreach(self::fetch_rows_by_ids($chunk) as$c){
                    yield array_map(array(__CLASS__,'sanitize_cell'),array_values(self::contact_flat_row($c)));
                }
            }
        });
        $writer->download($filename);
    }

    private static function download_full_xlsx($ids,$filename,$filters,$segment_name){
        $writer=new LV_XLSX_Writer();

        $writer->add_sheet_stream('Контакты',function()use($ids){
            yield self::flat_headers();
            foreach(array_chunk($ids,self::BATCH_SIZE) as$chunk){
                foreach(self::fetch_rows_by_ids($chunk) as$c){
                    yield array_map(array(__CLASS__,'sanitize_cell'),array_values(self::contact_flat_row($c)));
                }
            }
        });

        $writer->add_sheet_stream('Доп. сведения',function()use($ids){
            yield array('ID контакта','Ключ поля','Название поля','Тип','Значение');
            foreach(array_chunk($ids,self::BATCH_SIZE) as$chunk){
                $map=self::fetch_custom_fields($chunk);
                foreach($chunk as$cid)foreach($map[(int)$cid]??array() as$f)yield array($cid,self::sanitize_cell($f->field_key??''),self::sanitize_cell($f->field_label),self::sanitize_cell($f->field_type),self::sanitize_cell($f->field_value));
            }
        });

        $writer->add_sheet_stream('Связанные заявки',function()use($ids){
            yield array('ID контакта','ID заявки','Форма','Дата','Статус','Ответственный');
            foreach(array_chunk($ids,self::BATCH_SIZE) as$chunk){
                $map=self::fetch_linked_apps($chunk);
                foreach($chunk as$cid)foreach($map[(int)$cid]??array() as$a)yield array($cid,$a->application_id,self::sanitize_cell($a->form_title),mysql2date('d.m.Y H:i',$a->submitted_at),$a->processed?'Обработано':'Не обработано',LV_Applications_Plugin::assignee_name($a->assignee_id));
            }
        });

        $writer->add_sheet_stream('Согласия',function()use($ids){
            yield array('ID контакта','Вид согласия','Email','Основной','Статус','Дата получения','Источник','Каналы','Версия документа','Код формы','Связанная заявка','UUID отправки','Дата отзыва','Подтверждение');
            foreach(array_chunk($ids,self::BATCH_SIZE) as$chunk){
                foreach(self::fetch_rows_by_ids($chunk) as$c){
                    foreach(array('personal_data','marketing') as$type){
                        $cs=($c->lv_generic_consents??array())[$type]??null;if(!$cs)continue;$channels=LV_Consent_Service::decode_channels($cs);$labels=LV_Consent_Service::channel_labels();$names=array();foreach($channels as$ch)$names[]=$labels[$ch]??$ch;
                        yield array($c->id,LV_Consent_Service::consent_type_options()[$type]??$type,'','',LV_Consent_Service::status_options()[$cs->status],$cs->obtained_at?mysql2date('d.m.Y H:i',$cs->obtained_at):'',isset(LV_Consent_Service::source_options()[$cs->source])?LV_Consent_Service::source_options()[$cs->source]:'',implode(', ',$names),self::sanitize_cell($cs->document_version),self::sanitize_cell($cs->form_code),$cs->source_application_id,self::sanitize_cell($cs->submission_uuid),$cs->revoked_at?mysql2date('d.m.Y H:i',$cs->revoked_at):'',self::sanitize_cell($cs->evidence));
                    }
                    foreach($c->lv_emails??array() as$e){
                        $cs=$e->lv_consent??null;$status=$cs?$cs->status:'unknown';
                        yield array($c->id,'Email-рассылка (адрес) ',self::sanitize_cell($e->value),$e->is_primary?'Да':'Нет',LV_Consent_Service::status_options()[$status],$cs&&$cs->obtained_at?mysql2date('d.m.Y H:i',$cs->obtained_at):'',$cs&&isset(LV_Consent_Service::source_options()[$cs->source])?LV_Consent_Service::source_options()[$cs->source]:'','E-mail',$cs?self::sanitize_cell($cs->document_version):'',$cs?self::sanitize_cell($cs->form_code):'',$cs?$cs->source_application_id:0,$cs?self::sanitize_cell($cs->submission_uuid):'',$cs&&$cs->revoked_at?mysql2date('d.m.Y H:i',$cs->revoked_at):'',$cs?self::sanitize_cell($cs->evidence):'');
                    }
                }
            }
        });

        $info=array(
            array('Параметр','Значение'),
            array('Дата формирования',current_time('d.m.Y H:i')),
            array('Сотрудник',wp_get_current_user()->display_name),
            array('Сегмент',$segment_name?:'—'),
            array('Количество контактов',count($ids)),
            array('Фильтры',wp_json_encode(LV_Contact_Query::sanitize_filters($filters),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
        );
        $writer->add_sheet('Сведения о выгрузке',$info);
        $writer->download($filename);
    }

    private static function mailing_email_owners($ids){
        global $wpdb;
        $owners=array();
        foreach(array_chunk($ids,self::BATCH_SIZE) as$chunk){
            $chunk=array_values(array_filter(array_map('absint',$chunk)));
            if(!$chunk)continue;
            $in=implode(',',array_fill(0,count($chunk),'%d'));
            $rows=$wpdb->get_results($wpdb->prepare(
                'SELECT contact_id,value,normalized FROM '.LV_CRM_Extensions::contact_emails_table()." WHERE contact_id IN ({$in})",
                $chunk
            ));
            foreach((array)$rows as$row){
                $norm=strtolower(trim($row->normalized?:$row->value));
                if(!$norm||!is_email($norm))continue;
                $cid=(int)$row->contact_id;
                if(!array_key_exists($norm,$owners))$owners[$norm]=$cid;
                elseif($owners[$norm]!==$cid)$owners[$norm]=0; // 0 marks a cross-contact conflict.
            }
        }
        return$owners;
    }

    private static function mailing_records_iter($ids,$segment_name,$ignore_consent=false){
        $owners=self::mailing_email_owners($ids);
        $emitted=array();
        foreach(array_chunk($ids,self::BATCH_SIZE) as$chunk){
            foreach(self::fetch_rows_by_ids($chunk) as$c){
                $phone=!empty($c->lv_phones[0]->value)?$c->lv_phones[0]->value:'';
                $tags=implode(', ',wp_list_pluck($c->lv_tags??array(),'name'));
                foreach($c->lv_emails??array() as$e){
                    $norm=strtolower(trim($e->normalized?:$e->value));
                    if(!$norm||!is_email($norm)||isset($emitted[$norm]))continue;
                    if(!isset($owners[$norm])||(int)$owners[$norm]!== (int)$c->id)continue;
                    $cs=$e->lv_consent??null;$status=$cs?$cs->status:'unknown';
                    // Explicit revocation remains a hard stop even for the administrator override.
                    if('revoked'===$status)continue;
                    if(!$ignore_consent&&'granted'!==$status)continue;
                    $emitted[$norm]=true;
                    yield array('contact'=>$c,'email'=>$e,'consent'=>$cs,'status'=>$status,'phone'=>$phone,'tags'=>$tags,'segment'=>$segment_name);
                }
            }
        }
    }

    private static function split_name($full){
        return array($full,'');
    }

    private static function download_csv($ids,$profile,$filename,$segment_name,$ignore_consent=false){
        while(ob_get_level())ob_end_clean();
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.rawurlencode($filename).'"; filename*=UTF-8\'\''.rawurlencode($filename));
        header('X-Content-Type-Options: nosniff');
        $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");

        if('csv_generic'===$profile){
            fputcsv($out,self::flat_headers());
            foreach(array_chunk($ids,self::BATCH_SIZE) as$chunk)foreach(self::fetch_rows_by_ids($chunk) as$c)fputcsv($out,array_map(array(__CLASS__,'sanitize_cell'),array_values(self::contact_flat_row($c))));
        }else{
            $records=self::mailing_records_iter($ids,$segment_name,$ignore_consent);
            if('csv_mailchimp'===$profile){
                fputcsv($out,array('Email Address','FNAME','LNAME','Organization','Phone','Tags'));
                foreach($records as$r){list($fn,$ln)=self::split_name($r['contact']->display_name);fputcsv($out,array_map(array(__CLASS__,'sanitize_cell'),array($r['email']->value,$fn,$ln,$r['contact']->organization,$r['phone'],$r['tags'])));}
            }elseif('csv_unisender'===$profile){
                fputcsv($out,array('Email','Name','Phone','Tags','Segment'));
                foreach($records as$r)fputcsv($out,array_map(array(__CLASS__,'sanitize_cell'),array($r['email']->value,$r['contact']->display_name,$r['phone'],$r['tags'],$r['segment'])));
            }else{
                fputcsv($out,array('Email','Name','Organization','Phone','Tags','Segment','Contact ID','Consent Date','Consent Source'));
                foreach($records as$r){
                    $cs=$r['consent'];
                    fputcsv($out,array_map(array(__CLASS__,'sanitize_cell'),array(
                        $r['email']->value,$r['contact']->display_name,$r['contact']->organization,$r['phone'],$r['tags'],$r['segment'],$r['contact']->id,
                        $cs&&$cs->obtained_at?mysql2date('Y-m-d',$cs->obtained_at):'',
                        $cs&&isset(LV_Consent_Service::source_options()[$cs->source])?LV_Consent_Service::source_options()[$cs->source]:''
                    )));
                }
            }
        }
        fclose($out);exit;
    }

    public static function log_export($profile,$filters,$mode,$contacts_count,$rows_count,$filename,$segment_name,$stats=array()){
        global$wpdb;
        $wpdb->insert(self::export_log_table(),array(
            'user_id'=>get_current_user_id(),'created_at'=>current_time('mysql'),'export_type'=>'contacts','profile'=>sanitize_key($profile),
            'mode'=>sanitize_key($mode),'filters_json'=>wp_json_encode(LV_Contact_Query::sanitize_filters($filters),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'segment_name'=>sanitize_text_field($segment_name),'contacts_count'=>absint($contacts_count),'rows_count'=>absint($rows_count),
            'filename'=>sanitize_file_name($filename),'stats_json'=>wp_json_encode($stats,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        ),array('%d','%s','%s','%s','%s','%s','%s','%d','%d','%s','%s'));
    }
}
