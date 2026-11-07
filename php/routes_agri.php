<?php
/* =====================================================================
   Kivu Systems — php/routes_agri.php
   AgriSmart MVP: farms, fields, crop cycles, livestock, inventory, yields.
   Scoped to the caller's account (multi-tenant). Admin/operator can write.
   ===================================================================== */
declare(strict_types=1);

function routes_agri(): array {
    $id = '(?P<id>[A-Za-z0-9_\-]{3,64})';
    return [
        ['GET',  '#^/agri/summary$#',              'h_agri_summary',      'read'],
        ['GET',  '#^/agri/farms$#',                'h_agri_farms_list',   'read'],
        ['POST', '#^/agri/farms$#',                'h_agri_farm_save',    'write'],
        ['PUT',  '#^/agri/farms/' . $id . '$#',    'h_agri_farm_save',    'write'],
        ['DELETE','#^/agri/farms/' . $id . '$#',   'h_agri_farm_delete',  'write'],
        ['GET',  '#^/agri/fields$#',               'h_agri_fields_list',  'read'],
        ['POST', '#^/agri/fields$#',               'h_agri_field_save',   'write'],
        ['PUT',  '#^/agri/fields/' . $id . '$#',   'h_agri_field_save',   'write'],
        ['DELETE','#^/agri/fields/' . $id . '$#',  'h_agri_field_delete', 'write'],
        ['GET',  '#^/agri/cycles$#',               'h_agri_cycles_list',  'read'],
        ['POST', '#^/agri/cycles$#',               'h_agri_cycle_save',   'write'],
        ['PUT',  '#^/agri/cycles/' . $id . '$#',   'h_agri_cycle_save',   'write'],
        ['DELETE','#^/agri/cycles/' . $id . '$#',  'h_agri_cycle_delete', 'write'],
        ['GET',  '#^/agri/livestock$#',            'h_agri_ls_list',      'read'],
        ['POST', '#^/agri/livestock$#',            'h_agri_ls_save',      'write'],
        ['PUT',  '#^/agri/livestock/' . $id . '$#','h_agri_ls_save',      'write'],
        ['DELETE','#^/agri/livestock/' . $id . '$#','h_agri_ls_delete',   'write'],
        ['GET',  '#^/agri/inventory$#',            'h_agri_inv_list',     'read'],
        ['POST', '#^/agri/inventory$#',            'h_agri_inv_save',     'write'],
        ['PUT',  '#^/agri/inventory/' . $id . '$#','h_agri_inv_save',     'write'],
        ['DELETE','#^/agri/inventory/' . $id . '$#','h_agri_inv_delete',  'write'],
        ['GET',  '#^/agri/yields$#',               'h_agri_yields_list',  'read'],
        ['POST', '#^/agri/yields$#',               'h_agri_yield_save',   'write'],
        ['PUT',  '#^/agri/yields/' . $id . '$#',   'h_agri_yield_save',   'write'],
        ['DELETE','#^/agri/yields/' . $id . '$#',  'h_agri_yield_delete', 'write'],
    ];
}

function agri_account(?array $u): string {
    ensure_schema();
    $a = scope_account($u);
    need($a !== null && $a !== '', 'Select an account to manage AgriSmart data (account_id).', 400);
    return $a;
}
function agri_row(string $table, string $id, string $accountId): array {
    $st = db()->prepare("SELECT * FROM `$table` WHERE id=? AND account_id=?");
    $st->execute([$id, $accountId]);
    $r = $st->fetch();
    need($r, 'Record not found', 404);
    return $r;
}
function agri_upsert(string $table, array $fields, string $id, string $accountId, ?array $u): array {
    $fields['account_id'] = $accountId;
    if ($id === '') {
        $id = uid(substr($table, 0, 4));
        $fields['id'] = $id;
        if (!isset($fields['created_at'])) $fields['created_at'] = now_dt();
        $cols = array_keys($fields); $vals = array_values($fields);
        db()->prepare("INSERT INTO `$table` (`".implode('`,`',$cols)."`) VALUES (".implode(',',array_fill(0,count($cols),'?')).")")->execute($vals);
    } else {
        agri_row($table, $id, $accountId);
        $sets=[]; $vals=[];
        foreach ($fields as $k=>$v) { $sets[]="`$k`=?"; $vals[]=$v; }
        $vals[]=$id; $vals[]=$accountId;
        db()->prepare("UPDATE `$table` SET ".implode(',',$sets)." WHERE id=? AND account_id=?")->execute($vals);
    }
    audit('agri_saved', $table, $id, ['by' => $u['username'] ?? '']);
    return agri_row($table, $id, $accountId);
}

