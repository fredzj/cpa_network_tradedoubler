<?php
declare(strict_types=1);

/**
 * Tradedoubler -> MySQL sync, intended to run as a cron job on Vimexx (DirectAdmin) hosting.
 *
 * Saves, per site (source):
 *   - account, sources, programs, payments, earnings      (Publisher API, OAuth2)
 *   - daily statistics per program                         (Publisher API)
 *   - transactions = conversions (sales/leads)             (Publisher API)
 *   - claims                                               (Claims API, token)
 *   - vouchers                                             (Vouchers API, token)
 *   - product feeds + products                             (Products API, token)
 *   - conversion postback subscriptions                    (Conversions API, token)
 *
 * Usage:  php sync.php [--force] [--only=transactions,claims] [--backfill-days=N]
 * Requires PHP >= 8.1 with curl + pdo_mysql.
 */

const TD_CONNECT = 'https://connect.tradedoubler.com';
const TD_LEGACY  = 'https://api.tradedoubler.com/1.0';
const TASKS      = ['account', 'programs', 'statistics', 'transactions', 'payments', 'claims', 'vouchers', 'products', 'conversions'];

if (!is_file(__DIR__ . '/config.php')) {
    fwrite(STDERR, "Missing config.php - copy config.example.php to config.php first.\n");
    exit(1);
}
$cfg = require __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    if (empty($cfg['web_key']) || !hash_equals((string)$cfg['web_key'], (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Forbidden\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
    $argv = ['sync.php'];
    if (!empty($_GET['force'])) $argv[] = '--force';
    if (!empty($_GET['only']))  $argv[] = '--only=' . $_GET['only'];
}

date_default_timezone_set($cfg['timezone'] ?? 'Europe/Amsterdam');
set_time_limit(0);
ignore_user_abort(true);

$opts = ['force' => false, 'only' => null, 'backfill' => null];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--force') $opts['force'] = true;
    elseif (str_starts_with($arg, '--only=')) $opts['only'] = array_filter(array_map('trim', explode(',', substr($arg, 7))));
    elseif (str_starts_with($arg, '--backfill-days=')) $opts['backfill'] = (int)substr($arg, 16);
}

Log::$file = $cfg['log_file'] ?? null;
if (Log::$file && !is_dir(dirname(Log::$file))) @mkdir(dirname(Log::$file), 0750, true);

$lock = fopen(__DIR__ . '/sync.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    Log::info('Another sync is still running, exiting.');
    exit(0);
}

try {
    $sync = new Sync($cfg, $opts);
    $ok = $sync->run();
} catch (Throwable $e) {
    Log::info('FATAL: ' . $e->getMessage());
    $ok = false;
}
flock($lock, LOCK_UN);
exit($ok ? 0 : 1);

// ---------------------------------------------------------------------------------------------

final class Log
{
    public static ?string $file = null;

    public static function info(string $msg): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
        echo $line;
        if (self::$file) @file_put_contents(self::$file, $line, FILE_APPEND | LOCK_EX);
    }
}

final class ApiException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message, $status);
    }
}

function maskUrl(string $url): string
{
    return preg_replace('/token=[^&]+/', 'token=***', $url);
}

function j(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** First non-null value of the given keys (the API is not consistent in casing). */
function pick(array $a, string ...$keys): mixed
{
    foreach ($keys as $k) if (isset($a[$k])) return $a[$k];
    return null;
}

/** Normalise API timestamps (strings or epoch milliseconds) to MySQL DATETIME. */
function dt(mixed $v, string $format = 'Y-m-d H:i:s'): ?string
{
    if ($v === null || $v === '') return null;
    try {
        $d = is_numeric($v)
            ? (new DateTimeImmutable('@' . intdiv((int)$v, 1000)))->setTimezone(new DateTimeZone(date_default_timezone_get()))
            : new DateTimeImmutable((string)$v);
        return $d->format($format);
    } catch (Exception) {
        return null;
    }
}

/** Extract the list of records from the various envelopes the APIs use. */
function items(mixed $r, string ...$keys): array
{
    if (!is_array($r)) return [];
    if (array_is_list($r)) {
        return (isset($r[0]) && is_array($r[0]) && isset($r[0]['items']) && is_array($r[0]['items'])) ? $r[0]['items'] : $r;
    }
    foreach (['items', ...$keys] as $k) if (isset($r[$k]) && is_array($r[$k])) return $r[$k];
    return [];
}

/**
 * Read a productsUnlimited export file ({"products":[ one product per line ]}) without loading it all.
 * Falls back to decoding the whole file if the layout is ever different.
 */
function exportProducts(string $path): Generator
{
    $fh = fopen($path, 'rb');
    $lineByLine = true;
    $buffer = [];
    while (($line = fgets($fh)) !== false) {
        $line = rtrim(trim($line), ',');
        if ($line === '' || $line === '{"products":[' || $line === ']}' || $line === ']') continue;
        $p = json_decode($line, true);
        if (!is_array($p)) {
            $lineByLine = false;
            break;
        }
        if (isset($p['products']) && is_array($p['products'])) { // whole export on a single line
            yield from $p['products'];
            continue;
        }
        $buffer[] = $p;
        if (count($buffer) === 500) {
            yield from $buffer;
            $buffer = [];
        }
    }
    fclose($fh);

    if ($lineByLine) {
        yield from $buffer;
        return;
    }
    // Unexpected layout: decode everything (needs memory_limit of roughly 10x the file size).
    // Products already yielded will simply be upserted again.
    yield from items(json_decode((string)file_get_contents($path), true), 'products');
}

function http(string $method, string $url, array $headers, ?string $body = null, int $tries = 5): mixed
{
    for ($attempt = 1; ; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'tradedoubler-sync/1.0',
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);

        $retryable = $resp === false || $status === 429 || $status >= 500;
        if ($retryable && $attempt < $tries) {
            $wait = $status === 429 ? 61 : 10 * $attempt;
            Log::info(sprintf('  %s %s -> %s, retrying in %ds', $method, maskUrl($url), $resp === false ? $err : $status, $wait));
            sleep($wait);
            continue;
        }
        if ($resp === false) throw new RuntimeException("$method " . maskUrl($url) . " failed: $err");
        if ($status >= 400) throw new ApiException($status, "$method " . maskUrl($url) . " -> HTTP $status: " . substr(trim($resp), 0, 300));
        if (trim($resp) === '') return null;

        $data = json_decode($resp, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("$method " . maskUrl($url) . ' returned invalid JSON: ' . substr(trim($resp), 0, 200));
        }
        return $data;
    }
}

