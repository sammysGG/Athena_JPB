<?php
declare(strict_types=1);

session_start();

const DB_PATH = '/var/www/data/jpb.sqlite';
const ANNUAL_LEAVE_DAYS = 38;
const JPB_ROSTER_JSON = __DIR__ . '/jpb_roster.json';

/** @return list<array{username:string,password:string,firstname:string,surname:string,gender:string,rank:string,service_number:string}> */
function load_jpb_roster(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    if (!is_readable(JPB_ROSTER_JSON)) {
        $cache = [];

        return $cache;
    }

    $decoded = json_decode((string) file_get_contents(JPB_ROSTER_JSON), true);

    $cache = is_array($decoded) ? $decoded : [];

    return $cache;
}

function jpb_seed_int(int $userId, int $salt, int $min, int $max): int
{
    if ($min >= $max) {
        return $min;
    }

    $h = crc32((string) $userId . '|' . (string) $salt);

    return $min + ($h % ($max - $min + 1));
}

function jpb_format_gbp(float $amount): string
{
    return '£' . number_format(round($amount, 2), 2, '.', ',');
}

function rank_to_long_title(string $rank): string
{
    static $map = [
        'Brig' => 'Brigadier',
        'Col' => 'Colonel',
        'Lt Col' => 'Lieutenant Colonel',
        'Maj' => 'Major',
        'Capt' => 'Captain',
        'Lt' => 'Lieutenant',
        '2Lt' => 'Second Lieutenant',
        'WO1' => 'Warrant Officer Class 1',
        'WO2' => 'Warrant Officer Class 2',
        'SSgt' => 'Staff Sergeant',
        'Sgt' => 'Sergeant',
        'Cpl' => 'Corporal',
        'LCpl' => 'Lance Corporal',
        'Pte' => 'Private',
    ];

    return $map[$rank] ?? $rank;
}

function role_for_roster_user(string $rank, string $username): string
{
    if ($username === 'admin') {
        return 'Unit Administrator';
    }

    if (in_array($rank, ['Brig', 'Col', 'Lt Col'], true)) {
        return 'Line Manager';
    }

    if (in_array($rank, ['Maj', 'Capt'], true) && jpb_seed_int(crc32($username), 1, 0, 2) === 0) {
        return 'Line Manager';
    }

    return 'Self Service User';
}

function roster_email(array $row): string
{
    if (($row['username'] ?? '') === 'admin') {
        return 'admin@jpb.local';
    }

    $a = strtolower(preg_replace('/[^a-z]+/i', '', $row['firstname'] ?? ''));
    $b = strtolower(preg_replace('/[^a-z]+/i', '', $row['surname'] ?? ''));

    return $a . '.' . $b . '@jpb.local';
}

/** @return array{basic:float,specialist:float,allowance:float,gross:float,paye:float,ni:float,pension:float,net:float} */
function pay_components_for_user(int $userId, string $rank): array
{
    static $baseMonthly = [
        'Brig' => 10500.0,
        'Col' => 8200.0,
        'Lt Col' => 7200.0,
        'Maj' => 6400.0,
        'Capt' => 5200.0,
        'Lt' => 4600.0,
        '2Lt' => 4100.0,
        'WO1' => 4850.0,
        'WO2' => 4480.0,
        'SSgt' => 4120.0,
        'Sgt' => 3780.0,
        'Cpl' => 3380.0,
        'LCpl' => 3080.0,
        'Pte' => 2140.0,
    ];

    $base = $baseMonthly[$rank] ?? 2200.0;
    $jitter = 0.94 + (jpb_seed_int($userId, 11, 0, 120) / 1000.0);
    $basic = round($base * $jitter, 2);

    $specCap = in_array($rank, ['Brig', 'Col', 'Lt Col', 'Maj', 'Capt', 'Lt', '2Lt'], true) ? 120.0 : 340.0;
    $specialist = (float) jpb_seed_int($userId, 12, 0, (int) $specCap);
    if ($specialist < 25 && $rank === 'Pte') {
        $specialist = 0.0;
    }

    $allowance = jpb_seed_int($userId, 13, 0, 4) === 0 ? 0.0 : (float) jpb_seed_int($userId, 14, 35, 195);

    $gross = round($basic + $specialist + $allowance, 2);
    $pension = round($gross * 0.096, 2);
    $ni = round($gross * 0.068, 2);
    $netRaw = round($gross * 0.672 - $pension * 0.4, 2);
    $paye = round($gross - $netRaw - $ni - $pension, 2);
    if ($paye < 120) {
        $paye = round($gross * 0.22, 2);
        $netRaw = round($gross - $paye - $ni - $pension, 2);
    }
    if ($paye < 0) {
        $paye = round($gross * 0.18, 2);
        $netRaw = round($gross - $paye - $ni - $pension, 2);
    }

    return [
        'basic' => $basic,
        'specialist' => $specialist,
        'allowance' => $allowance,
        'gross' => $gross,
        'paye' => $paye,
        'ni' => $ni,
        'pension' => $pension,
        'net' => $netRaw,
    ];
}

/** @return list<array{effective:string,rank:string,band:string,location:string,role:string}> */
function career_history_for_user(int $userId, string $rank): array
{
    static $ladder = ['Pte', 'LCpl', 'Cpl', 'Sgt', 'SSgt', 'WO2', 'WO1', '2Lt', 'Lt', 'Capt', 'Maj', 'Lt Col', 'Col', 'Brig'];
    $idx = array_search($rank, $ladder, true);
    if ($idx === false) {
        $idx = 0;
    }

    $locations = ['Catterick Garrison', 'Aldershot', 'Colchester', 'Tidworth', 'Larkhill', 'Inverness', 'Bovington', 'Shorncliffe'];
    $units = [
        '3rd Battalion Training Group',
        'Regimental Headquarters',
        '1st Battalion Field Company',
        'Support Company HQ',
        'Training Wing',
        'Operations Cell',
    ];

    $rows = [];
    for ($i = 0; $i < 4; $i++) {
        $rIdx = max(0, $idx - $i);
        $r = $ladder[$rIdx];
        $long = rank_to_long_title($r);
        $pc = pay_components_for_user($userId + $i * 31, $r);
        $annual = $pc['basic'] * 12.0;
        $band = sprintf('%s — indicative £%s / yr', $long, number_format($annual, 0, '.', ','));
        $rows[] = [
            'effective' => date('d-M-Y', strtotime('-' . ($i * 22) . ' months')),
            'rank' => $r,
            'band' => $band,
            'location' => $locations[jpb_seed_int($userId, 20 + $i, 0, count($locations) - 1)],
            'role' => $units[jpb_seed_int($userId, 30 + $i, 0, count($units) - 1)],
        ];
    }

    return $rows;
}

function user_has_roster_columns(PDO $db): bool
{
    $stmt = $db->query("PRAGMA table_info(users)");
    $names = array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'name');

    return in_array('mil_rank', $names, true);
}

