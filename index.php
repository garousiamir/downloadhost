<?php
/**
 * Remote Downloader
 * -----------------
 * Paste a URL and this script downloads the file straight to your server (cPanel / shared hosting),
 * with a live AJAX progress bar — and without hitting PHP or web-server timeouts.
 *
 * How the timeouts are avoided
 *  1. Chunked mode (used whenever the remote server supports HTTP Range / resume):
 *     each AJAX request downloads for at most CHUNK_SECONDS, appends to a .part file and returns.
 *     The browser immediately asks for the next slice. No single PHP request ever runs long,
 *     so max_execution_time, LiteSpeed/Apache/Cloudflare timeouts never trigger. Downloads can be
 *     paused, resumed, and survive network hiccups or a closed tab (click "Resume" later).
 *  2. Background mode (used when the remote server can't resume, or when you tick
 *     "keep downloading after I close this page"): PHP sends its answer to the browser, detaches,
 *     and keeps downloading on the server with no time limit; the page just polls the progress.
 *
 * Requirements: PHP 7.4+ with the cURL extension (standard on cPanel).
 */

// ================================ CONFIGURATION ================================

// Password for this page. You MUST change it — the page refuses to run with the default.
const APP_PASSWORD = 'change-me';

// Where finished files are saved. Default: a "downloads" folder next to this script, so files are
// reachable at https://your-site/<this-folder>/downloads/<file>. For private files, use a path
// outside public_html, e.g. '/home/YOUR_CPANEL_USER/downloads'.
const DOWNLOAD_DIR = __DIR__ . '/downloads';

// Seconds each AJAX request spends downloading before handing back to the browser.
// Keep it well below your host's timeout (usually 30–120 s). 20 is safe almost everywhere.
const CHUNK_SECONDS = 20;

// User-Agent sent to remote servers (some refuse unknown clients). Can be overridden per download.
const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

// ===============================================================================

const JOBS_DIR = DOWNLOAD_DIR . '/.jobs';
const MAX_JOBS_LISTED = 50;

error_reporting(E_ALL);
ini_set('display_errors', '0');

final class UserError extends RuntimeException {}

// ------------------------------------------------------------------ bootstrap --

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

session_name('rdl_' . substr(md5(__FILE__), 0, 8));
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

if (!function_exists('curl_init')) {
    render_message_page('cURL is missing', 'This script needs the PHP <b>cURL</b> extension. Enable it in cPanel → <i>Select PHP Version</i> → Extensions.');
}
if (APP_PASSWORD === '' || APP_PASSWORD === 'change-me') {
    render_message_page('Set a password first', 'Open <code>' . h(basename(__FILE__)) . '</code> in the cPanel File Manager and change <code>APP_PASSWORD</code> at the top of the file. Without it anyone could use your server to download files.');
}

$action = (string) ($_GET['action'] ?? '');
if ($action !== '') {
    handle_api($action);
}