/** Publisher API (connect.tradedoubler.com): OAuth2 password grant, 60 requests/minute. */
final class PublisherApi
{
    private ?string $access = null;
    private ?string $refresh = null;
    private int $expires = 0;
    private float $lastCall = 0.0;

    public function __construct(private readonly array $oauth) {}

    public function get(string $path, array $query = []): mixed
    {
        $this->authenticate();
        $this->throttle();
        $url = TD_CONNECT . $path . ($query ? '?' . http_build_query($query) : '');
        return http('GET', $url, [
            'Authorization: Bearer ' . $this->access,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
    }

    /** Iterate all records of an offset/limit paginated endpoint. */
    public function paged(string $path, array $query = [], int $limit = 100): Generator
    {
        $offset = 0;
        do {
            $rows = items($this->get($path, $query + ['offset' => $offset, 'limit' => $limit]));
            yield from $rows;
            $offset += $limit;
        } while (count($rows) === $limit);
    }

    private function authenticate(): void
    {
        if ($this->access && time() < $this->expires - 60) return;

        $fields = $this->refresh
            ? ['grant_type' => 'refresh_token', 'refresh_token' => $this->refresh]
            : ['grant_type' => 'password', 'username' => $this->oauth['username'], 'password' => $this->oauth['password']];
        try {
            $this->throttle();
            $r = http('POST', TD_CONNECT . '/uaa/oauth/token', [
                'Authorization: Basic ' . base64_encode($this->oauth['client_id'] . ':' . $this->oauth['client_secret']),
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ], http_build_query($fields));
        } catch (ApiException $e) {
            if ($this->refresh) { // refresh token rejected: log in again
                $this->refresh = null;
                $this->authenticate();
                return;
            }
            throw new RuntimeException('Publisher API login failed: ' . $e->getMessage());
        }
        $this->access  = $r['access_token'];
        $this->refresh = $r['refresh_token'] ?? null;
        $this->expires = time() + (int)($r['expires_in'] ?? 600);
    }

    private function throttle(): void
    {
        $wait = 1.05 - (microtime(true) - $this->lastCall);
        if ($wait > 0) usleep((int)($wait * 1e6));
        $this->lastCall = microtime(true);
    }
}

/** Legacy token APIs on api.tradedoubler.com (Products, Vouchers, Claims, Conversions). */
final class LegacyApi
{
    private const HEADERS = ['Accept: application/json; charset=utf-8', 'Content-Type: application/json; charset=utf-8'];

    public function get(string $resource, array $matrix, string $token): mixed
    {
        usleep(300000);
        return http('GET', $this->url($resource, $matrix, $token), self::HEADERS);
    }

    /** Stream a (redirected) export file to disk. 5xx/network errors are retried; 4xx (incl. 429 quota) are not. */
    public function download(string $resource, array $matrix, string $token, string $path, int $tries = 3): void
    {
        $url = $this->url($resource, $matrix, $token);
        for ($attempt = 1; ; $attempt++) {
            usleep(300000);
            $fp = fopen($path, 'wb');
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_HTTPHEADER     => self::HEADERS,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT        => 1800,
                CURLOPT_ENCODING       => '',
                CURLOPT_USERAGENT      => 'tradedoubler-sync/1.0',
            ]);
            $ok     = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err    = curl_error($ch);
            fclose($fp);

            if ($ok !== false && $status < 400) return;
            if (($ok === false || $status >= 500) && $attempt < $tries) {
                Log::info(sprintf('  download %s -> %s, retrying in %ds', maskUrl($url), $ok === false ? $err : $status, 10 * $attempt));
                sleep(10 * $attempt);
                continue;
            }
            if ($ok === false) throw new RuntimeException('download ' . maskUrl($url) . " failed: $err");
            throw new ApiException($status, 'download ' . maskUrl($url) . " -> HTTP $status: " . substr(trim((string)file_get_contents($path, false, null, 0, 300)), 0, 300));
        }
    }

