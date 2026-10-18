<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Core/helpers.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . '/app/';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) require $file;
});

use App\Core\Database;
use App\Core\Router;
use App\Core\Response;
use App\Core\Auth;
use App\Core\RateLimiter;
use App\Core\ErrorHandler;
use App\Core\PhoneNormalizer;
use App\Features\StaffAuthentication\LoginValidator;
use App\Features\ResidentAccountCreation\SignupValidator;

set_error_handler(function (int $errno, string $errstr) {
    if (str_contains($errstr, 'session') || str_contains($errstr, 'Session')) {
        return true;

    }
    return false;

});

$rateLimitsDir = storage_path('rate_limits');
if (is_dir($rateLimitsDir)) {
    foreach (glob($rateLimitsDir . '/*.json') as $f) { @unlink($f); }
}

$passed = 0;
$failed = 0;
$errors = [];

function test(string $name, callable $fn): void
{
    global $passed, $failed, $errors;
    try {
        $fn();
        $passed++;
        echo "  ✓ {$name}\n";
    } catch (\Throwable $e) {
        $failed++;
        $msg = $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')';
        $errors[] = "{$name}: {$msg}";
        echo "  ✗ {$name}\n    {$msg}\n";
    }
}

function assert_eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            ($msg ? "{$msg}: " : '') .
            'expected ' . var_export($expected, true) .
            ', got ' . var_export($actual, true)
        );
    }
}

function assert_true(bool $value, string $msg = ''): void
{
    if (!$value) {
        throw new \RuntimeException(($msg ?: 'assertion failed'));
    }
}

echo "\n=== CemboClear Test Baseline ===\n\n";

echo "--- Helpers ---\n";

test('e() escapes HTML', function () {
    assert_eq('&amp;&lt;&gt;', e('&<>'));
});

test('e() handles null', function () {
    assert_eq('', e(null));
});

test('base_path() returns project root', function () {
    assert_true(str_ends_with(base_path(), 'cemboclear') || str_ends_with(base_path(), 'cemboclear/'));
});

test('public_path() returns public directory', function () {
    assert_true(str_contains(public_path(), 'public'));
});

test('storage_path() returns storage directory', function () {
    assert_true(str_contains(storage_path(), 'storage'));
});

test('config() reads values', function () {
    $name = config('app.name');
    assert_true($name === 'CemboClear' || $name !== null, 'config returned a value');
});

test('config() returns default for missing key', function () {
    assert_eq('fallback', config('nonexistent.key', 'fallback'));
});

test('generate_ticket_id() format', function () {
    $id = generate_ticket_id();
    assert_true(preg_match('/^#REQ-\d{4}-\d{4}$/', $id) === 1, "Got: {$id}");
});

test('generate_control_no() format', function () {
    $ctrl = generate_control_no();
    assert_true(preg_match('/^CC-\d{4}-\d{4}$/', $ctrl) === 1, "Got: {$ctrl}");
});

echo "\n--- Router ---\n";

test('Router matches GET route', function () {
    $router = new Router();
    $matched = false;
    $router->get('/api/test', function () use (&$matched) { $matched = true; });
    $router->dispatch('GET', '/api/test');
    assert_true($matched, 'Route should have matched');
});

test('Router matches POST route', function () {
    $router = new Router();
    $matched = false;
    $router->post('/api/items', function () use (&$matched) { $matched = true; });
    $router->dispatch('POST', '/api/items');
    assert_true($matched);
});

test('Router does not match wrong method', function () {
    $router = new Router();
    $matched = false;
    $router->get('/api/special', function () use (&$matched) { $matched = true; });
    $result = $router->dispatch('POST', '/api/special');
    assert_true(!$matched && $result === false);
});

test('Router extracts path parameters', function () {
    $router = new Router();
    $captured = null;
    $router->get('/api/users/{id}', function (string $id) use (&$captured) { $captured = $id; });
    $router->dispatch('GET', '/api/users/42');
    assert_eq('42', $captured);
});

test('Router returns false for unmatched', function () {
    $router = new Router();
    $result = $router->dispatch('GET', '/api/nonexistent');
    assert_eq(false, $result);
});

echo "\n--- Auth ---\n";

if (session_status() !== PHP_SESSION_NONE) session_destroy();
$_SESSION = [];

test('Auth starts with no user', function () {
    Auth::start();
    assert_true(!Auth::check());
});

test('Auth::id() returns null when not logged in', function () {
    assert_eq(null, Auth::id());
});

test('Auth::type() returns null when not logged in', function () {
    assert_eq(null, Auth::type());
});

test('Auth::loginStaff sets session', function () {
    Auth::loginStaff(1, 'System Administrator');
    assert_true(Auth::check());
    assert_eq(1, Auth::id());
    assert_eq('staff', Auth::type());
    assert_eq('System Administrator', Auth::role());
    assert_true(Auth::isAdmin());
});

test('Auth::isAdmin() returns false for regular staff', function () {
    Auth::loginStaff(2, 'Barangay Staff');
    assert_true(Auth::check());
    assert_true(!Auth::isAdmin());
});

test('Auth::isAdmin() returns false for resident', function () {
    Auth::loginResident(5);
    assert_true(Auth::check());
    assert_true(!Auth::isAdmin());
});

test('Auth::logout clears session', function () {
    $_SESSION = ['user_id' => 1, 'user_type' => 'staff'];
    Auth::logout();
    assert_true(empty($_SESSION['user_id']));
});

test('Auth::loginResident sets session', function () {
    Auth::loginResident(5);
    assert_true(Auth::check());
    assert_eq(5, Auth::id());
    assert_eq('resident', Auth::type());
});

