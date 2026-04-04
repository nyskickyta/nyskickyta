<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Stockholm');

function load_env_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $trimmed, 2);
        $name = trim($name);
        $value = trim(trim($value), "\"'");

        if ($name === '' || getenv($name) !== false) {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

load_env_file(__DIR__ . '/.env');
load_env_file(__DIR__ . '/.env.local');
load_env_file(dirname(__DIR__) . '/.env');
load_env_file(dirname(__DIR__) . '/.env.local');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $httpsEnabled = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    );

    session_set_cookie_params([
        'httponly' => true,
        'secure' => $httpsEnabled,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

const ADMIN_DATA_FILE = __DIR__ . '/../data/admin-data.json';
const USER_ROLE_ADMIN = 'admin';
const USER_ROLE_SALES = 'sales';
const USER_ROLE_WORKER = 'worker';
const ORGANIZATION_TYPE_HQ = 'hq';
const ORGANIZATION_TYPE_REGIONAL_COMPANY = 'regional_company';
const ORGANIZATION_TYPE_FRANCHISE_UNIT = 'franchise_unit';

function env_value(string $name, string $default = ''): string
{
    $value = getenv($name);

    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
}

define('APP_ENV', env_value('APP_ENV', 'production'));

error_reporting(E_ALL);

if (defined('APP_ENV') && APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

function mysql_host(): string
{
    return env_value('MYSQL_HOST');
}

function mysql_port(): int
{
    return (int)env_value('MYSQL_PORT', '3306');
}

function mysql_database(): string
{
    return env_value('MYSQL_DATABASE');
}

function mysql_user(): string
{
    return env_value('MYSQL_USER');
}

function mysql_password(): string
{
    return env_value('MYSQL_PASSWORD');
}

function mysql_charset(): string
{
    return env_value('MYSQL_CHARSET', 'utf8mb4');
}

function fortnox_api_base_url(): string
{
    return rtrim(env_value('FORTNOX_API_BASE_URL', 'https://api.fortnox.se/3'), '/');
}

function fortnox_access_token(): string
{
    return env_value('FORTNOX_ACCESS_TOKEN');
}

function fortnox_client_secret(): string
{
    return env_value('FORTNOX_CLIENT_SECRET');
}

function use_mock_fortnox(): bool
{
    $value = getenv('USE_MOCK_FORTNOX');

    if (!is_string($value)) {
        return false;
    }

    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function fortnox_api_is_configured(): bool
{
    if (use_mock_fortnox()) {
        return true;
    }

    return fortnox_access_token() !== '' && fortnox_client_secret() !== '';
}

function fortnox_debug_preview_enabled(): bool
{
    $value = getenv('FORTNOX_DEBUG_PREVIEW');

    if (!is_string($value)) {
        return false;
    }

    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function require_pdo_connection(mixed $pdo): PDO
{
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Databasanslutning saknas.');
    }

    return $pdo;
}

function bolagsverket_api_url(): string
{
    $value = getenv('BOLAGSVERKET_API_URL');

    return is_string($value) ? trim($value) : '';
}

function bolagsverket_api_base_url(): string
{
    $value = getenv('BOLAGSVERKET_API_BASE_URL');

    return is_string($value) ? trim($value) : '';
}

function bolagsverket_token_url(): string
{
    $value = getenv('BOLAGSVERKET_TOKEN_URL');

    return is_string($value) ? trim($value) : '';
}

function bolagsverket_client_id(): string
{
    $value = getenv('BOLAGSVERKET_CLIENT_ID');

    return is_string($value) ? trim($value) : '';
}

function bolagsverket_client_secret(): string
{
    $value = getenv('BOLAGSVERKET_CLIENT_SECRET');

    return is_string($value) ? trim($value) : '';
}

function bolagsverket_scope(): string
{
    return env_value('BOLAGSVERKET_SCOPE', 'vardefulla-datamangder:read');
}

function bolagsverket_api_key(): string
{
    $value = getenv('BOLAGSVERKET_API_KEY');

    return is_string($value) ? trim($value) : '';
}

function bolagsverket_api_auth_header(): string
{
    $value = getenv('BOLAGSVERKET_API_AUTH_HEADER');

    return is_string($value) && trim($value) !== '' ? trim($value) : 'X-API-Key';
}

function bolagsverket_api_auth_prefix(): string
{
    $value = getenv('BOLAGSVERKET_API_AUTH_PREFIX');

    return is_string($value) ? trim($value) : '';
}

function app_env(): string
{
    $value = getenv('APP_ENV');

    return is_string($value) && $value !== '' ? trim($value) : 'production';
}

function is_dev_environment(): bool
{
    return in_array(app_env(), ['dev', 'development', 'local'], true);
}

function should_expose_internal_errors(): bool
{
    return is_dev_environment();
}

function internal_error_message(Throwable $exception, string $fallback): string
{
    if (should_expose_internal_errors() && trim($exception->getMessage()) !== '') {
        return trim($exception->getMessage());
    }

    return $fallback;
}

function use_mock_company_lookup(): bool
{
    $value = getenv('USE_MOCK_COMPANY_LOOKUP');

    if (!is_string($value)) {
        return false;
    }

    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function company_lookup_api_url(): string
{
    return bolagsverket_api_base_url() !== '' ? bolagsverket_api_base_url() : bolagsverket_api_url();
}

function company_lookup_api_token(): string
{
    return bolagsverket_api_key();
}

function company_lookup_is_configured(): bool
{
    if (use_mock_company_lookup()) {
        return true;
    }

    if (bolagsverket_api_base_url() !== ''
        && bolagsverket_token_url() !== ''
        && bolagsverket_client_id() !== ''
        && bolagsverket_client_secret() !== '') {
        return true;
    }

    return bolagsverket_api_url() !== '';
}

function is_logged_in(): bool
{
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function valid_user_roles(): array
{
    return [
        USER_ROLE_ADMIN,
        USER_ROLE_SALES,
        USER_ROLE_WORKER,
    ];
}

function normalize_user_role(string $role): string
{
    return in_array($role, valid_user_roles(), true) ? $role : USER_ROLE_WORKER;
}

function role_label(string $role): string
{
    return match (normalize_user_role($role)) {
        USER_ROLE_ADMIN => 'Admin',
        USER_ROLE_SALES => 'Säljare',
        USER_ROLE_WORKER => 'Arbetare',
        default => 'Arbetare',
    };
}

function current_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function current_user_role(): string
{
    $roles = current_user_roles();
    foreach ([USER_ROLE_ADMIN, USER_ROLE_SALES, USER_ROLE_WORKER] as $role) {
        if (in_array($role, $roles, true)) {
            return $role;
        }
    }

    return USER_ROLE_WORKER;
}

function current_user_roles(): array
{
    $roles = $_SESSION['roles'] ?? null;
    if (is_array($roles) && $roles !== []) {
        $normalized = array_values(array_unique(array_map(
            static fn(mixed $role): string => normalize_user_role((string)$role),
            $roles
        )));

        return $normalized !== [] ? $normalized : [normalize_user_role((string)($_SESSION['role'] ?? USER_ROLE_WORKER))];
    }

    return [normalize_user_role((string)($_SESSION['role'] ?? USER_ROLE_WORKER))];
}

function current_user_name(): string
{
    return (string)($_SESSION['admin_user_name'] ?? 'Admin');
}

function current_user_username(): string
{
    return (string)($_SESSION['admin_username'] ?? '');
}

function current_user_organization_id(): ?int
{
    $organizationId = $_SESSION['organization_id'] ?? null;

    return $organizationId !== null && $organizationId !== '' ? (int)$organizationId : null;
}

function current_user_organization_name(): string
{
    return (string)($_SESSION['organization_name'] ?? '');
}

function current_user_can(string $capability): bool
{
    $roles = current_user_roles();

    $hasRole = static fn(array $allowed): bool => array_intersect($roles, $allowed) !== [];

    return match ($capability) {
        'dashboard.view' => $hasRole(valid_user_roles()),
        'customers.view', 'customers.manage' => $hasRole([USER_ROLE_ADMIN, USER_ROLE_SALES]),
        'requests.view', 'requests.manage' => $hasRole([USER_ROLE_ADMIN, USER_ROLE_SALES]),
        'quotes.view', 'quotes.manage', 'quotes.send', 'quotes.approve' => $hasRole([USER_ROLE_ADMIN, USER_ROLE_SALES]),
        'jobs.view' => $hasRole([USER_ROLE_ADMIN, USER_ROLE_SALES, USER_ROLE_WORKER]),
        'jobs.assign' => $hasRole([USER_ROLE_ADMIN, USER_ROLE_SALES]),
        'jobs.manage' => $hasRole([USER_ROLE_ADMIN]),
        'jobs.progress' => $hasRole([USER_ROLE_ADMIN, USER_ROLE_WORKER]),
        'invoices.view', 'invoices.manage' => $hasRole([USER_ROLE_ADMIN]),
        'jobs.complete_and_invoice' => $hasRole([USER_ROLE_ADMIN, USER_ROLE_WORKER]),
        'settings.view', 'settings.manage', 'users.manage', 'products.manage', 'packages.manage' => $hasRole([USER_ROLE_ADMIN]),
        default => false,
    };
}

function require_capability(string $capability): void
{
    if (!is_logged_in() || !current_user_can($capability)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

function login_admin(): void
{
    session_regenerate_id(true);
    $_SESSION['admin_logged_in'] = true;
}

function set_logged_in_user(array $user): void
{
    $roles = $user['effective_roles'] ?? [$user['role'] ?? USER_ROLE_WORKER];
    if (!is_array($roles) || $roles === []) {
        $roles = [$user['role'] ?? USER_ROLE_WORKER];
    }
    $roles = array_values(array_unique(array_map(static fn(mixed $role): string => normalize_user_role((string)$role), $roles)));

    $_SESSION['user_id'] = (int)($user['id'] ?? 0);
    $_SESSION['role'] = normalize_user_role((string)($user['role'] ?? USER_ROLE_WORKER));
    $_SESSION['roles'] = $roles;
    $_SESSION['organization_id'] = ($user['organization_id'] ?? null) !== null && ($user['organization_id'] ?? '') !== ''
        ? (int)$user['organization_id']
        : null;
    $_SESSION['organization_name'] = (string)($user['organization_name'] ?? '');
    $_SESSION['admin_user_id'] = (int)($user['id'] ?? 0);
    $_SESSION['admin_user_name'] = (string)($user['name'] ?? $user['username'] ?? 'Admin');
    $_SESSION['admin_username'] = (string)($user['username'] ?? '');
}

function logout_admin(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}

function csrf_token(): string
{
    $token = $_SESSION['csrf_token'] ?? null;
    if (!is_string($token) || $token === '') {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
    }

    return $token;
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '" />';
}

function request_csrf_token(): string
{
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($headerToken) && trim($headerToken) !== '') {
        return trim($headerToken);
    }

    $postToken = $_POST['csrf_token'] ?? '';
    return is_string($postToken) ? trim($postToken) : '';
}

function is_valid_csrf_token(?string $token = null): bool
{
    $provided = $token ?? request_csrf_token();
    $stored = $_SESSION['csrf_token'] ?? null;

    return is_string($stored) && $stored !== '' && is_string($provided) && $provided !== '' && hash_equals($stored, $provided);
}

function require_valid_csrf_token(bool $json = false): void
{
    if (is_valid_csrf_token()) {
        return;
    }

    if ($json) {
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Din session kunde inte verifieras. Ladda om sidan och prova igen.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(419);
    exit('CSRF verification failed.');
}

function redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['admin_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['admin_flash'])) {
        return null;
    }

    $flash = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);

    return is_array($flash) ? $flash : null;
}

function set_form_state(string $key, array $state): void
{
    if (!isset($_SESSION['admin_form_state']) || !is_array($_SESSION['admin_form_state'])) {
        $_SESSION['admin_form_state'] = [];
    }

    $_SESSION['admin_form_state'][$key] = $state;
}

function get_form_state(string $key): ?array
{
    $state = $_SESSION['admin_form_state'][$key] ?? null;

    return is_array($state) ? $state : null;
}

function pull_form_state(string $key): ?array
{
    $state = get_form_state($key);

    if ($state !== null) {
        unset($_SESSION['admin_form_state'][$key]);
    }

    return $state;
}

function clear_form_state(string $key): void
{
    unset($_SESSION['admin_form_state'][$key]);
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function format_date(string $date): string
{
    $timestamp = strtotime($date);

    return $timestamp ? date('j M Y', $timestamp) : $date;
}

function format_datetime(string $date): string
{
    $timestamp = strtotime($date);

    return $timestamp ? date('j M Y H:i', $timestamp) : $date;
}

function format_currency(float $amount): string
{
    return number_format($amount, 0, ',', ' ') . ' kr';
}

function to_float(mixed $value): float
{
    if (is_string($value)) {
        $value = str_replace([' ', ','], ['', '.'], $value);
    }

    return (float)$value;
}

function customer_type_label(string $type): string
{
    return match ($type) {
        'company' => 'Företag',
        'association' => 'Förening / BRF',
        default => 'Privatperson',
    };
}

function yes_no_label(bool $value): string
{
    return $value ? 'Ja' : 'Nej';
}

function quote_totals(array $customer, float $labor, float $material, float $other): array
{
    $billingVatMode = (string)($customer['billing_vat_mode'] ?? 'standard_vat');
    $customerType = (string)($customer['customer_type'] ?? 'private');
    $rutEnabled = !empty($customer['rut_enabled'] ?? $customer['rut_active'] ?? false);
    $totalExVat = calculateTotalExVat($labor, $material, $other);
    $vatRate = calculateVatRate($customerType, $billingVatMode);
    $vatAmount = calculateVat($totalExVat, $billingVatMode, $customerType);
    $totalIncVat = calculateTotalIncVat($totalExVat, $vatAmount);
    $rutAmount = calculateRutAmount($labor, $rutEnabled, $customerType);
    $customerPrice = calculateAmountToPay($totalIncVat, $totalExVat, $rutAmount, $customerType, $billingVatMode);

    return [
        'total_amount_ex_vat' => $totalExVat,
        'vat_rate' => $vatRate,
        'vat_amount' => $vatAmount,
        'total_amount_inc_vat' => $totalIncVat,
        'rut_amount' => $rutAmount,
        'amount_after_rut' => $customerPrice,
        'reverse_charge_text' => reverseChargeText($customerType, $billingVatMode),
        'rut_applies' => $rutAmount > 0,
    ];
}

function calculateTotalExVat(float $labor, float $material, float $other): float
{
    return round($labor + $material + $other, 2);
}

function calculateTotalAmount(float $labor, float $material, float $other): float
{
    return calculateTotalExVat($labor, $material, $other);
}

function calculateRutAmount(float $labor, bool $rutEnabled, string $customerType): float
{
    return calculateRutAmountWithUsedAmount($labor, $rutEnabled, $customerType, 0.0);
}

function calculateRutYearlyLimit(): float
{
    return 75000.0;
}

function calculateRutAmountWithUsedAmount(float $labor, bool $rutEnabled, string $customerType, float $usedAmountThisYear = 0.0): float
{
    if (!$rutEnabled || $customerType !== 'private') {
        return 0.0;
    }

    $remainingAllowance = max(0.0, calculateRutYearlyLimit() - max(0.0, $usedAmountThisYear));
    $calculatedAmount = round(max(0, $labor) * 0.5, 2);

    return round(min($calculatedAmount, $remainingAllowance), 2);
}

function calculateAmountAfterRut(float $total, float $rutAmount): float
{
    return round($total - max(0, $rutAmount), 2);
}

function calculateVatRate(string $customerType, string $billingVatMode): float
{
    if (in_array($customerType, ['company', 'association'], true) && $billingVatMode === 'reverse_charge') {
        return 0.0;
    }

    return 0.25;
}

function calculateVat(float $totalExVat, string $billingVatMode, string $customerType = 'private'): float
{
    $vatRate = calculateVatRate($customerType, $billingVatMode);

    return round($totalExVat * max(0, $vatRate), 2);
}

function calculateVatAmount(float $totalExVat, float $vatRate): float
{
    return round($totalExVat * max(0, $vatRate), 2);
}

function calculateTotalIncVat(float $totalExVat, float $vatAmount): float
{
    return round($totalExVat + $vatAmount, 2);
}

function calculateTotalAmountIncVat(float $totalExVat, float $vatAmount): float
{
    return calculateTotalIncVat($totalExVat, $vatAmount);
}

function calculateAmountToPay(float $totalIncVat, float $totalExVat, float $rutAmount, string $customerType, string $billingVatMode): float
{
    if (in_array($customerType, ['company', 'association'], true) && $billingVatMode === 'reverse_charge') {
        return round($totalExVat, 2);
    }

    if (in_array($customerType, ['company', 'association'], true)) {
        return round($totalIncVat, 2);
    }

    return calculateAmountAfterRut($totalIncVat, $rutAmount);
}

function reverseChargeText(string $customerType, string $billingVatMode): string
{
    if (in_array($customerType, ['company', 'association'], true) && $billingVatMode === 'reverse_charge') {
        return 'Omvänd skattskyldighet';
    }

    return '';
}

function validate_non_negative(float $value): bool
{
    return $value >= 0;
}

function status_label(string $status): string
{
    $labels = [
        'draft' => 'Utkast',
        'sent' => 'Skickad',
        'approved' => 'Godkänd',
        'rejected' => 'Nekad',
        'planned' => 'Planerat',
        'scheduled' => 'Schemalagt',
        'in_progress' => 'Pågående',
        'completed' => 'Klart',
        'cancelled' => 'Avbrutet',
        'invoiced' => 'Fakturerat',
    ];

    return $labels[$status] ?? ucfirst($status);
}
