<?php
/* =====================================================================
   KIVU Tracker — php/routes_reports.php
   Report catalogue, report execution, CSV export and saved templates.
   ===================================================================== */
declare(strict_types=1);

function routes_reports(): array {
    $id = '(?P<id>[A-Za-z0-9_\-]{3,64})';
    return [
        ['GET', '#^/reports/types$#', 'h_report_types', 'read'],
        ['POST', '#^/reports/run$#', 'h_report_run', 'read'],
        ['GET', '#^/reports/run$#', 'h_report_run', 'read'],
        ['GET', '#^/reports/export\.csv$#', 'h_report_csv', 'read'],
        ['GET', '#^/reports/templates$#', 'h_report_templates', 'read'],
        ['POST', '#^/reports/templates$#', 'h_report_template_save', 'write'],
        ['PUT', '#^/reports/templates/' . $id . '$#', 'h_report_template_save', 'write'],
        ['DELETE', '#^/reports/templates/' . $id . '$#', 'h_report_template_delete', 'write'],
    ];
}

/* Collects report parameters from the JSON body or the query string. */
function report_params(): array {
    $p = [];
    foreach (['type', 'units', 'from', 'to', 'group', 'speed_limit', 'min_change', 'severity',
              'event_types', 'limit'] as $k) {
        $v = bval($k, null);
        if ($v !== null) $p[$k] = $v;
    }
    if (!isset($p['type']) && qp('type')) $p['type'] = qp('type');
    return $p;
}
function h_report_types($user, $p): array {
    return ['types' => report_types()];
}
function h_report_run($user, $p): array {
    $params = report_params();
    $type = (string)($params['type'] ?? 'unit_summary');
    need(in_array($type, array_column(report_types(), 'id'), true), 'Unknown report type: ' . $type);
    $report = report_run($type, $params, $user);
    $report['generated_at'] = date('c');
    $report['generated_by'] = $user['username'];
    audit('report_run', 'report', $type, ['from' => $report['from'], 'to' => $report['to'],
        'units' => count($report['units'])]);
    return $report;
}
function h_report_csv($user, $p): array {
    $params = report_params();
    $type = (string)($params['type'] ?? 'unit_summary');
    need(in_array($type, array_column(report_types(), 'id'), true), 'Unknown report type: ' . $type);
    $report = report_run($type, $params, $user);
    audit('report_export', 'report', $type, ['format' => 'csv']);
    return ['__raw' => "\xEF\xBB\xBF" . report_to_csv($report),      // BOM so Excel reads UTF-8
        '__content_type' => 'text/csv',
        '__filename' => 'kivu-' . $type . '-' . date('Ymd-His') . '.csv'];
}

function csv_cell($v): string {
    if ($v === null) return '';
    if (is_bool($v)) $v = $v ? 'yes' : 'no';
    if (is_array($v)) $v = jenc($v);
    $s = (string)$v;
    if (preg_match('/[",;\n]/', $s)) return '"' . str_replace('"', '""', $s) . '"';
    return $s;
}
function csv_row(array $cells): string {
    return implode(',', array_map('csv_cell', $cells)) . "\r\n";
}
function report_to_csv(array $rep): string {
    $out = csv_row(['Report', $rep['type']]);
    $out .= csv_row(['Period', $rep['from'], '->', $rep['to']]);
    $out .= csv_row(['Generated', $rep['generated_at'] ?? date('c')]);
    $out .= csv_row(['Units', implode(', ', array_map(fn($u) => $u['name'], $rep['units']))]);
    $out .= "\r\n" . csv_row(['Summary']);
    foreach ($rep['summary'] as $s) $out .= csv_row([$s['label'], $s['value']]);
    foreach ($rep['tables'] as $t) {
        $out .= "\r\n" . csv_row([$t['title'] ?? 'Table']);
        $out .= csv_row(array_map(fn($c) => $c['label'], $t['columns']));
        foreach ($t['rows'] as $row) {
            $line = [];
            foreach ($t['columns'] as $c) $line[] = $row[$c['key']] ?? '';
            $out .= csv_row($line);
        }
        if (!empty($t['totals'])) {
            $line = [];
            foreach ($t['columns'] as $i => $c) $line[] = $i === 0 ? 'TOTAL' : ($t['totals'][$c['key']] ?? '');
            $out .= csv_row($line);
        }
    }
    foreach ($rep['charts'] as $ch) {
        $out .= "\r\n" . csv_row([$ch['title'] . ' (chart data)']);
        $out .= csv_row(array_merge([$ch['type']], array_map(fn($s) => $s['name'], $ch['series'])));
        foreach ($ch['labels'] as $i => $label) {
            $line = [$label];
            foreach ($ch['series'] as $s) $line[] = $s['data'][$i] ?? '';
            $out .= csv_row($line);
        }
    }
    return $out;
}

