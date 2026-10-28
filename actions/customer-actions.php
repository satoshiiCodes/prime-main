<?php
/**
 * Prime City - Customer list actions.
 *
 * Everything the customer list page needs from the server: authentication,
 * branch/area scoping, the customer query and the requirements download.
 *
 * Produces no HTML - dashboard-style page views include it and render.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Account types that can only see their own branch.
 *
 * @return array<int, string>
 */
function customer_restricted_account_types()
{
    return array('Area Manager', 'Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector', 'Collector');
}

/**
 * Branches known to the system.
 *
 * @return array<int, string>
 */
function customer_all_branches()
{
    return array('Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan');
}

/**
 * Start the session once.
 *
 * @return void
 */
function customer_start_session()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Is a user signed in?
 *
 * @return bool
 */
function customer_is_authenticated()
{
    return isset($_SESSION['logged_in'], $_SESSION['user_id'])
        && $_SESSION['logged_in'] === true;
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function customer_redirect($location)
{
    if (!headers_sent()) {
        header('Location: ' . $location);
        return;
    }

    echo '<script>window.location.href = ' . json_encode($location) . ';</script>';
}

/**
 * Destroy the session and the remember-me cookie.
 *
 * @return void
 */
function customer_sign_out()
{
    customer_start_session();

    $_SESSION = array();

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_unset();
    session_destroy();
    setcookie('remember_me', '', time() - 3600, '/');
}

/**
 * Honour `?logout=true`.
 *
 * @return void
 */
function customer_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    customer_sign_out();
    customer_redirect('index.php');
    exit;
}

/**
 * Map `account_source` to its table, ignoring anything unknown.
 *
 * @param  string $source Session account source.
 * @return string
 */
function customer_table_for_source($source)
{
    $tables = array(
        'accounts'           => 'accounts',
        'collector_accounts' => 'collector_accounts',
        'proxy_accounts'     => 'proxy_accounts',
    );

    return isset($tables[$source]) ? $tables[$source] : 'accounts';
}

/**
 * Load the signed-in account, including the area for collector accounts.
 *
 * @param  PDO $pdo Open connection.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account no longer exists.
 */
function customer_fetch_user(PDO $pdo)
{
    $source = $_SESSION['account_source'] ?? 'accounts';
    $table = customer_table_for_source($source);

    $columns = 'first_name, middle_name, last_name, suffix, account_type, branch';
    if ($source === 'collector_accounts') {
        $columns .= ', area';
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT {$columns} FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute(array($userId));

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException('User not found for id ' . $userId);
    }

    return $user;
}

/**
 * Branches a user is allowed to see. An empty list means "no branch limit".
 *
 * @param  array<string, mixed> $user Current user record.
 * @return array<int, string>
 */
function customer_scope_branches(array $user)
{
    $accountType = (string) ($user['account_type'] ?? '');
    $branch = trim((string) ($user['branch'] ?? ''));

    if ($accountType === 'Admin') {
        return customer_all_branches();
    }

    if ($accountType === 'Area Manager') {
        if ($branch === '') {
            return array();
        }

        return array_values(array_filter(array_map('trim', explode(',', $branch)), 'strlen'));
    }

    if (in_array($accountType, customer_restricted_account_types(), true)) {
        return $branch === '' ? array() : array($branch);
    }

    return array();
}

/**
 * Normalise the branch filter for the current user, redirecting when the
 * request asks for a branch outside their scope.
 *
 * @param  array<string, mixed> $user      Current user record.
 * @param  string               $requested Raw `?branch=` value.
 * @return string
 */
function customer_resolve_branch_filter(array $user, $requested)
{
    $requested = trim((string) $requested);
    $accountType = (string) ($user['account_type'] ?? '');
    $branches = customer_scope_branches($user);

    if ($accountType === 'Area Manager' && $branches) {
        if ($requested === '') {
            return $branches[0];
        }

        if (!in_array($requested, $branches, true)) {
            customer_redirect('customer_list.php?branch=' . urlencode($branches[0]));
            exit;
        }

        return $requested;
    }

    if (in_array($accountType, customer_restricted_account_types(), true) && $branches) {
        $ownBranch = $branches[0];

        if ($requested === '') {
            return $ownBranch;
        }

        if ($requested !== $ownBranch) {
            customer_redirect('customer_list.php?branch=' . urlencode($ownBranch));
            exit;
        }

        return $requested;
    }

    return $requested;
}

