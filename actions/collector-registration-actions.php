<?php
/**
 * Prime City - Collector / proxy registration actions.
 *
 * Server-side logic for collector_registration.php: the signed-in user, the
 * branch choices they may pick, validation of the form and the insert into
 * collector_accounts or proxy_accounts.
 *
 * The session helpers and the branch-option rule are shared with
 * customer-registration-actions.php, which follows the same pattern.
 */

require_once __DIR__ . '/customer-registration-actions.php';

/**
 * Upload directory for collector profile images, created on first use.
 *
 * @return string Absolute path with a trailing separator.
 *
 * @throws RuntimeException When the directory cannot be created or written to.
 */
function cr_upload_dir()
{
    $path = env_root_path() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profile_images' . DIRECTORY_SEPARATOR;

    if (!is_dir($path) && !@mkdir($path, 0755, true)) {
        throw new RuntimeException('Failed to create the upload directory.');
    }

    if (!is_writable($path)) {
        throw new RuntimeException('Upload directory is not writable. Please check permissions.');
    }

    return $path;
}

/**
 * Load the signed-in account.
 *
 * The original page checked `accounts` and then `proxy_accounts`, whatever the
 * session's account source says.
 *
 * @param  PDO $pdo Open connection.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account cannot be found.
 */
function cr_fetch_user(PDO $pdo)
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    foreach (array('accounts', 'proxy_accounts') as $table) {
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = :id");
        $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            return $user;
        }
    }

    throw new RuntimeException('User not found in any account table');
}

/**
 * Is the username already used by a collector or proxy account?
 *
 * @param  mysqli $conn     Open connection.
 * @param  string $username Candidate username.
 * @return bool
 */
function cr_username_taken(mysqli $conn, $username)
{
    $stmt = $conn->prepare(
        'SELECT id FROM collector_accounts WHERE username = ?
         UNION
         SELECT id FROM proxy_accounts WHERE username = ?'
    );
    $stmt->bind_param('ss', $username, $username);
    $stmt->execute();

    $result = $stmt->get_result();
    $taken = $result && $result->num_rows > 0;

    if ($result) {
        $result->free();
    }
    $stmt->close();

    return $taken;
}

/**
 * Is the employee number already used by a collector or proxy account?
 *
 * @param  mysqli $conn           Open connection.
 * @param  string $employeeNumber Candidate employee number.
 * @return bool
 */
function cr_employee_number_taken(mysqli $conn, $employeeNumber)
{
    $stmt = $conn->prepare(
        'SELECT id FROM collector_accounts WHERE employee_number = ?
         UNION
         SELECT id FROM proxy_accounts WHERE employee_number = ?'
    );
    $stmt->bind_param('ss', $employeeNumber, $employeeNumber);
    $stmt->execute();

    $result = $stmt->get_result();
    $taken = $result && $result->num_rows > 0;

    if ($result) {
        $result->free();
    }
    $stmt->close();

    return $taken;
}

/**
 * Is another active collector already assigned to this area in this branch?
 *
 * @param  mysqli $conn   Open connection.
 * @param  string $area   Area name.
 * @param  string $branch Branch name.
 * @return bool
 */
function cr_area_taken(mysqli $conn, $area, $branch)
{
    $stmt = $conn->prepare("SELECT id FROM collector_accounts WHERE area = ? AND branch = ? AND account_status != 'Inactive'");
    $stmt->bind_param('ss', $area, $branch);
    $stmt->execute();

    $result = $stmt->get_result();
    $taken = $result && $result->num_rows > 0;

    if ($result) {
        $result->free();
    }
    $stmt->close();

    return $taken;
}

/**
 * Validate the submitted form and insert the collector/proxy account.
 *
 * Mirrors the original handler, including every message it raises.
 *
 * @param  mysqli $conn Open connection.
 * @param  string $dirs Profile image upload directory.
 * @return string The success message to flash.
 *
 * @throws RuntimeException On any validation or storage failure.
 */