function h_agri_summary($user, $p): array {
    $a = agri_account($user);
    $q = fn($sql) => (int)(db()->prepare($sql)->execute([$a]) ? db()->prepare($sql)->execute([$a]) : 0); // placeholder
    $counts = [];
    foreach ([['farms','agri_farms'],['fields','agri_fields'],['crop_cycles','agri_crop_cycles'],['livestock','agri_livestock'],['inventory_items','agri_inventory']] as $pair) {
        try { $st=db()->prepare("SELECT COUNT(*) FROM `{$pair[1]}` WHERE account_id=?"); $st->execute([$a]); $counts[$pair[0]]=(int)$st->fetchColumn(); }
        catch(Throwable $e){ $counts[$pair[0]]=0; }
    }
    try { $st=db()->prepare("SELECT COALESCE(SUM(quantity),0) FROM agri_yields WHERE account_id=?"); $st->execute([$a]); $counts['total_yield_qty']=(float)$st->fetchColumn(); }
    catch(Throwable $e){ $counts['total_yield_qty']=0; }
    return ['ok'=>true,'summary'=>$counts];
}

function h_agri_farms_list($user,$p): array { $a=agri_account($user); $st=db()->prepare('SELECT * FROM agri_farms WHERE account_id=? ORDER BY name'); $st->execute([$a]); return ['ok'=>true,'farms'=>$st->fetchAll()?:[]]; }
function h_agri_farm_save($user,$p): array {
    $a=agri_account($user); $b=body(); $id=(string)($p['id']??$b['id']??'');
    $row=agri_upsert('agri_farms',['name'=>trim((string)($b['name']??'')),'location'=>trim((string)($b['location']??''))?:null,'size_ha'=>$b['size_ha']!==''&&isset($b['size_ha'])?(float)$b['size_ha']:null,'owner_name'=>trim((string)($b['owner_name']??''))?:null,'notes'=>trim((string)($b['notes']??''))?:null,'updated_at'=>now_dt()],$id,$a,$user);
    need($row['name']!=='','Farm name is required'); return ['ok'=>true,'farm'=>$row];
}
function h_agri_farm_delete($user,$p): array { $a=agri_account($user); agri_row('agri_farms',(string)$p['id'],$a); db()->prepare('DELETE FROM agri_farms WHERE id=? AND account_id=?')->execute([(string)$p['id'],$a]); audit('agri_deleted','agri_farms',(string)$p['id'],[]); return ['ok'=>true]; }

function h_agri_fields_list($user,$p): array { $a=agri_account($user); $st=db()->prepare('SELECT f.*,fa.name farm_name FROM agri_fields f LEFT JOIN agri_farms fa ON fa.id=f.farm_id WHERE f.account_id=? ORDER BY fa.name,f.name'); $st->execute([$a]); return ['ok'=>true,'fields'=>$st->fetchAll()?:[]]; }
function h_agri_field_save($user,$p): array {
    $a=agri_account($user); $b=body(); $id=(string)($p['id']??$b['id']??''); $farm=(string)($b['farm_id']??''); need($farm!=='','farm_id required');
    $row=agri_upsert('agri_fields',['farm_id'=>$farm,'name'=>trim((string)($b['name']??'')),'crop'=>trim((string)($b['crop']??''))?:null,'soil_type'=>trim((string)($b['soil_type']??''))?:null,'area_ha'=>isset($b['area_ha'])&&$b['area_ha']!==''?(float)$b['area_ha']:null,'notes'=>trim((string)($b['notes']??''))?:null],$id,$a,$user);
    return ['ok'=>true,'field'=>$row];
}
function h_agri_field_delete($user,$p): array { $a=agri_account($user); agri_row('agri_fields',(string)$p['id'],$a); db()->prepare('DELETE FROM agri_fields WHERE id=? AND account_id=?')->execute([(string)$p['id'],$a]); return ['ok'=>true]; }

function h_agri_cycles_list($user,$p): array { $a=agri_account($user); $st=db()->prepare('SELECT c.*,f.name field_name FROM agri_crop_cycles c LEFT JOIN agri_fields f ON f.id=c.field_id WHERE c.account_id=? ORDER BY c.planted_at DESC,c.created_at DESC'); $st->execute([$a]); return ['ok'=>true,'cycles'=>$st->fetchAll()?:[]]; }
function h_agri_cycle_save($user,$p): array {
    $a=agri_account($user); $b=body(); $id=(string)($p['id']??$b['id']??'');
    $row=agri_upsert('agri_crop_cycles',['field_id'=>(string)($b['field_id']??''),'crop'=>trim((string)($b['crop']??'')),'variety'=>trim((string)($b['variety']??''))?:null,'planted_at'=>($b['planted_at']??'')?:null,'expected_harvest'=>($b['expected_harvest']??'')?:null,'harvested_at'=>($b['harvested_at']??'')?:null,'status'=>in_array(($b['status']??'planned'),['planned','growing','harvested','failed'],true)?$b['status']:'planned','yield_qty'=>isset($b['yield_qty'])&&$b['yield_qty']!==''?(float)$b['yield_qty']:null,'yield_unit'=>trim((string)($b['yield_unit']??'kg'))?:'kg','notes'=>trim((string)($b['notes']??''))?:null],$id,$a,$user);
    return ['ok'=>true,'cycle'=>$row];
}
function h_agri_cycle_delete($user,$p): array { $a=agri_account($user); agri_row('agri_crop_cycles',(string)$p['id'],$a); db()->prepare('DELETE FROM agri_crop_cycles WHERE id=? AND account_id=?')->execute([(string)$p['id'],$a]); return ['ok'=>true]; }

