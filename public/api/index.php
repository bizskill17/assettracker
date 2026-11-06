<?php
/**
 * Hostel Asset Movement Tracker — JSON API for Hostinger (PHP 8 + MySQL/MariaDB).
 *
 * Actions (?a=...):
 *   GET  status  → { needsSetup, user }
 *   POST setup   → create the first Admin login (only while no login exists)
 *   POST login   → { email, password }
 *   POST logout
 *   GET  data    → every collection the app uses
 *   POST save    → { coll, id, data, mode: "set" | "update" }
 *   POST delete  → { coll, id }
 *
 * Tables are created automatically on first use (see install.php).
 */
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(array $d, int $http = 200): void
{
    http_response_code($http);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $code, string $message, int $http = 400): void
{
    out(['error' => ['code' => $code, 'message' => $message]], $http);
}

if (!is_file($configFile)) {
    fail('unavailable', 'Server is not configured: api/config.php is missing. Check the DB_* secrets in GitHub.', 500);
}
require $configFile;
require __DIR__ . '/install.php';

session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_name('hat_sess');
session_start();

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}

/* ---------- collections: API field name => [column, type] ----------
   types: s = text, i = int (nullable), n = epoch ms (nullable), b = 0/1
   write: who may change rows — "admin" (role Admin) or "member" (anyone except Viewer) */
const COLLS = [
    'blocks' => ['table' => 'blocks', 'write' => 'admin', 'fields' => [
        'name' => ['name', 's'], 'code' => ['code', 's'], 'kind' => ['kind', 's'], 'note' => ['note', 's'],
        'order' => ['sort_order', 'i'], 'sample' => ['sample', 'b']]],
    'rooms' => ['table' => 'rooms', 'write' => 'admin', 'fields' => [
        'blockId' => ['block_id', 's'], 'number' => ['number', 's'], 'floor' => ['floor', 's'], 'type' => ['type', 's'],
        'capacity' => ['capacity', 'i'], 'order' => ['sort_order', 'i'], 'sample' => ['sample', 'b']]],
    'staff' => ['table' => 'users', 'write' => 'admin', 'fields' => [
        'name' => ['name', 's'], 'role' => ['role', 's'], 'blockId' => ['block_id', 's'], 'phone' => ['phone', 's'],
        'email' => ['email', 's'], 'active' => ['active', 's'], 'order' => ['sort_order', 'i'], 'sample' => ['sample', 'b']]],
    'itemTypes' => ['table' => 'item_types', 'write' => 'admin', 'fields' => [
        'name' => ['name', 's'], 'category' => ['category', 's'], 'prefix' => ['prefix', 's'],
        'order' => ['sort_order', 'i'], 'sample' => ['sample', 'b']]],
    'vendors' => ['table' => 'vendors', 'write' => 'admin', 'fields' => [
        'name' => ['name', 's'], 'kind' => ['kind', 's'], 'phone' => ['phone', 's'], 'contact' => ['contact', 's'],
        'notes' => ['notes', 's'], 'order' => ['sort_order', 'i'], 'sample' => ['sample', 'b']]],
    'categories' => ['table' => 'categories', 'write' => 'admin', 'fields' => [
        'name' => ['name', 's'], 'order' => ['sort_order', 'i'], 'sample' => ['sample', 'b']]],
    'reasons' => ['table' => 'reasons', 'write' => 'admin', 'fields' => [
        'name' => ['name', 's'], 'statusAfter' => ['status_after', 's'], 'dest' => ['dest', 's'],
        'order' => ['sort_order', 'i'], 'sample' => ['sample', 'b']]],
    'assets' => ['table' => 'assets', 'write' => 'member', 'fields' => [
        'tag' => ['tag', 's'], 'name' => ['name', 's'], 'itemTypeId' => ['item_type_id', 's'], 'category' => ['category', 's'],
        'blockId' => ['block_id', 's'], 'room' => ['room', 's'], 'status' => ['status', 's'], 'condition' => ['cond', 's'],
        'supplierId' => ['supplier_id', 's'], 'notes' => ['notes', 's'], 'createdAt' => ['created_at', 'n'],
        'updatedAt' => ['updated_at', 'n'], 'lastMovedAt' => ['last_moved_at', 'n'], 'sample' => ['sample', 'b']]],
    'movements' => ['table' => 'movements', 'write' => 'member', 'fields' => [
        'assetId' => ['asset_id', 's'], 'assetTag' => ['asset_tag', 's'], 'assetName' => ['asset_name', 's'],
        'fromBlockId' => ['from_block_id', 's'], 'fromRoom' => ['from_room', 's'], 'toBlockId' => ['to_block_id', 's'],
        'toRoom' => ['to_room', 's'], 'reason' => ['reason', 's'], 'statusAfter' => ['status_after', 's'],
        'handledBy' => ['handled_by', 's'], 'vendor' => ['vendor', 's'], 'notes' => ['notes', 's'],
        'at' => ['at', 'n'], 'loggedAt' => ['logged_at', 'n'], 'loggedBy' => ['logged_by', 's'], 'sample' => ['sample', 'b']]],
];