    private function url(string $resource, array $matrix, string $token): string
    {
        $m = '';
        foreach ($matrix as $k => $v) {
            if ($v !== null && $v !== '') $m .= ';' . rawurlencode($k) . '=' . rawurlencode((string)$v);
        }
        return TD_LEGACY . '/' . $resource . $m . '?token=' . rawurlencode($token);
    }
}

final class Db
{
    public readonly PDO $pdo;

    public function __construct(array $c, private readonly string $now)
    {
        $this->pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('SET NAMES utf8mb4');
        $this->migrate();
    }

    /** INSERT ... ON DUPLICATE KEY UPDATE in batches; adds synced_at to every row. */
    public function upsert(string $table, array $rows, int $batch = 200): int
    {
        if (!$rows) return 0;
        $cols   = [...array_keys(reset($rows)), 'synced_at'];
        $colSql = implode(',', array_map(fn($c) => "`$c`", $cols));
        $update = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", $cols));
        $tuple  = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';

        foreach (array_chunk($rows, $batch) as $chunk) {
            $params = [];
            foreach ($chunk as $row) array_push($params, ...[...array_values($row), $this->now]);
            $sql = "INSERT INTO `$table` ($colSql) VALUES " . implode(',', array_fill(0, count($chunk), $tuple))
                 . " ON DUPLICATE KEY UPDATE $update";
            $this->pdo->prepare($sql)->execute($params);
        }
        return count($rows);
    }

    public function state(string $task): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM td_sync_state WHERE task = ?');
        $st->execute([$task]);
        return $st->fetch() ?: null;
    }

    public function markState(string $task, bool $success, ?string $error = null, ?string $meta = null): void
    {
        $sql = 'INSERT INTO td_sync_state (task, last_started, last_success, last_error, meta) VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE last_started = VALUES(last_started), last_error = VALUES(last_error),
                last_success = COALESCE(VALUES(last_success), last_success), meta = COALESCE(VALUES(meta), meta)';
        $this->pdo->prepare($sql)->execute([$task, $this->now, $success ? $this->now : null, $error, $meta]);
    }

    private function migrate(): void
    {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $schema = "
CREATE TABLE IF NOT EXISTS td_sync_state (
  task VARCHAR(100) NOT NULL PRIMARY KEY,
  last_started DATETIME NULL, last_success DATETIME NULL, last_error TEXT NULL, meta TEXT NULL
) $t;
CREATE TABLE IF NOT EXISTS td_snapshots (
  name VARCHAR(50) NOT NULL, snapshot_date DATE NOT NULL, data LONGTEXT NOT NULL, synced_at DATETIME NOT NULL,
  PRIMARY KEY (name, snapshot_date)
) $t;
CREATE TABLE IF NOT EXISTS td_sources (
  source_id BIGINT NOT NULL PRIMARY KEY, name VARCHAR(255) NULL, url VARCHAR(500) NULL, status VARCHAR(50) NULL,
  data LONGTEXT NOT NULL, synced_at DATETIME NOT NULL
) $t;
CREATE TABLE IF NOT EXISTS td_programs (
  source_id BIGINT NOT NULL, program_id BIGINT NOT NULL, name VARCHAR(255) NULL, status_id INT NULL,
  data LONGTEXT NOT NULL, synced_at DATETIME NOT NULL,
  PRIMARY KEY (source_id, program_id)
) $t;
CREATE TABLE IF NOT EXISTS td_statistics (
  source_id BIGINT NOT NULL, program_id BIGINT NOT NULL, stat_date DATE NOT NULL,
  clicks INT NULL, leads INT NULL, sales INT NULL, order_value DECIMAL(14,2) NULL, commission DECIMAL(14,2) NULL,
  currency CHAR(3) NULL, data LONGTEXT NOT NULL, synced_at DATETIME NOT NULL,
  PRIMARY KEY (source_id, program_id, stat_date), KEY (stat_date)
) $t;
CREATE TABLE IF NOT EXISTS td_transactions (
  transaction_id BIGINT NOT NULL PRIMARY KEY, source_id BIGINT NULL, program_id BIGINT NULL,
  event_type_id INT NULL, status CHAR(1) NULL, order_number VARCHAR(255) NULL,
  order_value DECIMAL(14,2) NULL, commission DECIMAL(14,2) NULL, currency CHAR(3) NULL, paid TINYINT(1) NULL,
  time_of_transaction DATETIME NULL, time_of_last_modified DATETIME NULL,
  data LONGTEXT NOT NULL, synced_at DATETIME NOT NULL,
  KEY (source_id, time_of_transaction), KEY (program_id)
) $t;
CREATE TABLE IF NOT EXISTS td_payments (
  payment_id BIGINT NOT NULL PRIMARY KEY, payment_date DATETIME NULL, amount DECIMAL(14,2) NULL, currency CHAR(3) NULL,
  data LONGTEXT NOT NULL, synced_at DATETIME NOT NULL
) $t;
CREATE TABLE IF NOT EXISTS td_claims (
  claim_id BIGINT NOT NULL PRIMARY KEY, source_id BIGINT NULL, program_id BIGINT NULL,
  status_id INT NULL, status_name VARCHAR(100) NULL, order_number VARCHAR(255) NULL,
  data LONGTEXT NOT NULL, synced_at DATETIME NOT NULL,
  KEY (source_id)
) $t;
CREATE TABLE IF NOT EXISTS td_vouchers (
  source_id BIGINT NOT NULL, voucher_id BIGINT NOT NULL, program_id BIGINT NULL, code VARCHAR(255) NULL,
  title VARCHAR(500) NULL, start_date DATETIME NULL, end_date DATETIME NULL,
  data LONGTEXT NOT NULL, synced_at DATETIME NOT NULL,
  PRIMARY KEY (source_id, voucher_id), KEY (end_date)
) $t;
CREATE TABLE IF NOT EXISTS td_product_feeds (
  source_id BIGINT NOT NULL, feed_id BIGINT NOT NULL, name VARCHAR(255) NULL, active TINYINT(1) NULL,
  number_of_products INT NULL, data LONGTEXT NOT NULL, synced_at DATETIME NOT NULL,
  PRIMARY KEY (source_id, feed_id), KEY (feed_id)
) $t;
CREATE TABLE IF NOT EXISTS td_feed_downloads (
  feed_id BIGINT NOT NULL PRIMARY KEY, source_id BIGINT NOT NULL, last_modified VARCHAR(50) NULL,
  product_count INT NOT NULL, synced_at DATETIME NOT NULL
) $t;
CREATE TABLE IF NOT EXISTS td_products (
  feed_id BIGINT NOT NULL, product_id VARCHAR(100) NOT NULL,
  name VARCHAR(500) NULL, price DECIMAL(14,2) NULL, data LONGTEXT NOT NULL, synced_at DATETIME NOT NULL,
  PRIMARY KEY (feed_id, product_id), KEY (synced_at)
) $t;
CREATE TABLE IF NOT EXISTS td_conversion_subscriptions (
  source_id BIGINT NOT NULL, subscription_id VARCHAR(100) NOT NULL, name VARCHAR(255) NULL,
  data LONGTEXT NOT NULL, synced_at DATETIME NOT NULL,
  PRIMARY KEY (source_id, subscription_id)
) $t;";
        // Older versions stored products per site; that table is replaced (products are re-downloaded).
        $perSite = $this->pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'td_products' AND COLUMN_NAME = 'source_id'")->fetchColumn();
        if ($perSite) {
            $this->pdo->exec('DROP TABLE td_products');
            Log::info('Migrated td_products to one copy per feed; products will be downloaded again.');
        }
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $sql) $this->pdo->exec($sql);
    }
}