/* ------------------------------------------------- saved report templates */
function h_report_templates($user, $p): array {
    $accountId = scope_account($user);
    $sql = 'SELECT * FROM report_templates WHERE 1=1';
    $prm = [];
    if ($accountId) { $sql .= ' AND account_id = ?'; $prm[] = $accountId; }
    if (qp('type')) { $sql .= ' AND type = ?'; $prm[] = qp('type'); }
    $sql .= ' ORDER BY name';
    $st = db()->prepare($sql);
    $st->execute($prm);
    return ['templates' => array_map(fn($t) => ['id' => $t['id'], 'name' => $t['name'], 'type' => $t['type'],
        'params' => jdec($t['params_json'] ?? null, []), 'shared' => (int)$t['shared'],
        'created_at' => iso($t['created_at'])], $st->fetchAll())];
}
function h_report_template_save($user, $p): array {
    $accountId = scope_account($user);
    $id = (string)($p['id'] ?? '');
    $name = bstr('name', '');
    $type = bstr('type', 'unit_summary');
    need($name !== '', 'Template name is required');
    need(in_array($type, array_column(report_types(), 'id'), true), 'Unknown report type: ' . $type);
    $params = bval('params', []);
    if (!is_array($params)) $params = [];
    if ($id === '') {
        $account = is_super($user) ? (string)(bstr('account_id', (string)$accountId) ?: '') : (string)$accountId;
        need($account !== '', 'account_id is required');
        $id = uid('rpt');
        db()->prepare('INSERT INTO report_templates (id,account_id,name,type,params_json,created_by,shared,created_at)
                       VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$id, $account, $name, $type, jenc($params), $user['id'], bint('shared', 1) ? 1 : 0, now_dt()]);
    } else {
        $st = db()->prepare('SELECT * FROM report_templates WHERE id = ?');
        $st->execute([$id]);
        $t = $st->fetch();
        need($t, 'Template not found', 404);
        need(is_super($user) || $t['account_id'] === $accountId, 'Template not found', 404);
        db()->prepare('UPDATE report_templates SET name=?, type=?, params_json=?, shared=? WHERE id=?')
            ->execute([$name, $type, jenc($params), bint('shared', 1) ? 1 : 0, $id]);
    }
    audit('report_template_saved', 'report_template', $id, ['name' => $name, 'type' => $type]);
    return ['template_id' => $id, 'message' => 'Report template saved'];
}
function h_report_template_delete($user, $p): array {
    $id = (string)$p['id'];
    $st = db()->prepare('SELECT * FROM report_templates WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch();
    need($t, 'Template not found', 404);
    need(is_super($user) || $t['account_id'] === scope_account($user), 'Template not found', 404);
    db()->prepare('DELETE FROM report_templates WHERE id = ?')->execute([$id]);
    audit('report_template_deleted', 'report_template', $id, ['name' => $t['name']]);
    return ['message' => 'Report template deleted'];
}