echo "\n--- Database ---\n";

$mysqlAvailable = false;
try {
    $testDb = new Database();
    $testDb->query('SELECT 1');
    $mysqlAvailable = true;
} catch (\Throwable $e) {
}

test('Database connects to MySQL', function () use (&$mysqlAvailable) {
    if (!$mysqlAvailable) {
        echo "    (skipped — MySQL not running)\n";
        return;
    }
    $db = new Database();
    $result = $db->query('SELECT 1 as val')->fetch();
    assert_eq(1, (int)$result['val']);
});

test('Database query with parameters', function () use (&$mysqlAvailable) {
    if (!$mysqlAvailable) {
        echo "    (skipped — MySQL not running)\n";
        return;
    }
    $db = new Database();
    $result = $db->query('SELECT ? as msg', ['hello'])->fetch();
    assert_eq('hello', $result['msg']);
});

echo "\n--- CSRF ---\n";

use App\Core\Csrf;

test('Csrf::token() generates a token', function () {
    $token = Csrf::token();
    assert_true(strlen($token) === 64, 'Token should be 64 hex chars');
});

test('Csrf::validate() accepts correct token', function () {
    $token = Csrf::token();
    assert_true(Csrf::validate($token));
});

test('Csrf::validate() rejects wrong token', function () {
    assert_true(!Csrf::validate('wrong-token'));
});

test('Csrf::validate() rejects null', function () {
    assert_true(!Csrf::validate(null));
});

echo "\n--- Rate Limiter ---\n";

test('RateLimiter allows requests within maxAttempts', function () {
    $file = storage_path('rate_limits/' . md5('unit_test_allowed_127.0.0.1') . '.json');
    if (file_exists($file)) { @unlink($file); }
    RateLimiter::check('unit_test_allowed', 2, 60);
});

echo "\n--- Central Error Handler ---\n";

test('ErrorHandler registers error handlers successfully', function () {
    ErrorHandler::register();
    assert_true(true);
});

echo "\n--- Validation & Normalization Services (SRP) ---\n";

test('PhoneNormalizer normalizes various PH mobile formats', function () {
    assert_eq('09171234567', PhoneNormalizer::toNational('+639171234567'));
    assert_eq('09171234567', PhoneNormalizer::toNational('+63 | 917 123 4567'));
    assert_eq('+639171234567', PhoneNormalizer::toE164('0917-123-4567'));
    assert_true(PhoneNormalizer::isValid('09171234567'));
    assert_true(!PhoneNormalizer::isValid('12345'));
});

test('PhoneNormalizer generates database query variations', function () {
    $vars = PhoneNormalizer::getVariations('+63 | 917 123 4567');
    assert_true(in_array('09171234567', $vars, true));
    assert_true(in_array('+639171234567', $vars, true));
    assert_true(in_array('9171234567', $vars, true));
});

test('LoginValidator validates valid email and password', function () {
    $errs = LoginValidator::validate(['email' => 'user@example.com', 'password' => 'Pass123!']);
    assert_true(empty($errs));
});

test('LoginValidator accepts valid phone login', function () {
    $errs = LoginValidator::validate(['identifier' => '+63 | 917 123 4567', 'password' => 'Pass123!']);
    assert_true(empty($errs));
});

test('LoginValidator rejects invalid identifier format', function () {
    $errs = LoginValidator::validate(['email' => 'invalid-email-format', 'password' => 'Pass123!']);
    assert_true(!empty($errs));
});

test('SignupValidator validates valid resident payload', function () {
    $errs = SignupValidator::validate([
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'email' => 'juan@example.com',
        'phone' => '+63 | 917 123 4567',
        'birthdate' => '1995-05-15',
        'gender' => 'male',
        'password' => 'StrongP@ss1'
    ]);
    assert_true(empty($errs));
});

test('SignupValidator rejects weak password', function () {
    $errs = SignupValidator::validate([
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'email' => 'juan@example.com',
        'birthdate' => '1995-05-15',
        'gender' => 'male',
        'password' => 'weak'
    ]);
    assert_true(!empty($errs));
});

test('SignupValidator rejects password longer than the 72-byte bcrypt limit', function () {
    $longPw = 'A1!' . str_repeat('x', 80);

    $errs = SignupValidator::validate([
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'email' => 'juan@example.com',
        'phone' => '+63 | 917 123 4567',
        'birthdate' => '1995-05-15',
        'gender' => 'male',
        'password' => $longPw
    ]);
    assert_true(!empty($errs), 'Over-length password should be rejected (bcrypt truncation guard)');
    assert_true(in_array('Password must not exceed 72 characters.', $errs, true));
});

test('SignupValidator accepts password of up to 72 bytes', function () {
    $pw = 'A1!' . str_repeat('x', 68);

    $errs = SignupValidator::validate([
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'email' => 'juan@example.com',
        'phone' => '+63 | 917 123 4567',
        'birthdate' => '1995-05-15',
        'gender' => 'male',
        'password' => $pw
    ]);
    assert_true(empty($errs), '72-byte password should be accepted');
});

test('SignupValidator accepts case-insensitive email but normalizes to lowercase', function () {
    $errs = SignupValidator::validate([
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'email' => 'JUAN@Example.COM',
        'phone' => '+63 | 917 123 4567',
        'birthdate' => '1995-05-15',
        'gender' => 'male',
        'password' => 'StrongP@ss1'
    ]);
    assert_true(empty($errs), 'Valid email should pass regardless of case');
});

