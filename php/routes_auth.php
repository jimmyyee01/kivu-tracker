<?php
/* =====================================================================
   KIVU Tracker — php/routes_auth.php
   Sign in / out, own-password change, session listing and user
   management (admin of an account, or the super administrator).
   ===================================================================== */
declare(strict_types=1);

function routes_auth(): array {
    $id = '(?P<id>[A-Za-z0-9_\-]{3,64})';
    return [
        ['POST', '#^/auth/login$#', 'h_auth_login', 'public'],
        ['POST', '#^/auth/logout$#', 'h_auth_logout', 'read'],
        ['GET',  '#^/auth/me$#', 'h_auth_me', 'read'],
        ['POST', '#^/auth/password$#', 'h_auth_password', 'read'],
        ['GET',  '#^/auth/sessions$#', 'h_auth_sessions', 'read'],
        ['GET',  '#^/users$#', 'h_users_list', 'admin'],
        ['POST', '#^/users$#', 'h_users_save', 'admin'],
        ['PUT',  '#^/users/' . $id . '$#', 'h_users_save', 'admin'],
        ['DELETE', '#^/users/' . $id . '$#', 'h_users_delete', 'admin'],
    ];
}

function h_auth_login($user, $p): array {
    ensure_schema();
    $username = bstr('username');
    $password = (string)bval('password', '');
    need($username !== '' && $password !== '', 'Username and password are required');
    $st = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
    $st->execute([$username, $username]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, (string)$u['password_hash'])) {
        audit('login_failed', 'user', null, ['username' => $username]);
        throw new ApiError('Invalid username or password', 401);
    }
    need((int)$u['enabled'] === 1, 'This account is disabled — contact your administrator', 403);
    if (!is_super($u) && $u['account_id']) {
        $st = db()->prepare('SELECT enabled, name FROM accounts WHERE id = ?');
        $st->execute([$u['account_id']]);
        $acc = $st->fetch();
        if ($acc && (int)$acc['enabled'] !== 1) throw new ApiError('Account "' . $acc['name'] . '" is disabled', 403);
    }
    $token = create_session($u);
    audit('login', 'user', $u['id']);
    return ['token' => $token, 'user' => user_public($u),
        'expires_at' => date('c', time() + SESSION_DAYS * 86400),
        'settings' => ['platform' => setting('platform_name', APP_NAME), 'map_tiles' => setting('map_tiles'),
            'logo' => branding_logo_url()]];
}

function h_auth_logout($user, $p): array {
    $t = bearer_token();
    if ($t) destroy_session($t);
    audit('logout', 'user', $user['id']);
    return ['message' => 'Signed out'];
}

function h_auth_me($user, $p): array {
    return ['user' => user_public($user), 'access' => [
        'can_write' => can_write($user), 'can_admin' => can_admin($user), 'is_super' => is_super($user),
        'units' => allowed_unit_ids($user)], 'server_time' => date('c')];
}

function h_auth_password($user, $p): array {
    $current = (string)bval('current_password', '');
    $new = (string)bval('new_password', '');
    need(strlen($new) >= 6, 'The new password must be at least 6 characters');
    need(password_verify($current, (string)$user['password_hash']), 'Current password is incorrect', 403);
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_BCRYPT), $user['id']]);
    audit('password_changed', 'user', $user['id']);
    return ['message' => 'Password updated'];
}

function h_auth_sessions($user, $p): array {
    $st = db()->prepare('SELECT token, created_at, expires_at, ip, user_agent FROM sessions WHERE user_id = ? ORDER BY created_at DESC');
    $st->execute([$user['id']]);
    return ['sessions' => array_map(fn($s) => ['current' => $s['token'] === ($user['token'] ?? ''),
        'created_at' => iso($s['created_at']), 'expires_at' => iso($s['expires_at']),
        'ip' => $s['ip'], 'user_agent' => $s['user_agent']], $st->fetchAll())];
}

/* -------------------------------------------------------- user management */
function h_users_list($user, $p): array {
    $accountId = scope_account($user);
    $sql = 'SELECT * FROM users WHERE 1=1';
    $prm = [];
    if ($accountId) { $sql .= ' AND account_id = ?'; $prm[] = $accountId; }
    if (qp('q')) { $sql .= ' AND (username LIKE ? OR email LIKE ? OR full_name LIKE ?)';
        $like = '%' . qp('q') . '%'; array_push($prm, $like, $like, $like); }
    $sql .= ' ORDER BY username';
    $st = db()->prepare($sql);
    $st->execute($prm);
    return ['users' => array_map('user_public', $st->fetchAll())];
}