/**
 * Customer rows visible to the current user, newest filters applied.
 *
 * @param  PDO                  $pdo    Open connection.
 * @param  array<string, mixed> $user   Current user record.
 * @param  array<string, string> $filters status / branch / search.
 * @return array<int, array<string, mixed>>
 */
function customer_fetch_all(PDO $pdo, array $user, array $filters)
{
    $statusFilter = (string) ($filters['status'] ?? '');
    $branchFilter = (string) ($filters['branch'] ?? '');
    $searchQuery = (string) ($filters['search'] ?? '');

    $sql = "SELECT
                ca.id,
                ca.profile_image,
                ca.last_name,
                ca.first_name,
                ca.middle_name,
                ca.suffix,
                ca.branch,
                ca.address,
                ca.contact_number,
                ca.status,
                ca.registration_date,
                ca.requirements,
                ca.area,
                EXISTS(SELECT 1 FROM financing f WHERE f.customer_id = ca.id AND f.status = 'active') as has_active_loan
            FROM customer_accounts ca";

    $params = array();
    $conditions = array();

    if ($statusFilter !== '') {
        $conditions[] = 'ca.status = ?';
        $params[] = $statusFilter;
    }

    if ($branchFilter !== '') {
        $conditions[] = 'ca.branch = ?';
        $params[] = $branchFilter;
    }

    // Collectors only see the area assigned to them.
    if (($user['account_type'] ?? '') === 'Collector' && !empty($user['area'])) {
        $conditions[] = 'ca.area = ?';
        $params[] = $user['area'];
    }

    if ($searchQuery !== '') {
        $nameConditions = array();

        foreach (explode(' ', $searchQuery) as $term) {
            $term = trim($term);
            if ($term === '') {
                continue;
            }

            $nameConditions[] = '(ca.first_name LIKE ? OR ca.last_name LIKE ?)';
            $params[] = '%' . $term . '%';
            $params[] = '%' . $term . '%';
        }

        if ($nameConditions) {
            $conditions[] = '(' . implode(' AND ', $nameConditions) . ')';
        }
    }

    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY ca.last_name ASC, ca.first_name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Re-sort in PHP so the order is stable regardless of MySQL collation.
    usort($customers, function ($a, $b) {
        $lastA = strtolower(trim((string) ($a['last_name'] ?? '')));
        $lastB = strtolower(trim((string) ($b['last_name'] ?? '')));
        $firstA = strtolower(trim((string) ($a['first_name'] ?? '')));
        $firstB = strtolower(trim((string) ($b['first_name'] ?? '')));

        $comparison = strcmp($lastA, $lastB);

        return $comparison !== 0 ? $comparison : strcmp($firstA, $firstB);
    });

    return $customers;
}

/**
 * Resolve a stored requirement path against the project root.
 *
 * @param  string $relative Stored path, e.g. `uploads/requirements/file.png`.
 * @return string|null Absolute path when the file exists inside the project.
 */
function customer_requirement_path($relative)
{
    $root = realpath(env_root_path());
    if ($root === false || $relative === '') {
        return null;
    }

    $full = realpath($root . DIRECTORY_SEPARATOR . ltrim((string) $relative, '/\\'));

    if ($full === false || strpos($full, $root) !== 0 || !is_file($full)) {
        return null;
    }

    return $full;
}

/**
 * Stream one requirement, or every requirement as a zip.
 *
 * Ends the request on success or failure.
 *
 * @param  PDO                   $pdo  Open connection.
 * @param  array<string, mixed>  $user Current user record.
 * @return void
 */