function newId(): string
{
    return base_convert((string) (int) (microtime(true) * 1000), 10, 36) . bin2hex(random_bytes(5));
}

function castIn(mixed $v, string $type): mixed
{
    if ($type === 'b') {
        return $v ? 1 : 0;
    }
    if ($type === 'i' || $type === 'n') {
        return ($v === null || $v === '') ? null : (int) $v;
    }
    if ($v === null) {
        return '';
    }
    if (is_bool($v)) {
        $v = $v ? 'Yes' : 'No';
    }
    return mb_substr(trim((string) $v), 0, 5000);
}

function rowOut(string $coll, array $row): array
{
    $o = ['id' => $row['id']];
    foreach (COLLS[$coll]['fields'] as $key => [$col, $type]) {
        $v = $row[$col] ?? null;
        if ($type === 'b') {
            $v = (bool) $v;
        } elseif ($type === 'i' || $type === 'n') {
            $v = $v === null ? null : (int) $v;
        } elseif ($v === null) {
            $v = '';
        }
        $o[$key] = $v;
    }
    if ($coll === 'staff') {
        $o['hasLogin'] = !empty($row['password_hash']);
    }
    return $o;
}

function body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function currentUser(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $st = db()->prepare("SELECT id, name, role, email, active FROM users WHERE id = ? AND password_hash IS NOT NULL");
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    if (!$u || $u['active'] === 'No') {
        unset($_SESSION['uid']);
        return null;
    }
    return $u;
}

function requireUser(): array
{
    $u = currentUser();
    if (!$u) {
        fail('unauthenticated', 'Please sign in again.', 401);
    }
    return $u;
}

function loginCount(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM users WHERE password_hash IS NOT NULL AND active <> 'No'")->fetchColumn();
}

function publicUser(?array $u): ?array
{
    return $u ? ['id' => $u['id'], 'name' => $u['name'], 'role' => $u['role'], 'email' => $u['email']] : null;
}

/* ---------- routing ---------- */
$action = $_GET['a'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST' && ($_SERVER['HTTP_X_REQ'] ?? '') !== '1') {
    fail('forbidden', 'Missing request header.', 403);
}