function create_expense_and_career_tables(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS expense_claims (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            reference TEXT NOT NULL,
            claim_type TEXT NOT NULL,
            amount TEXT NOT NULL,
            status TEXT NOT NULL,
            approver TEXT NOT NULL,
            submitted_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS career_rows (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            effective_date TEXT NOT NULL,
            rank_label TEXT NOT NULL,
            salary_band TEXT NOT NULL,
            location TEXT NOT NULL,
            role_title TEXT NOT NULL
        );
    ");
}

function repopulate_jpb_from_roster(PDO $db): void
{
    $roster = load_jpb_roster();

    if ($roster === []) {
        return;
    }

    $db->exec('DELETE FROM payslips');
    $db->exec('DELETE FROM expense_claims');
    $db->exec('DELETE FROM career_rows');
    $db->exec('DELETE FROM reset_codes');
    $db->exec('DELETE FROM users');
    $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('users','payslips','expense_claims','career_rows')");

    $insUser = $db->prepare('INSERT INTO users (username, password, display_name, role, email, employee_no, last_login, mil_rank, gender, service_number)
        VALUES (?,?,?,?,?,?,?,?,?,?)');
    $insPay = $db->prepare('INSERT INTO payslips (user_id, period, gross_pay, net_pay, status, created_at, basic_pay, specialist_pay, allowance_fit, paye, ni, pension)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $insExp = $db->prepare('INSERT INTO expense_claims (user_id, reference, claim_type, amount, status, approver, submitted_at) VALUES (?,?,?,?,?,?,?)');
    $insCar = $db->prepare('INSERT INTO career_rows (user_id, effective_date, rank_label, salary_band, location, role_title) VALUES (?,?,?,?,?,?)');

    $lastLoginTpl = ['11-May-2026 18:41', '10-May-2026 09:12', '09-May-2026 15:27', '08-May-2026 07:03', '07-May-2026 16:22'];

    foreach ($roster as $i => $row) {
        $rank = (string) $row['rank'];
        $display = $rank . ' ' . $row['firstname'] . ' ' . $row['surname'];
        $role = role_for_roster_user($rank, (string) $row['username']);
        $email = roster_email($row);
        $last = $lastLoginTpl[jpb_seed_int($i, 3, 0, count($lastLoginTpl) - 1)];

        $insUser->execute([
            $row['username'],
            $row['password'],
            $display,
            $role,
            $email,
            'JPB-' . substr((string) $row['service_number'], -5),
            $last,
            $rank,
            $row['gender'],
            (string) $row['service_number'],
        ]);

        $uid = (int) $db->lastInsertId();
        $periods = [
            ['April 2026', '2026-04-30'],
            ['March 2026', '2026-03-31'],
            ['February 2026', '2026-02-28'],
        ];
        $nPayslips = 1 + jpb_seed_int($uid, 40, 0, 2);

        for ($p = 0; $p < $nPayslips; $p++) {
            $pc = pay_components_for_user($uid + $p * 97, $rank);
            [$periodLabel, $created] = $periods[$p];
            $insPay->execute([
                $uid,
                $periodLabel,
                jpb_format_gbp($pc['gross']),
                jpb_format_gbp($pc['net']),
                'Published',
                $created,
                jpb_format_gbp($pc['basic']),
                jpb_format_gbp($pc['specialist']),
                jpb_format_gbp($pc['allowance']),
                jpb_format_gbp($pc['paye']),
                jpb_format_gbp($pc['ni']),
                jpb_format_gbp($pc['pension']),
            ]);
        }

        $nClaims = jpb_seed_int($uid, 50, 0, 4) === 0 ? 0 : (1 + jpb_seed_int($uid, 51, 0, 5));
        $statuses = ['Paid', 'Awaiting RAO approval', 'Returned for evidence', 'Draft', 'Paid'];
        $types = ['Duty travel - rail', 'Field exercise subsistence', 'Private mileage', 'Accommodation', 'Duty meal claim'];
        $approvers = ['Capt Forrest', 'WO2 Webster', 'RAO Cell', 'OC A Company', 'Unit admin'];

        for ($c = 0; $c < $nClaims; $c++) {
            $ref = sprintf('EXP-2026-%04d', 1200 + ($uid * 17 + $c * 41) % 7999);
            $amt = jpb_format_gbp((float) jpb_seed_int($uid, 60 + $c, 1200, 9850) / 100);
            $day = jpb_seed_int($uid, 64 + $c, 1, 28);
            $mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May'][jpb_seed_int($uid, 65 + $c, 0, 4)];
            $submitted = sprintf('%02d-%s-2026', $day, $mon);
            $insExp->execute([
                $uid,
                $ref,
                $types[jpb_seed_int($uid, 61 + $c, 0, count($types) - 1)],
                $amt,
                $statuses[jpb_seed_int($uid, 62 + $c, 0, count($statuses) - 1)],
                $approvers[jpb_seed_int($uid, 63 + $c, 0, count($approvers) - 1)],
                $submitted,
            ]);
        }

        foreach (array_reverse(career_history_for_user($uid, $rank)) as $cr) {
            $insCar->execute([$uid, $cr['effective'], $cr['rank'], $cr['band'], $cr['location'], $cr['role']]);
        }
    }

    $db->exec("INSERT INTO reset_codes (user_id, reset_code, expires_at) VALUES
        ((SELECT id FROM users WHERE username = 'admin' LIMIT 1), 'APR-ADMIN-9381', '2026-05-12 18:00:00')");

    $db->exec("INSERT OR REPLACE INTO app_config (key, value) VALUES
        ('environment', 'training'),
        ('ldap_host', 'ldap://corp-dc01.jpb.local'),
        ('backup_path', '/backups/'),
        ('support_phone', '0141 2246690'),
        ('roster_schema', '2')");
}

function migrate_roster_schema_if_needed(PDO $db): void
{
    if (load_jpb_roster() === []) {
        return;
    }

    if (user_has_roster_columns($db)) {
        return;
    }

    create_expense_and_career_tables($db);

    $db->exec("ALTER TABLE users ADD COLUMN mil_rank TEXT NOT NULL DEFAULT ''");
    $db->exec("ALTER TABLE users ADD COLUMN gender TEXT NOT NULL DEFAULT ''");
    $db->exec("ALTER TABLE users ADD COLUMN service_number TEXT NOT NULL DEFAULT ''");

    $payCols = array_column($db->query('PRAGMA table_info(payslips)')->fetchAll(PDO::FETCH_ASSOC) ?: [], 'name');
    foreach (['basic_pay', 'specialist_pay', 'allowance_fit', 'paye', 'ni', 'pension'] as $col) {
        if (!in_array($col, $payCols, true)) {
            $db->exec("ALTER TABLE payslips ADD COLUMN {$col} TEXT NOT NULL DEFAULT ''");
        }
    }

    repopulate_jpb_from_roster($db);
}

function app_db(): PDO
{
    static $db = null;

    if ($db instanceof PDO) {
        return $db;
    }

    $isNew = !file_exists(DB_PATH);
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if ($isNew) {
        seed_database($db);
    } else {
        migrate_roster_schema_if_needed($db);
    }

    return $db;
}

function seed_database(PDO $db): void
{
    $db->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            display_name TEXT NOT NULL,
            role TEXT NOT NULL,
            email TEXT NOT NULL,
            employee_no TEXT NOT NULL,
            last_login TEXT NOT NULL,
            mil_rank TEXT NOT NULL DEFAULT '',
            gender TEXT NOT NULL DEFAULT '',
            service_number TEXT NOT NULL DEFAULT ''
        );

        CREATE TABLE payslips (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            period TEXT NOT NULL,
            gross_pay TEXT NOT NULL,
            net_pay TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL,
            basic_pay TEXT NOT NULL DEFAULT '',
            specialist_pay TEXT NOT NULL DEFAULT '',
            allowance_fit TEXT NOT NULL DEFAULT '',
            paye TEXT NOT NULL DEFAULT '',
            ni TEXT NOT NULL DEFAULT '',
            pension TEXT NOT NULL DEFAULT ''
        );

        CREATE TABLE reset_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            reset_code TEXT NOT NULL,
            expires_at TEXT NOT NULL
        );

        CREATE TABLE app_config (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
    ");

    create_expense_and_career_tables($db);
    repopulate_jpb_from_roster($db);
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function current_user(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $stmt = app_db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function require_login(): array
{
    $user = current_user();

    if (!$user) {
        header('Location: /?page=login');
        exit;
    }

    return $user;
}

function page_url(string $page): string
{
    return '/?page=' . rawurlencode($page);
}

function worklist_items(): array
{
    return [
        [
            'id' => 'expense-approval',
            'from' => 'RAO',
            'type' => 'Approval',
            'subject' => 'Expense claim EXP-2026-1042 requires evidence upload.',
            'due' => '13-May',
            'page' => 'expenses',
            'action' => 'Review',
        ],
        [
            'id' => 'leave-balance',
            'from' => 'Unit Admin',
            'type' => 'Action',
            'subject' => 'Confirm leave balance before summer block leave.',
            'due' => '16-May',
            'page' => 'time',
            'action' => 'Open',
        ],
        [
            'id' => 'hearing-review',
            'from' => 'Medical Centre',
            'type' => 'Notice',
            'subject' => 'Book annual hearing conservation appointment.',
            'due' => '20-May',
            'page' => 'health',
            'action' => 'Open',
        ],
    ];
}

function cleared_worklist_ids(): array
{
    if (!isset($_SESSION['cleared_worklist_ids']) || !is_array($_SESSION['cleared_worklist_ids'])) {
        $_SESSION['cleared_worklist_ids'] = [];
    }

    return $_SESSION['cleared_worklist_ids'];
}

function active_worklist_items(): array
{
    $cleared = array_flip(cleared_worklist_ids());

    return array_values(array_filter(
        worklist_items(),
        fn (array $item): bool => !isset($cleared[$item['id']])
    ));
}

function active_worklist_count(): int
{
    return count(active_worklist_items());
}

function clear_worklist_items(array $ids): void
{
    $validIds = array_column(worklist_items(), 'id');
    $validLookup = array_flip($validIds);
    $selected = array_values(array_filter($ids, fn ($id): bool => is_string($id) && isset($validLookup[$id])));

    if (!$selected) {
        return;
    }

    $_SESSION['cleared_worklist_ids'] = array_values(array_unique(array_merge(cleared_worklist_ids(), $selected)));
}

function handle_worklist_post(string $redirectPage): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['worklist_action'] ?? '') !== 'clear') {
        return;
    }

    $ids = $_POST['notification_ids'] ?? [];
    clear_worklist_items(is_array($ids) ? $ids : [$ids]);
    header('Location: ' . page_url($redirectPage));
    exit;
}

function leave_bookings(): array
{
    if (!isset($_SESSION['leave_bookings']) || !is_array($_SESSION['leave_bookings'])) {
        $_SESSION['leave_bookings'] = [];
    }

    return $_SESSION['leave_bookings'];
}

function leave_days(string $startDate, string $endDate): int
{
    try {
        $start = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);
    } catch (Throwable) {
        return 0;
    }

    if ($end < $start) {
        return 0;
    }

    return (int) $start->diff($end)->days + 1;
}

function active_leave_bookings(): array
{
    return array_values(array_filter(
        leave_bookings(),
        fn (array $booking): bool => ($booking['status'] ?? '') === 'Booked'
    ));
}

function leave_taken_days(): int
{
    return array_sum(array_map(
        fn (array $booking): int => (int) ($booking['days'] ?? 0),
        active_leave_bookings()
    ));
}

function add_leave_booking(array $data): void
{
    leave_bookings();

    $_SESSION['leave_bookings'][] = [
        'id' => uniqid('leave-', false),
        'type' => $data['leave_type'],
        'start_date' => $data['start_date'],
        'end_date' => $data['end_date'],
        'location' => $data['leave_location'],
        'notes' => $data['notes'],
        'days' => leave_days($data['start_date'], $data['end_date']),
        'status' => 'Booked',
        'submitted_at' => date('d-M-Y H:i'),
    ];
}

function cancel_leave_booking(string $bookingId): bool
{
    foreach (leave_bookings() as $index => $booking) {
        if (($booking['id'] ?? '') === $bookingId && ($booking['status'] ?? '') === 'Booked') {
            $_SESSION['leave_bookings'][$index]['status'] = 'Cancelled';
            $_SESSION['leave_bookings'][$index]['cancelled_at'] = date('d-M-Y H:i');

            return true;
        }
    }

    return false;
}

function bank_details(): array
{
    $user = current_user();
    $uid = $user ? (int) $user['id'] : 0;

    if ($user && (!isset($_SESSION['bank_details_user_id']) || (int) $_SESSION['bank_details_user_id'] !== $uid)) {
        $sn = (string) ($user['service_number'] ?? '2482');
        $suffix = preg_replace('/\D/', '', $sn);
        $suffix = substr(str_pad($suffix, 4, '0', STR_PAD_LEFT), -4);
        $_SESSION['bank_details'] = [
            'account_name' => $user['display_name'],
            'bank_name' => 'UK Military Banking',
            'sort_code' => sprintf(
                '%02d-%02d-%02d',
                jpb_seed_int($uid, 88, 10, 99),
                jpb_seed_int($uid, 89, 10, 99),
                jpb_seed_int($uid, 90, 10, 99)
            ),
            'account_number' => (string) jpb_seed_int($uid, 91, 10000000, 99999999),
            'roll_number' => 'N/A',
        ];
        $_SESSION['bank_details_user_id'] = $uid;
        $_SESSION['bank_account_suffix'] = $suffix;
    }

    if (!isset($_SESSION['bank_details']) || !is_array($_SESSION['bank_details'])) {
        $_SESSION['bank_details'] = [
            'account_name' => 'WO2 Nathan Webster',
            'bank_name' => 'Websters Defence Bank',
            'sort_code' => '12-34-56',
            'account_number' => '12345678',
            'roll_number' => 'N/A',
        ];
    }

    return $_SESSION['bank_details'];
}

function update_bank_details(array $details): void
{
    $_SESSION['bank_details'] = [
        'account_name' => trim((string) ($details['account_name'] ?? '')),
        'bank_name' => trim((string) ($details['bank_name'] ?? '')),
        'sort_code' => trim((string) ($details['sort_code'] ?? '')),
        'account_number' => trim((string) ($details['account_number'] ?? '')),
        'roll_number' => trim((string) ($details['roll_number'] ?? '')),
    ];
}

function render_header(string $title = 'Personnel Business Suite', bool $showTools = false): void
{
    $user = current_user();
    $notificationCount = $showTools ? active_worklist_count() : 0;
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>JPB <?= h($title) ?></title>
        <link rel="stylesheet" href="/assets/styles.css?v=20260512-jpa-reference">
    </head>
    <body>
        <header class="topbar">
            <div class="brand">
                <a class="logo" href="<?= page_url('home') ?>">JPA</a>
                <div class="support">
                    <strong>If you need help, contact the JPAC using Support or phone</strong><br>
                    Military: 94560 3360 &nbsp; Civilian: 0141 2246690 &nbsp; Personnel: JPA 94560 5360
                </div>
                <?php if ($showTools): ?>
                    <span class="suite-name">Personnel Business Suite</span>
                <?php endif; ?>
            </div>
            <nav class="top-actions" aria-label="Application actions">
                <?php if ($showTools): ?>
                    <a href="<?= page_url('home') ?>" title="Home">&#8962;</a>
                    <a href="<?= page_url('favorites') ?>" title="Favorites">&#9733;</a>
                    <a href="<?= page_url('settings') ?>" title="Settings">&#9881;</a>
                    <a href="<?= page_url('notifications') ?>" title="Notifications">&#9670;<?php if ($notificationCount > 0): ?><span class="badge"><?= h((string) $notificationCount) ?></span><?php endif; ?></a>
                    <span class="session-text">Logged In As <?= h($user['username'] ?? '') ?><br>Last Login <?= h($user['last_login'] ?? '') ?></span>
                    <a href="<?= page_url('help') ?>" title="Help">?</a>
                    <a href="<?= page_url('logout') ?>" title="Logout">&#9211;</a>
                <?php endif; ?>
            </nav>
        </header>
        <main class="page-shell">
    <?php
}

function render_footer(): void
{
    ?>
        </main>
        <footer class="footer">
            <span>Copyright (c) 1998, 2026, Websters Systems. All rights reserved.</span>
            <a href="<?= page_url('about') ?>">About this Page</a>
        </footer>
        <script>
            (() => {
                const storageKey = 'jpa.navigator.openSections';
                const nodes = document.querySelectorAll('.tree-node[data-nav-id]');

                if (!nodes.length) {
                    return;
                }

                const saved = JSON.parse(localStorage.getItem(storageKey) || '{}');

                nodes.forEach((node) => {
                    const navId = node.dataset.navId;

                    if (Object.prototype.hasOwnProperty.call(saved, navId)) {
                        node.open = Boolean(saved[navId]);
                    }

                    node.addEventListener('toggle', () => {
                        saved[navId] = node.open;
                        localStorage.setItem(storageKey, JSON.stringify(saved));
                    });
                });
            })();
        </script>
    </body>
    </html>
    <?php
}

function render_navigator(): void
{
    ?>
    <aside class="navigator">
        <div class="pane-title">Navigator</div>
        <ul class="tree">
            <li>
                <details class="tree-node" data-nav-id="expenses">
                    <summary>JPB Expenses</summary>
                    <ul>
                        <li><a href="<?= page_url('expenses') ?>">Expenses Home</a></li>
                        <li><a href="<?= page_url('expense-claim') ?>">Enter Expense Claim</a></li>
                    </ul>
                </details>
            </li>
            <li>
                <details class="tree-node" data-nav-id="self-service">
                    <summary>JPB Self Service</summary>
                    <ul>
                        <li>
                            <details class="tree-node" data-nav-id="my-information">
                                <summary>My Information</summary>
                                <ul>
                                    <li><a href="<?= page_url('personal-info') ?>">Personal Information</a></li>
                                    <li><a href="<?= page_url('misc-details') ?>">Miscellaneous Personal Details</a></li>
                                    <li><a href="<?= page_url('service-summary') ?>">Personal and Service Details Summary</a></li>
                                    <li><a href="<?= page_url('information-views') ?>">My Information Views</a></li>
                                </ul>
                            </details>
                        </li>
                        <li>
                            <details class="tree-node" data-nav-id="my-time">
                                <summary>My Time</summary>
                                <ul>
                                    <li><a href="<?= page_url('leave-absence') ?>">Leave of Absence</a></li>
                                    <li><a href="<?= page_url('cancel-leave') ?>">Cancel Leave of Absence</a></li>
                                    <li><a href="<?= page_url('absence-balances') ?>">Absence Balances</a></li>
                                </ul>
                            </details>
                        </li>
                        <li>
                            <details class="tree-node" data-nav-id="my-money">
                                <summary>My Money</summary>
                                <ul>
                                    <li><a href="<?= page_url('reports') ?>">Pay Report</a></li>
                                    <li><a href="<?= page_url('payslip') ?>">Payslip</a></li>
                                    <li><a href="<?= page_url('bank-account') ?>">Bank Account Information</a></li>
                                </ul>
                            </details>
                        </li>
                        <li>
                            <details class="tree-node" data-nav-id="my-health">
                                <summary>My Health</summary>
                                <ul>
                                    <li><a href="<?= page_url('dental-details') ?>">Dental Details</a></li>
                                    <li><a href="<?= page_url('medical-details') ?>">Medical Details</a></li>
                                </ul>
                            </details>
                        </li>
                        <li>
                            <details class="tree-node" data-nav-id="my-skills">
                                <summary>My Skills</summary>
                                <ul>
                                    <li><a href="<?= page_url('competencies') ?>">Competencies</a></li>
                                    <li><a href="<?= page_url('exiting-service') ?>">Exiting the Service</a></li>
                                </ul>
                            </details>
                        </li>
                    </ul>
                </details>
            </li>
        </ul>
    </aside>
    <?php
}

function render_guides(): void
{
    ?>
    <aside class="guides">
        <div class="pane-title">Navigation Guides</div>
        <h3>Favorites</h3>
        <p>Use these buttons for guidance.</p>
        <a href="<?= page_url('process-guide') ?>">JPB Process Guide</a>
        <a href="<?= page_url('self-service-guide') ?>">Self Service User Guide</a>
        <a href="<?= page_url('expenses-guide') ?>">Expenses User Guide</a>
        <a href="<?= page_url('support') ?>">Support User Guide</a>
        <p>If you are experiencing difficulties, please contact your JPB Enquiry Centre Agent for assistance.</p>
    </aside>
    <?php
}

function render_app_page(string $title, callable $content): void
{
    require_login();
    render_header($title, true);
    ?>
    <section class="home-title"><?= h($title) ?></section>
    <section class="home-grid">
        <?php render_navigator(); ?>
        <section class="content-area">
            <?php $content(); ?>
        </section>
        <?php render_guides(); ?>
    </section>
    <?php
    render_footer();
}

function login_page(): void
{
    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        try {
            // Training vulnerability: deliberately built with string concatenation.
            $sql = "SELECT * FROM users WHERE username = '$username' AND password = '$password' LIMIT 1";
            $user = app_db()->query($sql)->fetch();

            if ($user) {
                $_SESSION['user_id'] = (int) $user['id'];
                header('Location: ' . page_url('home'));
                exit;
            }

            $error = 'Invalid username or password.';
        } catch (Throwable $exception) {
            $error = 'Database error: ' . $exception->getMessage();
        }
    }

    render_header('Login');
    ?>
    <section class="login-panel">
        <form class="login-form" method="post" autocomplete="off">
            <div class="field-row">
                <label for="username"><span>*</span> User Name</label>
                <input id="username" name="username" autofocus>
            </div>
            <div class="field-row">
                <label for="password"><span>*</span> Password</label>
                <input id="password" name="password" type="password">
            </div>
            <div class="button-row">
                <button type="submit">Log In</button>
                <a class="button-link" href="<?= page_url('reset') ?>">Forgot your Password?</a>
            </div>
            <?php if ($error): ?>
                <p class="error"><?= h($error) ?></p>
            <?php endif; ?>
        </form>
    </section>
    <?php
    render_footer();
}

function reset_page(): void
{
    $message = '';
    $leakedCode = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = $_POST['username'] ?? '';

        try {
            // Training vulnerability: reset lookup is injectable and leaks useful errors.
            $sql = "SELECT users.username, users.email, reset_codes.reset_code
                    FROM users JOIN reset_codes ON reset_codes.user_id = users.id
                    WHERE users.username = '$username'
                    LIMIT 1";
            $result = app_db()->query($sql)->fetch();

            if ($result) {
                $message = 'Password reset instructions have been sent to ' . mask_email($result['email']) . '.';
                $leakedCode = $result['reset_code'];
            } else {
                $message = 'No user record was found for that username.';
            }
        } catch (Throwable $exception) {
            $message = 'Database error: ' . $exception->getMessage();
        }
    }

    render_header('Automated Password Reset - Step 1');
    ?>
    <section class="reset-page">
        <div class="wizard-title">
            <h1>Automated Password Reset - Step 1</h1>
            <div>
                <a href="<?= page_url('login') ?>">Cancel</a>
                <button form="reset-form">Next</button>
            </div>
        </div>
        <p class="required-note">* indicates required field</p>
        <div class="section-rule">
            <h2>User Details</h2>
        </div>
        <p>Enter your Username and Click Next</p>
        <form id="reset-form" method="post" class="reset-form">
            <label for="reset-username"><span>*</span> User Name</label>
            <input id="reset-username" name="username">
        </form>
        <?php if ($message): ?>
            <p class="notice"><?= h($message) ?></p>
        <?php endif; ?>
        <?php if ($leakedCode): ?>
            <!-- reset reference: <?= h($leakedCode) ?> -->
        <?php endif; ?>
    </section>
    <?php
    render_footer();
}