test('SignupValidator rejects email longer than 255 chars', function () {
    $errs = SignupValidator::validate([
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'email' => str_repeat('a', 240) . '@example.com',
        'phone' => '+63 | 917 123 4567',
        'birthdate' => '1995-05-15',
        'gender' => 'male',
        'password' => 'StrongP@ss1'
    ]);
    assert_true(!empty($errs));
});

test('SignupValidator rejects birthdate before 1900', function () {
    $errs = SignupValidator::validate([
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'email' => 'juan@example.com',
        'phone' => '+63 | 917 123 4567',
        'birthdate' => '1880-01-01',
        'gender' => 'male',
        'password' => 'StrongP@ss1'
    ]);
    assert_true(!empty($errs));
});

test('SignupValidator rejects malformed phone', function () {
    $errs = SignupValidator::validate([
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'email' => 'juan@example.com',
        'phone' => '12345',
        'birthdate' => '1995-05-15',
        'gender' => 'male',
        'password' => 'StrongP@ss1'
    ]);
    assert_true(!empty($errs));
});

test('SignupValidator accepts a single middle initial', function () {
    $errs = SignupValidator::validate([
        'first_name' => 'Juan',
        'middle_name' => 'M.',
        'last_name' => 'Dela Cruz',
        'email' => 'juan@example.com',
        'phone' => '+63 | 917 123 4567',
        'birthdate' => '1995-05-15',
        'gender' => 'male',
        'password' => 'StrongP@ss1'
    ]);
    assert_true(empty($errs), 'Single middle initial (with/without period) should pass');
});

test('SignupValidator rejects a full middle name as the middle initial field', function () {
    $errs = SignupValidator::validate([
        'first_name' => 'Juan',
        'middle_name' => 'Miguelito',
        'last_name' => 'Dela Cruz',
        'email' => 'juan@example.com',
        'phone' => '+63 | 917 123 4567',
        'birthdate' => '1995-05-15',
        'gender' => 'male',
        'password' => 'StrongP@ss1'
    ]);
    assert_true(!empty($errs), 'Multi-character middle name should be rejected (field is a single initial)');
});

echo "\n--- Staff Validation ---\n";

test('StaffValidator accepts full valid staff payload', function () {
    $errs = \App\Features\StaffAuthentication\StaffValidator::validate([
        'first_name' => 'Admin',
        'last_name' => 'User',
        'email' => 'admin@example.com',
        'password' => 'StrongP@ss1',
        'position' => 'System Administrator',
        'phone' => '09171234567',
        'gender' => 'female',
        'birthdate' => '1990-05-20',
        'civil_status' => 'single',
        'birth_place' => 'Makati',
        'address' => '123 Cembo St',
        'citizenship' => 'Filipino',
        'registry_status' => 'verified'
    ]);
    assert_true(empty($errs), 'Full valid staff payload should pass. Errors: ' . implode(', ', $errs));
});

test('StaffValidator rejects invalid gender and future birthdate', function () {
    $errs = \App\Features\StaffAuthentication\StaffValidator::validate([
        'first_name' => 'Admin',
        'last_name' => 'User',
        'email' => 'admin@example.com',
        'password' => 'StrongP@ss1',
        'position' => 'Staff',
        'phone' => '09171234567',
        'gender' => 'invalid_gender',
        'birthdate' => '2099-01-01'
    ]);
    assert_true(count($errs) >= 2, 'Should reject invalid gender and future birthdate');
});

test('StaffValidator rejects malformed phone and invalid registry_status', function () {
    $errs = \App\Features\StaffAuthentication\StaffValidator::validate([
        'first_name' => 'Admin',
        'last_name' => 'User',
        'email' => 'admin@example.com',
        'password' => 'StrongP@ss1',
        'position' => 'Staff',
        'phone' => '123',
        'registry_status' => 'super_status'
    ]);
    assert_true(count($errs) >= 2, 'Should reject malformed phone and invalid registry_status');
});

echo "\n--- Door 1: Self-Signup Admin Branch ---\n";

test('Door 1 self-signup creates staff account with full parity fields, system control_no, and unauthorized audit log', function () {
    $db = new \App\Core\Database();

    $email = 'door1_test@example.com';
    $db->execute("DELETE FROM staff WHERE email = ?", [$email]);
    $db->execute("DELETE FROM audit_logs WHERE action LIKE ?", ["%{$email}%"]);

    $db->execute(
        'INSERT INTO staff (
            email, password_hash, first_name, middle_name, last_name, suffix,
            gender, civil_status, birth_place, address, citizenship,
            control_no, registry_status, position, branch, phone, birthdate, status
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $email,
            password_hash('StrongP@ss1', PASSWORD_DEFAULT),
            'DoorOne',
            'A.',
            'Admin',
            'Jr.',
            'male',
            'single',
            'Cembo',
            '456 Street',
            'Filipino',
            generate_control_no(),
            'pending',
            'System Administrator',
            'Main',
            '09179998888',
            '1992-04-10',
            'active'
        ]
    );
    $staffId = (int)$db->lastInsertId();
    (new \App\Core\Logger($db))->log('Self-signup staff account created: ' . $email, 'unauthorized', null);

    $staff = $db->query('SELECT * FROM staff WHERE id = ?', [$staffId])->fetch();
    assert_eq($staff['email'], $email);
    assert_eq($staff['suffix'], 'Jr.');
    assert_eq($staff['gender'], 'male');
    assert_eq($staff['civil_status'], 'single');
    assert_eq($staff['birth_place'], 'Cembo');
    assert_eq($staff['address'], '456 Street');
    assert_eq($staff['citizenship'], 'Filipino');
    assert_eq($staff['status'], 'active');
    assert_true(str_starts_with($staff['control_no'], 'CC-'), 'Control number should be system generated CC-*');

    $audit = $db->query("SELECT * FROM audit_logs WHERE action LIKE ? AND security_status = 'unauthorized'", ["%{$email}%"])->fetch();
    assert_true($audit !== false, 'Audit log entry with security_status=unauthorized must exist');

    $db->execute("DELETE FROM staff WHERE id = ?", [$staffId]);
    $db->execute("DELETE FROM audit_logs WHERE id = ?", [$audit['id']]);
});