try {
    ensureSchema(db());

    switch ($action) {
        case 'status':
            out(['needsSetup' => loginCount() === 0, 'user' => publicUser(currentUser())]);

        case 'setup': {
            if (loginCount() > 0) {
                fail('forbidden', 'Setup is already done. Sign in instead.', 403);
            }
            $b = body();
            $name = castIn($b['name'] ?? '', 's');
            $email = strtolower(castIn($b['email'] ?? '', 's'));
            $pass = (string) ($b['password'] ?? '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
                fail('invalid_argument', 'Enter a name, a valid email and a password of at least 8 characters.');
            }
            $id = newId();
            db()->prepare("INSERT INTO users (id, name, role, email, active, password_hash) VALUES (?, ?, 'Admin', ?, 'Yes', ?)")
                ->execute([$id, $name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['uid'] = $id;
            out(['user' => publicUser(currentUser())]);
        }

        case 'login': {
            $b = body();
            $email = strtolower(trim((string) ($b['email'] ?? '')));
            $pass = (string) ($b['password'] ?? '');
            $st = db()->prepare("SELECT * FROM users WHERE email = ? AND password_hash IS NOT NULL AND active <> 'No'");
            $st->execute([$email]);
            $u = $st->fetch();
            if (!$u || !password_verify($pass, $u['password_hash'])) {
                usleep(800000);
                fail('invalid_login', 'Email or password is wrong.', 401);
            }
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            }
            session_regenerate_id(true);
            $_SESSION['uid'] = $u['id'];
            out(['user' => publicUser($u)]);
        }

        case 'logout':
            $_SESSION = [];
            session_destroy();
            out(['ok' => true]);

        case 'data': {
            requireUser();
            $res = [];
            foreach (COLLS as $coll => $def) {
                $sql = "SELECT * FROM `{$def['table']}`";
                if ($coll === 'movements') {
                    $sql .= " ORDER BY at DESC LIMIT 2000";
                }
                $res[$coll] = array_map(fn($r) => rowOut($coll, $r), db()->query($sql)->fetchAll());
            }
            out($res);
        }

        case 'save': {
            $u = requireUser();
            $b = body();
            $coll = (string) ($b['coll'] ?? '');
            $id = (string) ($b['id'] ?? '');
            $data = is_array($b['data'] ?? null) ? $b['data'] : [];
            $mode = ($b['mode'] ?? 'set') === 'update' ? 'update' : 'set';
            if (!isset(COLLS[$coll]) || !preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $id)) {
                fail('invalid_argument', 'Unknown record.');
            }
            $def = COLLS[$coll];
            if ($u['role'] === 'Viewer' || ($def['write'] === 'admin' && $u['role'] !== 'Admin')) {
                fail('forbidden', $def['write'] === 'admin' ? 'Only an Admin can change masters, blocks and users.' : 'Viewers cannot make changes.', 403);
            }
            $cols = [];
            $vals = [];
            foreach ($data as $key => $v) {
                if (!isset($def['fields'][$key]) || ($coll === 'movements' && $key === 'loggedBy')) {
                    continue;
                }
                [$col, $type] = $def['fields'][$key];
                $v = castIn($v, $type);
                if ($coll === 'staff' && $col === 'email') {
                    $v = $v === '' ? null : strtolower($v);
                }
                $cols[] = $col;
                $vals[] = $v;
            }
            // Login password for Users: write-only, hashed. Blank keeps the current one.
            if ($coll === 'staff' && isset($data['password']) && (string) $data['password'] !== '') {
                if (strlen((string) $data['password']) < 8) {
                    fail('invalid_argument', 'Login password must be at least 8 characters.');
                }
                $cols[] = 'password_hash';
                $vals[] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
            }
            if ($coll === 'movements' && $mode === 'set') {
                $cols[] = 'logged_by';
                $vals[] = $u['id'];
            }
            $t = $def['table'];
            if ($mode === 'update') {
                if (!$cols) {
                    out(['ok' => true]);
                }
                $set = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
                $st = db()->prepare("UPDATE `$t` SET $set WHERE id = ?");
                $st->execute([...$vals, $id]);
                if ($st->rowCount() === 0) {
                    $chk = db()->prepare("SELECT 1 FROM `$t` WHERE id = ?");
                    $chk->execute([$id]);
                    if (!$chk->fetchColumn()) {
                        fail('invalid_argument', 'That record no longer exists.');
                    }
                }
            } else {
                $chk = db()->prepare("SELECT 1 FROM `$t` WHERE id = ?");
                $chk->execute([$id]);
                if ($chk->fetchColumn()) {
                    if ($cols) {
                        $set = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
                        db()->prepare("UPDATE `$t` SET $set WHERE id = ?")->execute([...$vals, $id]);
                    }
                } else {
                    // Plain INSERT: a clash on a unique tag/email raises 23000 instead of touching another row.
                    $colSql = implode(', ', array_map(fn($c) => "`$c`", ['id', ...$cols]));
                    $ph = implode(', ', array_fill(0, count($cols) + 1, '?'));
                    db()->prepare("INSERT INTO `$t` ($colSql) VALUES ($ph)")->execute([$id, ...$vals]);
                }
            }
            $st = db()->prepare("SELECT * FROM `$t` WHERE id = ?");
            $st->execute([$id]);
            out(['row' => rowOut($coll, $st->fetch() ?: ['id' => $id])]);
        }

        case 'delete': {
            $u = requireUser();
            $b = body();
            $coll = (string) ($b['coll'] ?? '');
            $id = (string) ($b['id'] ?? '');
            if (!isset(COLLS[$coll])) {
                fail('invalid_argument', 'Unknown record.');
            }
            $def = COLLS[$coll];
            if ($u['role'] === 'Viewer' || ($def['write'] === 'admin' && $u['role'] !== 'Admin')) {
                fail('forbidden', 'You do not have permission to delete this.', 403);
            }
            if ($coll === 'staff' && $id === $u['id']) {
                fail('invalid_argument', 'You cannot delete your own login.');
            }
            db()->prepare("DELETE FROM `{$def['table']}` WHERE id = ?")->execute([$id]);
            out(['ok' => true]);
        }

        case 'seed': {
            // Allowed for a signed-in Admin, or for the deploy workflow using a key derived from DB_PASS.
            $key = (string) ($_GET['key'] ?? '');
            $u = currentUser();
            $okKey = $key !== '' && hash_equals(hash_hmac('sha256', 'asset-tracker-seed', DB_PASS), $key);
            if (!$okKey && (!$u || $u['role'] !== 'Admin')) {
                fail('forbidden', 'Only an Admin can load sample data.', 403);
            }
            require __DIR__ . '/seed.php';
            out(['added' => seedSample(db())]);
        }

        case 'clear': {
            // Deletes all blocks, rooms, assets and movements. Masters (users, item types, vendors,
            // categories, reasons) are kept. Only the deploy workflow can call this, with a key derived from DB_PASS.
            $key = (string) ($_GET['key'] ?? '');
            if ($key === '' || !hash_equals(hash_hmac('sha256', 'asset-tracker-clear', DB_PASS), $key)) {
                fail('forbidden', 'Not allowed.', 403);
            }
            $done = [];
            foreach (['movements', 'assets', 'rooms', 'blocks'] as $t) {
                $done[$t] = db()->exec("DELETE FROM `$t`");
            }
            // Users assigned to a deleted block become "All blocks".
            db()->exec("UPDATE users SET block_id = ''");
            out(['deleted' => $done]);
        }

        default:
            fail('invalid_argument', 'Unknown action.', 404);
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        fail('invalid_argument', 'That tag or email is already used by another record.');
    }
    error_log('[asset-tracker] ' . $e->getMessage());
    fail('unavailable', 'Database error. Check the DB_* settings and try again.', 500);
}