function h_agri_ls_list($user,$p): array { $a=agri_account($user); $st=db()->prepare('SELECT l.*,fa.name farm_name FROM agri_livestock l LEFT JOIN agri_farms fa ON fa.id=l.farm_id WHERE l.account_id=? ORDER BY l.species,l.tag'); $st->execute([$a]); return ['ok'=>true,'livestock'=>$st->fetchAll()?:[]]; }
function h_agri_ls_save($user,$p): array {
    $a=agri_account($user); $b=body(); $id=(string)($p['id']??$b['id']??'');
    $row=agri_upsert('agri_livestock',['farm_id'=>(string)($b['farm_id']??''),'tag'=>trim((string)($b['tag']??''))?:null,'species'=>trim((string)($b['species']??'')),'breed'=>trim((string)($b['breed']??''))?:null,'sex'=>trim((string)($b['sex']??''))?:null,'birth_date'=>($b['birth_date']??'')?:null,'status'=>in_array(($b['status']??'active'),['active','sold','deceased'],true)?$b['status']:'active','weight_kg'=>isset($b['weight_kg'])&&$b['weight_kg']!==''?(float)$b['weight_kg']:null,'notes'=>trim((string)($b['notes']??''))?:null],$id,$a,$user);
    return ['ok'=>true,'animal'=>$row];
}
function h_agri_ls_delete($user,$p): array { $a=agri_account($user); agri_row('agri_livestock',(string)$p['id'],$a); db()->prepare('DELETE FROM agri_livestock WHERE id=? AND account_id=?')->execute([(string)$p['id'],$a]); return ['ok'=>true]; }

function h_agri_inv_list($user,$p): array { $a=agri_account($user); $st=db()->prepare('SELECT i.*,fa.name farm_name FROM agri_inventory i LEFT JOIN agri_farms fa ON fa.id=i.farm_id WHERE i.account_id=? ORDER BY i.category,i.item_name'); $st->execute([$a]); return ['ok'=>true,'inventory'=>$st->fetchAll()?:[]]; }
function h_agri_inv_save($user,$p): array {
    $a=agri_account($user); $b=body(); $id=(string)($p['id']??$b['id']??'');
    $row=agri_upsert('agri_inventory',['farm_id'=>trim((string)($b['farm_id']??''))?:null,'item_name'=>trim((string)($b['item_name']??'')),'category'=>in_array(($b['category']??'other'),['seed','fertilizer','feed','chemical','equipment','other'],true)?$b['category']:'other','quantity'=>(float)($b['quantity']??0),'unit'=>trim((string)($b['unit']??'units'))?:'units','reorder_level'=>isset($b['reorder_level'])&&$b['reorder_level']!==''?(float)$b['reorder_level']:null,'cost'=>isset($b['cost'])&&$b['cost']!==''?(float)$b['cost']:null,'supplier'=>trim((string)($b['supplier']??''))?:null,'notes'=>trim((string)($b['notes']??''))?:null,'updated_at'=>now_dt()],$id,$a,$user);
    return ['ok'=>true,'item'=>$row];
}
function h_agri_inv_delete($user,$p): array { $a=agri_account($user); agri_row('agri_inventory',(string)$p['id'],$a); db()->prepare('DELETE FROM agri_inventory WHERE id=? AND account_id=?')->execute([(string)$p['id'],$a]); return ['ok'=>true]; }

function h_agri_yields_list($user,$p): array { $a=agri_account($user); $st=db()->prepare('SELECT y.*,fa.name farm_name FROM agri_yields y LEFT JOIN agri_farms fa ON fa.id=y.farm_id WHERE y.account_id=? ORDER BY y.harvest_date DESC,y.created_at DESC'); $st->execute([$a]); return ['ok'=>true,'yields'=>$st->fetchAll()?:[]]; }
function h_agri_yield_save($user,$p): array {
    $a=agri_account($user); $b=body(); $id=(string)($p['id']??$b['id']??'');
    $row=agri_upsert('agri_yields',['farm_id'=>trim((string)($b['farm_id']??''))?:null,'cycle_id'=>trim((string)($b['cycle_id']??''))?:null,'product'=>trim((string)($b['product']??'')),'quantity'=>(float)($b['quantity']??0),'unit'=>trim((string)($b['unit']??'kg'))?:'kg','harvest_date'=>($b['harvest_date']??'')?:null,'revenue'=>isset($b['revenue'])&&$b['revenue']!==''?(float)$b['revenue']:null,'notes'=>trim((string)($b['notes']??''))?:null],$id,$a,$user);
    return ['ok'=>true,'yield'=>$row];
}
function h_agri_yield_delete($user,$p): array { $a=agri_account($user); agri_row('agri_yields',(string)$p['id'],$a); db()->prepare('DELETE FROM agri_yields WHERE id=? AND account_id=?')->execute([(string)$p['id'],$a]); return ['ok'=>true]; }
?>