final class Sync
{
    private readonly string $now;
    private readonly DateTimeImmutable $today;
    private readonly Db $db;
    private readonly PublisherApi $pub;
    private readonly LegacyApi $legacy;
    /** @var array<int, array> source_id => source */
    private array $sources = [];
    private ?array $apiTokens = null;

    public function __construct(private readonly array $cfg, private readonly array $opts)
    {
        $this->now    = date('Y-m-d H:i:s');
        $this->today  = new DateTimeImmutable('today');
        $this->db     = new Db($cfg['db'], $this->now);
        $this->pub    = new PublisherApi($cfg['oauth']);
        $this->legacy = new LegacyApi();
    }

    public function run(): bool
    {
        Log::info('Sync started');
        $this->loadSources();

        $ok = true;
        foreach (TASKS as $task) {
            if (!$this->due($task)) continue;
            $started = microtime(true);
            Log::info("Task $task");
            try {
                $this->{'sync' . ucfirst($task)}();
                $this->db->markState($task, true);
                Log::info(sprintf("Task %s done in %.0fs", $task, microtime(true) - $started));
            } catch (Throwable $e) {
                $ok = false;
                $this->db->markState($task, false, $e->getMessage());
                Log::info("Task $task FAILED: " . $e->getMessage());
            }
        }
        Log::info('Sync finished' . ($ok ? '' : ' with errors'));
        return $ok;
    }

    // --- tasks -------------------------------------------------------------------------------

    private function loadSources(): void
    {
        $filter = array_map('intval', $this->cfg['source_ids'] ?? []);
        $rows = [];
        foreach (items($this->pub->get('/publisher/sources')) as $s) {
            $id = (int)$s['id'];
            $rows[] = ['source_id' => $id, 'name' => $s['name'] ?? null, 'url' => $s['url'] ?? null,
                       'status' => $s['sourceStatus'] ?? null, 'data' => j($s)];
            if (!$filter || in_array($id, $filter, true)) $this->sources[$id] = $s;
        }
        $this->db->upsert('td_sources', $rows);
        Log::info(sprintf('Sources: %d stored, %d selected for sync', count($rows), count($this->sources)));
    }