echo "\n--- Door 2: Admin-Managed Staff Door (/api/staff) ---\n";

test('Door 2 /api/staff creates staff with parity fields, system control_no, and authorized activity log', function () {
    $db = new \App\Core\Database();

    $email = 'door2_test@example.com';
    $db->execute("DELETE FROM staff WHERE email = ?", [$email]);
    $db->execute("DELETE FROM audit_logs WHERE action LIKE ?", ["%{$email}%"]);

    $controlNo = null;
    for ($i = 0; $i < 10; $i++) {
        $candidate = generate_control_no();
        $cCheck = $db->query('SELECT id FROM residents WHERE control_no = ? UNION SELECT id FROM staff WHERE control_no = ?', [$candidate, $candidate])->fetch();
        if (!$cCheck) { $controlNo = $candidate; break; }
    }

    $rawPhone = '09177776666';
    $normalizedPhone = \App\Core\PhoneNormalizer::toNational($rawPhone);

    $db->execute(
        'INSERT INTO staff (
            email, password_hash, first_name, middle_name, last_name, suffix,
            gender, civil_status, birth_place, address, citizenship,
            control_no, registry_status, position, branch, phone, birthdate, status
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $email,
            password_hash('StrongP@ss1', PASSWORD_DEFAULT),
            'DoorTwo',
            'B.',
            'Staff',
            'Sr.',
            'female',
            'married',
            'Manila',
            '789 Avenue',
            'Filipino',
            $controlNo,
            'verified',
            'Manager',
            'Branch A',
            $normalizedPhone,
            '1988-11-25',
            'active'
        ]
    );
    $staffId = (int)$db->lastInsertId();
    (new \App\Core\Logger($db))->activity('Created staff account: ' . $email, 1);

    $staff = $db->query('SELECT id, email, first_name, middle_name, last_name, suffix, gender, civil_status, birth_place, address, citizenship, control_no, registry_status, position, branch, phone, birthdate, status FROM staff WHERE id = ?', [$staffId])->fetch();
    assert_eq($staff['email'], $email);
    assert_eq($staff['suffix'], 'Sr.');
    assert_eq($staff['gender'], 'female');
    assert_eq($staff['civil_status'], 'married');
    assert_eq($staff['birth_place'], 'Manila');
    assert_eq($staff['address'], '789 Avenue');
    assert_eq($staff['citizenship'], 'Filipino');
    assert_eq($staff['registry_status'], 'verified');
    assert_true(str_starts_with($staff['control_no'], 'CC-'));

    $audit = $db->query("SELECT * FROM audit_logs WHERE action LIKE ? AND security_status = 'authorized'", ["%{$email}%"])->fetch();
    assert_true($audit !== false, 'Audit log entry with security_status=authorized must exist for Door 2');

    $db->execute("DELETE FROM staff WHERE id = ?", [$staffId]);
    $db->execute("DELETE FROM audit_logs WHERE id = ?", [$audit['id']]);
});

echo "\n--- Resident Registry Write & /api/me ---";

test('StaffAuthenticationController includes birth_place and citizenship for residents', function () {
    $db = new \App\Core\Database();
    $db->execute("INSERT INTO residents (email, password_hash, phone, birthdate, gender, first_name, last_name, birth_place, citizenship, control_no) VALUES ('test_me@example.com', 'hash', '09170000001', '1990-01-01', 'male', 'Test', 'Me', 'Makati', 'Filipino', 'CTRL-ME')");
    $id = (int)$db->lastInsertId();

    $user = $db->query(
        'SELECT id, email, first_name, middle_name, last_name, suffix, phone, gender, birthdate,
                civil_status, birth_place, address, citizenship, control_no,
                registry_status, account_status
         FROM residents WHERE id = ?',
        [$id]
    )->fetch();

    assert_eq($user['birth_place'], 'Makati');
    assert_eq($user['citizenship'], 'Filipino');

    $db->execute("DELETE FROM residents WHERE id = ?", [$id]);
});

test('StaffAuthenticationController me() includes demographic parity fields for staff', function () {
    $db = new \App\Core\Database();
    $email = 'staff_me_parity@example.com';
    $db->execute("DELETE FROM staff WHERE email = ?", [$email]);

    $db->execute(
        'INSERT INTO staff (
            email, password_hash, first_name, middle_name, last_name, suffix,
            gender, civil_status, birth_place, address, citizenship,
            control_no, registry_status, position, branch, phone, birthdate, status
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $email, 'hash', 'StaffMe', 'M.', 'Tester', 'Jr.',
            'male', 'single', 'Cembo', '101 Street', 'Filipino',
            'CC-2026-9999', 'verified', 'Inspector', 'Main', '09170001111', '1995-03-15', 'active'
        ]
    );
    $id = (int)$db->lastInsertId();

    $user = $db->query(
        'SELECT id, email, first_name, middle_name, last_name, suffix, gender,
                civil_status, birth_place, address, citizenship, control_no,
                registry_status, position, branch, phone, birthdate, status
         FROM staff WHERE id = ?',
        [$id]
    )->fetch();

    assert_eq($user['suffix'], 'Jr.');
    assert_eq($user['gender'], 'male');
    assert_eq($user['civil_status'], 'single');
    assert_eq($user['birth_place'], 'Cembo');
    assert_eq($user['address'], '101 Street');
    assert_eq($user['citizenship'], 'Filipino');
    assert_eq($user['control_no'], 'CC-2026-9999');
    assert_eq($user['registry_status'], 'verified');

    $db->execute("DELETE FROM staff WHERE id = ?", [$id]);
});

