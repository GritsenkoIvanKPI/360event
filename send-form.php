<?php
/**
 * Application-form endpoint — takes a submission from the #request form on
 * index.html (webinar «Перевірка 360°») and forwards it to Telegram.
 *
 * The bot token lives in config.php (git-ignored, never served to the browser).
 * The page only ever talks to this script.
 *
 *   browser  ->  send-form.php  ->  api.telegram.org
 *                     ^
 *                config.php (token, never committed)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// Kyiv time, so the timestamp in Telegram matches the organisers' clock.
@date_default_timezone_set('Europe/Kiev');

// A fatal (missing extension, unparsable config) would otherwise return an empty
// 500 that tells nobody anything. Turn it into JSON and a log line.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    error_log('send-form fatal: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
});

/** Send a JSON response and stop. */
function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- self-test ---------- */

// GET /send-form.php?selftest=1 reports whether this server can run the endpoint.
// It never prints the token — only whether one is present and how long it is.
if (isset($_GET['selftest'])) {
    $cfgPath = __DIR__ . '/config.php';
    $cfg     = is_file($cfgPath) ? @require $cfgPath : null;

    respond(200, [
        'ok'              => true,
        'php'             => PHP_VERSION,
        'php_ok'          => version_compare(PHP_VERSION, '7.4', '>='),
        'config_present'  => is_file($cfgPath),
        'config_is_array' => is_array($cfg),
        'token_present'   => is_array($cfg) && !empty($cfg['bot_token']),
        'token_length'    => is_array($cfg) ? strlen((string)($cfg['bot_token'] ?? '')) : 0,
        'chat_id'         => is_array($cfg) ? (string)($cfg['chat_id'] ?? '') : '',
        'ext_curl'        => function_exists('curl_init'),
        'allow_url_fopen' => (bool)ini_get('allow_url_fopen'),
        'can_send'        => function_exists('curl_init') || (bool)ini_get('allow_url_fopen'),
        'ext_mbstring'    => function_exists('mb_substr'),
        'ext_json'        => function_exists('json_encode'),
        'can_write_tmp'   => is_writable(sys_get_temp_dir()),
    ]);
}

/* ---------- method ---------- */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

/* ---------- config ---------- */

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    error_log('send-form: config.php is missing');
    respond(500, ['ok' => false, 'error' => 'server_not_configured']);
}

$config = require $configPath;
if (!is_array($config)) {
    error_log('send-form: config.php did not return an array');
    respond(500, ['ok' => false, 'error' => 'server_not_configured']);
}

$token  = trim((string)($config['bot_token'] ?? ''));
$chatId = trim((string)($config['chat_id'] ?? ''));

if ($token === '' || $chatId === '' || strpos($token, 'PASTE') === 0 || strpos($chatId, 'PASTE') === 0) {
    error_log('send-form: bot_token or chat_id not filled in');
    respond(500, ['ok' => false, 'error' => 'server_not_configured']);
}

/* ---------- input ---------- */

$raw   = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