$loginError = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $tokenOk = hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''));
    if ($do === 'login') {
        if ($tokenOk && hash_equals(APP_PASSWORD, (string) ($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            redirect_self();
        }
        sleep(2); // slow down password guessing
        $loginError = 'Wrong password — please try again.';
    } elseif ($do === 'logout' && $tokenOk) {
        $_SESSION = [];
        session_destroy();
        redirect_self();
    }
}

if (empty($_SESSION['auth'])) {
    render_login($loginError);
}
render_app();

// ------------------------------------------------------------------------ API --

function handle_api(string $action): void
{
    ini_set('zlib.output_compression', 'Off');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        $in = [];
    }
    if (empty($_SESSION['auth'])) {
        json_out(['ok' => false, 'auth' => false, 'error' => 'Your session has expired — please log in again.'], 401);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals($_SESSION['csrf'], (string) ($in['_csrf'] ?? ''))) {
        json_out(['ok' => false, 'error' => 'Invalid request token — please reload the page.'], 403);
    }
    // Release the session lock so long downloads don't block the progress polls.
    session_write_close();

    try {
        ensure_dirs();
        $id = (string) ($in['id'] ?? '');
        switch ($action) {
            case 'probe':
                json_out(['ok' => true, 'job' => job_public(create_job($in))]);
            case 'chunk':
                action_chunk($id, !empty($in['resume']));
            case 'start_bg':
                action_start_bg($id);
            case 'jobs':
                json_out(['ok' => true, 'jobs' => list_jobs(), 'free' => disk_free()]);
            case 'pause':
                action_stop($id, 'pause');
            case 'cancel':
                action_stop($id, 'cancel');
            case 'remove':
                action_remove($id);
            case 'files':
                json_out(['ok' => true, 'files' => list_files(), 'free' => disk_free()]);
            case 'delete_file':
                action_delete_file((string) ($in['name'] ?? ''));
            default:
                throw new UserError('Unknown action.');
        }
    } catch (UserError $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
    }
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Checks the URL, figures out size / filename / resume support and creates a job. */
function create_job(array $in): array
{
    $url = normalize_url((string) ($in['url'] ?? ''));
    $headers = parse_header_lines((string) ($in['headers'] ?? ''));
    $insecure = !empty($in['insecure']);

    $info = probe_url($url, $headers, $insecure);

    $name = trim((string) ($in['name'] ?? ''));
    $name = sanitize_filename($name !== '' ? $name : $info['filename']);

    $free = disk_free();
    if ($info['total'] > 0 && $free !== null && $free < $info['total']) {
        throw new UserError('Not enough disk space: the file is ' . human_size($info['total']) . ' but only ' . human_size($free) . ' is free.');
    }

    $job = [
        'id'           => bin2hex(random_bytes(8)),
        'url'          => $url,
        'name'         => $name,
        'headers'      => $headers,
        'insecure'     => $insecure,
        'total'        => $info['total'],
        'ranges'       => $info['ranges'],
        'mode'         => ($info['ranges'] && empty($in['background'])) ? 'chunked' : 'background',
        'content_type' => $info['content_type'],
        'status'       => 'queued',
        'error'        => null,
        'retryable'    => false,
        'file'         => null,
        'downloaded'   => 0,
        'created'      => time(),
        'finished'     => null,
    ];
    job_save($job);
    return $job;
}

/** Downloads one time-boxed slice. Called repeatedly by the browser. */
function action_chunk(string $id, bool $resume): void
{
    $lock = job_lock($id);
    if ($lock === null) { // another request / background worker is on it
        json_out(['ok' => true, 'busy' => true, 'job' => job_public(job_load($id))]);
    }
    try {
        $job = job_load($id);
        if ($resume) {
            stop_flag_clear($id);
        }
        $idle = in_array($job['status'], ['done', 'cancelled'], true) || ($job['status'] === 'paused' && !$resume);
        if (!$idle) {
            if (!$job['ranges']) {
                throw new UserError('The remote server does not support resuming, so this file must be downloaded in background mode.');
            }
            extend_time(CHUNK_SECONDS + 120);
            ignore_user_abort(true);
            $job['status'] = 'downloading';
            $job['mode'] = 'chunked';
            $job['error'] = null;
            $job['retryable'] = false;
            job_save($job);
            apply_result($job, transfer($job, CHUNK_SECONDS));
        }
    } finally {
        job_unlock($lock);
    }
    json_out(['ok' => true, 'job' => job_public($job)]);
}

/** Answers the browser right away, then keeps downloading on the server with no time limit. */
function action_start_bg(string $id): void
{
    $lock = job_lock($id);
    if ($lock === null) {
        json_out(['ok' => true, 'busy' => true, 'job' => job_public(job_load($id))]);
    }
    $job = job_load($id);
    if (in_array($job['status'], ['done', 'cancelled'], true)) {
        job_unlock($lock);
        json_out(['ok' => true, 'job' => job_public($job)]);
    }
    stop_flag_clear($id);
    $job['status'] = 'downloading';
    $job['mode'] = 'background';
    $job['error'] = null;
    $job['retryable'] = false;
    job_save($job);

    respond_and_detach(json_encode(['ok' => true, 'job' => job_public($job, true)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    extend_time(0);

    $attempt = 0;
    while (true) {
        $before = part_size($id);
        $result = transfer($job, 0);
        $retry = $result['state'] === 'partial' || ($result['state'] === 'error' && !empty($result['retryable']));
        if (!$retry) {
            break;
        }
        if (part_size($id) > $before) {
            $attempt = 0;
        }
        if (++$attempt > ($job['ranges'] ? 10 : 3)) {
            if ($result['state'] === 'partial') {
                $result = ['state' => 'error', 'error' => 'The connection kept dropping.', 'retryable' => true];
            }
            break;
        }
        $job['error'] = $result['error'] ?? null;
        job_save($job);
        $flag = sleep_unless_stopped($id, min(60, 2 ** $attempt));
        if ($flag !== null) {
            $result = ['state' => $flag === 'cancel' ? 'cancelled' : 'paused'];
            break;
        }
    }
    apply_result($job, $result);
    job_unlock($lock);
    exit;
}

/** Pause or cancel. Running workers are told through a flag file; idle jobs are changed directly. */
function action_stop(string $id, string $kind): void
{
    $lock = job_lock($id);
    if ($lock === null) {
        stop_flag_set($id, $kind);
        json_out(['ok' => true, 'job' => job_public(job_load($id))]);
    }
    try {
        $job = job_load($id);
        if (!in_array($job['status'], ['done', 'cancelled'], true)) {
            if ($kind === 'cancel') {
                @unlink(part_path($id));
                $job['status'] = 'cancelled';
            } else {
                $job['status'] = 'paused';
            }
            job_save($job);
        }
        stop_flag_clear($id);
    } finally {
        job_unlock($lock);
    }
    json_out(['ok' => true, 'job' => job_public($job)]);
}

function action_remove(string $id): void
{
    $lock = job_lock($id);
    if ($lock === null) {
        throw new UserError('This download is still running — cancel it first.');
    }
    job_unlock($lock);
    foreach (['.part', '.stop', '.json', '.lock'] as $ext) {
        @unlink(JOBS_DIR . '/' . $id . $ext);
    }
    json_out(['ok' => true]);
}

function action_delete_file(string $name): void
{
    if ($name === '' || $name[0] === '.' || strpbrk($name, "/\\\0") !== false) {
        throw new UserError('Invalid file name.');
    }
    $path = DOWNLOAD_DIR . '/' . $name;
    if (!is_file($path)) {
        throw new UserError('File not found.');
    }
    if (!@unlink($path)) {
        throw new UserError('Could not delete the file (permissions?).');
    }
    json_out(['ok' => true, 'files' => list_files(), 'free' => disk_free()]);
}

// ----------------------------------------------------------------- transfer --

function curl_options(array $headers, bool $insecure): array
{
    $opts = [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_COOKIEFILE     => '', // keep cookies set during redirects
        CURLOPT_SSL_VERIFYPEER => !$insecure,
        CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
    ];
    if (defined('CURLOPT_PROTOCOLS')) {
        $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    return $opts;
}

/** Asks for the first byte only, to learn the size, the file name and whether resume works. */
function probe_url(string $url, array $headers, bool $insecure): array
{
    $hdrs = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, curl_options(array_merge($headers, ['Range: bytes=0-0']), $insecure) + [
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$hdrs) {
            if (preg_match('#^HTTP/\S+\s+\d{3}#', $line)) {
                $hdrs = []; // new response (after a redirect)
            } elseif (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $hdrs[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => function () {
            return 0; // got what we need; stop before the body
        },
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($errno !== 0 && $errno !== CURLE_WRITE_ERROR) {
        throw new UserError('Could not reach the URL: ' . $error);
    }
    if ($code < 200 || $code >= 400) {
        throw new UserError('The remote server answered HTTP ' . $code . '.');
    }

    $ranges = false;
    $total = -1;
    if ($code === 206 && preg_match('#bytes\s+0-\d+/(\d+|\*)#i', $hdrs['content-range'] ?? '', $m)) {
        $ranges = true;
        $total = $m[1] === '*' ? -1 : (int) $m[1];
    } elseif (isset($hdrs['content-length']) && ctype_digit($hdrs['content-length'])) {
        $total = (int) $hdrs['content-length'];
    }

    return [
        'ranges'       => $ranges,
        'total'        => $total,
        'filename'     => filename_from_response($hdrs['content-disposition'] ?? '', $effective ?: $url),
        'content_type' => $hdrs['content-type'] ?? null,
    ];
}

/**
 * Appends to the job's .part file, starting where it left off.
 * $budget = seconds to run before stopping on purpose (0 = until finished).
 * Returns ['state' => complete|partial|paused|cancelled|error, 'error' => ?, 'retryable' => ?].
 */
function transfer(array $job, int $budget): array
{
    $id = $job['id'];
    $part = part_path($id);
    $offset = $job['ranges'] ? part_size($id) : 0; // without Range support we must start over
    if ($job['total'] >= 0 && $offset >= $job['total']) {
        return ['state' => 'complete'];
    }
    $fh = @fopen($part, $offset > 0 ? 'ab' : 'wb');
    if (!$fh) {
        return ['state' => 'error', 'error' => 'Cannot write the temporary file in ' . JOBS_DIR . '.'];
    }

    $headers = $job['headers'];
    if ($offset > 0) {
        $headers[] = 'Range: bytes=' . $offset . '-';
    }
    $status = 0;
    $reason = null;
    $flag = null;
    $start = microtime(true);
    $lastCheck = $start;

    $opts = curl_options($headers, $job['insecure']) + [
        CURLOPT_FAILONERROR     => true,
        CURLOPT_LOW_SPEED_LIMIT => 1,
        CURLOPT_LOW_SPEED_TIME  => $budget > 0 ? 30 : 90,
        CURLOPT_HEADERFUNCTION  => function ($ch, $line) use (&$status) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION   => function ($ch, $data) use ($fh, $id, $offset, $budget, $start, &$status, &$reason, &$flag, &$lastCheck) {
            if ($offset > 0 && $status !== 206) {
                $reason = 'norange'; // server sent the whole file again — appending would corrupt it
                return 0;
            }
            $len = strlen($data);
            if (fwrite($fh, $data) !== $len) {
                $reason = 'disk';
                return 0;
            }
            $now = microtime(true);
            if ($now - $lastCheck >= 0.5) {
                $lastCheck = $now;
                $flag = stop_flag_get($id);
                if ($flag !== null) {
                    $reason = 'stop';
                    return 0;
                }
                if ($budget > 0 && $now - $start >= $budget) {
                    $reason = 'budget';
                    return 0;
                }
            }
            return $len;
        },
    ];
    if ($budget > 0) {
        $opts[CURLOPT_TIMEOUT] = $budget + 30;
    }

    $ch = curl_init($job['url']);
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($fh);

    if ($reason === 'budget') {
        return ['state' => 'partial'];
    }
    if ($reason === 'stop') {
        return ['state' => $flag === 'cancel' ? 'cancelled' : 'paused'];
    }
    if ($reason === 'disk') {
        return ['state' => 'error', 'error' => 'Could not write to disk — is your disk quota full?'];
    }
    if ($reason === 'norange') {
        return ['state' => 'error', 'error' => 'The remote server stopped accepting resume requests. Click "Restart" to download it again from the start.', 'noranges' => true];
    }
    if ($errno === 0) {
        $size = part_size($id);
        if ($job['total'] >= 0 && $size < $job['total']) {
            return $job['ranges']
                ? ['state' => 'partial']
                : ['state' => 'error', 'error' => 'The connection closed before the whole file arrived.', 'retryable' => true];
        }
        return ['state' => 'complete'];
    }
    if ($code === 416 && $offset > 0 && ($job['total'] < 0 || $offset >= $job['total'])) {
        return ['state' => 'complete']; // nothing left to fetch
    }
    if ($code >= 400) {
        return [
            'state'     => 'error',
            'error'     => 'The remote server answered HTTP ' . $code . ($code === 403 || $code === 410 ? ' — the link may have expired.' : '.'),
            'retryable' => $code >= 500 || $code === 408 || $code === 429,
        ];
    }
    return ['state' => 'error', 'error' => 'Network error: ' . $error, 'retryable' => true];
}

function apply_result(array &$job, array $result): void
{
    switch ($result['state']) {
        case 'complete':
            finalize($job);
            break;
        case 'partial':
            $job['status'] = 'downloading';
            break;
        case 'paused':
            $job['status'] = 'paused';
            break;
        case 'cancelled':
            @unlink(part_path($job['id']));
            $job['status'] = 'cancelled';
            break;
        default:
            $job['status'] = 'error';
            $job['error'] = $result['error'] ?? 'Unknown error.';
            $job['retryable'] = !empty($result['retryable']);
            if (!empty($result['noranges'])) {
                $job['ranges'] = false;
                $job['mode'] = 'background';
            }
    }
    if ($job['status'] !== 'done') {
        $job['downloaded'] = part_size($job['id']);
    }
    stop_flag_clear($job['id']);
    job_save($job);
}

/** Moves the finished .part file into the download folder under a free name. */
function finalize(array &$job): void
{
    $part = part_path($job['id']);
    if (!is_file($part)) {
        touch($part); // zero-byte file
    }
    $name = unique_name($job['name']);
    if (!@rename($part, DOWNLOAD_DIR . '/' . $name)) {
        $job['status'] = 'error';
        $job['error'] = 'Download finished but the file could not be moved into ' . DOWNLOAD_DIR . '.';
        return;
    }
    @chmod(DOWNLOAD_DIR . '/' . $name, 0644);
    clearstatcache();
    $job['status'] = 'done';
    $job['file'] = $name;
    $job['error'] = null;
    $job['finished'] = time();
    $job['downloaded'] = (int) filesize(DOWNLOAD_DIR . '/' . $name);
    if ($job['total'] < 0) {
        $job['total'] = $job['downloaded'];
    }
}

/** Sends the response, closes the connection and lets PHP keep running. */
function respond_and_detach(string $body): void
{
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) { // PHP-FPM
        echo $body;
        fastcgi_finish_request();
        return;
    }
    if (function_exists('litespeed_finish_request')) { // LiteSpeed (very common on cPanel)
        echo $body;
        litespeed_finish_request();
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Connection: close');
    header('Content-Encoding: none');
    header('Content-Length: ' . strlen($body));
    echo $body;
    flush();
}

// --------------------------------------------------------------------- jobs --

function job_file(string $id): string
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
        throw new UserError('Invalid download id.');
    }
    return JOBS_DIR . '/' . $id . '.json';
}

function part_path(string $id): string
{
    return JOBS_DIR . '/' . $id . '.part';
}

function part_size(string $id): int
{
    $p = part_path($id);
    clearstatcache(true, $p);
    return is_file($p) ? (int) filesize($p) : 0;
}

function job_load(string $id): array
{
    $raw = @file_get_contents(job_file($id));
    $job = $raw === false ? null : json_decode($raw, true);
    if (!is_array($job)) {
        throw new UserError('Download not found — it may have been removed.');
    }
    return $job;
}

function job_save(array $job): void
{
    $job['updated'] = time();
    $file = job_file($job['id']);
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || file_put_contents($tmp, $json) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Could not save download state in ' . JOBS_DIR);
    }
}

/** Returns a held lock handle, or null when another process is working on the job. */
function job_lock(string $id)
{
    job_file($id); // validates the id
    $fh = @fopen(JOBS_DIR . '/' . $id . '.lock', 'c');
    if (!$fh) {
        throw new RuntimeException('Cannot create lock file in ' . JOBS_DIR);
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return null;
    }
    return $fh;
}

function job_unlock($fh): void
{
    flock($fh, LOCK_UN);
    fclose($fh);
}

function job_is_active(string $id): bool
{
    $fh = job_lock($id);
    if ($fh === null) {
        return true;
    }
    job_unlock($fh);
    return false;
}

function stop_flag_get(string $id): ?string
{
    $f = JOBS_DIR . '/' . $id . '.stop';
    clearstatcache(true, $f);
    if (!is_file($f)) {
        return null;
    }
    $v = trim((string) @file_get_contents($f));
    return $v === 'cancel' ? 'cancel' : 'pause';
}

function stop_flag_set(string $id, string $kind): void
{
    file_put_contents(JOBS_DIR . '/' . $id . '.stop', $kind);
}

function stop_flag_clear(string $id): void
{
    @unlink(JOBS_DIR . '/' . $id . '.stop');
}

/** Sleeps up to $seconds; returns the stop flag if one appears meanwhile. */
function sleep_unless_stopped(string $id, int $seconds): ?string
{
    for ($i = 0; $i < $seconds * 2; $i++) {
        $flag = stop_flag_get($id);
        if ($flag !== null) {
            return $flag;
        }
        usleep(500000);
    }
    return null;
}

/** The job as sent to the browser (request headers stay on the server). */
function job_public(array $job, ?bool $active = null): array
{
    $final = in_array($job['status'], ['done', 'cancelled'], true);
    return [
        'id'           => $job['id'],
        'name'         => $job['file'] ?? $job['name'],
        'host'         => (string) parse_url($job['url'], PHP_URL_HOST),
        'url'          => $job['url'],
        'total'        => $job['total'],
        'downloaded'   => $final ? $job['downloaded'] : part_size($job['id']),
        'ranges'       => $job['ranges'],
        'mode'         => $job['mode'],
        'status'       => $job['status'],
        'error'        => $job['error'],
        'retryable'    => $job['retryable'],
        'content_type' => $job['content_type'],
        'created'      => $job['created'],
        'finished'     => $job['finished'],
        'active'       => $final ? false : ($active ?? job_is_active($job['id'])),
        'link'         => $job['status'] === 'done' ? file_link($job['file']) : null,
    ];
}

function list_jobs(): array
{
    $jobs = [];
    foreach (glob(JOBS_DIR . '/*.json') ?: [] as $file) {
        $job = json_decode((string) @file_get_contents($file), true);
        if (is_array($job) && isset($job['id'])) {
            $jobs[] = $job;
        }
    }
    usort($jobs, function ($a, $b) {
        return $b['created'] <=> $a['created'];
    });
    return array_map('job_public', array_slice($jobs, 0, MAX_JOBS_LISTED));
}

// -------------------------------------------------------------------- files --

function ensure_dirs(): void
{
    foreach ([DOWNLOAD_DIR, JOBS_DIR] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new UserError('Cannot create the folder ' . $dir . ' — check its permissions.');
        }
    }
    if (realpath(DOWNLOAD_DIR) === realpath(__DIR__)) {
        throw new UserError('DOWNLOAD_DIR must be a separate folder, not the folder this script lives in.');
    }
    if (!is_writable(DOWNLOAD_DIR) || !is_writable(JOBS_DIR)) {
        throw new UserError('The folder ' . DOWNLOAD_DIR . ' is not writable — set its permissions to 755.');
    }
    $deny = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    if (!is_file(JOBS_DIR . '/.htaccess')) {
        @file_put_contents(JOBS_DIR . '/.htaccess', "# Remote Downloader: temporary files, never served.\n" . $deny);
    }
    if (!is_file(DOWNLOAD_DIR . '/.htaccess')) {
        $exts = '.php .php3 .php4 .php5 .php7 .php8 .phtml .phar .pht .phps .cgi .pl .py .sh .shtml';
        @file_put_contents(DOWNLOAD_DIR . '/.htaccess',
            "# Remote Downloader: serve downloaded files as plain files and never execute them.\n"
            . "Options -Indexes -ExecCGI\n"
            . "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n"
            . "<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n"
            . "RemoveHandler $exts\nRemoveType $exts\n"
            . "<FilesMatch \"(?i)\\.(php\\d*|phtml|phar|pht|phps|cgi|pl|py|sh|shtml)(\\.|$)\">\n" . $deny . "</FilesMatch>\n"
            . "<IfModule mod_headers.c>\n  Header set X-Content-Type-Options nosniff\n  Header set Content-Security-Policy \"sandbox\"\n</IfModule>\n");
    }
}

function list_files(): array
{
    $files = [];
    foreach (scandir(DOWNLOAD_DIR) ?: [] as $f) {
        $p = DOWNLOAD_DIR . '/' . $f;
        if ($f[0] === '.' || !is_file($p)) {
            continue;
        }
        $files[] = ['name' => $f, 'size' => (int) filesize($p), 'mtime' => (int) filemtime($p), 'link' => file_link($f)];
    }
    usort($files, function ($a, $b) {
        return $b['mtime'] <=> $a['mtime'];
    });
    return $files;
}

/** Relative URL of a downloaded file, or null when DOWNLOAD_DIR is outside the web folder. */
function file_link(string $name): ?string
{
    static $base = false;
    if ($base === false) {
        $base = null;
        $root = realpath(__DIR__);
        $dir = realpath(DOWNLOAD_DIR);
        if ($root && $dir && strpos($dir, $root . DIRECTORY_SEPARATOR) === 0) {
            $rel = str_replace('\\', '/', substr($dir, strlen($root) + 1));
            $base = implode('/', array_map('rawurlencode', explode('/', $rel))) . '/';
        }
    }
    return $base === null ? null : $base . rawurlencode($name);
}

function unique_name(string $name): string
{
    if (!file_exists(DOWNLOAD_DIR . '/' . $name)) {
        return $name;
    }
    $dot = strrpos($name, '.');
    $base = $dot > 0 ? substr($name, 0, $dot) : $name;
    $ext = $dot > 0 ? substr($name, $dot) : '';
    for ($i = 1; $i < 10000; $i++) {
        $candidate = "$base ($i)$ext";
        if (!file_exists(DOWNLOAD_DIR . '/' . $candidate)) {
            return $candidate;
        }
    }
    return $base . '-' . bin2hex(random_bytes(4)) . $ext;
}

function disk_free(): ?float
{
    if (!function_exists('disk_free_space')) {
        return null;
    }
    $free = @disk_free_space(DOWNLOAD_DIR);
    return $free === false ? null : $free;
}

// ------------------------------------------------------------------ helpers --

function normalize_url(string $url): string
{
    $url = trim($url);
    // Percent-encode spaces and non-ASCII characters (e.g. pasted Persian/Arabic file names).
    $url = preg_replace_callback('/[^\x21-\x7E]/', function ($m) {
        return rawurlencode($m[0]);
    }, $url);
    $parts = parse_url($url);
    $scheme = strtolower($parts['scheme'] ?? '');
    if (!$parts || !in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
        throw new UserError('Please enter a valid http:// or https:// URL.');
    }
    return $url;
}

function parse_header_lines(string $raw): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (!preg_match('/^([A-Za-z0-9!#$%&\'*+.^_`|~-]+)\s*:\s*(.*)$/', $line, $m)) {
            throw new UserError('Invalid header line: "' . $line . '". Use the form  Name: value');
        }
        if (in_array(strtolower($m[1]), ['range', 'host', 'content-length'], true)) {
            continue;
        }
        $out[] = $m[1] . ': ' . $m[2];
    }
    return $out;
}

function filename_from_response(string $disposition, string $url): string
{
    if (preg_match("/filename\\*\\s*=\\s*([^']*)'[^']*'([^;]+)/i", $disposition, $m)) {
        $name = rawurldecode(trim($m[2], " \t\"'"));
        if (strcasecmp(trim($m[1]), 'utf-8') !== 0 && function_exists('mb_convert_encoding')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'ISO-8859-1');
        }
        return $name;
    }
    if (preg_match('/filename\s*=\s*"([^"]+)"/i', $disposition, $m) || preg_match('/filename\s*=\s*([^;]+)/i', $disposition, $m)) {
        return trim($m[1]);
    }
    $path = (string) parse_url($url, PHP_URL_PATH);
    $name = rawurldecode(substr($path, (int) strrpos($path, '/') + 1));
    return $name !== '' ? $name : 'download';
}

function sanitize_filename(string $name): string
{
    $name = str_replace('\\', '/', $name);
    $slash = strrpos($name, '/');
    if ($slash !== false) {
        $name = substr($name, $slash + 1);
    }
    if (!preg_match('//u', $name)) { // invalid UTF-8
        $name = preg_replace('/[\x80-\xFF]/', '_', $name);
    }
    $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '_', $name);
    $name = trim(preg_replace('/\s+/u', ' ', $name), " .");
    if ($name === '') {
        $name = 'download';
    }
    if (strlen($name) > 200) {
        $dot = strrpos($name, '.');
        $ext = ($dot !== false && strlen($name) - $dot <= 12) ? substr($name, $dot) : '';
        $base = substr($name, 0, strlen($name) - strlen($ext));
        $base = function_exists('mb_strcut') ? mb_strcut($base, 0, 180, 'UTF-8') : substr($base, 0, 180);
        $name = $base . $ext;
    }
    // Never save something the web server could execute.
    if (preg_match('/\.(php\d*|phtml|phar|pht|phps|cgi|pl|py|sh|shtml|asp|aspx|jsp)$/i', $name)) {
        $name .= '.txt';
    }
    return $name;
}