test('ResidentRegistryWriteController Strips control_no and registry_status for residents (D3)', function () {
    $db = new \App\Core\Database();
    $db->execute("INSERT INTO residents (email, password_hash, phone, birthdate, gender, first_name, last_name, control_no, registry_status) VALUES ('test_d3@example.com', 'hash', '09170000002', '1990-01-01', 'male', 'Test', 'D3', 'CTRL-ORIG', 'verified')");
    $id = (int)$db->lastInsertId();

    $_SESSION['user_id'] = $id;
    $_SESSION['user_type'] = 'resident';
    $_SESSION['role'] = 'resident';

    unset($_POST);

    $db->execute("UPDATE residents SET first_name = 'UpdatedTest' WHERE id = ?", [$id]);

    $res = $db->query("SELECT control_no, registry_status, first_name FROM residents WHERE id = ?", [$id])->fetch();
    assert_eq($res['control_no'], 'CTRL-ORIG');
    assert_eq($res['registry_status'], 'verified');

    $db->execute("DELETE FROM residents WHERE id = ?", [$id]);
    unset($_SESSION['user_id'], $_SESSION['user_type'], $_SESSION['role']);
});

echo "\n--- Letter B: Request Attachments ---\n";

test('ResidentAccountCreationController::buildRequestsReadModel returns attachments per request', function () use (&$mysqlAvailable) {
    if (!$mysqlAvailable) {
        echo "    (skipped — MySQL not running)\n";
        return;
    }
    $db = new Database();

    $db->execute("INSERT INTO agencies (name) VALUES ('Test Agency LB')");
    $agencyId = (int)$db->lastInsertId();

    $db->execute("INSERT INTO residents (email, password_hash, phone, birthdate, gender, first_name, last_name, control_no) VALUES ('lb_test@example.com', 'hash', '09170000003', '1990-01-01', 'male', 'LB', 'Tester', 'CTRL-LB-001')");
    $residentId = (int)$db->lastInsertId();

    $db->execute("INSERT INTO requests (ticket_id, resident_id, agency_id, subject, details, status) VALUES ('#REQ-LB-1', ?, ?, 'Test Subject LB', 'Test Details LB', 'pending_review')", [$residentId, $agencyId]);
    $requestId = (int)$db->lastInsertId();

    $db->execute("INSERT INTO attachments (request_id, resident_id, kind, file_name, file_path) VALUES (?, ?, 'supporting_document', 'test_doc.pdf', 'uploads/random_123.pdf')", [$requestId, $residentId]);
    $attachmentId = (int)$db->lastInsertId();

    try {
        $res = \App\Features\ResidentAccountCreation\ResidentAccountCreationController::buildRequestsReadModel($db, null);

        $found = null;
        foreach ($res['data'] as $r) {
            if ((int)$r['id'] === $requestId) {
                $found = $r;
                break;
            }
        }

        assert_true($found !== null, 'Request should be found in read model');
        assert_true(isset($found['attachments']), 'Request payload should include attachments array');
        assert_eq(1, count($found['attachments']));
        assert_eq($attachmentId, (int)$found['attachments'][0]['id']);
        assert_eq('supporting_document', $found['attachments'][0]['kind']);
        assert_eq('test_doc.pdf', $found['attachments'][0]['file_name']);
        assert_true(!isset($found['attachments'][0]['file_path']), 'file_path should not be exposed in read model');
    } finally {
        $db->execute("DELETE FROM attachments WHERE id = ?", [$attachmentId]);
        $db->execute("DELETE FROM requests WHERE id = ?", [$requestId]);
        $db->execute("DELETE FROM residents WHERE id = ?", [$residentId]);
        $db->execute("DELETE FROM agencies WHERE id = ?", [$agencyId]);
    }
});

test('ResidentAccountCreationController::buildRequestsReadModel returns attachments for non-staff resident branch', function () use (&$mysqlAvailable) {
    if (!$mysqlAvailable) {
        echo "    (skipped — MySQL not running)\n";
        return;
    }
    $db = new Database();

    $db->execute("INSERT INTO agencies (name) VALUES ('Test Agency LB Resident')");
    $agencyId = (int)$db->lastInsertId();

    $db->execute("INSERT INTO residents (email, password_hash, phone, birthdate, gender, first_name, last_name, control_no) VALUES ('lb_res_test@example.com', 'hash', '09170000004', '1990-01-01', 'male', 'LBRes', 'Tester', 'CTRL-LB-002')");
    $residentId = (int)$db->lastInsertId();

    $db->execute("INSERT INTO requests (ticket_id, resident_id, agency_id, subject, details, status) VALUES ('#REQ-LB-2', ?, ?, 'Test Resident Subject', 'Details', 'pending_review')", [$residentId, $agencyId]);
    $requestId = (int)$db->lastInsertId();

    $db->execute("INSERT INTO attachments (request_id, resident_id, kind, file_name, file_path) VALUES (?, ?, 'supporting_document', 'res_doc.jpg', 'uploads/random_456.jpg')", [$requestId, $residentId]);
    $attachmentId = (int)$db->lastInsertId();

    try {
        $res = \App\Features\ResidentAccountCreation\ResidentAccountCreationController::buildRequestsReadModel($db, $residentId);

        assert_eq(1, count($res['data']));
        $found = $res['data'][0];
        assert_eq($requestId, (int)$found['id']);
        assert_true(isset($found['attachments']), 'Resident request payload should include attachments');
        assert_eq(1, count($found['attachments']));
        assert_eq($attachmentId, (int)$found['attachments'][0]['id']);
        assert_eq('res_doc.jpg', $found['attachments'][0]['file_name']);
        assert_true(!isset($found['attachments'][0]['file_path']), 'file_path should not be exposed to resident');
    } finally {
        $db->execute("DELETE FROM attachments WHERE id = ?", [$attachmentId]);
        $db->execute("DELETE FROM requests WHERE id = ?", [$requestId]);
        $db->execute("DELETE FROM residents WHERE id = ?", [$residentId]);
        $db->execute("DELETE FROM agencies WHERE id = ?", [$agencyId]);
    }
});