/** Trim, collapse whitespace and cap a field's length. */
function field(array $src, string $key, int $max): string
{
    $value = (string)($src[$key] ?? '');
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[ \t]+/u', ' ', $value) ?? '';
    $value = trim($value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

$name    = field($input, 'name', 120);
$phone   = field($input, 'phone', 40);
$email   = field($input, 'email', 160);
$message = field($input, 'message', 1000);
$page    = field($input, 'page', 200);
$consent = !empty($input['consent']);
$trap     = field($input, 'website', 200); // honeypot: real people leave it empty

/* ---------- spam trap ---------- */

// A bot that fills every field gets a success response and nothing is sent, so it
// has no signal to retry against.
if ($trap !== '') {
    respond(200, ['ok' => true]);
}

/* ---------- validation ---------- */

$errors = [];

$nameLen = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
if ($nameLen < 2) {
    $errors['name'] = 'required';
}

// Phone is the one contact we always need; e-mail is optional but must be valid if given.
$digits = preg_replace('/\D+/', '', $phone) ?? '';
if (strlen($digits) < 9 || strlen($digits) > 15) {
    $errors['phone'] = 'invalid';
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'invalid';
}

if (!$consent) {
    $errors['consent'] = 'required';
}

// Header-injection guard — these values are echoed into an e-mail copy too.
if (preg_match('/[\r\n]/', $name . $phone . $email)) {
    $errors['name'] = 'invalid';
}

if ($errors) {
    respond(422, ['ok' => false, 'error' => 'validation_failed', 'fields' => $errors]);
}

/* ---------- rate limit ---------- */

$limit = (int)($config['rate_limit_per_hour'] ?? 5);
if ($limit > 0) {
    $ip     = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $bucket = sys_get_temp_dir() . '/webinar360-form-' . hash('sha256', $ip) . '.txt';
    $now    = time();
    $hits   = [];

    if (is_file($bucket)) {
        $stored = json_decode((string)file_get_contents($bucket), true);
        if (is_array($stored)) {
            // keep only the hits from the last hour
            $hits = array_values(array_filter(
                $stored,
                static fn($t) => is_int($t) && $t > $now - 3600
            ));
        }
    }

    if (count($hits) >= $limit) {
        respond(429, ['ok' => false, 'error' => 'rate_limited']);
    }

    $hits[] = $now;
    @file_put_contents($bucket, json_encode($hits), LOCK_EX);
}

/* ---------- compose ---------- */

/** Escape for Telegram's HTML parse mode. */
function tg(string $text): string
{
    return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$lines   = [];
$lines[] = '<b>🔔 Нова заявка — вебінар «Перевірка 360°»</b>';
$lines[] = '';
$lines[] = '<b>Імʼя:</b> ' . tg($name);
$lines[] = '<b>Телефон:</b> ' . tg($phone);

if ($email !== '') {
    $lines[] = '<b>Email:</b> ' . tg($email);
}

if ($message !== '') {
    $lines[] = '';
    $lines[] = '<b>Коментар:</b>';
    $lines[] = tg($message);
}

$lines[] = '';
$lines[] = '<i>360webinaruaoa.online · ' . tg(date('d.m.Y H:i')) . ' (Київ)</i>';

$text = implode("\n", $lines);

/* ---------- send ---------- */

$payload = [
    'chat_id'                  => $chatId,
    'text'                     => $text,
    'parse_mode'               => 'HTML',
    'disable_web_page_preview' => true,
];

/**
 * POST form-encoded data. Uses cURL when the extension exists and falls back to a
 * stream, because Hostinger's PHP 7.4 ships without ext-curl on some plans.
 *
 * @return array{body: ?string, status: int, error: string}
 */
function httpPost(string $url, array $fields): array
{
    $body = http_build_query($fields);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $response = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        return [
            'body'   => $response === false ? null : (string)$response,
            'status' => $status,
            'error'  => $error,
        ];
    }

    if (!ini_get('allow_url_fopen')) {
        return ['body' => null, 'status' => 0, 'error' => 'no curl and allow_url_fopen is off'];
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n"
                             . 'Content-Length: ' . strlen($body) . "\r\n",
            'content'       => $body,
            'timeout'       => 15,
            // read the body even on a 4xx so the API's reason can be logged
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    $status   = 0;

    // $http_response_header is defined in this scope by the stream wrapper
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }

    return [
        'body'   => $response === false ? null : (string)$response,
        'status' => $status,
        'error'  => $response === false ? 'stream request failed' : '',
    ];
}

$sent = httpPost('https://api.telegram.org/bot' . $token . '/sendMessage', $payload);

if ($sent['body'] === null || $sent['status'] !== 200) {
    // Log the reason for the operator, but never echo it — the API error text can
    // quote the request, and the token must not go anywhere near a browser response.
    error_log('send-form: telegram failed, http ' . $sent['status'] . ' ' . $sent['error']
        . ' ' . substr((string)$sent['body'], 0, 300));
    respond(502, ['ok' => false, 'error' => 'delivery_failed']);
}

$result = json_decode((string)$sent['body'], true);
if (!is_array($result) || empty($result['ok'])) {
    error_log('send-form: telegram rejected the message: ' . substr((string)$sent['body'], 0, 300));
    respond(502, ['ok' => false, 'error' => 'delivery_failed']);
}

/* ---------- optional e-mail copy ---------- */

$notify = trim((string)($config['notify_email'] ?? ''));
if ($notify !== '' && filter_var($notify, FILTER_VALIDATE_EMAIL)) {
    $body = "Імʼя: $name\nТелефон: $phone\nEmail: $email\nКоментар: $message\nСторінка: $page\n";
    @mail(
        $notify,
        'Нова заявка — вебінар «Перевірка 360°»',
        $body,
        "Content-Type: text/plain; charset=UTF-8\r\nFrom: web@" . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    );
}

respond(200, ['ok' => true]);