function customer_handle_download(PDO $pdo, array $user)
{
    if (!isset($_GET['download_requirements'])) {
        return;
    }

    $customerId = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT) ?: 0;
    $fileIndex = filter_input(INPUT_GET, 'file_index', FILTER_VALIDATE_INT);

    try {
        $customer = customer_assert_visible($pdo, $user, $customerId);

        if (empty($customer['requirements'])) {
            throw new RuntimeException('No requirements found');
        }

        $requirements = explode(',', (string) $customer['requirements']);

        if ($fileIndex !== null && $fileIndex !== false && isset($requirements[$fileIndex])) {
            $fullPath = customer_requirement_path($requirements[$fileIndex]);

            if ($fullPath === null) {
                throw new RuntimeException('File not found or access denied');
            }

            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
            header('Content-Length: ' . filesize($fullPath));
            readfile($fullPath);
            exit;
        }

        $zip = new ZipArchive();
        $zipPath = tempnam(sys_get_temp_dir(), 'requirements_') . '.zip';

        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Could not create zip file');
        }

        $added = false;

        foreach ($requirements as $requirement) {
            $fullPath = customer_requirement_path($requirement);

            if ($fullPath !== null) {
                $zip->addFile($fullPath, basename($fullPath));
                $added = true;
            }
        }

        if (!$added) {
            $zip->close();
            @unlink($zipPath);
            throw new RuntimeException('No valid files found');
        }

        $zip->close();

        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="customer_' . $customerId . '_requirements.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        unlink($zipPath);
        exit;

    } catch (Throwable $e) {
        error_log('[customer-list] ' . $e->getMessage());
        customer_redirect('customer_list.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

/**
 * Everything the customer list view needs, in one call.
 *
 * @param  string               $requestedBranch Raw `?branch=` value.
 * @param  bool                 $redirectOnFail  Send guests to the login page.
 * @param  array<string, mixed> $preloadedUser   Already loaded user record.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account or database is unavailable.
 */
function customer_context($requestedBranch = '', $redirectOnFail = true, array $preloadedUser = array())
{
    customer_start_session();

    if (!customer_is_authenticated()) {
        if ($redirectOnFail) {
            customer_redirect('index.php');
            exit;
        }

        throw new RuntimeException('Not authenticated.', 401);
    }

    $pdo = db_pdo();
    $user = $preloadedUser ? $preloadedUser : customer_fetch_user($pdo);

    $accountType = (string) ($user['account_type'] ?? '');

    $filters = array(
        'status' => (string) ($_GET['status'] ?? ''),
        'branch' => customer_resolve_branch_filter($user, $requestedBranch),
        'search' => trim((string) ($_GET['search'] ?? '')),
    );

    $customers = customer_fetch_all($pdo, $user, $filters);

    $isCollector = $accountType === 'Collector';
    $isProxyCollector = $accountType === 'Proxy Collector';
    $isProxyCashier = $accountType === 'Proxy Collector-Cashier';

    return array(
        'user'       => $user,
        'filters'    => $filters,
        'branches'   => customer_scope_branches($user),
        'customers'  => $customers,
        'endpoint'   => 'api/customer-endpoint.php',
        'flags'      => array(
            'is_admin'          => $accountType === 'Admin',
            'is_area_manager'   => $accountType === 'Area Manager',
            'is_collector'      => $isCollector,
            'is_proxy_collector' => $isProxyCollector,
            'can_manage'        => !$isCollector && !$isProxyCollector,
            'can_delete'        => !$isProxyCashier,
            'show_branch_filter' => in_array($accountType, array('Admin', 'Area Manager'), true),
            'show_area_note'    => $isCollector && isset($user['area']),
        ),
    );
}

/**
 * Does the current user's branch/area scope include this customer?
 *
 * Used by the JSON endpoint so an ID outside the visible list cannot be
 * modified.
 *
 * @param  PDO                   $pdo        Open connection.
 * @param  array<string, mixed>  $user       Current user record.
 * @param  int                   $customerId Customer primary key.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the customer is missing or out of scope.
 */
function customer_assert_visible(PDO $pdo, array $user, $customerId)
{
    $stmt = $pdo->prepare('SELECT id, branch, area, requirements FROM customer_accounts WHERE id = ? LIMIT 1');
    $stmt->execute(array((int) $customerId));
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        throw new RuntimeException('Customer not found', 404);
    }

    $branches = customer_scope_branches($user);

    if ($branches && !in_array((string) $customer['branch'], $branches, true)) {
        throw new RuntimeException('Customer is outside your branch', 403);
    }

    if (($user['account_type'] ?? '') === 'Collector'
        && !empty($user['area'])
        && (string) $customer['area'] !== (string) $user['area']) {
        throw new RuntimeException('Customer is outside your area', 403);
    }

    return $customer;
}
