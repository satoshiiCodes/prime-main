<?php
/**
 * Prime City - Customer registration JSON endpoint.
 *
 * Read-only checks used by the registration form's live validation.
 *
 *   GET ?action=username-availability&username=jdoe
 *   GET ?action=duplicate-name&last_name=Dela+Cruz&first_name=Juan
 *
 * Both use the exact queries the registration handler runs, so the browser can
 * never disagree with the server.
 */

require_once __DIR__ . '/../actions/customer-registration-actions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

/**
 * Emit a JSON response and stop.
 *
 * @param  array<string, mixed> $payload Response body.
 * @param  int                  $status  HTTP status code.
 * @return void
 */
function registration_json(array $payload, $status = 200)
{
    if (!headers_sent()) {
        http_response_code($status);
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

registration_start_session();

if (!registration_is_authenticated()) {
    registration_json(array('success' => false, 'message' => 'Unauthorized'), 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    registration_json(array('success' => false, 'message' => 'Method not allowed'), 405);
}

$action = strtolower(trim((string) ($_GET['action'] ?? '')));

try {
    $conn = db();

    switch ($action) {
        case 'username-availability':
            $username = trim((string) ($_GET['username'] ?? ''));

            if ($username === '') {
                registration_json(array(
                    'success' => false,
                    'message' => 'A username is required.',
                ), 400);
            }

            $taken = registration_username_taken($conn, $username);

            registration_json(array(
                'success'   => true,
                'username'  => $username,
                'available' => !$taken,
                'message'   => $taken
                    ? 'This username is already taken.'
                    : 'This username is available.',
            ));
            break;

        case 'duplicate-name':
            $lastName = trim((string) ($_GET['last_name'] ?? ''));
            $firstName = trim((string) ($_GET['first_name'] ?? ''));

            if ($lastName === '' || $firstName === '') {
                registration_json(array(
                    'success' => false,
                    'message' => 'Last name and first name are required.',
                ), 400);
            }

            $middleName = (string) ($_GET['middle_name'] ?? '');
            $suffix = (string) ($_GET['suffix'] ?? '');

            if ($suffix === 'None') {
                $suffix = '';
            }

            $duplicate = registration_duplicate_name($conn, $lastName, $firstName, $middleName, $suffix);

            registration_json(array(
                'success'   => true,
                'duplicate' => $duplicate,
                'message'   => $duplicate
                    ? 'A customer with the same name already exists in the system.'
                    : 'No matching customer found.',
            ));
            break;

        default:
            registration_json(array(
                'success' => false,
                'message' => 'Unsupported action',
                'allowed' => array('username-availability', 'duplicate-name'),
            ), 400);
    }
} catch (Throwable $e) {
    error_log('[customer-registration-endpoint] ' . $e->getMessage());
    registration_json(array('success' => false, 'message' => 'Could not run the check.'), 500);
}