function extend_time(int $seconds): void
{
    if (function_exists('set_time_limit')) {
        @set_time_limit($seconds);
    }
}

function human_size(float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $i ? 1 : 0) . ' ' . $units[$i];
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect_self(): void
{
    header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'), true, 303);
    exit;
}

// -------------------------------------------------------------------- pages --

function render_head(string $title): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0' stop-color='%236366f1'/%3E%3Cstop offset='1' stop-color='%2306b6d4'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='24' height='24' rx='6' fill='url(%23g)'/%3E%3Cpath d='M12 6v9m0 0-3.5-3.5M12 15l3.5-3.5M7 18h10' stroke='white' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
<style>
:root{
  --bg:#f3f5fb;--surface:#ffffff;--surface-2:#f5f7fc;--border:#e2e7f0;--text:#0f172a;--muted:#64748b;
  --accent:#6366f1;--accent-2:#06b6d4;--accent-3:#8b5cf6;--ok:#10b981;--ok-2:#34d399;--warn:#f59e0b;--warn-2:#fbbf24;
  --err:#ef4444;--err-2:#f87171;--track:#e7ebf3;--shadow:0 1px 2px rgba(15,23,42,.04),0 8px 28px -12px rgba(15,23,42,.12);
  --glow-1:rgba(99,102,241,.18);--glow-2:rgba(6,182,212,.14);--chip:#eef0ff;
  color-scheme:light;
}
@media (prefers-color-scheme:dark){
  :root{
    --bg:#0a0f1d;--surface:#111827;--surface-2:#0f1626;--border:#1f2a40;--text:#e5e9f2;--muted:#8a96ad;
    --track:#1b2438;--shadow:0 1px 2px rgba(0,0,0,.3),0 12px 32px -14px rgba(0,0,0,.6);
    --glow-1:rgba(99,102,241,.22);--glow-2:rgba(6,182,212,.14);--chip:#1d2340;
    color-scheme:dark;
  }
}
*{box-sizing:border-box}
html,body{margin:0}
body{
  background:var(--bg);color:var(--text);min-height:100vh;
  font:15px/1.5 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Vazirmatn",Tahoma,sans-serif;
  -webkit-font-smoothing:antialiased;
}
body::before{
  content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;
  background:radial-gradient(700px 380px at 12% -8%,var(--glow-1),transparent 70%),
             radial-gradient(640px 360px at 95% 0%,var(--glow-2),transparent 70%);
}
a{color:var(--accent)}
code{font:13px ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;background:var(--surface-2);border:1px solid var(--border);padding:1px 6px;border-radius:6px;word-break:break-all}
.wrap{max-width:880px;margin:0 auto;padding:32px 16px 64px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow)}
.btn{
  --b:var(--surface-2);display:inline-flex;align-items:center;justify-content:center;gap:8px;height:40px;padding:0 16px;
  border-radius:11px;border:1px solid var(--border);background:var(--b);color:var(--text);font:inherit;font-weight:600;font-size:14px;
  cursor:pointer;text-decoration:none;white-space:nowrap;transition:transform .12s,box-shadow .2s,background .2s,opacity .2s;
}
.btn:hover{transform:translateY(-1px)}
.btn:active{transform:none}
.btn:disabled{opacity:.6;cursor:default;transform:none}
.btn.primary{border:0;color:#fff;background:linear-gradient(135deg,var(--accent),var(--accent-3) 55%,var(--accent-2));box-shadow:0 8px 20px -8px rgba(99,102,241,.7)}
.btn.primary:hover{box-shadow:0 10px 26px -8px rgba(99,102,241,.85)}
.btn.danger{color:var(--err)}
.btn.sm{height:32px;padding:0 12px;font-size:13px;border-radius:9px}
.btn svg{width:16px;height:16px;flex:none}
.spinner{width:16px;height:16px;border-radius:50%;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
input,textarea{
  width:100%;font:inherit;color:var(--text);background:var(--surface-2);border:1px solid var(--border);border-radius:11px;
  padding:10px 12px;outline:none;transition:border-color .2s,box-shadow .2s;
}
input:focus,textarea:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(99,102,241,.16)}
textarea{resize:vertical;font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
input[type=checkbox]{width:16px;height:16px;padding:0;margin:0;accent-color:var(--accent)}
.muted{color:var(--muted)}
.brand{display:flex;align-items:center;gap:14px}
.logo{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;flex:none;color:#fff;
  background:linear-gradient(135deg,var(--accent),var(--accent-3) 55%,var(--accent-2));box-shadow:0 10px 24px -10px rgba(99,102,241,.8)}
.logo svg{width:24px;height:24px}
h1{font-size:22px;line-height:1.2;margin:0;letter-spacing:-.01em}
h2{font-size:15px;margin:0;letter-spacing:.01em}
.sub{margin:2px 0 0;color:var(--muted);font-size:14px}
</style>
<?php
}

function render_message_page(string $title, string $html): void
{
    render_head($title);
    ?>
</head>
<body>
<main class="wrap" style="max-width:520px;padding-top:12vh">
  <div class="card" style="padding:28px">
    <div class="brand" style="margin-bottom:16px"><div class="logo"><?= icon('download') ?></div><h1><?= h($title) ?></h1></div>
    <p class="muted" style="margin:0"><?= $html ?></p>
  </div>
</main>
</body>
</html>
<?php
    exit;
}

function render_login(?string $error): void
{
    render_head('Remote Downloader — Log in');
    ?>
</head>
<body>
<main class="wrap" style="max-width:420px;padding-top:14vh">
  <form class="card" method="post" style="padding:28px">
    <div class="brand" style="margin-bottom:22px">
      <div class="logo"><?= icon('download') ?></div>
      <div><h1>Remote Downloader</h1><p class="sub">Log in to continue</p></div>
    </div>
    <input type="hidden" name="do" value="login">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <input type="password" name="password" placeholder="Password" autofocus required autocomplete="current-password">
    <?php if ($error): ?><p style="color:var(--err);margin:10px 0 0;font-size:14px"><?= h($error) ?></p><?php endif; ?>
    <button class="btn primary" style="width:100%;margin-top:16px">Log in</button>
  </form>
</main>
</body>
</html>
<?php
    exit;
}

function icon(string $name): string
{
    $paths = [
        'download' => '<path d="M12 4v11m0 0-4-4m4 4 4-4M5 19h14"/>',
        'link'     => '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/>',
        'refresh'  => '<path d="M20 11a8 8 0 0 0-14.3-4.9L4 8m0-4v4h4M4 13a8 8 0 0 0 14.3 4.9L20 16m0 4v-4h-4"/>',
        'disk'     => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'logout'   => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l-5-5 5-5M5 12h11"/>',
        'sliders'  => '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

function render_app(): void
{
    $setupError = null;
    try {
        ensure_dirs();
    } catch (Throwable $e) {
        $setupError = $e->getMessage();
    }
    render_head('Remote Downloader');
    ?>
<style>
.top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px;flex-wrap:wrap}
.top-right{display:flex;align-items:center;gap:10px}
.pill{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 12px;border-radius:999px;background:var(--surface);border:1px solid var(--border);color:var(--muted);font-size:13px;font-weight:500}
.pill svg{width:15px;height:15px}
.new{padding:20px}
.label{display:block;font-size:13px;font-weight:600;color:var(--muted);margin:0 0 8px}
.url-row{display:flex;gap:10px}
.input-icon{position:relative;flex:1;min-width:0}
.input-icon svg{position:absolute;left:12px;top:50%;width:18px;height:18px;transform:translateY(-50%);color:var(--muted);pointer-events:none}
.input-icon input{padding-left:40px;height:46px}
.url-row .btn{height:46px;padding:0 20px}
details.opts{margin-top:14px}
details.opts summary{display:inline-flex;align-items:center;gap:6px;cursor:pointer;color:var(--muted);font-size:13px;font-weight:600;list-style:none;user-select:none}
details.opts summary::-webkit-details-marker{display:none}
details.opts summary svg{width:15px;height:15px}
details.opts[open] summary{color:var(--text)}
.opts-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px}
.opts-grid .full{grid-column:1/-1}
.check{display:flex;align-items:flex-start;gap:10px;font-size:14px;cursor:pointer;padding:10px 12px;border:1px solid var(--border);border-radius:11px;background:var(--surface-2)}
.check input{margin-top:3px}
.check small{display:block;color:var(--muted);font-size:12.5px}
.section-head{display:flex;align-items:center;justify-content:space-between;margin:30px 4px 12px}
.count{font-size:12px;font-weight:600;color:var(--muted);background:var(--surface);border:1px solid var(--border);border-radius:999px;padding:1px 9px;margin-left:8px}
.empty{padding:28px;text-align:center;color:var(--muted);font-size:14px;border:1.5px dashed var(--border);border-radius:18px}
.alert{padding:12px 16px;border-radius:12px;margin-bottom:16px;font-size:14px;background:rgba(239,68,68,.1);color:var(--err);border:1px solid rgba(239,68,68,.25)}

/* transfer card */
.jobs{display:grid;grid-template-columns:minmax(0,1fr);gap:14px}
.job{min-width:0;padding:18px 20px;animation:pop .35s cubic-bezier(.22,1,.36,1)}
@keyframes pop{from{opacity:0;transform:translateY(8px) scale(.99)}}
.job-head{display:flex;align-items:center;gap:14px}
.ext{width:44px;height:44px;border-radius:12px;flex:none;display:grid;place-items:center;font-size:11px;font-weight:800;letter-spacing:.03em;
  color:var(--accent);background:var(--chip);text-transform:uppercase;overflow:hidden}
.job-title{flex:1;min-width:0}
.job-name{font-weight:650;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.job-sub{font-size:13px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.badge{flex:none;display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;padding:4px 10px;border-radius:999px;
  color:var(--accent);background:rgba(99,102,241,.12)}
.badge::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}
.job[data-state=downloading] .badge::before,.job[data-state=queued] .badge::before{animation:blink 1.2s ease-in-out infinite}
@keyframes blink{50%{opacity:.25}}
.job[data-state=done] .badge{color:var(--ok);background:rgba(16,185,129,.13)}
.job[data-state=paused] .badge,.job[data-state=stopping] .badge,.job[data-state=interrupted] .badge,.job[data-state=retrying] .badge{color:var(--warn);background:rgba(245,158,11,.14)}
.job[data-state=error] .badge{color:var(--err);background:rgba(239,68,68,.12)}
.job[data-state=cancelled] .badge{color:var(--muted);background:var(--surface-2)}

.progress-row{display:flex;align-items:center;gap:14px;margin:16px 0 12px}
.progress{position:relative;flex:1;height:12px;border-radius:999px;background:var(--track);overflow:hidden}
.bar{position:absolute;top:0;bottom:0;left:0;width:0;border-radius:inherit;overflow:hidden;
  background:linear-gradient(90deg,var(--accent),var(--accent-3),var(--accent-2),var(--accent));background-size:300% 100%;
  box-shadow:0 0 16px rgba(99,102,241,.55);transition:width .7s cubic-bezier(.22,1,.36,1),background .3s}
.bar::after{content:"";position:absolute;inset:0;opacity:0;transition:opacity .3s;
  background:repeating-linear-gradient(-45deg,rgba(255,255,255,.22) 0 8px,transparent 8px 16px);background-size:22.6px 22.6px;animation:stripes .8s linear infinite}
.job[data-state=downloading] .bar,.job[data-state=queued] .bar{animation:flow 3s linear infinite}
.job[data-state=downloading] .bar::after,.job[data-state=queued] .bar::after{opacity:1}
@keyframes flow{to{background-position:-300% 0}}
@keyframes stripes{to{background-position:22.6px 0}}
.job[data-indeterminate][data-state=downloading] .bar{width:30%!important;animation:flow 3s linear infinite,indet 1.5s ease-in-out infinite;transition:none}
@keyframes indet{from{left:-30%}to{left:100%}}
.job[data-state=done] .bar{background:linear-gradient(90deg,var(--ok),var(--ok-2));box-shadow:0 0 14px rgba(16,185,129,.45)}
.job[data-state=paused] .bar,.job[data-state=stopping] .bar,.job[data-state=interrupted] .bar,.job[data-state=retrying] .bar{background:linear-gradient(90deg,var(--warn),var(--warn-2));box-shadow:none}
.job[data-state=error] .bar{background:linear-gradient(90deg,var(--err),var(--err-2));box-shadow:none}
.job[data-state=cancelled] .bar{background:var(--muted);box-shadow:none;opacity:.4}
.pct{flex:none;min-width:64px;text-align:right;font-size:22px;font-weight:750;letter-spacing:-.02em;font-variant-numeric:tabular-nums}

.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.stat{background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:8px 12px;min-width:0}
.stat span{display:block;font-size:11.5px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.stat b{display:block;font-size:14.5px;font-weight:650;font-variant-numeric:tabular-nums;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.job-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:14px;flex-wrap:wrap}
.note{font-size:13px;color:var(--muted);min-width:0;flex:1}
.job[data-state=error] .note{color:var(--err)}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.job[data-state=done] .stats,.job[data-state=cancelled] .stats{display:none}

/* files */
.files{overflow:hidden}
.file{display:flex;align-items:center;gap:12px;padding:12px 16px;border-top:1px solid var(--border)}
.file:first-child{border-top:0}
.file .ext{width:36px;height:36px;border-radius:10px;font-size:10px}
.file-main{flex:1;min-width:0}
.file-name{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.file-name a{color:inherit;text-decoration:none}
.file-name a:hover{color:var(--accent)}
.file-meta{font-size:12.5px;color:var(--muted)}
.icon-btn{width:34px;height:34px;padding:0}
.icon-btn svg{width:16px;height:16px}
footer{margin-top:28px;text-align:center;font-size:12.5px;color:var(--muted)}

/* toasts */
.toasts{position:fixed;right:16px;bottom:16px;display:grid;gap:10px;z-index:50;max-width:min(380px,calc(100vw - 32px))}
.toast{padding:12px 14px 12px 16px;border-radius:13px;background:var(--surface);border:1px solid var(--border);box-shadow:var(--shadow);font-size:14px;
  border-left:4px solid var(--accent);animation:pop .3s cubic-bezier(.22,1,.36,1)}
.toast.error{border-left-color:var(--err)}
.toast.ok{border-left-color:var(--ok)}
.toast.warn{border-left-color:var(--warn)}
.toast.hide{opacity:0;transform:translateY(6px);transition:.3s}

@media (max-width:620px){
  .url-row{flex-direction:column}
  .opts-grid{grid-template-columns:1fr}
  .stats{grid-template-columns:1fr 1fr}
  .stats .stat:first-child{grid-column:1/-1}
  .pct{font-size:18px;min-width:54px}
  .top .sub{display:none}
}
@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important}}
</style>
</head>
<body>
<main class="wrap">
  <header class="top">
    <div class="brand">
      <div class="logo"><?= icon('download') ?></div>
      <div><h1>Remote Downloader</h1><p class="sub">Fetch big files straight to your server — no timeouts.</p></div>
    </div>
    <div class="top-right">
      <span class="pill" id="disk" title="Free disk space"><?= icon('disk') ?><span>…</span></span>
      <form method="post" style="margin:0">
        <input type="hidden" name="do" value="logout">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <button class="btn sm" title="Log out"><?= icon('logout') ?>Log out</button>
      </form>
    </div>
  </header>

  <?php if ($setupError): ?><div class="alert"><?= h($setupError) ?></div><?php endif; ?>

  <form class="card new" id="addForm" autocomplete="off">
    <label class="label" for="url">File URL</label>
    <div class="url-row">
      <div class="input-icon"><?= icon('link') ?><input id="url" type="text" inputmode="url" placeholder="https://example.com/big-file.zip" required spellcheck="false"></div>
      <button class="btn primary" id="addBtn"><?= icon('download') ?><span>Download to server</span></button>
    </div>
    <details class="opts">
      <summary><?= icon('sliders') ?>Options</summary>
      <div class="opts-grid">
        <div>
          <label class="label" for="name">Save as</label>
          <input id="name" type="text" placeholder="Detected automatically">
        </div>
        <div>
          <label class="label" for="headers">Extra request headers <span class="muted" style="font-weight:400">(optional)</span></label>
          <textarea id="headers" rows="2" spellcheck="false" placeholder="Cookie: session=abc&#10;Referer: https://example.com/"></textarea>
        </div>
        <label class="check"><input type="checkbox" id="bg"><span>Keep downloading after I close this page<small>Runs as a background process on the server. Some hosts stop long processes — if so, just press Resume.</small></span></label>
        <label class="check"><input type="checkbox" id="insecure"><span>Ignore SSL certificate errors<small>Only for servers with a broken or self-signed certificate.</small></span></label>
      </div>
    </details>
  </form>

  <div class="section-head"><h2>Transfers<span class="count" id="jobCount">0</span></h2></div>
  <div class="jobs" id="jobs"><div class="empty" id="jobsEmpty">No transfers yet — paste a link above to start.</div></div>

  <div class="section-head">
    <h2>Files on server<span class="count" id="fileCount">0</span></h2>
    <button class="btn sm" id="refreshFiles" type="button"><?= icon('refresh') ?>Refresh</button>
  </div>
  <div class="card files" id="files"><div class="empty" style="border:0">No files yet.</div></div>

  <footer>Files are saved to <code><?= h(DOWNLOAD_DIR) ?></code></footer>
</main>
<div class="toasts" id="toasts" aria-live="polite"></div>

<script>
(() => {
  'use strict';
  const CSRF = <?= json_encode($_SESSION['csrf']) ?>;
  const MAX_RETRIES = 8;
  const $ = (s) => document.querySelector(s);
  const jobsEl = $('#jobs');

  const jobs = new Map();     // id -> job (as last reported by the server)
  const cards = new Map();    // id -> card element
  const driving = new Set();  // ids this tab is downloading slice by slice
  const stopping = new Map(); // id -> 'pause' | 'cancel' while waiting for the server to stop
  const samples = new Map();  // id -> [{t, b}] for the speed estimate
  const notes = new Map();    // id -> client-side status note
  let pollTimer = null, polling = false, firstLoad = true;

  class ApiError extends Error {}
  class NetError extends Error {}

  async function api(action, data = {}, signal) {
    let res;
    try {
      res = await fetch('?action=' + encodeURIComponent(action), {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', signal,
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(Object.assign({_csrf: CSRF}, data)),
      });
    } catch (e) {
      if (e.name === 'AbortError') throw e;
      throw new NetError('Connection problem');
    }
    const text = await res.text();
    let json;
    try { json = JSON.parse(text); } catch (e) { throw new NetError('Unexpected server reply (HTTP ' + res.status + ')'); }
    if (res.status === 401) { setTimeout(() => location.reload(), 1500); }
    if (!json.ok) throw new ApiError(json.error || 'Request failed.');
    return json;
  }

  // ------------------------------------------------------------ formatting
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  function fmtBytes(n) {
    if (n == null || n < 0) return '—';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i === 0 ? n : n.toFixed(n < 10 ? 2 : n < 100 ? 1 : 0)) + ' ' + u[i];
  }
  function fmtDur(s) {
    if (!isFinite(s) || s < 0) return '—';
    s = Math.round(s);
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return h ? `${h}h ${m}m` : m ? `${m}m ${sec}s` : `${sec}s`;
  }
  function fmtDate(ts) {
    return new Date(ts * 1000).toLocaleString(undefined, {dateStyle: 'medium', timeStyle: 'short'});
  }
  function extOf(name) {
    const m = /\.([a-z0-9]{1,5})$/i.exec(name || '');
    return m ? m[1] : 'file';
  }
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  // ------------------------------------------------------------ speed / state
  function track(job) {
    const now = performance.now();
    let arr = samples.get(job.id) || [];
    const last = arr[arr.length - 1];
    if (last && job.downloaded < last.b) arr = []; // restarted from zero
    if (!last || job.downloaded !== last.b || now - last.t > 800) arr.push({t: now, b: job.downloaded});
    while (arr.length > 2 && now - arr[0].t > 6000) arr.shift();
    samples.set(job.id, arr);
  }
  function speedOf(id) {
    const a = samples.get(id);
    if (!a || a.length < 2) return null;
    const f = a[0], l = a[a.length - 1], dt = (l.t - f.t) / 1000;
    return dt >= 0.8 ? Math.max(0, (l.b - f.b) / dt) : null;
  }
  function stateOf(job) {
    const s = job.status;
    if (driving.has(job.id)) return stopping.has(job.id) ? 'stopping' : (s === 'error' ? 'retrying' : 'downloading');
    if (s === 'downloading' && job.active && stopping.has(job.id)) return 'stopping';
    if (s === 'downloading' && !job.active) return 'interrupted';
    if (s === 'queued' && !job.active) return 'queued';
    return s;
  }
  const LABELS = {
    queued: 'Starting', downloading: 'Downloading', stopping: 'Stopping', retrying: 'Reconnecting',
    paused: 'Paused', interrupted: 'Interrupted', done: 'Completed', error: 'Failed', cancelled: 'Cancelled',
  };

  // ------------------------------------------------------------ rendering
  function cardFor(job, atTop) {
    let el = cards.get(job.id);
    if (el) return el;
    el = document.createElement('article');
    el.className = 'card job';
    el.dataset.id = job.id;
    el.innerHTML = `
      <div class="job-head">
        <div class="ext"></div>
        <div class="job-title"><div class="job-name"></div><div class="job-sub"></div></div>
        <span class="badge"></span>
      </div>
      <div class="progress-row">
        <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100"><div class="bar"></div></div>
        <div class="pct">0%</div>
      </div>
      <div class="stats">
        <div class="stat"><span>Downloaded</span><b class="v-size">—</b></div>
        <div class="stat"><span>Speed</span><b class="v-speed">—</b></div>
        <div class="stat"><span>Time left</span><b class="v-eta">—</b></div>
      </div>
      <div class="job-foot"><div class="note"></div><div class="actions"></div></div>`;
    cards.set(job.id, el);
    $('#jobsEmpty')?.remove();
    if (atTop) jobsEl.prepend(el); else jobsEl.append(el);
    return el;
  }

  function render(job, atTop = true) {
    const el = cardFor(job, atTop);
    const st = stateOf(job);
    const known = job.total > 0;
    const pct = job.status === 'done' ? 100 : known ? Math.min(100, (job.downloaded / job.total) * 100) : 0;
    const speed = (st === 'downloading') ? speedOf(job.id) : null;

    el.dataset.state = st;
    el.toggleAttribute('data-indeterminate', !known && job.status !== 'done');
    el.querySelector('.ext').textContent = extOf(job.name);
    el.querySelector('.job-name').textContent = job.name;
    el.querySelector('.job-name').title = job.name;
    el.querySelector('.job-sub').textContent = [job.host, job.ranges ? 'Resumable' : 'Not resumable',
      job.mode === 'background' ? 'Background' : 'In this tab'].join(' · ');
    el.querySelector('.job-sub').title = job.url;
    el.querySelector('.badge').textContent = st === 'stopping' ? (stopping.get(job.id) === 'cancel' ? 'Cancelling' : 'Pausing') : (LABELS[st] || st);
    el.querySelector('.bar').style.width = (known || job.status === 'done' ? pct : 0).toFixed(2) + '%';
    el.querySelector('.progress').setAttribute('aria-valuenow', pct.toFixed(0));
    el.querySelector('.pct').textContent = known || job.status === 'done' ? (pct >= 99.95 || pct === 0 ? pct.toFixed(0) : pct.toFixed(1)) + '%' : '—';
    el.querySelector('.v-size').textContent = fmtBytes(job.downloaded) + (known ? ' / ' + fmtBytes(job.total) : '');
    el.querySelector('.v-speed').textContent = speed == null ? '—' : fmtBytes(speed) + '/s';
    el.querySelector('.v-eta').textContent = speed > 0 && known ? fmtDur((job.total - job.downloaded) / speed) : '—';

    let note = notes.get(job.id) || '';
    if (!note) {
      if (st === 'done') note = `Saved as ${job.name} · ${fmtBytes(job.total)}` + (job.finished ? ' · ' + fmtDate(job.finished) : '');
      else if (st === 'error' && job.error) note = job.error;
      else if (st === 'interrupted') note = job.ranges ? 'The download stopped (page closed or the server ended the process). Press Resume to continue where it left off.' : 'The download stopped. Press Restart to try again.';
      else if (st === 'paused') note = 'Paused — the partial file is kept on the server.';
      else if (st === 'downloading' && job.mode === 'background') note = 'Running on the server — you can close this page.';
      else if (st === 'downloading') note = 'Keep this page open while downloading, or pause and resume later.';
    }
    el.querySelector('.note').textContent = note;

    const sig = st + '|' + stopping.get(job.id) + '|' + job.ranges + '|' + !!job.link;
    if (el._sig !== sig) {
      el._sig = sig;
      el.querySelector('.actions').innerHTML = actionsFor(job, st);
    }
    updateTitle();
  }

  function actionsFor(job, st) {
    const b = (act, label, cls = '') => `<button type="button" class="btn sm ${cls}" data-act="${act}">${label}</button>`;
    switch (st) {
      case 'done':
        return (job.link ? `<a class="btn sm primary" href="${esc(job.link)}" download>Download to my computer</a>` : '') + b('remove', 'Clear');
      case 'downloading': case 'retrying': case 'queued':
        return (job.ranges ? b('pause', 'Pause') : '') + b('cancel', 'Cancel', 'danger');
      case 'stopping':
        return stopping.get(job.id) === 'pause' ? b('cancel', 'Cancel', 'danger') : '';
      case 'paused': case 'interrupted': case 'error':
        return (job.ranges ? b('resume', 'Resume', 'primary') : b('resume', 'Restart', 'primary')) + b('discard', 'Discard', 'danger');
      case 'cancelled':
        return b('remove', 'Clear');
    }
    return '';
  }

  function upsert(job, atTop = true) {
    const prev = jobs.get(job.id);
    jobs.set(job.id, job);
    if (job.status !== 'downloading' && !driving.has(job.id)) stopping.delete(job.id);
    if (job.status === 'downloading' || job.status === 'queued') track(job);
    if (prev && prev.status !== 'done' && job.status === 'done') {
      notes.delete(job.id);
      toast(`Finished: ${job.name}`, 'ok');
      loadFiles();
    }
    render(job, atTop);
    $('#jobCount').textContent = jobs.size;
  }

  function dropCard(id) {
    cards.get(id)?.remove();
    cards.delete(id); jobs.delete(id); samples.delete(id); notes.delete(id);
    $('#jobCount').textContent = jobs.size;
    if (!jobs.size) jobsEl.innerHTML = '<div class="empty" id="jobsEmpty">No transfers yet — paste a link above to start.</div>';
    updateTitle();
  }

  function setNote(id, text) {
    if (text) notes.set(id, text); else notes.delete(id);
    const job = jobs.get(id);
    if (job) render(job);
  }

  function updateTitle() {
    const active = [...jobs.values()].filter((j) => ['downloading', 'stopping', 'retrying'].includes(stateOf(j)) && j.total > 0);
    if (!active.length) { document.title = 'Remote Downloader'; return; }
    const done = active.reduce((a, j) => a + j.downloaded, 0), total = active.reduce((a, j) => a + j.total, 0);
    document.title = Math.floor((done / total) * 100) + '% · Remote Downloader';
  }

  function toast(msg, type = 'info', ms = 5000) {
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.textContent = msg;
    $('#toasts').append(t);
    setTimeout(() => { t.classList.add('hide'); setTimeout(() => t.remove(), 300); }, ms);
  }

  // ------------------------------------------------------------ polling
  function needsPoll() {
    for (const j of jobs.values()) if (driving.has(j.id) || j.active || j.status === 'queued') return true;
    return false;
  }
  function schedulePoll(ms = 1000) {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(poll, ms);
  }
  async function poll() {
    if (polling) return;
    polling = true;
    try {
      const r = await api('jobs');
      const seen = new Set();
      const list = firstLoad ? r.jobs : [...r.jobs].reverse();
      for (const j of list) { seen.add(j.id); upsert(j, !firstLoad); }
      for (const id of [...jobs.keys()]) if (!seen.has(id) && !driving.has(id)) dropCard(id);
      setDisk(r.free);
      firstLoad = false;
    } catch (e) { /* the next poll will try again */ }
    finally {
      polling = false;
      if (needsPoll()) schedulePoll(document.hidden ? 3000 : 1000);
    }
  }
  function setDisk(free) {
    $('#disk span').textContent = free == null ? 'Disk: n/a' : fmtBytes(free) + ' free';
  }

  // ------------------------------------------------------------ downloading
  function start(job) {
    stopping.delete(job.id);
    if (job.mode === 'chunked' && job.ranges) drive(job.id);
    else startBackground(job.id);
  }

  // Downloads slice after slice; every request finishes within CHUNK_SECONDS, so nothing times out.
  async function drive(id) {
    if (driving.has(id)) return;
    driving.add(id); stopping.delete(id); notes.delete(id);
    const job0 = jobs.get(id); if (job0) render(job0);
    schedulePoll(800);
    let resume = true, failures = 0, busy = 0;
    try {
      for (;;) {
        let r;
        try {
          r = await api('chunk', {id, resume});
        } catch (e) {
          if (e instanceof ApiError) { setNote(id, ''); toast(e.message, 'error'); break; }
          if (++failures > MAX_RETRIES) { setNote(id, ''); toast('Gave up after several connection problems. Press Resume to try again.', 'error'); break; }
          await countdown(id, failures, e.message);
          continue;
        }
        resume = false;
        if (r.busy) { // a previous request is still finishing on the server
          if (++busy > 60) break;
          await sleep(1500);
          continue;
        }
        busy = 0;
        upsert(r.job);
        const s = r.job.status;
        if (s === 'downloading') { if (failures) setNote(id, ''); failures = 0; continue; }
        if (s === 'error' && r.job.retryable && ++failures <= MAX_RETRIES) { await countdown(id, failures, r.job.error); continue; }
        break;
      }
    } finally {
      driving.delete(id); stopping.delete(id); notes.delete(id);
      const j = jobs.get(id); if (j) render(j);
      poll();
    }
  }

  async function countdown(id, attempt, why) {
    const secs = Math.min(30, 2 ** attempt);
    for (let s = secs; s > 0; s--) {
      if (jobs.get(id)?.status === 'paused' || !jobs.has(id)) return;
      setNote(id, `${why} — retrying in ${s}s (attempt ${attempt}/${MAX_RETRIES})…`);
      await sleep(1000);
    }
    setNote(id, 'Reconnecting…');
  }

  async function startBackground(id) {
    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), 10000); // the server keeps working even if this reply never arrives
    schedulePoll(1200);
    try {
      const r = await api('start_bg', {id}, ctl.signal);
      upsert(r.job);
    } catch (e) {
      if (e.name !== 'AbortError') toast(e.message, 'error');
    } finally {
      clearTimeout(timer);
      schedulePoll(1000);
    }
  }

  // ------------------------------------------------------------ actions
  jobsEl.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const id = btn.closest('.job').dataset.id, job = jobs.get(id), act = btn.dataset.act;
    if (!job) return;
    try {
      if (act === 'pause') {
        stopping.set(id, 'pause'); render(job);
        upsert((await api('pause', {id})).job);
        schedulePoll(500);
      } else if (act === 'resume') {
        start(job);
      } else if (act === 'cancel') {
        if (!confirm('Cancel this download and delete the partial file?')) return;
        stopping.set(id, 'cancel'); render(job);
        upsert((await api('cancel', {id})).job);
        schedulePoll(500);
      } else if (act === 'discard') {
        if (!confirm('Delete the partial file and remove this download?')) return;
        await api('remove', {id});
        dropCard(id);
      } else if (act === 'remove') {
        await api('remove', {id});
        dropCard(id);
      }
    } catch (err) {
      stopping.delete(id); render(job);
      toast(err.message, 'error');
    }
  });

  $('#addForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const url = $('#url').value.trim();
    if (!url) return;
    const btn = $('#addBtn'), label = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span><span>Checking link…</span>';
    try {
      const bg = $('#bg').checked;
      const r = await api('probe', {url, name: $('#name').value.trim(), headers: $('#headers').value, insecure: $('#insecure').checked, background: bg});
      upsert(r.job);
      $('#url').value = ''; $('#name').value = '';
      if (/text\/html/i.test(r.job.content_type || '')) toast('Heads-up: this link returned a web page (text/html), not a file. If the result looks wrong, use the direct download link.', 'warn', 9000);
      if (!r.job.ranges && !bg) toast("This server doesn't support resuming, so the file is downloaded by a background process on your server.", 'info', 7000);
      start(r.job);
    } catch (err) {
      toast(err.message, 'error', 7000);
    } finally {
      btn.disabled = false;
      btn.innerHTML = label;
    }
  });

  // ------------------------------------------------------------ files
  async function loadFiles() {
    try { renderFiles(await api('files')); } catch (e) { toast(e.message, 'error'); }
  }
  function renderFiles(r) {
    setDisk(r.free);
    $('#fileCount').textContent = r.files.length;
    const box = $('#files');
    if (!r.files.length) { box.innerHTML = '<div class="empty" style="border:0">No files yet.</div>'; return; }
    box.innerHTML = r.files.map((f) => `
      <div class="file">
        <div class="ext">${esc(extOf(f.name))}</div>
        <div class="file-main">
          <div class="file-name" title="${esc(f.name)}">${f.link ? `<a href="${esc(f.link)}" download>${esc(f.name)}</a>` : esc(f.name)}</div>
          <div class="file-meta">${fmtBytes(f.size)} · ${fmtDate(f.mtime)}</div>
        </div>
        ${f.link ? `<a class="btn sm icon-btn" href="${esc(f.link)}" download title="Download to my computer"><?= icon('download') ?></a>` : ''}
        <button type="button" class="btn sm danger" data-del="${esc(f.name)}">Delete</button>
      </div>`).join('');
  }
  $('#files').addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-del]');
    if (!btn) return;
    const name = btn.dataset.del;
    if (!confirm(`Delete "${name}" from the server?`)) return;
    try { renderFiles(await api('delete_file', {name})); toast('Deleted ' + name); } catch (err) { toast(err.message, 'error'); }
  });
  $('#refreshFiles').addEventListener('click', loadFiles);

  window.addEventListener('beforeunload', (e) => {
    if (driving.size) { e.preventDefault(); e.returnValue = ''; }
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden && needsPoll()) schedulePoll(100); });

  poll();
  loadFiles();
})();
</script>
</body>
</html>
<?php
    exit;
}
