<?php
/* =====================================================================
   Kivu Systems — php/routes_health.php
   HealthConnect MVP: patients, appointments, vitals, care plans,
   secure-ish messaging log. Scoped to account. Sensitive data: RBAC +
   audit on all access; production requires encryption/consent/retention
   controls per applicable regulations.
   ===================================================================== */
declare(strict_types=1);

function routes_health(): array {
    $id = '(?P<id>[A-Za-z0-9_\-]{3,64})';
    return [
        ['GET',  '#^/health/summary$#',                'h_health_summary',      'read'],
        ['GET',  '#^/health/patients$#',               'h_health_patients_list','read'],
        ['POST', '#^/health/patients$#',               'h_health_patient_save', 'write'],
        ['GET',  '#^/health/patients/' . $id . '$#',   'h_health_patient_get',  'read'],
        ['PUT',  '#^/health/patients/' . $id . '$#',   'h_health_patient_save', 'write'],
        ['DELETE','#^/health/patients/' . $id . '$#',  'h_health_patient_delete','admin'],
        ['GET',  '#^/health/appointments$#',           'h_health_appts_list',   'read'],
        ['POST', '#^/health/appointments$#',           'h_health_appt_save',    'write'],
        ['PUT',  '#^/health/appointments/' . $id . '$#','h_health_appt_save',   'write'],
        ['DELETE','#^/health/appointments/' . $id . '$#','h_health_appt_delete', 'write'],
        ['GET',  '#^/health/vitals$#',                 'h_health_vitals_list',  'read'],
        ['POST', '#^/health/vitals$#',                 'h_health_vital_save',   'write'],
        ['DELETE','#^/health/vitals/' . $id . '$#',    'h_health_vital_delete', 'write'],
        ['GET',  '#^/health/care-plans$#',             'h_health_plans_list',   'read'],
        ['POST', '#^/health/care-plans$#',             'h_health_plan_save',    'write'],
        ['PUT',  '#^/health/care-plans/' . $id . '$#', 'h_health_plan_save',    'write'],
        ['DELETE','#^/health/care-plans/' . $id . '$#','h_health_plan_delete',  'write'],
        ['GET',  '#^/health/messages$#',               'h_health_msgs_list',    'read'],
        ['POST', '#^/health/messages$#',               'h_health_msg_save',     'write'],
    ];
}

function health_account(?array $u): string {
    ensure_schema();
    $a = scope_account($u);
    need($a !== null && $a !== '', 'Select an account to manage HealthConnect data (account_id).', 400);
    return $a;
}
function health_row(string $table, string $id, string $accountId): array {
    $st = db()->prepare("SELECT * FROM `$table` WHERE id=? AND account_id=?");
    $st->execute([$id, $accountId]); $r = $st->fetch();
    need($r, 'Record not found', 404);
    audit('health_read', $table, $id, ['account' => $accountId]);
    return $r;
}
function health_upsert(string $table, array $fields, string $id, string $accountId, ?array $u): array {
    $fields['account_id'] = $accountId;
    if ($id === '') {
        $id = uid(substr($table, 0, 4));
        $fields['id'] = $id;
        if (!isset($fields['created_at']) && in_array($table,['health_patients','health_appointments','health_care_plans'],true)) $fields['created_at']=now_dt();
        $cols=array_keys($fields); $vals=array_values($fields);
        db()->prepare("INSERT INTO `$table` (`".implode('`,`',$cols)."`) VALUES (".implode(',',array_fill(0,count($cols),'?')).")")->execute($vals);
    } else {
        $st=db()->prepare("SELECT id FROM `$table` WHERE id=? AND account_id=?"); $st->execute([$id,$accountId]); need($st->fetch(),'Record not found',404);
        $sets=[]; $vals=[];
        foreach($fields as $k=>$v){ $sets[]="`$k`=?"; $vals[]=$v; }
        $vals[]=$id; $vals[]=$accountId;
        db()->prepare("UPDATE `$table` SET ".implode(',',$sets)." WHERE id=? AND account_id=?")->execute($vals);
    }
    audit('health_saved', $table, $id, ['by' => $u['username'] ?? '']);
    $st=db()->prepare("SELECT * FROM `$table` WHERE id=? AND account_id=?"); $st->execute([$id,$accountId]); return $st->fetch() ?: [];
}

function h_health_summary($user,$p): array {
    $a=health_account($user); $c=[];
    foreach([['patients','health_patients'],['appointments','health_appointments'],['care_plans','health_care_plans']] as $x){
        try{$st=db()->prepare("SELECT COUNT(*) FROM `{$x[1]}` WHERE account_id=?");$st->execute([$a]);$c[$x[0]]=(int)$st->fetchColumn();}catch(Throwable $e){$c[$x[0]]=0;}
    }
    try{$st=db()->prepare("SELECT COUNT(*) FROM health_appointments WHERE account_id=? AND status='scheduled' AND scheduled_at>=?");$st->execute([$a,now_dt()]);$c['upcoming']=(int)$st->fetchColumn();}catch(Throwable $e){$c['upcoming']=0;}
    return ['ok'=>true,'summary'=>$c];
}