    private function syncAccount(): void
    {
        foreach (['account' => '/publisher/account', 'earnings' => '/publisher/report/payments/earnings'] as $name => $path) {
            $this->db->upsert('td_snapshots', [['name' => $name, 'snapshot_date' => $this->today->format('Y-m-d'), 'data' => j($this->pub->get($path))]]);
        }
    }

    private function syncPrograms(): void
    {
        // The API accepts a single statusId per request, so query each status separately. Empty = all programs.
        $statuses = $this->cfg['program_status_ids'] ?? '3';
        $statuses = array_map('intval', array_filter(array_map('trim', is_array($statuses) ? $statuses : explode(',', (string)$statuses)), 'strlen'));

        foreach ($this->sources as $sid => $source) {
            $rows = [];
            foreach ($statuses ?: [null] as $status) {
                $query = ['sourceId' => $sid] + ($status === null ? [] : ['statusId' => $status]);
                foreach ($this->pub->paged('/publisher/programs', $query) as $p) {
                    $rows[(int)$p['id']] = ['source_id' => $sid, 'program_id' => (int)$p['id'], 'name' => $p['name'] ?? null,
                                            'status_id' => $p['statusId'] ?? null, 'data' => j($p)];
                }
            }
            Log::info(sprintf('  %s: %d programs', $this->label($sid), $this->db->upsert('td_programs', array_values($rows))));
        }
    }

    private function syncStatistics(): void
    {
        foreach ($this->sources as $sid => $source) {
            $scope = "statistics:$sid";
            [$from, $to] = $this->window($scope);
            $count = 0;
            foreach ($this->chunks($from, $to) as [$f, $t]) {
                $rows = [];
                $query = ['reportType' => 'program', 'intervalType' => 'day', 'sourceId' => $sid,
                          'fromDate' => $f->format('Ymd'), 'toDate' => $t->format('Ymd'),
                          'reportCurrencyCode' => $this->cfg['report_currency'] ?? 'EUR'];
                foreach ($this->pub->paged('/publisher/report/statistics', $query) as $r) {
                    $rows[] = [
                        'source_id'   => $sid,
                        'program_id'  => (int)($r['programId'] ?? 0),
                        'stat_date'   => dt($r['date'] ?? null, 'Y-m-d') ?? $f->format('Y-m-d'),
                        'clicks'      => $r['clicks'] ?? null,
                        'leads'       => $r['leads'] ?? null,
                        'sales'       => $r['sales'] ?? null,
                        'order_value' => $r['orderValue'] ?? null,
                        'commission'  => $r['commission'] ?? null,
                        'currency'    => $this->cfg['report_currency'] ?? 'EUR',
                        'data'        => j($r),
                    ];
                }
                $count += $this->db->upsert('td_statistics', $rows);
            }
            $this->db->markState($scope, true);
            Log::info(sprintf('  %s: %d statistic rows (%s..%s)', $this->label($sid), $count, $from->format('Y-m-d'), $to->format('Y-m-d')));
        }
    }

    /** Transactions are the conversions (sales + leads) attributed to each site. */
    private function syncTransactions(): void
    {
        foreach ($this->sources as $sid => $source) {
            $scope = "transactions:$sid";
            [$from, $to] = $this->window($scope);
            $count = 0;
            foreach ($this->chunks($from, $to) as [$f, $t]) {
                // Pass 1: by transaction date. Pass 2: by last modification (status changes on older transactions).
                foreach ([false, true] as $byModified) {
                    $query = ['sourceId' => $sid, 'fromDate' => $f->format('Ymd'), 'toDate' => $t->format('Ymd'),
                              'reportCurrencyCode' => $this->cfg['report_currency'] ?? 'EUR'];
                    if ($byModified) $query['lastModified'] = 'true';
                    $rows = [];
                    foreach ($this->pub->paged('/publisher/report/transactions', $query) as $r) {
                        $rows[(int)$r['transactionId']] = [
                            'transaction_id'        => (int)$r['transactionId'],
                            'source_id'             => (int)($r['sourceId'] ?? $sid),
                            'program_id'            => $r['programId'] ?? null,
                            'event_type_id'         => $r['eventTypeId'] ?? null,
                            'status'                => $r['status'] ?? null,
                            'order_number'          => pick($r, 'orderNumber', 'leadNumber'),
                            'order_value'           => $r['orderValue'] ?? null,
                            'commission'            => $r['commission'] ?? null,
                            'currency'              => $this->cfg['report_currency'] ?? 'EUR',
                            'paid'                  => isset($r['paid']) ? (int)(bool)$r['paid'] : null,
                            'time_of_transaction'   => dt(pick($r, 'timeOfTransaction', 'timeofTransaction')),
                            'time_of_last_modified' => dt(pick($r, 'timeOfLastModified', 'timeofLastModified')),
                            'data'                  => j($r),
                        ];
                    }
                    $count += $this->db->upsert('td_transactions', array_values($rows));
                }
            }
            $this->db->markState($scope, true);
            Log::info(sprintf('  %s: %d transactions (%s..%s)', $this->label($sid), $count, $from->format('Y-m-d'), $to->format('Y-m-d')));
        }
    }

