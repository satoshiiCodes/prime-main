<?php
/**
 * Edit Account form payload - same field design as the Edit Collector modal:
 * .form-section + .section-title, .form-label + .form-control + .form-text.
 * Only the body fields are returned; the modal shell owns the form + footer.
 */

session_start();
if (!isset($_SESSION['logged_in'])) {
    exit("Unauthorized access");
}

require '../includes/db_config.php';

if (!isset($_GET['id'])) {
    echo '<div class="alert alert-danger">No account ID specified.</div>';
    exit;
}

$account_id = intval($_GET['id']);

try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("SELECT * FROM accounts WHERE id = ?");
    $stmt->bind_param("i", $account_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        echo '<div class="alert alert-danger">Account not found.</div>';
        $stmt->close();
        $conn->close();
        exit;
    }

    $account = $result->fetch_assoc();

    /* Area Manager keeps a comma separated branch list. */
    $selected_branches = array();

    if ($account['account_type'] === 'Area Manager' && !empty($account['branch'])) {
        $selected_branches = array_map('trim', explode(',', $account['branch']));
    }

    $branch_options = array('Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan');

    $value = static function ($key) use ($account) {
        return htmlspecialchars((string) ($account[$key] ?? ''));
    };

    ob_start();
    ?>
                            <input type="hidden" name="id" value="<?php echo (int) $account['id']; ?>">

                            <div class="form-section">
                                <h5 class="section-title">Account Information</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="employee_number" class="form-label">Employee Number</label>
                                            <input type="text" class="form-control" id="employee_number" name="employee_number"
                                                   value="<?php echo $value('employee_number'); ?>" autocomplete="off">
                                            <div class="form-text">Unique employee identification number (optional)</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="username" class="form-label required-field">Username</label>
                                            <input type="text" class="form-control" id="username" name="username"
                                                   value="<?php echo $value('username'); ?>" autocomplete="off" required>
                                            <div class="form-text">Must be unique</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="account_type" class="form-label required-field">Account Type</label>
                                            <select class="form-select" id="account_type" name="account_type" required>
                                                <option value="">Select Account Type</option>
                                                <option value="Admin"<?php echo $account['account_type'] === 'Admin' ? ' selected' : ''; ?>>Admin</option>
                                                <option value="Area Manager"<?php echo $account['account_type'] === 'Area Manager' ? ' selected' : ''; ?>>Area Manager</option>
                                                <option value="Branch Manager"<?php echo $account['account_type'] === 'Branch Manager' ? ' selected' : ''; ?>>Branch Manager</option>
                                                <option value="Proxy Collector-Cashier"<?php echo $account['account_type'] === 'Proxy Collector-Cashier' ? ' selected' : ''; ?>>Proxy Collector-Cashier</option>
                                                <option value="Proxy Collector"<?php echo $account['account_type'] === 'Proxy Collector' ? ' selected' : ''; ?>>Proxy Collector</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6" id="singleBranchContainer">
                                        <div class="mb-3">
                                            <label for="branch" class="form-label required-field">Branch</label>
                                            <select class="form-select" id="branch" name="branch">
                                                <option value="All Branches"<?php echo $account['branch'] === 'All Branches' ? ' selected' : ''; ?>>All Branches</option>
                                                <?php foreach ($branch_options as $branch): ?>
                                                <option value="<?php echo htmlspecialchars($branch); ?>"<?php echo $account['branch'] === $branch ? ' selected' : ''; ?>><?php echo htmlspecialchars($branch); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-12" id="multipleBranchesContainer">
                                        <div class="branches-container" id="branchesContainer" style="display: <?php echo $account['account_type'] === 'Area Manager' ? 'block' : 'none'; ?>;">
                                            <label class="form-label required-field">Select Branches (Area Manager)</label>
                                            <div class="branch-checkbox-group">
                                                <div class="row">
                                                    <?php foreach ($branch_options as $index => $branch): ?>
                                                    <div class="col-md-6 branch-checkbox-item">
                                                        <div class="form-check">
                                                            <input class="form-check-input branch-checkbox" type="checkbox" name="branches[]"
                                                                   value="<?php echo htmlspecialchars($branch); ?>"
                                                                   id="branch_<?php echo $index; ?>"
                                                                   <?php echo in_array($branch, $selected_branches, true) ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="branch_<?php echo $index; ?>">
                                                                <?php echo htmlspecialchars($branch); ?>
                                                            </label>
                                                        </div>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <div class="form-text">Area Manager can manage multiple branches. Select all applicable branches.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h5 class="section-title">Login Credentials</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="password" class="form-label">New Password</label>
                                            <input type="password" class="form-control" id="password" name="password"
                                                   autocomplete="new-password" data-lpignore="true" data-form-type="other">
                                            <div class="form-text">Leave blank to keep current password &middot; minimum 8 characters</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                                            <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                                   autocomplete="new-password" data-lpignore="true" data-form-type="other">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h5 class="section-title">Personal Information</h5>
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="last_name" class="form-label required-field">Last Name</label>
                                            <input type="text" class="form-control" id="last_name" name="last_name"
                                                   value="<?php echo $value('last_name'); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="first_name" class="form-label required-field">First Name</label>
                                            <input type="text" class="form-control" id="first_name" name="first_name"
                                                   value="<?php echo $value('first_name'); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="middle_name" class="form-label">Middle Name</label>
                                            <input type="text" class="form-control" id="middle_name" name="middle_name"
                                                   value="<?php echo $value('middle_name'); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="suffix" class="form-label">Suffix</label>
                                            <select class="form-select" id="suffix" name="suffix">
                                                <option value="">None</option>
                                                <?php foreach (array('Sr', 'Jr', 'III', 'IV') as $suffix): ?>
                                                <option value="<?php echo $suffix; ?>"<?php echo $account['suffix'] === $suffix ? ' selected' : ''; ?>><?php echo $suffix; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h5 class="section-title">Contact Information</h5>
                                <div class="row">
                                    <div class="col-12">
                                        <div class="mb-3">
                                            <label for="address" class="form-label required-field">Address</label>
                                            <textarea class="form-control" id="address" name="address" rows="3" required><?php echo $value('address'); ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="contact_number" class="form-label required-field">Contact Number</label>
                                            <input type="tel" class="form-control" id="contact_number" name="contact_number"
                                                   pattern="09\d{9}" maxlength="11"
                                                   value="<?php echo $value('contact_number'); ?>" required>
                                            <div class="form-text">Must be 11 digits starting with "09"</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
    <?php
    echo ob_get_clean();

    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error loading edit form: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