function h_health_patients_list($user,$p): array {
    $a=health_account($user);
    $q=trim((string)qp('q','')); $sql='SELECT * FROM health_patients WHERE account_id=?'; $args=[$a];
    if($q!==''){ $sql.=' AND (full_name LIKE ? OR mrn LIKE ? OR phone LIKE ?)'; $like="%$q%"; array_push($args,$like,$like,$like); }
    $sql.=' ORDER BY full_name LIMIT 500';
    $st=db()->prepare($sql); $st->execute($args); return ['ok'=>true,'patients'=>$st->fetchAll()?:[]];
}
function h_health_patient_get($user,$p): array { $a=health_account($user); return ['ok'=>true,'patient'=>health_row('health_patients',(string)$p['id'],$a)]; }
function h_health_patient_save($user,$p): array {
    $a=health_account($user); $b=body(); $id=(string)($p['id']??$b['id']??'');
    $name=trim((string)($b['full_name']??'')); need($name!=='','Patient name is required');
    $row=health_upsert('health_patients',['full_name'=>$name,'mrn'=>trim((string)($b['mrn']??''))?:null,'dob'=>($b['dob']??'')?:null,'sex'=>trim((string)($b['sex']??''))?:null,'phone'=>trim((string)($b['phone']??''))?:null,'email'=>trim((string)($b['email']??''))?:null,'address'=>trim((string)($b['address']??''))?:null,'emergency_contact'=>trim((string)($b['emergency_contact']??''))?:null,'consent_at'=>($b['consent_at']??'')?:null,'status'=>in_array(($b['status']??'active'),['active','inactive','archived'],true)?$b['status']:'active','notes'=>trim((string)($b['notes']??''))?:null,'updated_at'=>now_dt()],$id,$a,$user);
    return ['ok'=>true,'patient'=>$row];
}
function h_health_patient_delete($user,$p): array { $a=health_account($user); require_admin($user); health_row('health_patients',(string)$p['id'],$a); db()->prepare("UPDATE health_patients SET status='archived',updated_at=? WHERE id=? AND account_id=?")->execute([now_dt(),(string)$p['id'],$a]); audit('health_archived','health_patients',(string)$p['id'],[]); return ['ok'=>true]; }

function h_health_appts_list($user,$p): array {
    $a=health_account($user); $pid=trim((string)qp('patient_id',''));
    $sql='SELECT a.*,p.full_name patient_name FROM health_appointments a LEFT JOIN health_patients p ON p.id=a.patient_id WHERE a.account_id=?'; $args=[$a];
    if($pid!==''){ $sql.=' AND a.patient_id=?'; $args[]=$pid; }
    $sql.=' ORDER BY a.scheduled_at DESC LIMIT 500';
    $st=db()->prepare($sql); $st->execute($args); return ['ok'=>true,'appointments'=>$st->fetchAll()?:[]];
}
function h_health_appt_save($user,$p): array {
    $a=health_account($user); $b=body(); $id=(string)($p['id']??$b['id']??'');
    $when=dt_param($b['scheduled_at']??null, null); need($when,'scheduled_at is required');
    $row=health_upsert('health_appointments',['patient_id'=>(string)($b['patient_id']??''),'clinician'=>trim((string)($b['clinician']??''))?:null,'kind'=>in_array(($b['kind']??'consult'),['consult','followup','tele-nursing','home-visit'],true)?$b['kind']:'consult','scheduled_at'=>$when,'duration_min'=>max(5,(int)($b['duration_min']??30)),'status'=>in_array(($b['status']??'scheduled'),['scheduled','completed','canceled','no_show'],true)?$b['status']:'scheduled','channel'=>in_array(($b['channel']??'video'),['video','phone','in_person'],true)?$b['channel']:'video','reason'=>trim((string)($b['reason']??''))?:null,'notes'=>trim((string)($b['notes']??''))?:null],$id,$a,$user);
    return ['ok'=>true,'appointment'=>$row];
}
function h_health_appt_delete($user,$p): array { $a=health_account($user); $st=db()->prepare('DELETE FROM health_appointments WHERE id=? AND account_id=?'); $st->execute([(string)$p['id'],$a]); return ['ok'=>true]; }

