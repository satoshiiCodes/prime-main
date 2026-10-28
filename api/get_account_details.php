<?php
/**
 * System account details - read-only view markup for the modal body (.pc-view).
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

    $full_name = $account['last_name'] . ', ' . $account['first_name'];

    if (!empty($account['middle_name'])) {
        $full_name .= ' ' . $account['middle_name'];
    }

    if (!empty($account['suffix']) && $account['suffix'] !== 'None') {
        $full_name .= ' ' . $account['suffix'];
    }

    $registered = !empty($account['registration_date']) ? date('M j, Y g:i A', strtotime($account['registration_date'])) : '';
    $created = !empty($account['created_at']) ? date('M j, Y g:i A', strtotime($account['created_at'])) : '';
    $updated = !empty($account['updated_at']) ? date('M j, Y g:i A', strtotime($account['updated_at'])) : '';

    $type = (string) ($account['account_type'] ?? '');
    $type_tone = $type === 'Admin' ? 'danger' : 'brand';

    $show = static function ($value, $fallback = '—') {
        $value = trim((string) $value);

        return $value === '' ? $fallback : htmlspecialchars($value);
    };

    $suffix_value = trim((string) ($account['suffix'] ?? ''));
    $has_suffix = $suffix_value !== '' && $suffix_value !== 'None';
    ?>
    <div class="pc-view">
        <div class="pc-view__hero">
            <div class="pc-view__avatar">
                <i class="fas fa-user-shield" aria-hidden="true"></i>
                <span class="pc-view__dot pc-view__dot--success" title="Active account"></span>
            </div>

            <div class="pc-view__identity">
                <h4 class="pc-view__name"><?php echo htmlspecialchars($full_name); ?></h4>
                <p class="pc-view__meta">
                    <?php echo trim((string) $account['employee_number']) !== '' ? 'Employee #' . htmlspecialchars((string) $account['employee_number']) : 'No employee number'; ?>
                    &middot; <?php echo $show($type); ?>
                </p>
                <div class="pc-view__pills">
                    <span class="pc-view__pill pc-view__pill--<?php echo $type_tone; ?>"><i class="fas fa-user-shield" aria-hidden="true"></i> <?php echo $show($type); ?></span>
                    <span class="pc-view__pill"><i class="fas fa-code-branch" aria-hidden="true"></i> <?php echo $show($account['branch'], 'No branch'); ?></span>
                    <span class="pc-view__pill"><i class="fas fa-at" aria-hidden="true"></i> <?php echo $show($account['username']); ?></span>
                </div>
            </div>
        </div>

        <section class="pc-view__section">
            <h6 class="pc-view__title"><span class="pc-view__icon"><i class="fas fa-id-card" aria-hidden="true"></i></span> Personal Information</h6>
            <dl class="pc-view__grid">
                <div class="pc-view__item">
                    <dt>Employee Number</dt>
                    <dd class="<?php echo trim((string) $account['employee_number']) === '' ? 'is-muted' : ''; ?>"><?php echo $show($account['employee_number'], 'Not set'); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Full Name</dt>
                    <dd><?php echo htmlspecialchars($full_name); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Last Name</dt>
                    <dd><?php echo $show($account['last_name']); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>First Name</dt>
                    <dd><?php echo $show($account['first_name']); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Middle Name</dt>
                    <dd class="<?php echo trim((string) $account['middle_name']) === '' ? 'is-muted' : ''; ?>"><?php echo $show($account['middle_name'], 'Not set'); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Suffix</dt>
                    <dd class="<?php echo $has_suffix ? '' : 'is-muted'; ?>"><?php echo $has_suffix ? htmlspecialchars($suffix_value) : 'None'; ?></dd>
                </div>
            </dl>
        </section>

        <section class="pc-view__section">
            <h6 class="pc-view__title"><span class="pc-view__icon"><i class="fas fa-location-dot" aria-hidden="true"></i></span> Contact Information</h6>
            <dl class="pc-view__grid">
                <div class="pc-view__item pc-view__item--wide">
                    <dt>Address</dt>
                    <dd class="<?php echo trim((string) $account['address']) === '' ? 'is-muted' : ''; ?>"><?php echo $show($account['address'], 'Not set'); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Contact Number</dt>
                    <dd><?php echo $show($account['contact_number'], 'Not set'); ?></dd>
                </div>
            </dl>
        </section>

        <section class="pc-view__section">
            <h6 class="pc-view__title"><span class="pc-view__icon"><i class="fas fa-shield-halved" aria-hidden="true"></i></span> Account Information</h6>
            <dl class="pc-view__grid">
                <div class="pc-view__item">
                    <dt>Account Type</dt>
                    <dd><?php echo $show($type); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Username</dt>
                    <dd><?php echo $show($account['username']); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Branch</dt>
                    <dd><?php echo $show($account['branch'], 'Not set'); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Registered</dt>
                    <dd class="<?php echo $registered === '' ? 'is-muted' : ''; ?>"><?php echo $registered === '' ? 'Not recorded' : htmlspecialchars($registered); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Created</dt>
                    <dd class="<?php echo $created === '' ? 'is-muted' : ''; ?>"><?php echo $created === '' ? 'Not recorded' : htmlspecialchars($created); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Last Updated</dt>
                    <dd class="<?php echo $updated === '' ? 'is-muted' : ''; ?>"><?php echo $updated === '' ? 'Not recorded' : htmlspecialchars($updated); ?></dd>
                </div>
            </dl>
        </section>
    </div>
    <?php

    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error retrieving account details: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
