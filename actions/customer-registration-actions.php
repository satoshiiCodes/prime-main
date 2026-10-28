<?php
/**
 * Prime City - Customer registration actions.
 *
 * Server-side logic for customer_registration.php: authentication, branch
 * options for the current account, upload handling and the registration
 * insert itself.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Account types that may only use their assigned branch.
 *
 * @return array<int, string>
 */
function registration_restricted_account_types()
{
    return array('Branch Manager', 'Proxy Collector-Cashier', 'Proxy Collector');
}

/**
 * Branches known to the system.
 *
 * @return array<int, string>
 */
function registration_all_branches()
{
    return array('Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan');
}

/**
 * Start the session once.
 *
 * @return void
 */
function registration_start_session()
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
function registration_is_authenticated()
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
function registration_redirect($location)
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
function registration_sign_out()
{
    registration_start_session();

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
function registration_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    registration_sign_out();
    registration_redirect('index.php');
    exit;
}

/**
 * Upload directories, created on first use.
 *
 * @return array<string, string>
 *
 * @throws RuntimeException When a directory cannot be created or written to.
 */
function registration_upload_dirs()
{
    $dirs = array(
        'profile'      => env_root_path() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profile_images' . DIRECTORY_SEPARATOR,
        'requirements' => env_root_path() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'requirements' . DIRECTORY_SEPARATOR,
    );

    foreach ($dirs as $label => $path) {
        if (!is_dir($path) && !@mkdir($path, 0755, true)) {
            throw new RuntimeException('Failed to create the ' . $label . ' upload directory.');
        }

        if (!is_writable($path)) {
            throw new RuntimeException('The ' . $label . ' upload directory is not writable.');
        }
    }

    return $dirs;
}

/**
 * Load the signed-in account.
 *
 * Only the accounts and proxy_accounts tables can reach this page - the same
 * limitation the original page had, but reported instead of fatal.
 *
 * @param  PDO $pdo Open connection.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account cannot be found.
 */
function registration_fetch_user(PDO $pdo)
{
    $source = $_SESSION['account_source'] ?? 'accounts';
    $table = $source === 'proxy_accounts' ? 'proxy_accounts' : 'accounts';
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = :id");
    $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
    $stmt->execute();

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException('User not found');
    }

    return $user;
}

/**
 * Branch choices for the current account.
 *
 * @param  array<string, mixed> $user Current user record.
 * @return array<string, mixed>
 */
function registration_branch_options(array $user)
{
    $accountType = (string) ($user['account_type'] ?? '');
    $userBranch = (string) ($user['branch'] ?? '');

    $allBranches = registration_all_branches();

    $available = array();
    $disabled = false;
    $selected = '';

    if ($accountType === 'Admin') {
        if ($userBranch === 'All Branches') {
            $available = $allBranches;
        } else {
            $available = array($userBranch);
            $disabled = true;
            $selected = $userBranch;
        }
    } elseif ($accountType === 'Area Manager') {
        if ($userBranch !== '') {
            $assigned = array_map('trim', explode(',', $userBranch));
            $available = array_intersect($allBranches, $assigned);
        } else {
            $available = array();
            $disabled = true;
        }
    } elseif (in_array($accountType, registration_restricted_account_types(), true)) {
        $available = array($userBranch);
        $disabled = true;
        $selected = $userBranch;
    } else {
        $available = $allBranches;
    }

    return array(
        'available' => $available,
        'disabled'  => $disabled,
        'selected'  => $selected,
    );
}

/**
 * Is this username already used by a customer?
 *
 * @param  mysqli $conn     Open connection.
 * @param  string $username Candidate username.
 * @return bool
 */