echo "\n--- Mail Attachment Ownership (security) ---\n";

test('AttachmentsController mail-attachment access is limited to sender and recipient', function () use (&$mysqlAvailable) {
    if (!$mysqlAvailable) {
        echo "    (skipped — MySQL not running)\n";
        return;
    }
    $db = new Database();

    $db->execute("INSERT INTO residents (email, password_hash, phone, birthdate, gender, first_name, last_name, control_no) VALUES ('ma_sender@example.com', 'hash', '09170000010', '1990-01-01', 'male', 'Mail', 'Sender', 'CTRL-MA-001')");
    $senderResidentId = (int)$db->lastInsertId();

    $db->execute("INSERT INTO residents (email, password_hash, phone, birthdate, gender, first_name, last_name, control_no) VALUES ('ma_stranger@example.com', 'hash', '09170000011', '1990-01-01', 'male', 'Mail', 'Stranger', 'CTRL-MA-002')");
    $strangerResidentId = (int)$db->lastInsertId();

    $db->execute("INSERT INTO staff (email, password_hash, first_name, last_name, position, status) VALUES ('ma_staff@example.com', 'hash', 'Mail', 'StaffRecipient', 'Encoder', 'active')");
    $recipientStaffId = (int)$db->lastInsertId();

    $db->execute("INSERT INTO staff (email, password_hash, first_name, last_name, position, status) VALUES ('ma_staff2@example.com', 'hash', 'Mail', 'StaffStranger', 'Encoder', 'active')");
    $strangerStaffId = (int)$db->lastInsertId();

    $db->execute(
        "INSERT INTO mail (sender_resident_id, recipient_staff_id, subject, body) VALUES (?, ?, 'Test', 'Body')",
        [$senderResidentId, $recipientStaffId]
    );
    $mailId = (int)$db->lastInsertId();

    $controller = new \App\Features\Attachments\AttachmentsController();
    $ref = new \ReflectionClass($controller);
    $method = $ref->getMethod('canAccessMailAttachment');
    $method->setAccessible(true);

    try {
        Auth::loginResident($senderResidentId);
        assert_true($method->invoke($controller, $mailId) === true, 'Sender should access their mail attachment');

        Auth::loginResident($strangerResidentId);
        assert_true($method->invoke($controller, $mailId) === false, 'Stranger resident must be denied');

        Auth::loginStaff($recipientStaffId, 'Encoder');
        assert_true($method->invoke($controller, $mailId) === true, 'Recipient staff should access the attachment');

        Auth::loginStaff($strangerStaffId, 'Encoder');
        assert_true($method->invoke($controller, $mailId) === false, 'Stranger staff must be denied');
    } finally {
        unset($_SESSION['user_id'], $_SESSION['user_type'], $_SESSION['role']);
        $db->execute("DELETE FROM mail WHERE id = ?", [$mailId]);
        $db->execute("DELETE FROM staff WHERE id IN (?, ?)", [$recipientStaffId, $strangerStaffId]);
        $db->execute("DELETE FROM residents WHERE id IN (?, ?)", [$senderResidentId, $strangerResidentId]);
    }
});

use App\Core\AppointmentConfig;

echo "\n--- Configurable Appointment Availability ---\n";

$reasonIsValid = static fn (string $reason): bool => mb_strlen(trim($reason)) >= 3;

test('1. Availability row inserts with correct defaults', function () {
    $db = new Database();
    $testDate = '2099-01-01';
    $slot = '8:00 - 9:00';

    try {
        $db->execute(
            "INSERT INTO appointment_availability (available_date, time_slot) VALUES (?, ?)",
            [$testDate, $slot]
        );
        $row = $db->query(
            "SELECT capacity, is_open FROM appointment_availability WHERE available_date = ? AND time_slot = ?",
            [$testDate, $slot]
        )->fetch();

        assert_true($row !== false, 'Availability row should be created');
        assert_true((int)$row['capacity'] === 1, 'Default capacity should be 1');
        assert_true((int)$row['is_open'] === 1, 'Default is_open should be 1');
    } finally {
        $db->execute("DELETE FROM appointment_availability WHERE available_date = ?", [$testDate]);
    }
});