    private function syncPayments(): void
    {
        $from = $this->today->modify('-' . $this->backfillDays() . ' days');
        $rows = [];
        foreach ($this->pub->paged('/publisher/report/payments', ['fromDate' => $from->format('Ymd'), 'toDate' => $this->today->format('Ymd')]) as $p) {
            $rows[] = ['payment_id' => (int)$p['paymentId'], 'payment_date' => dt($p['paymentDate'] ?? null),
                       'amount' => $p['paymentAmount'] ?? null, 'currency' => $p['currencyCode'] ?? null, 'data' => j($p)];
        }
        Log::info(sprintf('  %d payments', $this->db->upsert('td_payments', $rows)));
    }

    private function syncClaims(): void
    {
        $tokens = $this->legacyTokens('CLAIMS');
        if (!$tokens) return;
        // A publisher token sees the claims of all sites; site tokens only their own site.
        if (isset($tokens[0])) $tokens = [0 => $tokens[0]];

        $last = $this->db->state('claims')['last_success'] ?? null;
        $minUpdate = $last ? (new DateTimeImmutable($last))->modify('-2 days')->format('Y-m-d') : null;

        $count = 0;
        foreach ($tokens as $token) {
            $offset = 0;
            $limit  = 100;
            do {
                $batch = items($this->legacy->get('claims', [
                    'minUpdateDate' => $minUpdate, 'offset' => $offset, 'limit' => $limit, 'sortBy' => 'claimId', 'sortOrder' => 'asc',
                ], $token), 'claims');
                $rows = [];
                foreach ($batch as $c) {
                    $sid = (int)($c['affiliateSiteId'] ?? 0);
                    if (!empty($this->cfg['source_ids']) && !isset($this->sources[$sid])) continue;
                    $rows[] = ['claim_id' => (int)$c['claimId'], 'source_id' => $sid, 'program_id' => $c['programId'] ?? null,
                               'status_id' => $c['statusId'] ?? null, 'status_name' => $c['statusName'] ?? null,
                               'order_number' => $c['orderNumber'] ?? null, 'data' => j($c)];
                }
                $count += $this->db->upsert('td_claims', $rows);
                $offset += $limit;
            } while (count($batch) === $limit);
        }
        Log::info(sprintf('  %d claims%s', $count, $minUpdate ? " updated since $minUpdate" : ''));
    }

    private function syncVouchers(): void
    {
        foreach ($this->perSiteTokens('VOUCHERS') as $sid => $token) {
            $count = 0;
            $pageSize = 1000;
            for ($page = 1; ; $page++) {
                $batch = items($this->legacy->get('vouchers.json', ['pageSize' => $pageSize, 'page' => $page, 'dateOutputFormat' => 'iso8601'], $token), 'vouchers');
                $rows = [];
                foreach ($batch as $v) {
                    $rows[] = ['source_id' => $sid, 'voucher_id' => (int)$v['id'], 'program_id' => $v['programId'] ?? null,
                               'code' => $v['code'] ?? null, 'title' => mb_substr((string)($v['title'] ?? ''), 0, 500),
                               'start_date' => dt($v['startDate'] ?? null), 'end_date' => dt($v['endDate'] ?? null), 'data' => j($v)];
                }
                $count += $this->db->upsert('td_vouchers', $rows);
                if (count($batch) < $pageSize) break;
            }
            Log::info(sprintf('  %s: %d vouchers', $this->label($sid), $count));
        }
    }