function h_users_save($user, $p): array {
    $accountId = scope_account($user);
    $id = $p['id'] ?? '';
    $role = (string)bval('role', 'operator');
    need(in_array($role, ['admin', 'operator', 'viewer', 'super', 'reseller'], true), 'Unknown role: ' . $role);
    if ($role === 'super') need(is_super($user), 'Only a super administrator can create super administrators', 403);
    if ($role === 'reseller') need(is_super($user), 'Only a super administrator can create reseller users', 403);
    $account = $accountId;
    if (is_super($user)) {
        $requested = bstr('account_id', $accountId ?? '');
        $account = $requested === '' ? null : $requested;
        if ($role === 'super') $account = null;
    }
    /* super and reseller users belong to the platform / a portal, not to a client account */
    if (!in_array($role, ['super', 'reseller'], true)) {
        need((string)$account !== '', 'Assign the user to an account (account_id)', 400);
    } else {
        $account = null;
    }

    $fields = [
        'account_id' => $account, 'username' => bstr('username'), 'email' => bstr('email', '') ?: null,
        'phone' => bstr('phone', '') ?: null, 'full_name' => bstr('full_name', '') ?: null, 'role' => $role,
        'enabled' => bint('enabled', 1) ? 1 : 0,
    ];
    /* An absent key keeps the existing mask; an empty list clears it (full account access). */
    $accessProvided = array_key_exists('access_units', body()) || isset($_GET['access_units']);
    if ($accessProvided) {
        $list = list_of('access_units');
        $fields['access_units_json'] = $list ? jenc($list) : null;
    } elseif ($id === '') {
        $fields['access_units_json'] = null;
    }
    $password = (string)bval('password', '');
    if ($id === '') {
        need(trim((string)$fields['username']) !== '', 'Username is required');
        need(strlen($password) >= 6, 'Password must be at least 6 characters');
        $st = db()->prepare('SELECT id FROM users WHERE username = ?');
        $st->execute([$fields['username']]);
        need(!$st->fetch(), 'That username is already taken');
        $id = uid('usr');
        $cols = array_keys($fields);
        $cols[] = 'id'; $cols[] = 'password_hash'; $cols[] = 'salt'; $cols[] = 'created_at';
        $vals = array_values($fields);
        $vals[] = $id; $vals[] = password_hash($password, PASSWORD_BCRYPT);
        $vals[] = bin2hex(random_bytes(8)); $vals[] = now_dt();
        db()->prepare('INSERT INTO users (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($vals);
        audit('user_created', 'user', $id, ['username' => $fields['username'], 'role' => $role]);
    } else {
        $target = db()->prepare('SELECT * FROM users WHERE id = ?');
        $target->execute([$id]);
        $t = $target->fetch();
        need($t, 'User not found', 404);
        if (!is_super($user)) need($t['account_id'] === $accountId, 'That user belongs to another account', 403);
        if ($t['role'] === 'super') need(is_super($user), 'Only a super administrator can edit super administrators', 403);
        if (trim((string)$fields['username']) === '') unset($fields['username']);
        $set = [];
        $vals = [];
        foreach ($fields as $k => $v) { $set[] = "`$k` = ?"; $vals[] = $v; }
        if ($password !== '') {
            need(strlen($password) >= 6, 'Password must be at least 6 characters');
            $set[] = 'password_hash = ?'; $vals[] = password_hash($password, PASSWORD_BCRYPT);
        }
        $vals[] = $id;
        db()->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
        audit('user_updated', 'user', $id, ['role' => $role, 'password_changed' => $password !== '']);
    }
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return ['user' => user_public($st->fetch()), 'message' => 'User saved'];
}

function h_users_delete($user, $p): array {
    $id = (string)$p['id'];
    need($id !== $user['id'], 'You cannot delete your own account', 400);
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch();
    need($t, 'User not found', 404);
    if (!is_super($user)) need($t['account_id'] === scope_account($user), 'That user belongs to another account', 403);
    need($t['role'] !== 'super', 'Super administrators cannot be deleted', 403);
    db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    audit('user_deleted', 'user', $id, ['username' => $t['username']]);
    return ['message' => 'User deleted'];
}