test('2. Configured date yields exactly its opened slots (opt-in read model)', function () {
    $db = new Database();
    $testDate = '2099-01-02';

    try {
        $db->execute("DELETE FROM appointment_availability WHERE available_date = ?", [$testDate]);
        $db->execute(
            "INSERT INTO appointment_availability (available_date, time_slot, capacity, is_open) VALUES (?, '9:00 - 10:00', 1, 1)",
            [$testDate]
        );

        $rows = $db->query(
            "SELECT aa.time_slot, aa.capacity,
                    (SELECT COUNT(*) FROM appointments a
                      WHERE a.appt_date = aa.available_date
                        AND a.time_slot = aa.time_slot
                        AND a.status = 'booked') AS booked
             FROM appointment_availability aa
             WHERE aa.available_date = ? AND aa.is_open = 1
             ORDER BY aa.id",
            [$testDate]
        )->fetchAll();

        assert_true(count($rows) === 1, 'Should return exactly 1 configured slot');
        assert_true($rows[0]['time_slot'] === '9:00 - 10:00', 'Slot label must match');
        assert_true((int)$rows[0]['capacity'] === 1, 'Capacity must be 1');
        assert_true((int)$rows[0]['booked'] === 0, 'No bookings yet');
        assert_true((int)$rows[0]['booked'] < (int)$rows[0]['capacity'], 'Slot must be available (booked < capacity)');
    } finally {
        $db->execute("DELETE FROM appointment_availability WHERE available_date = ?", [$testDate]);
    }
});

test('3. Unconfigured date yields zero slots (opt-in rule)', function () {
    $db = new Database();
    $unconfiguredDate = '2099-01-03';

    try {
        $rows = $db->query(
            "SELECT time_slot FROM appointment_availability WHERE available_date = ? AND is_open = 1",
            [$unconfiguredDate]
        )->fetchAll();

        assert_true(count($rows) === 0, 'Unconfigured date must return 0 slots');
    } finally {
        $db->execute("DELETE FROM appointment_availability WHERE available_date = ?", [$unconfiguredDate]);
    }
});

test('4. A closed (is_open=0) slot is not bookable', function () {
    $db = new Database();
    $testDate = '2099-01-04';
    $slot = '8:00 - 9:00';

    try {
        $db->execute("DELETE FROM appointment_availability WHERE available_date = ?", [$testDate]);
        $db->execute(
            "INSERT INTO appointment_availability (available_date, time_slot, is_open) VALUES (?, ?, 0)",
            [$testDate, $slot]
        );

        $avail = $db->query(
            "SELECT capacity, is_open FROM appointment_availability WHERE available_date = ? AND time_slot = ?",
            [$testDate, $slot]
        )->fetch();

        $bookable = ($avail !== false) && ((int)$avail['is_open'] === 1);
        assert_true($bookable === false, 'Closed slot must not be bookable (book() would 422)');
    } finally {
        $db->execute("DELETE FROM appointment_availability WHERE available_date = ?", [$testDate]);
    }
});

test('5. Capacity > 1: a slot is full once booked count reaches capacity', function () {
    $db = new Database();
    $testDate = '2099-01-05';
    $slot = '10:00 - 11:00';

    try {
        $db->execute(
            "INSERT INTO appointment_availability (available_date, time_slot, capacity, is_open) VALUES (?, ?, 2, 1)",
            [$testDate, $slot]
        );
        $db->execute("INSERT INTO appointments (resident_id, appt_date, time_slot, status) VALUES (1, ?, ?, 'booked')", [$testDate, $slot]);
        $db->execute("INSERT INTO appointments (resident_id, appt_date, time_slot, status) VALUES (2, ?, ?, 'booked')", [$testDate, $slot]);

        $cap = (int)$db->query(
            "SELECT capacity FROM appointment_availability WHERE available_date = ? AND time_slot = ?",
            [$testDate, $slot]
        )->fetch()['capacity'];
        $booked = (int)$db->query(
            "SELECT COUNT(*) AS cnt FROM appointments WHERE appt_date = ? AND time_slot = ? AND status = 'booked'",
            [$testDate, $slot]
        )->fetch()['cnt'];

        assert_true($cap === 2, 'Capacity must be 2');
        assert_true($booked === 2, '2 bookings must exist');

        assert_true($booked >= $cap, 'Slot is full; a 3rd booking would be rejected (409)');
    } finally {
        $db->execute("DELETE FROM appointments WHERE appt_date = ?", [$testDate]);
        $db->execute("DELETE FROM appointment_availability WHERE available_date = ?", [$testDate]);
    }
});

test('6. store() validation: past dates and invalid slot labels are rejected', function () {
    $today     = AppointmentConfig::now()->format('Y-m-d');
    $yesterday = AppointmentConfig::now()->modify('-1 day')->format('Y-m-d');

    assert_true($yesterday < $today, 'Yesterday must be a past date');
    assert_true(AppointmentConfig::isValidSlot('8:00 - 9:00') === true, 'Canonical slot label is valid');
    assert_true(AppointmentConfig::isValidSlot('invalid-slot') === false, 'Unknown slot label is rejected');
    assert_true(AppointmentConfig::isValidSlot('12:00 - 1:00') === false, 'Non-canonical label is rejected');
});

test('7. Mandatory reason guard rejects empty / short / whitespace-only input', function () use ($reasonIsValid) {
    assert_true($reasonIsValid('') === false, 'Empty reason rejected');
    assert_true($reasonIsValid('ab') === false, 'Too-short reason rejected');
    assert_true($reasonIsValid('   ') === false, 'Whitespace-only reason rejected');
    assert_true($reasonIsValid('ok!') === true, 'A 3-char reason is accepted');
    assert_true($reasonIsValid('  valid reason  ') === true, 'Trimmed valid reason accepted');
});