    /**
     * Products are stored once per feed, not per site. td_product_feeds records which sites have access
     * to which feed; td_feed_downloads records which site's token (and so tracking links) was used.
     */
    private function syncProducts(): void
    {
        $tokens = $this->legacyTokens('PRODUCTS');
        if (!$tokens) return;
        uksort($tokens, fn($a, $b) => ($a === 0) <=> ($b === 0)); // publisher token (source 0) last

        // 1. Which feeds can each site access? (small JSON call per site)
        $only  = array_map('intval', $this->cfg['feed_ids'] ?? []);
        $feeds = []; // feed_id => ['feed' => array, 'tokens' => [source_id => token]]
        foreach ($tokens as $sid => $token) {
            $rows = [];
            foreach (items($this->legacy->get('productFeeds.json', [], $token), 'feeds', 'productFeeds') as $feed) {
                $fid = (int)$feed['feedId'];
                if ($only && !in_array($fid, $only, true)) continue;
                $feeds[$fid]['feed'] ??= $feed;
                $feeds[$fid]['tokens'][$sid] = $token;
                $rows[] = ['source_id' => $sid, 'feed_id' => $fid, 'name' => $feed['name'] ?? null,
                           'active' => isset($feed['active']) ? (int)(bool)$feed['active'] : null,
                           'number_of_products' => $feed['numberOfProducts'] ?? null, 'data' => j($feed)];
            }
            $this->db->upsert('td_product_feeds', $rows);
            $this->db->pdo->prepare('DELETE FROM td_product_feeds WHERE source_id = ? AND synced_at < ?')->execute([$sid, $this->now]);
        }
        Log::info(sprintf('  %d sites have access to %d distinct feeds%s', count($tokens), count($feeds), $only ? ' (limited by feed_ids)' : ''));
        if ($only && ($missing = array_diff($only, array_keys($feeds)))) {
            Log::info('  feed_ids not available to any site: ' . implode(', ', $missing));
        }

        // 2. Download each changed feed once.
        $state = $this->db->pdo->prepare('SELECT source_id, last_modified, product_count FROM td_feed_downloads WHERE feed_id = ?');
        $keep = [];
        foreach ($feeds as $fid => ['feed' => $feed, 'tokens' => $feedTokens]) {
            if (isset($feed['active']) && !$feed['active']) continue;
            $keep[] = $fid;

            $modified = isset($feed['lastModifiedTime']) ? (string)$feed['lastModifiedTime'] : null;
            $state->execute([$fid]);
            $prev = $state->fetch() ?: null;
            // Export files may only be downloaded 3x per 24h, so skip feeds that did not change.
            if (!$this->opts['force'] && $prev && (int)$prev['product_count'] > 0 && $modified !== null && $prev['last_modified'] === $modified) {
                Log::info(sprintf('  feed %d (%s) unchanged, skipped', $fid, $feed['name'] ?? ''));
                continue;
            }
            // Prefer the site used last time, so stored tracking links stay the same.
            if ($prev && isset($feedTokens[(int)$prev['source_id']])) {
                $feedTokens = [(int)$prev['source_id'] => $feedTokens[(int)$prev['source_id']]] + $feedTokens;
            }

            $download = $this->downloadFeed($fid, $feedTokens);
            if (!$download) {
                Log::info(sprintf('  feed %d: download limit reached for all %d sites, retrying next run', $fid, count($feedTokens)));
                continue;
            }
            [$sid, $file] = $download;

            $count = 0;
            $rows = [];
            foreach (exportProducts($file) as $p) {
                $offer = $p['offers'][0] ?? [];
                $id = pick($offer, 'sourceProductId', 'id') ?? pick($p, 'id') ?? sha1(j($p));
                $rows[] = ['feed_id' => $fid, 'product_id' => mb_substr((string)$id, 0, 100),
                           'name' => mb_substr((string)($p['name'] ?? ''), 0, 500),
                           'price' => $this->productPrice($p),
                           'data' => j($p)];
                if (count($rows) === 500) {
                    $count += $this->db->upsert('td_products', $rows, 100);
                    $rows = [];
                }
            }
            $count += $this->db->upsert('td_products', $rows, 100);
            @unlink($file);

            if ($count === 0 && (int)($feed['numberOfProducts'] ?? 0) > 0) {
                throw new RuntimeException(sprintf('feed %d: export contained no products, expected %d', $fid, $feed['numberOfProducts']));
            }

            // Full feed downloaded: remove products that are no longer in it.
            $del = $this->db->pdo->prepare('DELETE FROM td_products WHERE feed_id = ? AND synced_at < ?');
            $del->execute([$fid, $this->now]);
            $this->db->upsert('td_feed_downloads', [['feed_id' => $fid, 'source_id' => $sid, 'last_modified' => $modified, 'product_count' => $count]]);
            Log::info(sprintf('  feed %d (%s) via %s: %d products (%d removed)', $fid, $feed['name'] ?? '', $this->label($sid), $count, $del->rowCount()));
        }

        // 3. Drop products of feeds that are inactive, excluded by feed_ids, or no longer available to any site.
        if ($keep) {
            $in = implode(',', array_map('intval', $keep));
            $removed = $this->db->pdo->exec("DELETE FROM td_products WHERE feed_id NOT IN ($in)");
            $this->db->pdo->exec("DELETE FROM td_feed_downloads WHERE feed_id NOT IN ($in)");
            if ($removed) Log::info("  removed $removed products of feeds that are no longer available");
        }
    }

    /** Download a feed export with the first token still under the daily download limit: [source_id, file] or null. */
    private function downloadFeed(int $fid, array $tokens): ?array
    {
        foreach ($tokens as $sid => $token) {
            // productsUnlimited redirects to a ready-made export file containing the whole feed.
            $file = tempnam(sys_get_temp_dir(), 'tdfeed');
            try {
                $this->legacy->download('productsUnlimited.json', ['fid' => $fid], $token, $file);
                return [$sid, $file];
            } catch (ApiException $e) {
                @unlink($file);
                if ($e->status !== 429) throw $e;
            }
        }
        return null;
    }