function mask_email(string $email): string
{
    [$name, $domain] = array_pad(explode('@', $email, 2), 2, 'jpb.local');
    return substr($name, 0, 1) . str_repeat('*', max(strlen($name) - 1, 1)) . '@' . $domain;
}

function personnel_record(array $user): array
{
    $rank = (string) ($user['mil_rank'] ?? '');
    $rankLong = $rank !== '' ? rank_to_long_title($rank) : 'Service member';

    return [
        'Service Number' => (string) ($user['service_number'] ?? ''),
        'Rank' => $rankLong,
        'Regiment/Corps' => 'Royal Logistic Corps',
        'Unit' => '3rd Battalion Training Group',
        'Parent Formation' => '1st (UK) Division',
        'Station' => 'Catterick Garrison',
        'Trade' => 'Unit personnel (self service record)',
        'Security Clearance' => 'SC - Expires 18 Aug 2028',
        'Name' => $user['display_name'],
        'Email' => $user['email'],
    ];
}

function home_page(): void
{
    $items = active_worklist_items();

    render_app_page('Home', function () use ($items): void {
        ?>
        <section class="worklist">
            <h2>Worklist</h2>
            <p>Worklist Tip <span class="info-dot">i</span></p>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr><th>Action</th><th>From</th><th>Type</th><th>Subject</th><th>Sent Due</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><a href="<?= page_url($item['page']) ?>"><?= h($item['action']) ?></a></td>
                                <td><?= h($item['from']) ?></td>
                                <td><?= h($item['type']) ?></td>
                                <td><?= h($item['subject']) ?></td>
                                <td><?= h($item['due']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$items): ?>
                            <tr><td colspan="5">No worklist notifications remain.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php
    });
}