function registration_username_taken(mysqli $conn, $username)
{
    $stmt = $conn->prepare('SELECT id FROM customer_accounts WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
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
 * Does a customer with this name already exist?
 *
 * @param  mysqli $conn       Open connection.
 * @param  string $lastName   Last name.
 * @param  string $firstName  First name.
 * @param  string $middleName Middle name.
 * @param  string $suffix     Suffix, with "None" normalised to an empty string.
 * @return bool
 */
function registration_duplicate_name(mysqli $conn, $lastName, $firstName, $middleName, $suffix)
{
    $stmt = $conn->prepare(
        "SELECT id FROM customer_accounts
         WHERE last_name = ?
           AND first_name = ?
           AND (middle_name = ? OR (middle_name IS NULL AND ? = ''))
           AND (suffix = ? OR (suffix IS NULL AND ? = ''))
         LIMIT 1"
    );

    $stmt->bind_param('ssssss', $lastName, $firstName, $middleName, $middleName, $suffix, $suffix);
    $stmt->execute();

    $result = $stmt->get_result();
    $duplicate = $result && $result->num_rows > 0;

    if ($result) {
        $result->free();
    }
    $stmt->close();

    return $duplicate;
}

/**
 * Validate the submitted form and insert the customer.
 *
 * Mirrors the original handler exactly, including the messages it raises.
 *
 * @param  mysqli               $conn Open connection.
 * @param  array<string, string> $dirs Upload directories.
 * @return void
 *
 * @throws RuntimeException On any validation or storage failure.
 */
function registration_store(mysqli $conn, array $dirs)
{
    $contactNumber = (string) ($_POST['contact_number'] ?? '');
    $contactNumber2 = (string) ($_POST['contact_number_2'] ?? '');

    if ($contactNumber !== '' && !preg_match('/^09\d{9}$/', $contactNumber)) {
        throw new RuntimeException('Contact number must be 11 digits starting with 09');
    }

    if ($contactNumber2 !== '' && !preg_match('/^09\d{9}$/', $contactNumber2)) {
        throw new RuntimeException('Contact number #2 must be 11 digits starting with 09');
    }

    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($username === '') {
        throw new RuntimeException('Username is required');
    }

    if ($password === '') {
        throw new RuntimeException('Password is required');
    }

    if ($password !== $confirmPassword) {
        throw new RuntimeException('Passwords do not match');
    }

    // Duplicate username.
    if (registration_username_taken($conn, $username)) {
        throw new RuntimeException('Username already exists. Please choose a different username.');
    }

    // Duplicate customer name.
    $lastName = (string) ($_POST['last_name'] ?? '');
    $firstName = (string) ($_POST['first_name'] ?? '');
    $middleName = isset($_POST['middle_name']) ? (string) $_POST['middle_name'] : '';
    $suffix = !empty($_POST['suffix']) ? (string) $_POST['suffix'] : '';

    if ($suffix === 'None') {
        $suffix = '';
    }

    if (registration_duplicate_name($conn, $lastName, $firstName, $middleName, $suffix)) {
        throw new RuntimeException('A customer with the same name already exists in the system.');
    }

    $profileImagePath = null;
    $requirementsPaths = array();

    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == UPLOAD_ERR_OK) {
        $imageFileType = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        $newFilename = uniqid() . '.' . $imageFileType;
        $targetFile = $dirs['profile'] . $newFilename;

        if (getimagesize($_FILES['profile_image']['tmp_name']) === false) {
            throw new RuntimeException('File is not an image.');
        }

        if ($_FILES['profile_image']['size'] > 5000000) {
            throw new RuntimeException('Image file is too large (max 5MB).');
        }

        if (!in_array($imageFileType, array('jpg', 'jpeg', 'png', 'gif'), true)) {
            throw new RuntimeException('Only JPG, JPEG, PNG & GIF files are allowed.');
        }

        if (!move_uploaded_file($_FILES['profile_image']['tmp_name'], $targetFile)) {
            throw new RuntimeException('Failed to upload profile image.');
        }

        $profileImagePath = 'uploads/profile_assets/images/' . $newFilename;
    }

    if (isset($_FILES['requirements']) && is_array($_FILES['requirements']['name'])) {
        $allowedTypes = array('doc', 'docx', 'xls', 'xlsx', 'pdf', 'jpg', 'jpeg', 'png', 'gif');

        if (!is_dir($dirs['requirements'])) {
            throw new RuntimeException('The requirements upload directory is missing.');
        }

        foreach ($_FILES['requirements']['name'] as $key => $name) {
            if ($_FILES['requirements']['error'][$key] != UPLOAD_ERR_OK) {
                continue;
            }

            $fileType = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!in_array($fileType, $allowedTypes, true)) {
                throw new RuntimeException('Only Word, Excel, PDF, and image files are allowed for requirements.');
            }

            if ($_FILES['requirements']['size'][$key] > 20000000) {
                throw new RuntimeException("File '{$name}' is too large (max 20MB).");
            }

            $newFilename = uniqid() . '.' . $fileType;
            $targetFile = $dirs['requirements'] . $newFilename;

            if (!move_uploaded_file($_FILES['requirements']['tmp_name'][$key], $targetFile)) {
                throw new RuntimeException('Failed to upload requirement file.');
            }

            $requirementsPaths[] = '/uploads/requirements/' . $newFilename;
        }
    }

    $requiredFields = array(
        'last_name'   => (string) ($_POST['last_name'] ?? ''),
        'first_name'  => (string) ($_POST['first_name'] ?? ''),
        'province'    => (string) ($_POST['province'] ?? ''),
        'municipality' => (string) ($_POST['municipality'] ?? ''),
        'barangay'    => (string) ($_POST['barangay'] ?? ''),
        'street'      => (string) ($_POST['street'] ?? ''),
        'branch'      => (string) ($_POST['branch'] ?? ''),
    );

    foreach ($requiredFields as $value) {
        if ($value === '') {
            throw new RuntimeException('All required fields must be filled');
        }
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Optional fields: an empty choice is stored as NULL. Binding '' to the
    // nullable enum/date/varchar columns fails under STRICT_TRANS_TABLES, which
    // is what the original handler did (e.g. "Data truncated for column
    // 'gender'") - leaving Gender or Date of Birth blank blocked registration.
    $optional = array(
        'gender', 'marital_status', 'date_of_birth', 'place_of_birth',
        'spouse_name', 'spouse_occupation', 'area', 'occupation',
        'employer_business_name', 'nature_of_work', 'notes',
    );

    $optionalValues = array();

    foreach ($optional as $field) {
        $value = isset($_POST[$field]) ? (string) $_POST[$field] : '';
        $optionalValues[$field] = $value === '' ? null : $value;
    }

    $data = array(
        'last_name'              => $requiredFields['last_name'],
        'first_name'             => $requiredFields['first_name'],
        'middle_name'            => (string) ($_POST['middle_name'] ?? ''),
        'suffix'                 => !empty($_POST['suffix']) ? (string) $_POST['suffix'] : 'None',
        'gender'                 => $optionalValues['gender'],
        'marital_status'         => $optionalValues['marital_status'],
        'date_of_birth'          => $optionalValues['date_of_birth'],
        'place_of_birth'         => $optionalValues['place_of_birth'],
        'spouse_name'            => $optionalValues['spouse_name'],
        'spouse_occupation'      => $optionalValues['spouse_occupation'],
        'area'                   => $optionalValues['area'],
        'branch'                 => $requiredFields['branch'],
        'status'                 => !empty($_POST['status']) ? (string) $_POST['status'] : 'Active',
        'contact_number'         => $contactNumber,
        'contact_number_2'       => $contactNumber2 === '' ? null : $contactNumber2,
        'account_type'           => 'Customer',
        'username'               => $username,
        'password'               => $hashedPassword,
        'registration_date'      => date('Y-m-d H:i:s'),
        'profile_image'          => $profileImagePath,
        'occupation'             => $optionalValues['occupation'],
        'employer_business_name' => $optionalValues['employer_business_name'],
        'nature_of_work'         => $optionalValues['nature_of_work'],
        'notes'                  => $optionalValues['notes'],
        'requirements'           => $requirementsPaths ? implode(',', $requirementsPaths) : null,
    );

    // No., Street, Floor/Building, Barangay, Municipality/City, Province
    $data['address'] = implode(', ', array_filter(array(
        (string) ($_POST['house_number'] ?? ''),
        $requiredFields['street'],
        (string) ($_POST['building'] ?? ''),
        $requiredFields['barangay'],
        $requiredFields['municipality'],
        $requiredFields['province'],
    )));

    if (!empty($_POST['sameAsPermanent'])) {
        $data['present_address'] = $data['address'];
    } else {
        $data['present_address'] = implode(', ', array_filter(array(
            (string) ($_POST['present_house_number'] ?? ''),
            (string) ($_POST['present_street'] ?? ''),
            (string) ($_POST['present_building'] ?? ''),
            (string) ($_POST['present_barangay'] ?? ''),
            (string) ($_POST['present_municipality'] ?? ''),
            (string) ($_POST['present_province'] ?? ''),
        )));
    }

    $stmt = $conn->prepare(
        'INSERT INTO customer_accounts
            (last_name, first_name, middle_name, suffix, gender, marital_status,
             date_of_birth, place_of_birth, spouse_name, spouse_occupation,
             address, present_address, occupation, employer_business_name,
             nature_of_work, contact_number, contact_number_2, account_type, username, password, registration_date,
             profile_image, area, branch, status, notes, requirements)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    if (!$stmt) {
        throw new RuntimeException('Could not prepare the registration statement.');
    }

    $values = array(
        $data['last_name'],
        $data['first_name'],
        $data['middle_name'],
        $data['suffix'],
        $data['gender'],
        $data['marital_status'],
        $data['date_of_birth'],
        $data['place_of_birth'],
        $data['spouse_name'],
        $data['spouse_occupation'],
        $data['address'],
        $data['present_address'],
        $data['occupation'],
        $data['employer_business_name'],
        $data['nature_of_work'],
        $data['contact_number'],
        $data['contact_number_2'],
        $data['account_type'],
        $data['username'],
        $data['password'],
        $data['registration_date'],
        $data['profile_image'],
        $data['area'],
        $data['branch'],
        $data['status'],
        $data['notes'],
        $data['requirements'],
    );

    $stmt->bind_param(str_repeat('s', count($values)), ...$values);

    if (!$stmt->execute()) {
        throw new RuntimeException('Database error: ' . $stmt->error);
    }

    $stmt->close();
}

/**
 * Navigation variant for includes/sidebar.php.
 *
 * The original page always included the admin menu.
 *
 * @return string
 */
function registration_menu_variant()
{
    return 'admin';
}

/**
 * Everything the registration view needs, in one call.
 *
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the account, uploads or database are unusable.
 */
function registration_context()
{
    registration_start_session();
    registration_handle_logout();

    if (!registration_is_authenticated()) {
        registration_redirect('index.php');
        exit;
    }

    // The page has only ever supported accounts and proxy_accounts logins.
    $source = $_SESSION['account_source'] ?? 'accounts';

    if (!in_array($source, array('accounts', 'proxy_accounts'), true)) {
        registration_redirect('customer_list.php?error=' . urlencode('This account type cannot register customers.'));
        exit;
    }

    $pdo = db_pdo();
    $user = registration_fetch_user($pdo);

    $branch = registration_branch_options($user);
    $dirs = registration_upload_dirs();

    $errorMessage = '';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            registration_store(db(), $dirs);

            $_SESSION['success_message'] = 'Customer registered successfully!';
            registration_redirect('customer_registration.php');
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
        'layout_menu'        => registration_menu_variant(),
        'endpoint'           => 'api/customer-registration-endpoint.php',
    );
}