    /** The Conversions API is push-only (postbacks); we store the configured subscriptions. */
    private function syncConversions(): void
    {
        foreach ($this->legacyTokens('CONVERSIONS') as $sid => $token) {
            $rows = [];
            foreach (items($this->legacy->get('conversions/subscriptions', [], $token), 'subscriptions') as $s) {
                $id = pick($s, 'id', 'subscriptionId') ?? sha1(j($s));
                $rows[] = ['source_id' => $sid, 'subscription_id' => (string)$id, 'name' => $s['subscriptionName'] ?? null, 'data' => j($s)];
            }
            Log::info(sprintf('  %s: %d conversion subscriptions', $this->label($sid), $this->db->upsert('td_conversion_subscriptions', $rows)));
        }
    }

    // --- helpers -----------------------------------------------------------------------------

    private function due(string $task): bool
    {
        if ($this->opts['only'] !== null) return in_array($task, $this->opts['only'], true);
        if ($this->opts['force']) return true;
        $last = $this->db->state($task)['last_success'] ?? null;
        $interval = (int)($this->cfg['intervals'][$task] ?? 60);
        return !$last || strtotime($last) + $interval * 60 - 120 <= time(); // 2 min slack for cron jitter
    }

    private function backfillDays(): int
    {
        return $this->opts['backfill'] ?? (int)($this->cfg['backfill_days'] ?? 365);
    }

    /** Date window for a per-site report: full backfill the first time, otherwise the lookback period. */
    private function window(string $scope): array
    {
        $days = ($this->opts['backfill'] !== null || !($this->db->state($scope)['last_success'] ?? null))
            ? $this->backfillDays()
            : (int)($this->cfg['lookback_days'] ?? 45);
        return [$this->today->modify("-$days days"), $this->today];
    }

    /** Split a date range into chunks of at most 31 days (the reporting endpoints' limit). */
    private function chunks(DateTimeImmutable $from, DateTimeImmutable $to, int $days = 31): Generator
    {
        for ($start = $from; $start <= $to; $start = $end->modify('+1 day')) {
            $end = min($start->modify('+' . ($days - 1) . ' days'), $to);
            yield [$start, $end];
        }
    }

    private function label(int $sid): string
    {
        return $sid === 0 ? 'publisher (all sites)' : sprintf('site %d %s', $sid, $this->sources[$sid]['name'] ?? '');
    }

    /**
     * Legacy API tokens for a token system, as [source_id => token]; source_id 0 = publisher-wide token.
     * Order of precedence: config overrides, then tokens from GET /publisher/tokens.
     */
    private function legacyTokens(string $system): array
    {
        $out = [];
        $override = $this->cfg['legacy_tokens'][$system] ?? [];
        if (!empty($override['publisher'])) $out[0] = $override['publisher'];
        foreach ($override['sites'] ?? [] as $sid => $tok) if ($tok) $out[(int)$sid] = $tok;

        if ($this->apiTokens === null) {
            try {
                $this->apiTokens = $this->pub->get('/publisher/tokens')['tokens'] ?? [];
            } catch (Throwable $e) {
                Log::info('  Could not list tokens (' . $e->getMessage() . '); using config only');
                $this->apiTokens = [];
            }
        }
        foreach ($this->apiTokens as $t) {
            if (strtoupper((string)($t['tokenSystem'] ?? '')) !== $system || empty($t['token'])) continue;
            if (strtoupper((string)($t['tokenType'] ?? '')) === 'PUBLISHER') {
                $out[0] ??= $t['token'];
                continue;
            }
            // For SITE tokens the token "id" is the source id; fall back to matching by name.
            $sid = (int)(pick($t, 'sourceId', 'siteId', 'affiliateSiteId')
                ?? (isset($this->sources[(int)($t['id'] ?? 0)]) ? (int)$t['id'] : null)
                ?? $this->sourceIdByName((string)($t['name'] ?? '')));
            if ($sid) $out[$sid] ??= $t['token'];
            else Log::info(sprintf("  %s site token '%s' could not be matched to a source; add it to legacy_tokens", $system, $t['name'] ?? '?'));
        }

        $out = array_filter($out, fn($sid) => $sid === 0 || isset($this->sources[$sid]), ARRAY_FILTER_USE_KEY);
        if (!$out) Log::info("  No $system token found: create one in the Tradedoubler UI (Settings > Tokens) or set legacy_tokens in config.php");
        return $out;
    }

    /** Site tokens for each site; the publisher token (as source 0) only covers sites without their own token. */
    private function perSiteTokens(string $system): array
    {
        $tokens = $this->legacyTokens($system);
        if (isset($tokens[0]) && !array_diff_key($this->sources, $tokens)) unset($tokens[0]);
        return $tokens;
    }

    private function productPrice(array $p): ?float
    {
        $price = $p['price'] ?? $p['offers'][0]['priceHistory'][0]['price']['value'] ?? null;
        return is_numeric($price) ? (float)$price : null;
    }

    private function sourceIdByName(string $name): ?int
    {
        foreach ($this->sources as $id => $s) {
            if ($name !== '' && strcasecmp(trim((string)($s['name'] ?? '')), trim($name)) === 0) return $id;
        }
        return null;
    }
}