function expenses_section_page(): void
{
    render_app_page('JPB Expenses', function (): void {
        ?>
        <section class="content-card">
            <h1>JPB Expenses</h1>
            <p>Submit, review and track duty travel, subsistence and mileage claims.</p>
            <div class="action-strip">
                <a class="box-button" href="<?= page_url('expenses') ?>">Expenses Home</a>
                <a class="box-button" href="<?= page_url('expense-claim') ?>">Enter Expense Claim</a>
            </div>
        </section>
        <?php
    });
}

function expenses_page(): void
{
    render_app_page('Expenses Home', function (): void {
        $user = require_login();
        $stmt = app_db()->prepare('SELECT reference, claim_type, amount, status, approver FROM expense_claims WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([(int) $user['id']]);
        $claims = $stmt->fetchAll();
        ?>
        <section class="content-card">
            <h1>Expenses Home</h1>
            <table class="report-table">
                <thead><tr><th>Claim Ref</th><th>Type</th><th>Amount</th><th>Status</th><th>Approver</th></tr></thead>
                <tbody>
                    <?php foreach ($claims as $c): ?>
                        <tr>
                            <td><?= h($c['reference']) ?></td>
                            <td><?= h($c['claim_type']) ?></td>
                            <td><?= h($c['amount']) ?></td>
                            <td><?= h($c['status']) ?></td>
                            <td><?= h($c['approver']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$claims): ?>
                        <tr><td colspan="5">No expense claims on file for this account.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <p><a class="box-button" href="<?= page_url('expense-claim') ?>">Create New Expense Claim</a></p>
        </section>
        <?php
    });
}

function expense_claim_page(): void
{
    $submitted = $_SERVER['REQUEST_METHOD'] === 'POST';
    render_app_page('Enter Expense Claim', function () use ($submitted): void {
        ?>
        <section class="content-card">
            <h1>Enter Expense Claim</h1>
            <?php if ($submitted): ?>
                <p class="notice">Expense claim saved as draft and routed to the Regimental Administrative Officer.</p>
            <?php endif; ?>
            <form class="data-form" method="post">
                <label for="claim-type">Claim Type</label>
                <select id="claim-type" name="claim_type">
                    <option>Duty Travel - Rail</option>
                    <option>Subsistence</option>
                    <option>Private Vehicle Mileage</option>
                    <option>Accommodation</option>
                </select>
                <label for="duty-location">Duty Location</label>
                <input id="duty-location" name="duty_location" value="Catterick Garrison">
                <label for="amount">Amount</label>
                <input id="amount" name="amount" value="£0.00">
                <label for="cost-centre">Cost Centre</label>
                <input id="cost-centre" name="cost_centre" value="RLC-3BTG-OPS">
                <label for="reason">Reason for Claim</label>
                <textarea id="reason" name="reason">Travel in support of battalion training serial.</textarea>
                <div></div>
                <button type="submit">Save Claim</button>
            </form>
        </section>
        <?php
    });
}

function self_service_page(): void
{
    render_app_page('JPB Self Service', function (): void {
        ?>
        <section class="content-card">
            <h1>JPB Self Service</h1>
            <p>Access personnel information, time, pay, health readiness and skills records.</p>
            <div class="action-strip">
                <a class="box-button" href="<?= page_url('profile') ?>">My Information</a>
                <a class="box-button" href="<?= page_url('time') ?>">My Time</a>
                <a class="box-button" href="<?= page_url('reports') ?>">My Money</a>
                <a class="box-button" href="<?= page_url('health') ?>">My Health</a>
                <a class="box-button" href="<?= page_url('skills') ?>">My Skills</a>
            </div>
        </section>
        <?php
    });
}

function profile_page(): void
{
    render_app_page('My Information', function (): void {
        $user = require_login();
        ?>
        <section class="content-card">
            <h1>My Information</h1>
            <dl class="details">
                <?php foreach (personnel_record($user) as $label => $value): ?>
                    <dt><?= h($label) ?></dt><dd><?= h($value) ?></dd>
                <?php endforeach; ?>
            </dl>
        </section>
        <?php
    });
}

function personal_info_page(): void
{
    render_app_page('Personal Information', function (): void {
        $user = require_login();
        $record = personnel_record($user);
        ?>
        <section class="content-card">
            <h1>Personal Information</h1>
            <dl class="details">
                <dt>Full Name</dt><dd><?= h($user['display_name']) ?></dd>
                <dt>Service Number</dt><dd><?= h($record['Service Number']) ?></dd>
                <dt>Rank</dt><dd><?= h($record['Rank']) ?></dd>
                <dt>Unit</dt><dd><?= h($record['Unit']) ?></dd>
                <dt>Station</dt><dd><?= h($record['Station']) ?></dd>
                <dt>Trade</dt><dd><?= h($record['Trade']) ?></dd>
                <dt>Email</dt><dd><?= h($user['email']) ?></dd>
                <dt>Primary Phone</dt><dd><?= h(sprintf('07700 %07d', jpb_seed_int((int) $user['id'], 77, 1000000, 9999999))) ?></dd>
                <dt>Home Address</dt><dd>14 Bracken Close, Richmond, North Yorkshire, DL10 4TX</dd>
                <dt>Marital Status</dt><dd>Married</dd>
            </dl>
        </section>
        <?php
    });
}

function miscellaneous_details_page(): void
{
    render_app_page('Miscellaneous Personal Details', function (): void {
        $user = require_login();
        $parts = preg_split('/\s+/', trim((string) $user['display_name']));
        $surname = $parts !== [] ? (string) end($parts) : 'Kin';
        $spouseFirst = ['Emily', 'James', 'Sarah', 'David', 'Priya'][jpb_seed_int((int) $user['id'], 71, 0, 4)];
        ?>
        <section class="content-card">
            <h1>Miscellaneous Personal Details</h1>
            <h2>Next of Kin</h2>
            <table class="report-table">
                <thead><tr><th>Name</th><th>Relationship</th><th>Primary Number</th><th>Secondary Number</th><th>Address</th></tr></thead>
                <tbody>
                    <tr>
                        <td><?= h($spouseFirst . ' ' . $surname) ?></td>
                        <td>Spouse</td>
                        <td><?= h(sprintf('07700 %06d', jpb_seed_int((int) $user['id'], 72, 100000, 999999))) ?></td>
                        <td><?= h(sprintf('01677 %06d', jpb_seed_int((int) $user['id'], 73, 100000, 999999))) ?></td>
                        <td>Recorded family address on file</td>
                    </tr>
                    <tr>
                        <td><?= h('Alex ' . $surname) ?></td>
                        <td>Sibling</td>
                        <td><?= h(sprintf('07701 %06d', jpb_seed_int((int) $user['id'], 74, 100000, 999999))) ?></td>
                        <td>—</td>
                        <td>UK</td>
                    </tr>
                </tbody>
            </table>
            <h2>Emergency Contacts</h2>
            <table class="report-table">
                <thead><tr><th>Contact</th><th>Relationship</th><th>Number</th><th>Notes</th></tr></thead>
                <tbody>
                    <tr><td>Unit Orderly Room</td><td>Unit duty contact</td><td>94560 7712</td><td>Out of hours personnel admin</td></tr>
                    <tr><td>RAO Section</td><td>Pay and allowances</td><td>94560 7744</td><td>Escalation for pay queries</td></tr>
                </tbody>
            </table>
        </section>
        <?php
    });
}

function service_summary_page(): void
{
    render_app_page('Personal and Service Details Summary', function (): void {
        $user = require_login();
        ?>
        <section class="content-card">
            <h1>Personal and Service Details Summary</h1>
            <dl class="details">
                <?php foreach (personnel_record($user) as $label => $value): ?>
                    <dt><?= h($label) ?></dt><dd><?= h($value) ?></dd>
                <?php endforeach; ?>
                <dt>Assignment Start</dt><dd>01-Apr-2024</dd>
                <dt>Current Posting End Date</dt><dd>31-Mar-2027</dd>
            </dl>
        </section>
        <?php
    });
}

function information_views_page(): void
{
    render_app_page('My Information Views', function (): void {
        $user = require_login();
        $stmt = app_db()->prepare('SELECT effective_date, rank_label, salary_band, location, role_title FROM career_rows WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([(int) $user['id']]);
        $rows = $stmt->fetchAll();
        ?>
        <section class="content-card">
            <h1>My Information Views</h1>
            <p>Career, pay and posting view for the current service record.</p>
            <table class="report-table">
                <thead><tr><th>Effective Date</th><th>Rank</th><th>Salary Band</th><th>Location</th><th>Unit/Role</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= h($r['effective_date']) ?></td>
                            <td><?= h($r['rank_label']) ?></td>
                            <td><?= h($r['salary_band']) ?></td>
                            <td><?= h($r['location']) ?></td>
                            <td><?= h($r['role_title']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="5">No career history rows are stored for this account.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
        <?php
    });
}

function time_page(): void
{
    $taken = leave_taken_days();
    $remaining = max(ANNUAL_LEAVE_DAYS - $taken, 0);

    render_app_page('My Time', function () use ($taken, $remaining): void {
        ?>
        <section class="content-card">
            <h1>My Time</h1>
            <table class="report-table">
                <thead><tr><th>Period</th><th>Entry</th><th>Balance</th><th>Status</th></tr></thead>
                <tbody>
                    <tr><td>2026 Leave Year</td><td>Annual Leave</td><td><?= h((string) $remaining) ?> days remaining</td><td><?= h((string) $taken) ?> days booked</td></tr>
                    <tr><td>08-May-2026</td><td>Range support duty</td><td>1 TOIL day accrued</td><td>Approved</td></tr>
                    <tr><td>20-Jun-2026</td><td>Adventure training leave</td><td>5 days requested</td><td>Pending OC approval</td></tr>
                </tbody>
            </table>
            <div class="action-strip">
                <a class="box-button" href="<?= page_url('leave-absence') ?>">Book Leave</a>
                <a class="box-button" href="<?= page_url('cancel-leave') ?>">Cancel Leave</a>
                <a class="box-button" href="<?= page_url('absence-balances') ?>">Absence Balances</a>
            </div>
        </section>
        <?php
    });
}

function leave_absence_page(): void
{
    $error = '';
    $defaults = [
        'leave_type' => 'Annual Leave',
        'start_date' => date('Y-m-d', strtotime('+14 days')),
        'end_date' => date('Y-m-d', strtotime('+18 days')),
        'leave_location' => 'Home address',
        'notes' => '',
    ];
    $values = $defaults;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $values = [
            'leave_type' => trim((string) ($_POST['leave_type'] ?? 'Annual Leave')),
            'start_date' => trim((string) ($_POST['start_date'] ?? '')),
            'end_date' => trim((string) ($_POST['end_date'] ?? '')),
            'leave_location' => trim((string) ($_POST['leave_location'] ?? '')),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
        ];
        $days = leave_days($values['start_date'], $values['end_date']);
        $remaining = ANNUAL_LEAVE_DAYS - leave_taken_days();

        if ($days < 1) {
            $error = 'Enter a valid start and end date.';
        } elseif ($days > $remaining) {
            $error = 'This request exceeds the remaining annual leave balance.';
        } elseif ($values['leave_location'] === '') {
            $error = 'Enter a leave location.';
        } else {
            add_leave_booking($values);
            header('Location: ' . page_url('leave-absence') . '&booked=1');
            exit;
        }
    }

    $booked = ($_GET['booked'] ?? '') === '1';
    $remaining = max(ANNUAL_LEAVE_DAYS - leave_taken_days(), 0);

    render_app_page('Leave of Absence', function () use ($error, $values, $booked, $remaining): void {
        ?>
        <section class="content-card">
            <h1>Leave of Absence</h1>
            <p>Book annual leave or another authorised absence. Requests are stored for this session and reflected in absence balances.</p>
            <?php if ($booked): ?>
                <p class="notice">Leave request submitted and added to your booked absence list.</p>
            <?php endif; ?>
            <?php if ($error): ?>
                <p class="error"><?= h($error) ?></p>
            <?php endif; ?>
            <p><strong>Days Remaining:</strong> <?= h((string) $remaining) ?></p>
            <form class="data-form" method="post">
                <label for="leave-type">Leave Type</label>
                <select id="leave-type" name="leave_type">
                    <?php foreach (['Annual Leave', 'Compassionate Leave', 'Special Leave', 'Resettlement Leave'] as $type): ?>
                        <option <?= $values['leave_type'] === $type ? 'selected' : '' ?>><?= h($type) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="start-date">Start Date</label>
                <input id="start-date" name="start_date" type="date" value="<?= h($values['start_date']) ?>">
                <label for="end-date">End Date</label>
                <input id="end-date" name="end_date" type="date" value="<?= h($values['end_date']) ?>">
                <label for="leave-location">Leave Location</label>
                <input id="leave-location" name="leave_location" value="<?= h($values['leave_location']) ?>" placeholder="Home address or destination">
                <label for="leave-notes">Notes</label>
                <textarea id="leave-notes" name="notes" placeholder="Travel, contact or duty handover notes"><?= h($values['notes']) ?></textarea>
                <div></div>
                <button type="submit">Submit Leave Request</button>
            </form>
        </section>
        <?php
    });
}

function cancel_leave_page(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['leave_id'])) {
        cancel_leave_booking((string) $_POST['leave_id']);
        header('Location: ' . page_url('cancel-leave') . '&cancelled=1');
        exit;
    }

    $bookings = active_leave_bookings();
    $cancelled = ($_GET['cancelled'] ?? '') === '1';

    render_app_page('Cancel Leave of Absence', function () use ($bookings, $cancelled): void {
        ?>
        <section class="content-card">
            <h1>Cancel Leave of Absence</h1>
            <p>Select a booked absence and cancel it. Cancelled leave is returned to the annual balance.</p>
            <?php if ($cancelled): ?>
                <p class="notice">Leave booking cancelled and balance updated.</p>
            <?php endif; ?>
            <div class="table-scroll">
                <table class="report-table">
                    <thead><tr><th>Type</th><th>Start</th><th>End</th><th>Days</th><th>Location</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td><?= h($booking['type']) ?></td>
                                <td><?= h($booking['start_date']) ?></td>
                                <td><?= h($booking['end_date']) ?></td>
                                <td><?= h((string) $booking['days']) ?></td>
                                <td><?= h($booking['location']) ?></td>
                                <td>
                                    <form method="post">
                                        <input type="hidden" name="leave_id" value="<?= h($booking['id']) ?>">
                                        <button type="submit">Cancel</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$bookings): ?>
                            <tr><td colspan="6">No active leave bookings to cancel.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php
    });
}