function cr_store(mysqli $conn, $dirs)
{
    $employeeNumber = isset($_POST['employee_number']) ? trim((string) $_POST['employee_number']) : '';
    $lastName = isset($_POST['last_name']) ? (string) $_POST['last_name'] : '';
    $firstName = isset($_POST['first_name']) ? (string) $_POST['first_name'] : '';
    $middleName = isset($_POST['middle_name']) ? (string) $_POST['middle_name'] : '';
    $suffix = !empty($_POST['suffix']) ? (string) $_POST['suffix'] : 'None';
    $address = isset($_POST['address']) ? (string) $_POST['address'] : '';
    $contactNumber = isset($_POST['contact_number']) ? (string) $_POST['contact_number'] : '';
    $accountType = isset($_POST['account_type']) ? (string) $_POST['account_type'] : '';
    $status = isset($_POST['status']) ? (string) $_POST['status'] : '';
    $gender = isset($_POST['gender']) ? (string) $_POST['gender'] : '';
    $area = isset($_POST['area']) ? trim((string) $_POST['area']) : '';
    $branch = isset($_POST['branch']) ? (string) $_POST['branch'] : '';
    $birthDate = isset($_POST['birth_date']) ? (string) $_POST['birth_date'] : '';
    $placeOfBirth = isset($_POST['place_of_birth']) ? (string) $_POST['place_of_birth'] : '';
    $spouseName = isset($_POST['spouse_name']) ? (string) $_POST['spouse_name'] : null;
    $accountStatus = isset($_POST['account_status']) ? (string) $_POST['account_status'] : 'Active';
    $registrationDate = date('Y-m-d');

    if ($employeeNumber === '' || $lastName === '' || $firstName === '' || $address === '' || $contactNumber === ''
        || $status === '' || $gender === '' || $branch === '' || $birthDate === '' || $placeOfBirth === ''
        || $accountStatus === '' || $accountType === '') {
        throw new RuntimeException('All required fields must be filled');
    }

    if (!preg_match('/^[A-Z0-9\-]+$/', $employeeNumber)) {
        throw new RuntimeException('Employee number must contain only uppercase letters, numbers, and hyphens');
    }

    $isCollector = ($accountType === 'Collector');

    $username = isset($_POST['username']) ? (string) $_POST['username'] : '';
    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';

    if ($username === '' || $password === '' || $confirmPassword === '') {
        throw new RuntimeException('Username and password are required for all account types');
    }

    if ($password !== $confirmPassword) {
        throw new RuntimeException('Password and Confirm Password do not match');
    }

    if (strlen($password) < 6) {
        throw new RuntimeException('Password must be at least 6 characters long');
    }

    if (cr_username_taken($conn, $username)) {
        throw new RuntimeException('Username already exists. Please choose a different username.');
    }

    if (cr_employee_number_taken($conn, $employeeNumber)) {
        throw new RuntimeException('Employee number already exists. Please use a unique employee number.');
    }

    if ($isCollector) {
        if ($area === '') {
            throw new RuntimeException('Area is required for Collector accounts');
        }

        if (cr_area_taken($conn, $area, $branch)) {
            throw new RuntimeException('A collector is already assigned to this area in the selected branch. Please choose a different area or branch.');
        }
    } elseif ($area === '') {
        $area = 'All Areas';
    }

    $contactNumber = preg_replace('/[^0-9]/', '', $contactNumber);

    if (strlen($contactNumber) != 11 || substr($contactNumber, 0, 2) !== '09') {
        throw new RuntimeException("Contact number must be 11 digits starting with '09'");
    }

    $profileImage = null;

    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == UPLOAD_ERR_OK) {
        $fileExtension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
        $newFilename = uniqid() . '.' . $fileExtension;
        $targetFile = $dirs . $newFilename;

        if (getimagesize($_FILES['profile_image']['tmp_name']) === false) {
            throw new RuntimeException('File is not an image.');
        }

        if (!move_uploaded_file($_FILES['profile_image']['tmp_name'], $targetFile)) {
            throw new RuntimeException('Sorry, there was an error uploading your file.');
        }

        $profileImage = 'uploads/profile_assets/images/' . $newFilename;
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Both tables share this column list.
    $table = $isCollector ? 'collector_accounts' : 'proxy_accounts';

    $stmt = $conn->prepare("INSERT INTO {$table}
        (employee_number, last_name, first_name, middle_name, suffix, address, contact_number, account_type, registration_date,
         status, gender, area, branch, birth_date, place_of_birth, spouse_name, account_status, profile_image, username, password)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $values = array(
        $employeeNumber, $lastName, $firstName, $middleName, $suffix, $address, $contactNumber, $accountType, $registrationDate,
        $status, $gender, $area, $branch, $birthDate, $placeOfBirth, $spouseName, $accountStatus, $profileImage, $username, $hashedPassword,
    );

    $stmt->bind_param(str_repeat('s', count($values)), ...$values);

    if (!$stmt->execute()) {
        throw new RuntimeException('Error: ' . $stmt->error);
    }

    $stmt->close();

    $typeName = $isCollector ? 'Collector' : 'Proxy Collector';

    return $typeName . ' registered successfully! Employee Number: ' . $employeeNumber;
}

/**
 * Everything the registration view needs, in one call.
 *
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account, uploads or database are unusable.
 */
function cr_context()
{
    registration_start_session();
    registration_handle_logout();

    if (!registration_is_authenticated()) {
        registration_redirect('index.php');
        exit;
    }

    $pdo = db_pdo();

    // The page has only ever supported accounts and proxy_accounts logins; any
    // other account source is turned away instead of hitting the fatal error
    // the original produced.
    $source = $_SESSION['account_source'] ?? 'accounts';

    if (!in_array($source, array('accounts', 'proxy_accounts'), true)) {
        registration_redirect('employee_lists.php?error=' . urlencode('This account type cannot register collectors.'));
        exit;
    }

    try {
        $user = cr_fetch_user($pdo);
    } catch (RuntimeException $e) {
        error_log('[collector-registration] ' . $e->getMessage());
        registration_redirect('index.php');
        exit;
    }

    $branch = registration_branch_options($user);
    $uploadDir = cr_upload_dir();

    $errorMessage = '';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            $successMessage = cr_store(db(), $uploadDir);

            $_SESSION['success_message'] = $successMessage;
            registration_redirect('collector_registration.php');
            exit;
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
        }
    }

    $successMessage = '';

    if (isset($_SESSION['success_message'])) {
        $successMessage = (string) $_SESSION['success_message'];
        unset($_SESSION['success_message']);
    }

    return array(
        'user'               => $user,
        'available_branches' => $branch['available'],
        'branch_disabled'    => $branch['disabled'],
        'selected_branch'    => $branch['selected'],
        'success_message'    => $successMessage,
        'error_message'      => $errorMessage,
        'layout_menu'        => 'admin',
    );
}