function h_health_vitals_list($user,$p): array {
    $a=health_account($user); $pid=trim((string)qp('patient_id','')); need($pid!=='','patient_id is required');
    $st=db()->prepare('SELECT * FROM health_vitals WHERE account_id=? AND patient_id=? ORDER BY recorded_at DESC LIMIT 500'); $st->execute([$a,$pid]);
    audit('health_read','health_vitals',$pid,[]); return ['ok'=>true,'vitals'=>$st->fetchAll()?:[]];
}
function h_health_vital_save($user,$p): array {
    $a=health_account($user); $b=body();
    $row=health_upsert('health_vitals',['patient_id'=>(string)($b['patient_id']??''),'recorded_at'=>dt_param($b['recorded_at']??null, now_dt()),'recorded_by'=>trim((string)($b['recorded_by']??($user['full_name']??$user['username']??'')))?:null,'temp_c'=>isset($b['temp_c'])&&$b['temp_c']!==''?(float)$b['temp_c']:null,'pulse_bpm'=>isset($b['pulse_bpm'])&&$b['pulse_bpm']!==''?(int)$b['pulse_bpm']:null,'bp_systolic'=>isset($b['bp_systolic'])&&$b['bp_systolic']!==''?(int)$b['bp_systolic']:null,'bp_diastolic'=>isset($b['bp_diastolic'])&&$b['bp_diastolic']!==''?(int)$b['bp_diastolic']:null,'resp_rate'=>isset($b['resp_rate'])&&$b['resp_rate']!==''?(int)$b['resp_rate']:null,'spo2'=>isset($b['spo2'])&&$b['spo2']!==''?(int)$b['spo2']:null,'weight_kg'=>isset($b['weight_kg'])&&$b['weight_kg']!==''?(float)$b['weight_kg']:null,'glucose_mmol'=>isset($b['glucose_mmol'])&&$b['glucose_mmol']!==''?(float)$b['glucose_mmol']:null,'notes'=>trim((string)($b['notes']??''))?:null],(string)($p['id']??''),$a,$user);
    return ['ok'=>true,'vital'=>$row];
}
function h_health_vital_delete($user,$p): array { $a=health_account($user); db()->prepare('DELETE FROM health_vitals WHERE id=? AND account_id=?')->execute([(string)$p['id'],$a]); return ['ok'=>true]; }

function h_health_plans_list($user,$p): array {
    $a=health_account($user); $pid=trim((string)qp('patient_id',''));
    $sql='SELECT c.*,p.full_name patient_name FROM health_care_plans c LEFT JOIN health_patients p ON p.id=c.patient_id WHERE c.account_id=?'; $args=[$a];
    if($pid!==''){ $sql.=' AND c.patient_id=?'; $args[]=$pid; }
    $sql.=' ORDER BY c.updated_at DESC,c.created_at DESC'; $st=db()->prepare($sql); $st->execute($args); return ['ok'=>true,'plans'=>$st->fetchAll()?:[]];
}
function h_health_plan_save($user,$p): array {
    $a=health_account($user); $b=body(); $id=(string)($p['id']??$b['id']??'');
    $row=health_upsert('health_care_plans',['patient_id'=>(string)($b['patient_id']??''),'title'=>trim((string)($b['title']??'')),'diagnosis'=>trim((string)($b['diagnosis']??''))?:null,'goals'=>trim((string)($b['goals']??''))?:null,'interventions'=>trim((string)($b['interventions']??''))?:null,'status'=>in_array(($b['status']??'active'),['active','completed','paused'],true)?$b['status']:'active','start_date'=>($b['start_date']??'')?:null,'review_date'=>($b['review_date']??'')?:null,'created_by'=>trim((string)($b['created_by']??($user['full_name']??$user['username']??'')))?:null,'updated_at'=>now_dt()],$id,$a,$user);
    return ['ok'=>true,'plan'=>$row];
}
function h_health_plan_delete($user,$p): array { $a=health_account($user); db()->prepare('DELETE FROM health_care_plans WHERE id=? AND account_id=?')->execute([(string)$p['id'],$a]); return ['ok'=>true]; }

function h_health_msgs_list($user,$p): array {
    $a=health_account($user); $pid=trim((string)qp('patient_id','')); need($pid!=='','patient_id is required');
    $st=db()->prepare('SELECT * FROM health_messages WHERE account_id=? AND patient_id=? ORDER BY sent_at DESC LIMIT 500'); $st->execute([$a,$pid]);
    audit('health_read','health_messages',$pid,[]); return ['ok'=>true,'messages'=>$st->fetchAll()?:[]];
}
function h_health_msg_save($user,$p): array {
    $a=health_account($user); $b=body(); need(trim((string)($b['body']??''))!=='','Message body is required');
    $row=health_upsert('health_messages',['patient_id'=>(string)($b['patient_id']??''),'direction'=>in_array(($b['direction']??'outbound'),['inbound','outbound','internal'],true)?$b['direction']:'outbound','sender'=>trim((string)($b['sender']??($user['full_name']??$user['username']??'')))?:null,'body'=>trim((string)$b['body']),'channel'=>in_array(($b['channel']??'secure'),['secure','sms','email','note'],true)?$b['channel']:'secure','sent_at'=>now_dt()],(string)($p['id']??''),$a,$user);
    return ['ok'=>true,'message'=>$row];
}
?>