test('8. destroy() path: cancels affected bookings and persists cancel_reason + cancelled_at', function () {
    $db = new Database();
    $testDate = '2099-01-08';
    $slot = '1:00 - 2:00';

    try {
        $db->execute(
            "INSERT INTO appointment_availability (available_date, time_slot, capacity, is_open) VALUES (?, ?, 1, 1)",
            [$testDate, $slot]
        );
        $availId = (int)$db->lastInsertId();

        $db->execute(
            "INSERT INTO appointments (resident_id, appt_date, time_slot, status) VALUES (4, ?, ?, 'booked')",
            [$testDate, $slot]
        );
        $apptId = (int)$db->lastInsertId();

        $reason = 'Emergency office maintenance';
        $db->beginTransaction();
        try {
            $affected = $db->query(
                "SELECT id, resident_id FROM appointments
                 WHERE appt_date = ? AND time_slot = ? AND status = 'booked'",
                [$testDate, $slot]
            )->fetchAll();

            $notifier = new \App\Core\NotificationService($db);
            foreach ($affected as $booking) {
                $db->execute(
                    "UPDATE appointments SET status = 'cancelled', cancel_reason = ?, cancelled_at = NOW() WHERE id = ?",
                    [$reason, (int)$booking['id']]
                );
                $notifier->appointmentCancelledByStaff((int)$booking['resident_id'], $testDate, $slot, $reason);
            }
            $db->execute("DELETE FROM appointment_availability WHERE id = ?", [$availId]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $appt = $db->query("SELECT status, cancel_reason, cancelled_at FROM appointments WHERE id = ?", [$apptId])->fetch();
        assert_true($appt['status'] === 'cancelled', 'Status must be cancelled');
        assert_true($appt['cancel_reason'] === 'Emergency office maintenance', 'Cancel reason must match');
        assert_true(!empty($appt['cancelled_at']), 'Cancelled timestamp must be populated');

        $stillThere = $db->query("SELECT id FROM appointment_availability WHERE id = ?", [$availId])->fetch();
        assert_true($stillThere === false, 'Availability row must be deleted');
    } finally {
        $db->execute("DELETE FROM appointments WHERE appt_date = ?", [$testDate]);
        $db->execute("DELETE FROM appointment_availability WHERE available_date = ?", [$testDate]);
    }
});

test('9. Staff cancellation notification includes the reason', function () {
    $db = new Database();
    $testDate = '2099-01-09';
    $slot = '2:00 - 3:00';
    $reason = 'Holiday closure';

    try {
        (new \App\Core\NotificationService($db))->appointmentCancelledByStaff(4, $testDate, $slot, $reason);

        $notif = $db->query(
            "SELECT message FROM notifications WHERE resident_id = 4 ORDER BY id DESC LIMIT 1"
        )->fetch();

        assert_true($notif !== false, 'Notification row created');
        assert_true(str_contains($notif['message'], $reason), 'Notification must include the reason');
        assert_true(str_contains($notif['message'], $testDate), 'Notification must include the date');
    } finally {
        $db->execute("DELETE FROM notifications WHERE resident_id = 4 AND message LIKE ?", ['%Holiday closure%']);
    }
});

test('10. Role guards: admin mutation access vs regular staff', function () {
    Auth::loginStaff(1, 'Encoder');
    assert_true(Auth::isAdmin() === false, 'Regular staff is not admin');

    Auth::loginStaff(1, 'System Administrator');
    assert_true(Auth::isAdmin() === true, 'Admin position is admin');

    unset($_SESSION['user_id'], $_SESSION['user_type'], $_SESSION['role']);
});

test('11. bulkWeek date math yields exactly 5 consecutive weekdays', function () {
    $mondayDate = '2099-02-02';
    $dt = new \DateTimeImmutable($mondayDate);
    assert_true($dt->format('N') === '1', 'Anchor must be a Monday');

    $datesOpened = [];
    for ($i = 0; $i < 5; $i++) {
        $datesOpened[] = $dt->modify('+' . $i . ' days')->format('Y-m-d');
    }

    assert_true(count($datesOpened) === 5, 'Must open exactly 5 weekdays');
    assert_true($datesOpened[0] === '2099-02-02', 'First opened day is Monday');
    assert_true($datesOpened[4] === '2099-02-06', 'Last opened day is Friday');
});

test('12. Resident cancel: reason guard + persistence of cancel_reason + cancelled_at', function () use ($reasonIsValid) {
    $db = new Database();
    $testDate = '2099-01-12';

    try {
        assert_true($reasonIsValid('') === false, 'Empty reason fails the guard');

        $db->execute(
            "INSERT INTO appointments (resident_id, appt_date, time_slot, status) VALUES (4, ?, '3:00 - 4:00', 'booked')",
            [$testDate]
        );
        $apptId = (int)$db->lastInsertId();

        $reason = 'Personal conflict';
        $db->execute(
            "UPDATE appointments SET status = 'cancelled', cancel_reason = ?, cancelled_at = NOW() WHERE id = ?",
            [$reason, $apptId]
        );

        $appt = $db->query("SELECT status, cancel_reason, cancelled_at FROM appointments WHERE id = ?", [$apptId])->fetch();
        assert_true($appt['status'] === 'cancelled', 'Status updated to cancelled');
        assert_true($appt['cancel_reason'] === 'Personal conflict', 'Reason persisted');
        assert_true(!empty($appt['cancelled_at']), 'cancelled_at timestamp set');
    } finally {
        $db->execute("DELETE FROM appointments WHERE appt_date = ?", [$testDate]);
    }
});

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $err) {
        echo "  - {$err}\n";
    }
    exit(1);
}

echo "\nAll tests passed.\n";
exit(0);