function absence_balances_page(): void
{
    $bookings = active_leave_bookings();
    $taken = leave_taken_days();
    $remaining = max(ANNUAL_LEAVE_DAYS - $taken, 0);

    render_app_page('Absence Balances', function () use ($bookings, $taken, $remaining): void {
        ?>
        <section class="content-card">
            <h1>Absence Balances</h1>
            <dl class="details balance-summary">
                <dt>Annual Entitlement</dt><dd><?= h((string) ANNUAL_LEAVE_DAYS) ?> days</dd>
                <dt>Days Remaining</dt><dd><?= h((string) $remaining) ?></dd>
                <dt>Leave Taken</dt><dd><?= h((string) $taken) ?></dd>
            </dl>
            <h2>Booked Leave</h2>
            <div class="table-scroll">
                <table class="report-table">
                    <thead><tr><th>Type</th><th>Start</th><th>End</th><th>Days</th><th>Location</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td><?= h($booking['type']) ?></td>
                                <td><?= h($booking['start_date']) ?></td>
                                <td><?= h($booking['end_date']) ?></td>
                                <td><?= h((string) $booking['days']) ?></td>
                                <td><?= h($booking['location']) ?></td>
                                <td><?= h($booking['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$bookings): ?>
                            <tr><td colspan="6">No leave has been booked yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="action-strip">
                <a class="box-button" href="<?= page_url('leave-absence') ?>">Book Leave</a>
                <a class="box-button" href="<?= page_url('cancel-leave') ?>">Cancel Leave</a>
            </div>
        </section>
        <?php
    });
}

function reports_page(): void
{
    $user = require_login();
    $query = $_GET['q'] ?? '';
    $rows = [];
    $error = '';

    try {
        // Deliberate vulnerability: exec() supports stacked statements for ATTACH DATABASE exploit
        app_db()->exec("SELECT period, gross_pay, net_pay, status, created_at
                        FROM payslips
                        WHERE user_id = {$user['id']} AND period LIKE '%$query%'
                        ORDER BY created_at DESC");
        
        // Re-fetch safely with parameterized query for display
        $safe = app_db()->prepare("SELECT period, gross_pay, net_pay, status, created_at
                                   FROM payslips
                                   WHERE user_id = ? AND period LIKE ?
                                   ORDER BY created_at DESC");
        $safe->execute([(int) $user['id'], '%' . $query . '%']);
        $rows = $safe->fetchAll();
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }

    render_app_page('My Money', function () use ($query, $rows, $error): void {
        ?>
        <section class="content-card">
            <h1>My Money</h1>
            <p>Search published payslips and money reports.</p>
            <form class="search-form" method="get">
                <input type="hidden" name="page" value="reports">
                <label for="q">Period</label>
                <input id="q" name="q" value="<?= h($query) ?>" placeholder="April 2026">
                <button type="submit">Go</button>
            </form>
            <?php if ($error): ?>
                <p class="error">SQL error: <?= h($error) ?></p>
            <?php endif; ?>
            <table class="report-table">
                <thead>
                    <tr><th>Period</th><th>Gross Pay</th><th>Net Pay</th><th>Status</th><th>Created</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= h($row['period']) ?></td>
                            <td><?= h($row['gross_pay']) ?></td>
                            <td><?= h($row['net_pay']) ?></td>
                            <td><?= h($row['status']) ?></td>
                            <td><?= h($row['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows && !$error): ?>
                        <tr><td colspan="5">No matching reports were found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
        <?php
    });
}

function payslip_page(string $title = 'Payslip'): void
{
    $user = require_login();
    $stmt = app_db()->prepare('SELECT period, gross_pay, net_pay, status, created_at, basic_pay, specialist_pay, allowance_fit, paye, ni, pension
        FROM payslips WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([(int) $user['id']]);
    $p = $stmt->fetch() ?: [];
    $payslip = [
        'period' => (string) ($p['period'] ?? 'April 2026'),
        'gross_pay' => (string) ($p['gross_pay'] ?? '£0.00'),
        'net_pay' => (string) ($p['net_pay'] ?? '£0.00'),
        'status' => (string) ($p['status'] ?? 'Published'),
        'created_at' => (string) ($p['created_at'] ?? date('Y-m-d')),
        'basic_pay' => (string) ($p['basic_pay'] ?? ''),
        'specialist_pay' => (string) ($p['specialist_pay'] ?? '£0.00'),
        'allowance_fit' => (string) ($p['allowance_fit'] ?? '£0.00'),
        'paye' => (string) ($p['paye'] ?? '£0.00'),
        'ni' => (string) ($p['ni'] ?? '£0.00'),
        'pension' => (string) ($p['pension'] ?? '£0.00'),
    ];
    if ($payslip['basic_pay'] === '') {
        $payslip['basic_pay'] = $payslip['gross_pay'];
    }

    $acctSuffix = $_SESSION['bank_account_suffix'] ?? '2482';

    render_app_page($title, function () use ($title, $user, $payslip, $acctSuffix): void {
        ?>
        <section class="content-card payslip-card">
            <h1><?= h($title) ?></h1>
            <p>This is the published payslip currently available for display.</p>
            <dl class="details">
                <dt>Name</dt><dd><?= h($user['display_name']) ?></dd>
                <dt>Employee Number</dt><dd><?= h($user['employee_no']) ?></dd>
                <dt>Pay Period</dt><dd><?= h($payslip['period']) ?></dd>
                <dt>Status</dt><dd><?= h($payslip['status']) ?></dd>
                <dt>Published</dt><dd><?= h($payslip['created_at']) ?></dd>
            </dl>
            <div class="payslip-columns">
                <table class="report-table">
                    <thead><tr><th colspan="2">Earnings</th></tr></thead>
                    <tbody>
                        <tr><td>Basic Pay</td><td><?= h($payslip['basic_pay']) ?></td></tr>
                        <tr><td>Specialist Pay</td><td><?= h($payslip['specialist_pay']) ?></td></tr>
                        <tr><td>Allowance - Food and Incidentals</td><td><?= h($payslip['allowance_fit']) ?></td></tr>
                        <tr><th>Gross Pay</th><th><?= h($payslip['gross_pay']) ?></th></tr>
                    </tbody>
                </table>
                <table class="report-table">
                    <thead><tr><th colspan="2">Deductions</th></tr></thead>
                    <tbody>
                        <tr><td>PAYE Tax</td><td><?= h($payslip['paye']) ?></td></tr>
                        <tr><td>National Insurance</td><td><?= h($payslip['ni']) ?></td></tr>
                        <tr><td>Pension Contribution</td><td><?= h($payslip['pension']) ?></td></tr>
                        <tr><th>Net Payment</th><th><?= h($payslip['net_pay']) ?></th></tr>
                    </tbody>
                </table>
            </div>
            <p><strong>Payment Method:</strong> BACS to account ending <?= h($acctSuffix) ?></p>
        </section>
        <?php
    });
}

function bank_account_page(): void
{
    $saved = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        update_bank_details($_POST);
        $saved = true;
    }

    $details = bank_details();

    render_app_page('Bank Account Information', function () use ($details, $saved): void {
        ?>
        <section class="content-card">
            <h1>Bank Account Information</h1>
            <p>Training data only. These made-up bank details are editable for this session.</p>
            <?php if ($saved): ?>
                <p class="notice">Bank account information saved.</p>
            <?php endif; ?>
            <form class="data-form" method="post">
                <label for="account-name">Account Name</label>
                <input id="account-name" name="account_name" value="<?= h($details['account_name']) ?>">
                <label for="bank-name">Bank Name</label>
                <input id="bank-name" name="bank_name" value="<?= h($details['bank_name']) ?>">
                <label for="sort-code">Sort Code</label>
                <input id="sort-code" name="sort_code" value="<?= h($details['sort_code']) ?>" inputmode="numeric">
                <label for="account-number">Account Number</label>
                <input id="account-number" name="account_number" value="<?= h($details['account_number']) ?>" inputmode="numeric">
                <label for="roll-number">Roll Number</label>
                <input id="roll-number" name="roll_number" value="<?= h($details['roll_number']) ?>">
                <div></div>
                <button type="submit">Save Bank Details</button>
            </form>
        </section>
        <?php
    });
}

function health_page(): void
{
    render_app_page('My Health', function (): void {
        ?>
        <section class="content-card">
            <h1>My Health</h1>
            <table class="report-table">
                <thead><tr><th>Readiness Area</th><th>Result</th><th>Review Date</th><th>Notes</th></tr></thead>
                <tbody>
                    <tr><td>Medical Employment Standard</td><td>MFD</td><td>14-Oct-2026</td><td>Fit for full duties</td></tr>
                    <tr><td>Hearing Conservation</td><td>H2</td><td>20-May-2026</td><td>Annual audiometry due</td></tr>
                    <tr><td>Dental Fitness</td><td>Class 1</td><td>03-Feb-2027</td><td>No restrictions</td></tr>
                    <tr><td>Vaccinations</td><td>In date</td><td>11-Nov-2026</td><td>Tetanus, Hep A, Typhoid recorded</td></tr>
                </tbody>
            </table>
        </section>
        <?php
    });
}

function dental_details_page(): void
{
    render_app_page('Dental Details', function (): void {
        ?>
        <section class="content-card">
            <h1>Dental Details</h1>
            <dl class="details">
                <dt>Dental Fitness Class</dt><dd>Class 1 - Dentally Fit</dd>
                <dt>Dental Centre</dt><dd>Catterick Garrison Dental Centre</dd>
                <dt>Last Inspection</dt><dd>03-Feb-2026</dd>
                <dt>Next Routine Inspection</dt><dd>03-Feb-2027</dd>
                <dt>Treatment Required</dt><dd>No active treatment required</dd>
            </dl>
            <table class="report-table">
                <thead><tr><th>Date</th><th>Appointment</th><th>Outcome</th><th>Clinician</th></tr></thead>
                <tbody>
                    <tr><td>03-Feb-2026</td><td>Routine dental inspection</td><td>Class 1 confirmed</td><td>Maj Patel</td></tr>
                    <tr><td>19-Aug-2025</td><td>Scale and polish</td><td>Completed</td><td>Cpl Reeves</td></tr>
                </tbody>
            </table>
        </section>
        <?php
    });
}

function medical_details_page(): void
{
    render_app_page('Medical Details', function (): void {
        ?>
        <section class="content-card">
            <h1>Medical Details</h1>
            <dl class="details">
                <dt>Medical Employment Standard</dt><dd>MFD - Medically Fully Deployable</dd>
                <dt>Medical Centre</dt><dd>Catterick Medical Reception Station</dd>
                <dt>Last PULHHEEMS Review</dt><dd>14-Oct-2025</dd>
                <dt>Next Review Due</dt><dd>14-Oct-2026</dd>
                <dt>Restrictions</dt><dd>No restrictions recorded</dd>
            </dl>
            <table class="report-table">
                <thead><tr><th>Readiness Item</th><th>Status</th><th>Review Date</th><th>Notes</th></tr></thead>
                <tbody>
                    <tr><td>Hearing Conservation</td><td>H2</td><td>20-May-2026</td><td>Annual audiometry due</td></tr>
                    <tr><td>Vaccinations</td><td>In date</td><td>11-Nov-2026</td><td>Tetanus, Hep A and Typhoid recorded</td></tr>
                    <tr><td>Fitness to Deploy</td><td>Green</td><td>14-Oct-2026</td><td>No limiting medical factors</td></tr>
                </tbody>
            </table>
        </section>
        <?php
    });
}

function skills_page(): void
{
    render_app_page('My Skills', function (): void {
        ?>
        <section class="content-card">
            <h1>My Skills</h1>
            <table class="report-table">
                <thead><tr><th>Skill</th><th>Level</th><th>Authority</th><th>Expiry</th></tr></thead>
                <tbody>
                    <tr><td>SA80 A3 Weapon Handling Test</td><td>Pass</td><td>Unit Training Wing</td><td>30-Sep-2026</td></tr>
                    <tr><td>Defence Train the Trainer</td><td>Practitioner</td><td>Army Education Centre</td><td>Current</td></tr>
                    <tr><td>Battlefield Casualty Drills</td><td>Annual Refresher</td><td>Medical Centre</td><td>18-Jan-2027</td></tr>
                    <tr><td>Driver Wheeled Platform</td><td>B, C1, Land Rover</td><td>MT Section</td><td>Current</td></tr>
                    <tr><td>Information Management</td><td>Unit Administrator</td><td>Brigade G6</td><td>Current</td></tr>
                </tbody>
            </table>
        </section>
        <?php
    });
}

function competencies_page(): void
{
    render_app_page('Competencies', function (): void {
        ?>
        <section class="content-card">
            <h1>Competencies</h1>
            <table class="report-table">
                <thead><tr><th>Qualification</th><th>Level</th><th>Awarding Authority</th><th>Issued</th><th>Expiry</th><th>Status</th></tr></thead>
                <tbody>
                    <tr><td>SA80 A3 Weapon Handling Test</td><td>Pass</td><td>Unit Training Wing</td><td>30-Sep-2025</td><td>30-Sep-2026</td><td>Current</td></tr>
                    <tr><td>Defence Train the Trainer</td><td>Practitioner</td><td>Army Education Centre</td><td>12-Mar-2023</td><td>Current</td><td>Current</td></tr>
                    <tr><td>Battlefield Casualty Drills</td><td>Annual Refresher</td><td>Medical Centre</td><td>18-Jan-2026</td><td>18-Jan-2027</td><td>Current</td></tr>
                    <tr><td>Driver Wheeled Platform</td><td>B, C1, Land Rover</td><td>MT Section</td><td>04-Jul-2022</td><td>Current</td><td>Current</td></tr>
                    <tr><td>Information Management</td><td>Unit Administrator</td><td>Brigade G6</td><td>07-Nov-2024</td><td>07-Nov-2027</td><td>Current</td></tr>
                    <tr><td>Data Protection for HR Staff</td><td>Mandatory</td><td>Defence Academy</td><td>02-May-2026</td><td>02-May-2027</td><td>Current</td></tr>
                </tbody>
            </table>
        </section>
        <?php
    });
}

function exiting_service_page(): void
{
    $submitted = false;
    $choice = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $choice = (string) ($_POST['exit_action'] ?? '');
        $_SESSION['exit_service_action'] = $choice;
        $_SESSION['exit_service_notes'] = trim((string) ($_POST['exit_notes'] ?? ''));
        $submitted = true;
    } else {
        $choice = (string) ($_SESSION['exit_service_action'] ?? '');
    }

    render_app_page('Exiting the Service', function () use ($submitted, $choice): void {
        ?>
        <section class="content-card">
            <h1>Exiting the Service</h1>
            <p>Record the selected exit workflow and mark the account for local clearance processing.</p>
            <?php if ($submitted): ?>
                <p class="notice">Exit service workflow saved: <?= h($choice) ?>.</p>
            <?php endif; ?>
            <form class="data-form" method="post">
                <label for="exit-action">Exit Action</label>
                <select id="exit-action" name="exit_action">
                    <?php foreach (['Sign off and switch off', 'Bug out to civvi street'] as $option): ?>
                        <option <?= $choice === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="exit-date">Target Exit Date</label>
                <input id="exit-date" name="exit_date" type="date" value="2026-09-30">
                <label for="exit-notes">Clearance Notes</label>
                <textarea id="exit-notes" name="exit_notes">Return ID card, close locker issue, hand over HR duties and complete resettlement interview.</textarea>
                <div></div>
                <button type="submit">Submit Exit Request</button>
            </form>
        </section>
        <?php
    });
}

function notifications_page(): void
{
    handle_worklist_post('notifications');
    $items = active_worklist_items();

    render_app_page('Worklist Notifications', function () use ($items): void {
        ?>
        <section class="content-card">
            <h1>Worklist Notifications</h1>
            <p>Tick worklist notifications and press clear to remove them from the home worklist.</p>
            <form method="post">
                <input type="hidden" name="worklist_action" value="clear">
                <div class="table-scroll">
                    <table class="report-table">
                        <thead><tr><th>Clear</th><th>From</th><th>Type</th><th>Subject</th><th>Sent Due</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><input type="checkbox" name="notification_ids[]" value="<?= h($item['id']) ?>" aria-label="Clear <?= h($item['subject']) ?>"></td>
                                    <td><?= h($item['from']) ?></td>
                                    <td><?= h($item['type']) ?></td>
                                    <td><?= h($item['subject']) ?></td>
                                    <td><?= h($item['due']) ?></td>
                                    <td><a href="<?= page_url($item['page']) ?>"><?= h($item['action']) ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$items): ?>
                                <tr><td colspan="6">No active worklist notifications.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p><button type="submit" <?= !$items ? 'disabled' : '' ?>>Clear Selected</button></p>
            </form>
        </section>
        <?php
    });
}

function simple_content_page(string $title, string $body, array $links = []): void
{
    render_app_page($title, function () use ($title, $body, $links): void {
        ?>
        <section class="content-card">
            <h1><?= h($title) ?></h1>
            <p><?= h($body) ?></p>
            <?php if ($links): ?>
                <div class="action-strip">
                    <?php foreach ($links as $label => $page): ?>
                        <a class="box-button" href="<?= page_url($page) ?>"><?= h($label) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    });
}

function about_page(): void
{
    render_header('About this Page', current_user() !== null);
    ?>
    <section class="content-card">
        <h1>About this Page</h1>
        <p>This is a websters website much like the real thing. No actually this is much better than the real thing</p>
    </section>
    <?php
    render_footer();
}

$page = $_GET['page'] ?? 'login';

if ($page === 'logout') {
    session_destroy();
    header('Location: ' . page_url('login'));
    exit;
}

match ($page) {
    'login' => login_page(),
    'reset' => reset_page(),
    'home' => home_page(),
    'expenses-section' => expenses_section_page(),
    'expenses' => expenses_page(),
    'expense-claim' => expense_claim_page(),
    'self-service' => self_service_page(),
    'profile' => profile_page(),
    'personal-info' => personal_info_page(),
    'misc-details' => miscellaneous_details_page(),
    'service-summary' => service_summary_page(),
    'information-views' => information_views_page(),
    'time' => time_page(),
    'leave-absence' => leave_absence_page(),
    'cancel-leave' => cancel_leave_page(),
    'absence-balances' => absence_balances_page(),
    'reports' => reports_page(),
    'payslip' => payslip_page('Payslip'),
    'bank-account' => bank_account_page(),
    'health' => health_page(),
    'dental-details' => dental_details_page(),
    'medical-details' => medical_details_page(),
    'skills' => skills_page(),
    'competencies' => competencies_page(),
    'exiting-service' => exiting_service_page(),
    'notifications' => notifications_page(),
    'favorites' => simple_content_page('Favorites', 'Quick links saved for this user.', ['Expenses Home' => 'expenses', 'My Money' => 'reports', 'My Health' => 'health']),
    'settings' => simple_content_page('Settings', 'Personal display settings and notification preferences.', ['Worklist Notifications' => 'notifications']),
    'help' => simple_content_page('Help', 'Contact the JPB Support Desk for account, access and payroll queries.', ['Support User Guide' => 'support']),
    'process-guide' => simple_content_page('JPB Process Guide', 'Guidance for expenses, self service records and unit administration workflows.', ['JPB Expenses' => 'expenses-section', 'JPB Self Service' => 'self-service']),
    'self-service-guide' => simple_content_page('Self Service User Guide', 'Use self service to maintain personal records, review time, money, health and skills.', ['My Information' => 'profile', 'My Skills' => 'skills']),
    'expenses-guide' => simple_content_page('Expenses User Guide', 'Create claims for duty travel, subsistence, accommodation and mileage.', ['Enter Expense Claim' => 'expense-claim']),
    'support' => simple_content_page('Support User Guide', 'Support desk hours are 0800-1700 Monday to Friday. Quote your service number and unit when raising a ticket.', ['Notifications' => 'notifications']),
    'about' => about_page(),
    default => home_page(),
};
