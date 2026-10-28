<?php
/**
 * Prime City - Customer list page.
 *
 * Layout only: data and request handling come from actions/customer-actions.php,
 * asynchronous calls go to api/customer-endpoint.php, markup chrome comes from
 * includes/*, styles from assets/css, behaviour from assets/js.
 */

require_once __DIR__ . '/actions/customer-actions.php';
require_once __DIR__ . '/actions/customer-registration-actions.php';

customer_start_session();
customer_handle_logout();

if (!customer_is_authenticated()) {
    customer_redirect('index.php');
    exit;
}

try {
    $pdo = db_pdo();
    $user = customer_fetch_user($pdo);

    customer_handle_download($pdo, $user);
    $context = customer_context($_GET['branch'] ?? '', true, $user);

    // Branch options for the registration form in the "Add New Customer" modal.
    $registrationBranch = registration_branch_options($user);
    $available_branches = $registrationBranch['available'];
    $branch_disabled = $registrationBranch['disabled'];
    $selected_branch = $registrationBranch['selected'];
} catch (Throwable $e) {
    error_log('[customer-list] ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
            <meta charset="utf-8" />
            <title>Customer List - Unavailable</title>
            <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">The customer list is unavailable</h4>
                    <p class="mb-0">We could not load the customer records right now. Please try again in a moment.</p>
                </div>
            </div>
        </body>
    </html>
    <?php
    exit;
}

$user = $context['user'];
$layout_active = 'customer-list';

$flags = $context['flags'];
$filters = $context['filters'];
$branches = $context['branches'];
$customers = $context['customers'];

$statusFilter = $filters['status'];
$branchFilter = $filters['branch'];
$searchQuery = $filters['search'];
// Summary figures for the stat strip; derived from the rows already fetched.
$totalCustomers = count($customers);
$activeCount = 0;
$inactiveCount = 0;
$otherCount = 0;

foreach ($customers as $customerRow) {
    $customerStatus = (string) ($customerRow['status'] ?? 'Active');

    if ($customerStatus === 'Active') {
        $activeCount++;
    } elseif ($customerStatus === 'Inactive') {
        $inactiveCount++;
    } else {
        $otherCount++;
    }
}

$branchSet = array();
foreach ($customers as $customerRow) {
    if (!empty($customerRow['branch'])) {
        $branchSet[$customerRow['branch']] = true;
    }
}
$branchCount = count($branchSet);

$activePercent = $totalCustomers > 0 ? (int) round($activeCount * 100 / $totalCustomers) : 0;
$inactivePercent = $totalCustomers > 0 ? (int) round($inactiveCount * 100 / $totalCustomers) : 0;

$pageConfig = array(
    'endpoint'  => $context['endpoint'],
    'listUrl'   => 'customer_list.php',
    'canManage' => $flags['can_manage'],
    'isAdmin'   => $flags['is_admin'],
);

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <meta name="description" content="Prime City customer accounts" />
        <meta name="author" content="Prime City" />
        <title>Customer List</title>
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/customer-list-style.css" rel="stylesheet" />
    <link href="assets/css/customer-registration-style.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    </head>
    <body class="sb-nav-fixed">
        <div class="toast-container"></div>

        <!-- Image View Modal -->
        <div class="modal fade" id="imageViewModal" tabindex="-1" aria-labelledby="imageViewModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="imageViewModalLabel">Customer Profile Image</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body text-center p-0">
                        <img id="fullSizeImage" src="" class="img-fluid" alt="Full Size Profile Image">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add New Customer Modal -->
        <div class="modal fade pc-modal" id="addCustomerModal" tabindex="-1" aria-labelledby="addCustomerModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content pc-modal__content">
                    <header class="pc-modal__head">
                        <span class="pc-modal__icon"><i class="fas fa-user-plus" aria-hidden="true"></i></span>
                        <div class="pc-modal__heading">
                            <h5 class="modal-title" id="addCustomerModalLabel">Customer Registration</h5>
                            <p class="pc-modal__sub">Fields marked with an asterisk are required</p>
                        </div>
                        <button type="button" class="pc-modal__close" data-bs-dismiss="modal" aria-label="Close">
                            <i class="fas fa-xmark" aria-hidden="true"></i>
                        </button>
                    </header>

                    <form id="customerRegistrationForm" class="dash-form" action="customer_registration.php" method="POST" enctype="multipart/form-data">
                        <div class="modal-body pc-modal__body">
                            <?php include __DIR__ . '/includes/customer-registration-form.php'; ?>
                        </div>

                        <footer class="pc-modal__foot">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check me-1" aria-hidden="true"></i>
                                Register Customer
                            </button>
                        </footer>
                    </form>
                </div>
            </div>
        </div>
        <!-- Edit Customer Modal -->
        <div class="modal fade pc-modal" id="editCustomerModal" tabindex="-1" aria-labelledby="editCustomerModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content pc-modal__content">
                    <header class="pc-modal__head">
                        <span class="pc-modal__icon"><i class="fas fa-user-pen" aria-hidden="true"></i></span>
                        <div class="pc-modal__heading">
                            <h5 class="modal-title" id="editCustomerModalLabel">Edit Customer</h5>
                            <p class="pc-modal__sub">Fields marked with an asterisk are required</p>
                        </div>
                        <button type="button" class="pc-modal__close" data-bs-dismiss="modal" aria-label="Close">
                            <i class="fas fa-xmark" aria-hidden="true"></i>
                        </button>
                    </header>

                    <form id="editCustomerForm" class="dash-form" enctype="multipart/form-data">
                        <div class="modal-body pc-modal__body">
                            <input type="hidden" id="editCustomerId" name="id">
                            <input type="hidden" id="removeProfileImageFlag" name="remove_profile_image" value="0">
                            <input type="hidden" id="currentProfileImage" name="current_profile_image">

                            <!-- 1. Personal Information -->
                            <div class="form-section">
                                <h5 class="section-title">Personal Information</h5>

                                <div class="profile-upload" id="editProfileImageZone">
                                    <span class="upload__label">Profile Image</span>
                                    <label class="upload is-photo" for="editProfileImage">
                                        <input type="file" class="upload__input" id="editProfileImage" name="profile_image" accept="image/*">
                                        <img id="editProfileImagePreview" class="preview-image" src="" alt="" onerror="this.style.display='none'; var z = document.querySelector('#editProfileImageZone .upload'); if (z) { z.classList.remove('has-file'); }">
                                        <span class="upload__icon"><i class="fas fa-cloud-arrow-up" aria-hidden="true"></i></span>
                                        <span class="upload__title">Drop a photo here or click to browse</span>
                                        <span class="upload__hint">JPG, JPEG, PNG or GIF &middot; up to 5 MB</span>
                                    </label>
                                    <div class="upload__meta">
                                        <span class="upload__name" id="editProfileImageName">No file selected</span>
                                        <button type="button" class="upload__clear" id="removeProfileImageBtn" hidden>Remove</button>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <div class="form-floating">
                                            <input type="text" class="form-control" id="editLastName" name="last_name" placeholder=" " required>
                                            <label for="editLastName" class="required-field">Last Name</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-floating">
                                            <input type="text" class="form-control" id="editFirstName" name="first_name" placeholder=" " required>
                                            <label for="editFirstName" class="required-field">First Name</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-floating">
                                            <input type="text" class="form-control" id="editMiddleName" name="middle_name" placeholder=" ">
                                            <label for="editMiddleName">Middle Name</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-floating">
                                            <select class="form-select" id="editSuffix" name="suffix">
                                                <option value="None" selected>None</option>
                                                <option value="Sr.">Sr.</option>
                                                <option value="Jr.">Jr.</option>
                                                <option value="III">III</option>
                                                <option value="IV">IV</option>
                                            </select>
                                            <label for="editSuffix">Suffix</label>
                                        </div>
                                    </div>

                                    <div class="col-md-3">
                                        <div class="form-floating">
                                            <select class="form-select" id="editGender" name="gender" required>
                                                <option value="Male">Male</option>
                                                <option value="Female">Female</option>
                                            </select>
                                            <label for="editGender" class="required-field">Gender</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-floating">
                                            <select class="form-select" id="editMaritalStatus" name="marital_status">
                                                <option value="">Select Status</option>
                                                <option value="Single">Single</option>
                                                <option value="Married">Married</option>
                                                <option value="Widow">Widow</option>
                                                <option value="Separated">Separated</option>
                                            </select>
                                            <label for="editMaritalStatus">Marital Status</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-floating">
                                            <input type="date" class="form-control" id="editDateOfBirth" name="date_of_birth" placeholder=" ">
                                            <label for="editDateOfBirth">Date of Birth</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-floating">
                                            <input type="text" class="form-control" id="editPlaceOfBirth" name="place_of_birth" placeholder=" ">
                                            <label for="editPlaceOfBirth">Place of Birth</label>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="form-floating">
                                            <input type="text" class="form-control" id="editSpouseName" name="spouse_name" placeholder=" ">
                                            <label for="editSpouseName">Spouse Name</label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-floating">
                                            <input type="text" class="form-control" id="editSpouseOccupation" name="spouse_occupation" placeholder=" ">
                                            <label for="editSpouseOccupation">Spouse Occupation</label>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="form-floating">
                                            <select class="form-select" id="editArea" name="area">
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
                                            <label for="editArea">Area</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-floating">
                                            <select class="form-select" id="editBranch" name="branch">
                                                <option value="">Select Branch</option>
                                                <option value="Tuguegarao City">Tuguegarao City</option>
                                                <option value="Gattaran">Gattaran</option>
                                                <option value="Abulug">Abulug</option>
                                                <option value="Ilagan">Ilagan</option>
                                            </select>
                                            <label for="editBranch">Branch</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-floating">
                                            <select class="form-select" id="editStatus" name="status">
                                                <option value="Active">Active</option>
                                                <option value="Inactive">Inactive</option>
                                                <option value="Pending">Pending</option>
                                                <option value="Suspended">Suspended</option>
                                            </select>
                                            <label for="editStatus">Account Status</label>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="form-floating">
                                            <input type="text" class="form-control" id="editContactNumber" name="contact_number" placeholder=" " required>
                                            <label for="editContactNumber" class="required-field">Contact Number</label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-floating">
                                            <input type="text" class="form-control" id="editContactNumber2" name="contact_number_2" placeholder=" ">
                                            <label for="editContactNumber2">Contact Number #2 (Optional)</label>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="new-requirements-container" id="editRequirementsZone">
                                            <span class="upload__label">Requirements on file</span>
                                            <div id="currentRequirementsContainer">
                                                <p class="text-muted small mb-0">Loading current requirements...</p>
                                            </div>

                                            <span class="upload__label mt-3">Add requirements</span>
                                            <label class="upload" for="newRequirements">
                                                <input type="file" class="upload__input" id="newRequirements" name="new_requirements[]" multiple>
                                                <span class="upload__icon"><i class="fas fa-folder-open" aria-hidden="true"></i></span>
                                                <span class="upload__title">Drop documents here or click to browse</span>
                                                <span class="upload__hint">Word, Excel, PDF or images &middot; up to 20 MB each</span>
                                            </label>
                                            <div class="upload__meta">
                                                <span class="upload__name" id="editRequirementsName">No files selected</span>
                                                <button type="button" class="upload__clear" id="editRequirementsClear" hidden>Clear all</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 2. Contact Information -->
                            <div class="form-section">
                                <h5 class="section-title">Contact Information</h5>

                                <div class="address-section">
                                    <div class="address-title">Permanent Address</div>
                                    <div class="row g-3">
                                        <div class="col-md-2">
                                            <div class="form-floating">
                                                <input type="text" class="form-control" id="editHouseNumber" name="house_number" placeholder="No.">
                                                <label for="editHouseNumber">No.</label>
                                            </div>
                                        </div>
                                        <div class="col-md-5">
                                            <div class="form-floating">
                                                <input type="text" class="form-control" id="editStreet" name="street" placeholder="Street" required>
                                                <label for="editStreet" class="required-field">Street</label>
                                            </div>
                                        </div>
                                        <div class="col-md-5">
                                            <div class="form-floating">
                                                <input type="text" class="form-control" id="editBuilding" name="building" placeholder="Floor/Building">
                                                <label for="editBuilding">Floor/Building</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-floating">
                                                <input type="text" class="form-control" id="editBarangay" name="barangay" placeholder="Barangay" required>
                                                <label for="editBarangay" class="required-field">Barangay</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-floating">
                                                <input type="text" class="form-control" id="editMunicipality" name="municipality" placeholder="Municipality/City" required>
                                                <label for="editMunicipality" class="required-field">Municipality/City</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-floating">
                                                <input type="text" class="form-control" id="editProvince" name="province" placeholder="Province" required>
                                                <label for="editProvince" class="required-field">Province</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="address-section">
                                    <div class="form-check mb-2">
                                        <label class="form-check-label address-title" for="editSameAsPermanent">
                                            Present Address (if different)
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="editSameAsPermanent" name="sameAsPermanent">
                                        <div class="calculation-note">(Same as Permanent Address)</div>
                                    </div>

                                    <div class="row g-3" id="editPresentAddressFields">
                                        <div class="col-md-2">
                                            <div class="form-floating">
                                                <input type="text" class="form-control" id="editPresentHouseNumber" name="present_house_number" placeholder="No.">
                                                <label for="editPresentHouseNumber">No.</label>
                                            </div>
                                        </div>
                                        <div class="col-md-5">
                                            <div class="form-floating">
                                                <input type="text" class="form-control" id="editPresentStreet" name="present_street" placeholder="Street">
                                                <label for="editPresentStreet">Street</label>
                                            </div>
                                        </div>
                                        <div class="col-md-5">
                                            <div class="form-floating">
                                                <input type="text" class="form-control" id="editPresentBuilding" name="present_building" placeholder="Floor/Building">
                                                <label for="editPresentBuilding">Floor/Building</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-floating">
                                                <input type="text" class="form-control" id="editPresentBarangay" name="present_barangay" placeholder="Barangay">
                                                <label for="editPresentBarangay">Barangay</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-floating">
                                                <input type="text" class="form-control" id="editPresentMunicipality" name="present_municipality" placeholder="Municipality/City">
                                                <label for="editPresentMunicipality">Municipality/City</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-floating">
                                                <input type="text" class="form-control" id="editPresentProvince" name="present_province" placeholder="Province">
                                                <label for="editPresentProvince">Province</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 3. Employment Information -->
                            <div class="form-section">
                                <h5 class="section-title">Employment Information</h5>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="form-floating">
                                            <input type="text" class="form-control" id="editOccupation" name="occupation" placeholder=" ">
                                            <label for="editOccupation">Occupation</label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-floating">
                                            <input type="text" class="form-control" id="editEmployer" name="employer_business_name" placeholder=" ">
                                            <label for="editEmployer">Employer/Business Name</label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-floating">
                                            <input type="text" class="form-control" id="editNatureOfWork" name="nature_of_work" placeholder=" ">
                                            <label for="editNatureOfWork">Nature of Work</label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 4. Account Credentials -->
                            <div class="form-section" id="passwordSection">
                                <h5 class="section-title">
                                    Account Credentials
                                    <?php if (!$flags['is_admin']): ?>
                                    <span class="section-note">Admin only</span>
                                    <?php endif; ?>
                                </h5>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <div class="form-floating">
                                            <input type="text" class="form-control" id="editUsername" name="username" placeholder=" " autocomplete="username" <?php echo $flags['is_admin'] ? '' : 'disabled'; ?>>
                                            <label for="editUsername">Username</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-floating position-relative">
                                            <input type="password" class="form-control" id="editPassword" name="password" placeholder=" " autocomplete="new-password" <?php echo $flags['is_admin'] ? '' : 'disabled'; ?>>
                                            <label for="editPassword">New Password</label>
                                            <i class="fas fa-eye password-toggle" onclick="togglePasswordVisibility('editPassword')"></i>
                                        </div>
                                        <div class="form-text">Leave blank to keep the current password</div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-floating position-relative">
                                            <input type="password" class="form-control" id="editConfirmPassword" name="confirm_password" placeholder=" " autocomplete="new-password" <?php echo $flags['is_admin'] ? '' : 'disabled'; ?>>
                                            <label for="editConfirmPassword">Confirm New Password</label>
                                            <i class="fas fa-eye password-toggle" onclick="togglePasswordVisibility('editConfirmPassword')"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 5. Additional Notes -->
                            <div class="form-section">
                                <h5 class="section-title">Additional Notes</h5>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <div class="form-floating">
                                            <textarea class="form-control" id="editNotes" name="notes" style="height: 100px" placeholder=" "></textarea>
                                            <label for="editNotes">Customer Notes</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <footer class="pc-modal__foot">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check me-1" aria-hidden="true"></i>
                                Save Changes
                            </button>
                        </footer>                    </form>
                </div>
            </div>
        </div>
        <!-- Delete Confirmation Modal -->
        <div class="modal fade" id="deleteConfirmationModal" tabindex="-1" aria-labelledby="deleteConfirmationModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="deleteConfirmationModalLabel">Confirm Deletion</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="text-center mb-4">
                            <i class="fas fa-exclamation-triangle fa-4x text-danger mb-3"></i>
                            <h5>Are you sure you want to delete this customer?</h5>
                            <p id="customerNameToDelete" class="fw-bold"></p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete Customer</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- View Payments Modal -->
        <div class="modal fade" id="viewPaymentsModal" tabindex="-1" aria-labelledby="viewPaymentsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="viewPaymentsModalLabel">Customer Payments</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="paymentsContainer">
                            <!-- Payments will be loaded here -->
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2">Loading payment history...</p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Requirements Modal -->
        <div class="modal fade" id="requirementsModal" tabindex="-1" aria-labelledby="requirementsModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-white">
                        <h5 class="modal-title" id="requirementsModalLabel">Download Requirements</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="requirementsContainer">
                            <div class="text-center py-4">
                                <div class="spinner-border text-warning" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2">Loading requirements...</p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="#" id="downloadAllRequirements" class="btn btn-warning me-auto">
                            <i class="fas fa-file-archive me-1"></i> Download All as ZIP
                        </a>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <?php include __DIR__ . '/includes/topbar.php'; ?>

        <div id="layoutSidenav">
            <?php include __DIR__ . '/includes/sidebar.php'; ?>

            <div id="layoutSidenav_content">
                <main>
                    <div class="dash">
                        <header class="dash-head">
                            <div>
                                <p class="dash-eyebrow">
                                    <i class="fas fa-users" aria-hidden="true"></i>
                                    Prime City &middot; Customers
                                </p>
                                <h1 class="dash-title">Customer List</h1>
                                <p class="dash-sub">
                                    <strong><?php echo number_format($totalCustomers); ?></strong>
                                    <?php echo $totalCustomers === 1 ? 'account' : 'accounts'; ?>
                                    <?php if ($branchFilter !== ''): ?>
                                    &middot; branch <strong><?php echo htmlspecialchars($branchFilter); ?></strong>
                                    <?php endif; ?>
                                    <?php if ($statusFilter !== ''): ?>
                                    &middot; status <strong><?php echo htmlspecialchars($statusFilter); ?></strong>
                                    <?php endif; ?>
                                    <?php if ($searchQuery !== ''): ?>
                                    &middot; matching &ldquo;<strong><?php echo htmlspecialchars($searchQuery); ?></strong>&rdquo;
                                    <?php endif; ?>
                                </p>
                            </div>

                            <div class="dash-head__actions">
                                <?php if ($flags['show_area_note']): ?>
                                <span class="dash-pill">
                                    <i class="fas fa-map-location-dot" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars((string) ($user['area'] ?? '')); ?>
                                </span>
                                <?php endif; ?>

                                <?php if ($flags['can_manage']): ?>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                                    <i class="fas fa-plus me-1" aria-hidden="true"></i>
                                    Add New Customer
                                </button>
                                <?php endif; ?>
                            </div>
                        </header>

                        <?php if (isset($_GET['error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($_GET['error']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php endif; ?>

                        <?php if (isset($_GET['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($_GET['success']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php endif; ?>

                        <section class="dash-stats" aria-label="Customer summary">
                            <article class="stat">
                                <div class="stat__body">
                                    <p class="stat__label">Customers</p>
                                    <p class="stat__value" id="statTotal"><?php echo number_format($totalCustomers); ?></p>
                                    <p class="stat__meta" id="statTotalMeta">
                                        <?php echo $branchCount === 1 ? '1 branch' : number_format($branchCount) . ' branches'; ?>
                                    </p>
                                </div>
                                <span class="stat__icon"><i class="fas fa-users" aria-hidden="true"></i></span>
                            </article>

                            <article class="stat stat--green">
                                <div class="stat__body">
                                    <p class="stat__label">Active</p>
                                    <p class="stat__value" id="statActive"><?php echo number_format($activeCount); ?></p>
                                    <p class="stat__meta" id="statActiveMeta"><?php echo $activePercent; ?>% of listed accounts</p>
                                </div>
                                <span class="stat__icon"><i class="fas fa-circle-check" aria-hidden="true"></i></span>
                            </article>

                            <article class="stat stat--cyan">
                                <div class="stat__body">
                                    <p class="stat__label">Inactive</p>
                                    <p class="stat__value" id="statInactive"><?php echo number_format($inactiveCount); ?></p>
                                    <p class="stat__meta" id="statInactiveMeta"><?php echo $inactivePercent; ?>% of listed accounts</p>
                                </div>
                                <span class="stat__icon"><i class="fas fa-circle-pause" aria-hidden="true"></i></span>
                            </article>

                            <article class="stat stat--navy">
                                <div class="stat__body">
                                    <p class="stat__label">Other statuses</p>
                                    <p class="stat__value" id="statOther"><?php echo number_format($otherCount); ?></p>
                                    <p class="stat__meta">pending or suspended</p>
                                </div>
                                <span class="stat__icon"><i class="fas fa-circle-exclamation" aria-hidden="true"></i></span>
                            </article>
                        </section>

                        <section class="dash-card">
                            <header class="dash-card__head">
                                <span class="dash-card__icon"><i class="fas fa-address-book" aria-hidden="true"></i></span>
                                <div>
                                    <h2 class="dash-card__title">Customer Accounts</h2>
                                    <p class="dash-card__hint">Profiles, branches, contact details and account status</p>
                                </div>
                            </header>

                            <div class="dash-toolbar">
                                <form method="GET" action="customer_list.php" class="dash-toolbar__search">
                                    <?php
                                    $hasServerFilters = $searchQuery !== '' || $statusFilter !== '' || $branchFilter !== '';
                                    ?>
                                    <div class="dash-search">
                                        <input
                                            type="text"
                                            class="form-control search-input"
                                            name="search"
                                            placeholder="Search name, area or contact"
                                            value="<?php echo htmlspecialchars($searchQuery); ?>"
                                            autocomplete="off"
                                            spellcheck="false"
                                            aria-label="Search customers"
                                        >
                                        <a href="customer_list.php"
                                           class="dash-toolbar__clear"
                                           title="Clear search and filters"
                                           aria-label="Clear search and filters"
                                           data-has-filters="<?php echo $hasServerFilters ? '1' : '0'; ?>"
                                           <?php echo $hasServerFilters ? '' : 'hidden'; ?>>
                                            <i class="fas fa-xmark" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </form>

                                <div class="dash-toolbar__field">
                                    <label for="statusFilter">Status</label>
                                    <select class="form-select filter-select" id="statusFilter">
                                        <option value="">All Status</option>
                                        <option value="Active" <?php echo $statusFilter === 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo $statusFilter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="Pending" <?php echo $statusFilter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="Suspended" <?php echo $statusFilter === 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                                    </select>
                                </div>

                                <?php if ($flags['show_branch_filter']): ?>
                                <div class="dash-toolbar__field">
                                    <label for="branchFilter">Branch</label>
                                    <select class="form-select filter-select" id="branchFilter">
                                        <?php if ($flags['is_admin']): ?>
                                        <option value="">All Branches</option>
                                        <?php endif; ?>

                                        <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo htmlspecialchars($branch); ?>"
                                            <?php echo $branchFilter === $branch ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($branch); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php else: ?>
                                <!-- Hidden input to maintain branch filter for restricted users -->
                                <input type="hidden" id="branchFilter" value="<?php echo htmlspecialchars($branchFilter); ?>">
                                <?php endif; ?>
                            </div>

                            <div class="dash-card__body">
                                <?php if (empty($customers)): ?>
                                <div class="no-results-container">
                                    <div class="no-results-icon"><i class="fas fa-search"></i></div>
                                    <h4 class="text-muted">No Customer Accounts Found</h4>
                                    <?php if ($statusFilter !== '' || $branchFilter !== '' || $searchQuery !== ''): ?>
                                    <p class="text-muted">
                                        <?php if ($statusFilter !== '') echo 'Status: ' . htmlspecialchars($statusFilter) . '<br>'; ?>
                                        <?php if ($branchFilter !== '') echo 'Branch: ' . htmlspecialchars($branchFilter) . '<br>'; ?>
                                        <?php if ($flags['is_collector'] && isset($user['area'])) echo 'Area: ' . htmlspecialchars($user['area']) . '<br>'; ?>
                                        <?php if ($searchQuery !== '') echo 'Search: "' . htmlspecialchars($searchQuery) . '"<br>'; ?>
                                    </p>
                                    <a href="customer_list.php" class="btn btn-primary mt-3">Clear Filters</a>
                                    <?php else: ?>
                                    <p class="text-muted">No customer accounts have been registered yet.</p>
                                    <?php if ($flags['can_manage']): ?>
                                    <a href="customer_registration.php" class="btn btn-primary mt-3">Add New Customer</a>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>

                                <table id="datatablesSimple">
                                    <thead>
                                        <tr>
                                            <th>Profile</th>
                                            <th>Customer</th>
                                            <th>Branch</th>
                                            <th>Address</th>
                                            <th>Contact Number</th>
                                            <th>Status</th>
                                            <th>Registration Date</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($customers as $customer): ?>
                                            <tr data-id="<?php echo (int) $customer['id']; ?>">
                                                <td class='text-center'>
                                                    <div class='customer-avatar'>
                                                        <div class='no-image-icon'><i class='fas fa-user'></i></div>
                                                        <?php if (!empty($customer['profile_image'])): ?>
                                                        <img src="<?php echo htmlspecialchars($customer['profile_image']); ?>" class='customer-image' alt='' onerror="this.remove()">
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php
                                                    $fullName = htmlspecialchars((string) ($customer['last_name'] ?? '')) . ', ' . htmlspecialchars((string) ($customer['first_name'] ?? ''));
                                                    if (!empty($customer['middle_name'])) {
                                                        $fullName .= ' ' . htmlspecialchars($customer['middle_name']);
                                                    }
                                                    if (!empty($customer['suffix']) && $customer['suffix'] !== 'None') {
                                                        $fullName .= ' ' . htmlspecialchars($customer['suffix']);
                                                    }
                                                    echo $fullName;
                                                    ?>
                                                    <?php if (!empty($customer['area'])): ?>
                                                    <div class="customer-area"><?php echo htmlspecialchars($customer['area']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo !empty($customer['branch']) ? htmlspecialchars($customer['branch']) : 'N/A'; ?></td>
                                                <td><?php echo htmlspecialchars((string) ($customer['address'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars((string) ($customer['contact_number'] ?? '')); ?></td>
                                                <td>
                                                    <?php
                                                    $status = $customer['status'] ?? 'Active';
                                                    $badgeClass = 'bg-info';

                                                    switch ($status) {
                                                        case 'Active':
                                                            $badgeClass = 'bg-success';
                                                            break;
                                                        case 'Inactive':
                                                            $badgeClass = 'bg-secondary';
                                                            break;
                                                        case 'Pending':
                                                            $badgeClass = 'bg-warning';
                                                            break;
                                                        case 'Suspended':
                                                            $badgeClass = 'bg-danger';
                                                            break;
                                                    }

                                                    echo "<span class='badge status-badge $badgeClass'>" . htmlspecialchars($status) . "</span>";
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $registrationDate = (string) ($customer['registration_date'] ?? '');
                                                    echo $registrationDate !== '' ? date('m-d-Y h:i:s A', strtotime($registrationDate)) : 'N/A';
                                                    ?>
                                                </td>
                                                <td>
                                                    <div class="action-buttons-container">
                                                        <?php if (!$flags['can_manage']): ?>
                                                        <!-- Collectors and proxy collectors only see payment history -->
                                                        <a href="customer_view_payment.php?id=<?php echo (int) $customer['id']; ?>" class="btn btn-success btn-sm" title="View Payments"><i class="fas fa-money-bill-wave"></i></a>
                                                        <?php else: ?>
                                                        <button class="btn btn-primary btn-sm edit-btn" data-id="<?php echo (int) $customer['id']; ?>" title="Edit"><i class="fas fa-edit"></i></button>

                                                        <a href="customer_view_payment.php?id=<?php echo (int) $customer['id']; ?>" class="btn btn-success btn-sm" title="View Payments"><i class="fas fa-money-bill-wave"></i></a>

                                                        <?php if (!$customer['has_active_loan']): ?>
                                                        <a href="add_financing.php?customer_id=<?php echo (int) $customer['id']; ?>" class="btn btn-new-loan btn-sm" title="New Loan"><i class="fas fa-file-invoice-dollar"></i></a>
                                                        <?php else: ?>
                                                        <button class="btn btn-new-loan btn-sm" disabled title="Customer has active loan"><i class="fas fa-file-invoice-dollar"></i></button>
                                                        <?php endif; ?>

                                                        <?php if (!empty($customer['requirements'])): ?>
                                                        <button class="btn btn-warning btn-sm requirements-btn" data-id="<?php echo (int) $customer['id']; ?>" title="Download Requirements"><i class="fas fa-download"></i></button>
                                                        <?php else: ?>
                                                        <button class="btn btn-warning btn-sm" disabled title="No Requirements"><i class="fas fa-download"></i></button>
                                                        <?php endif; ?>

                                                        <?php if ($flags['can_delete']): ?>
                                                        <button class="btn btn-danger btn-sm delete-btn" data-id="<?php echo (int) $customer['id']; ?>" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                                        <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>
                </main>

                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>

        <script type="application/json" id="customer-list-data"><?php echo json_encode($pageConfig, $jsonFlags); ?></script>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
        <script type="application/json" id="customer-registration-data">{"success":"","error":"","endpoint":"api\/customer-registration-endpoint.php"}</script>

        <script src="assets/js/pc-toast.js"></script>
        <script src="assets/js/customer-registration.js"></script>
    <script src="assets/js/customer-list.js"></script>
    </body>
</html>
