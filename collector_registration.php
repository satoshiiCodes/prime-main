<?php
/**
 * Prime City - Collector / proxy registration page.
 *
 * Layout only: validation and the insert come from
 * actions/collector-registration-actions.php, layout chrome from includes/*,
 * styles from assets/css and behaviour from assets/js.
 */

require_once __DIR__ . '/actions/collector-registration-actions.php';

try {
    $context = cr_context();
} catch (Throwable $e) {
    error_log('[collector-registration] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Collector Registration - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The registration form is unavailable</h4>
                    <p class="mb-0">We could not prepare the registration form right now. Please try again in a moment.</p>
                </div>
            </div>
        </body>
    </html>
    <?php
    exit;
}

$user = $context['user'];
$available_branches = $context['available_branches'];
$branch_disabled = $context['branch_disabled'];
$selected_branch = $context['selected_branch'];
$success_message = $context['success_message'];
$error_message = $context['error_message'];

// Shared layout includes read these.
$layout_active = 'collector-registration';
$layout_menu = $context['layout_menu'];

$pageConfig = array(
    'success' => $success_message,
    'error'   => $error_message,
);

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;

/* Bump asset URLs when the files change so a normal refresh picks them up. */
$asset_version = static function (string $relative): string {
    $absolute = __DIR__ . '/' . $relative;

    return is_file($absolute) ? (string) filemtime($absolute) : '1';
};
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <meta name="description" content="Prime City collector registration" />
        <meta name="author" content="Prime City" />
        <title>Collector Registration - Prime City Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <!-- SweetAlert2 CSS -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dash-ui.css" rel="stylesheet" />
        <link href="assets/css/collector-registration-style.css?v=<?php echo $asset_version('assets/css/collector-registration-style.css'); ?>" rel="stylesheet" />
        <link href="assets/css/customer-registration-style.css?v=<?php echo $asset_version('assets/css/customer-registration-style.css'); ?>" rel="stylesheet" />
    </head>
    <body class="sb-nav-fixed">
        <?php include __DIR__ . '/includes/topbar.php'; ?>

        <div id="layoutSidenav">
            <?php include __DIR__ . '/includes/sidebar.php'; ?>

            <div id="layoutSidenav_content">
                <main>
                    <div class="dash">
                        <header class="dash-head">
                            <div>
                                <p class="dash-eyebrow">
                                    <i class="fas fa-user-plus" aria-hidden="true"></i>
                                    Prime City &middot; Employees
                                </p>
                                <h1 class="dash-title">Register Collector</h1>
                                <p class="dash-sub">Personal, contact, area and sign-in details for a new collector account.</p>
                            </div>

                            <div class="dash-head__actions">
                                <a class="btn btn-primary" href="employee_lists.php">
                                    <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>
                                    Back to employee list
                                </a>
                            </div>
                        </header>

                        <section class="dash-card">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-id-card-clip" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Collector Registration</h2>
                                    <p class="dash-card__hint">Fields marked with an asterisk are required</p>
                                </div>
                            </header>

                            <div class="dash-card__body">
<form id="collectorRegistrationForm" class="dash-form" action="collector_registration.php" method="POST" enctype="multipart/form-data">
                                    <div class="form-section account-type-section">
                                        <h5 class="section-title">Account Type</h5>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div class="form-floating">
                                                    <select class="form-select readonly-select" id="account_type" name="account_type" required readonly>
                                                        <option value="Collector" selected>Collector</option>
                                                    </select>
                                                    <label for="account_type" class="required-field">Account Type</label>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="registration_date" name="registration_date"
                                                           value="<?php echo htmlspecialchars(date('d M Y')); ?>" readonly>
                                                    <label for="registration_date">Registration Date</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Personal Information Section -->
                                    <div class="form-section">
                                        <h5 class="section-title">Personal Information</h5>

                                        <div class="profile-upload">
                                            <span class="upload__label" id="profileImageLabel">Profile Image</span>
                                            <label class="upload" for="profile_image" id="profileImageZone" aria-labelledby="profileImageLabel">
                                                <input
                                                    type="file"
                                                    class="upload__input"
                                                    id="profile_image"
                                                    name="profile_image"
                                                    accept="image/*"
                                                >
                                                <img id="profile_image_preview" class="preview-image" src="#" alt="Selected profile photo">
                                                <span class="upload__icon"><i class="fas fa-cloud-arrow-up" aria-hidden="true"></i></span>
                                                <span class="upload__title">Drop a photo here or click to browse</span>
                                                <span class="upload__hint">JPG, JPEG, PNG or GIF &middot; up to 5 MB</span>
                                            </label>
                                            <div class="upload__meta">
                                                <span class="upload__name" id="profileImageName">No file selected</span>
                                                <button type="button" class="upload__clear" id="profileImageClear" hidden>Remove</button>
                                            </div>
                                        </div>



                                        <div class="row g-3">
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="last_name" name="last_name" placeholder="Last Name" required>
                                                    <label for="last_name" class="required-field">Last Name</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="first_name" name="first_name" placeholder="First Name" required>
                                                    <label for="first_name" class="required-field">First Name</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="middle_name" name="middle_name" placeholder="Middle Name">
                                                    <label for="middle_name">Middle Name</label>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="form-floating">
                                                    <select class="form-select" id="suffix" name="suffix">
                                                        <option value="None">None</option>
                                                        <option value="Jr">Jr</option>
                                                        <option value="Sr">Sr</option>
                                                        <option value="II">II</option>
                                                        <option value="III">III</option>
                                                        <option value="IV">IV</option>
                                                    </select>
                                                    <label for="suffix">Suffix</label>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row g-3 mt-2">
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <select class="form-select" id="status" name="status" required>
                                                        <option value="">Select Status</option>
                                                        <option value="Single">Single</option>
                                                        <option value="Married">Married</option>
                                                        <option value="Widow">Widow</option>
                                                        <option value="Separated">Separated</option>
                                                    </select>
                                                    <label for="status" class="required-field">Status</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <select class="form-select" id="gender" name="gender" required>
                                                        <option value="">Select Gender</option>
                                                        <option value="Male">Male</option>
                                                        <option value="Female">Female</option>
                                                    </select>
                                                    <label for="gender" class="required-field">Gender</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="date" class="form-control" id="birth_date" name="birth_date" required>
                                                    <label for="birth_date" class="required-field">Birth Date</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="place_of_birth" name="place_of_birth" placeholder="Place of Birth" required>
                                                    <label for="place_of_birth" class="required-field">Place of Birth</label>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row g-3 mt-2">
                                            <div class="col-md-6">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="spouse_name" name="spouse_name" placeholder="Spouse Name">
                                                    <label for="spouse_name">Spouse Name (if applicable)</label>
                                                </div>
                                            </div>
                                            </div>
                                        </div>

                                    <!-- Contact Information Section -->
                                    <div class="form-section">
                                        <h5 class="section-title">Contact Information</h5>
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <div class="form-floating">
                                                    <textarea class="form-control" id="address" name="address" placeholder="Address" style="height: 100px" required></textarea>
                                                    <label for="address" class="required-field">Address</label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-floating">
                                                    <input type="tel" class="form-control" id="contact_number" name="contact_number" 
                                                        pattern="[0-9]{11}" title="Please enter exactly 11 digits starting with 09" 
                                                        maxlength="11" placeholder="Contact Number" required>
                                                    <label for="contact_number" class="required-field">Contact Number</label>
                                                    <small class="text-muted">Must be 11 digits starting with "09"</small>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-floating">
                                                    <select class="form-select" id="area" name="area">
                                                        <option value="">Select Area</option>
                                                        <option value="Area 1">Area 1</option>
                                                        <option value="Area 2">Area 2</option>
                                                        <option value="Area 3">Area 3</option>
                                                        <option value="Area 4">Area 4</option>
                                                        <option value="Area 5">Area 5</option>
                                                        <option value="Area 6">Area 6</option>
                                                        <option value="Area 7">Area 7</option>
                                                        <option value="Area 8">Area 8</option>
                                                        <option value="Area 9">Area 9</option>
                                                        <option value="Area 10">Area 10</option>
                                                    </select>
                                                    <label for="area" id="area-label" class="required-field">Area</label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-floating">
                                                    <select class="form-select" id="branch" name="branch" 
                                                        <?php echo $branch_disabled ? 'disabled' : ''; ?> required>
                                                        <option value="">Select Branch</option>
                                                        <?php foreach ($available_branches as $branch): ?>
                                                            <option value="<?php echo $branch; ?>" 
                                                                <?php echo ($branch_disabled && $branch === $selected_branch) ? 'selected' : ''; ?>>
                                                                <?php echo $branch; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <?php if ($branch_disabled): ?>
                                                        <input type="hidden" name="branch" value="<?php echo $selected_branch; ?>">
                                                    <?php endif; ?>
                                                    <label for="branch" class="required-field">Branch</label>
                                                    <?php if ($branch_disabled): ?>
                                                        <small class="text-muted">Branch is automatically set to your assigned branch (<?php echo $selected_branch; ?>)</small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-floating">
                                                    <select class="form-select" id="account_status" name="account_status" required>
                                                        <option value="Active">Active</option>
                                                        <option value="Inactive">Inactive</option>
                                                        <option value="Suspended">Suspended</option>
                                                    </select>
                                                    <label for="account_status" class="required-field">Account Status</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Employee Number Section -->
                                    <div class="form-section">
                                        <h5 class="section-title">Employee Information</h5>
                                        <div class="row g-3">
                                            <div class="col-md-12">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control employee-number-field" id="employee_number" name="employee_number" 
                                                           placeholder="Employee Number" 
                                                           oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9\-]/g, '')"
                                                           pattern="[A-Z0-9\-]+"
                                                           title="Uppercase letters, numbers, and hyphens only"
                                                           required>
                                                    <label for="employee_number" class="required-field">Employee Number</label>
                                                </div>
                                                <div class="input-hint">
                                                    <i class="fas fa-info-circle"></i> Use numbers only (e.g., 00001)
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Login Credentials Section (For All Account Types) -->
                                    <div class="form-section" id="login-credentials-section">
                                        <h5 class="section-title">Login Credentials</h5>
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="username" name="username" placeholder="Username" autocomplete="off" required>
                                                    <label for="username" class="required-field">Username</label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-floating password-input-group">
                                                    <input type="password" class="form-control" id="password" name="password" placeholder="Password" autocomplete="new-password" required>
                                                    <label for="password" class="required-field">Password</label>
                                                    <button type="button" class="password-toggle" onclick="togglePassword('password')"
                                                            aria-label="Show password" aria-pressed="false" title="Show password">
                                                        <i class="fas fa-eye" aria-hidden="true"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-floating password-input-group">
                                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm Password" autocomplete="new-password" required>
                                                    <label for="confirm_password" class="required-field">Confirm Password</label>
                                                    <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')"
                                                            aria-label="Show password confirmation" aria-pressed="false" title="Show password confirmation">
                                                        <i class="fas fa-eye" aria-hidden="true"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="dash-form__actions">
                                        <a class="btn btn-outline-secondary" href="employee_lists.php">Cancel</a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-check me-1" aria-hidden="true"></i>
                                            Register Collector
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </section>
                    </div>
                </main>

                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>

        <script type="application/json" id="collector-registration-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>
        <!-- SweetAlert2 JS -->
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="assets/js/pc-toast.js?v=<?php echo $asset_version('assets/js/pc-toast.js'); ?>"></script>
        <script src="assets/js/collector-registration.js?v=<?php echo $asset_version('assets/js/collector-registration.js'); ?>"></script>
    </body>
</html>
