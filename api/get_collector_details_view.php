<?php
/**
 * Collector account details - read-only record rendered with the dash detail
 * view component (.pc-view): identity hero + labelled value groups.
 */

session_start();

require '../includes/db_config.php';

if (!isset($_GET['id']) || $_GET['id'] === '') {
    echo '<div class="alert alert-danger">No collector ID provided.</div>';
    exit;
}

$collector_id = intval($_GET['id']);

try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("
        SELECT
            id, last_name, first_name, middle_name, suffix,
            address, contact_number, account_type, branch,
            registration_date, status, gender, area,
            birth_date, place_of_birth, spouse_name,
            account_status, username, profile_image,
            employee_number
        FROM collector_accounts
        WHERE id = ?
    ");
    $stmt->bind_param("i", $collector_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        echo '<div class="alert alert-danger">Collector not found.</div>';
        $stmt->close();
        $conn->close();
        exit;
    }

    $collector = $result->fetch_assoc();

    $full_name = $collector['last_name'] . ', ' . $collector['first_name'];

    if (!empty($collector['middle_name'])) {
        $full_name .= ' ' . $collector['middle_name'];
    }

    if (!empty($collector['suffix']) && $collector['suffix'] !== 'None') {
        $full_name .= ' ' . $collector['suffix'];
    }

    $age = '';

    if (!empty($collector['birth_date']) && $collector['birth_date'] !== '0000-00-00') {
        $age = (new DateTime($collector['birth_date']))->diff(new DateTime())->y;
    }

    $registration_date = '';

    if (!empty($collector['registration_date'])) {
        $registration_date = date('M j, Y g:i A', strtotime($collector['registration_date']));
    }

    $status = (string) $collector['account_status'];
    $status_tone = $status === 'Active' ? 'success' : ($status === 'Inactive' ? 'secondary' : 'warning');
    $dot_tone = $status === 'Active' ? 'success' : ($status === 'Inactive' ? 'secondary' : 'warning');

    $has_photo = !empty($collector['profile_image']) && file_exists(__DIR__ . '/../' . $collector['profile_image']);

    $show = static function ($value, $fallback = '—') {
        $value = trim((string) $value);

        return $value === '' ? $fallback : htmlspecialchars($value);
    };

    $birth_display = '';

    if (!empty($collector['birth_date']) && $collector['birth_date'] !== '0000-00-00') {
        $birth_display = date('M j, Y', strtotime($collector['birth_date']));

        if ($age !== '') {
            $birth_display .= ' &middot; ' . $age . ' yrs';
        }
    }
    ?>
    <div class="pc-view">
        <div class="pc-view__hero">
            <div class="pc-view__avatar">
                <?php if ($has_photo): ?>
                    <img src="<?php echo htmlspecialchars($collector['profile_image']); ?>" alt="<?php echo htmlspecialchars($full_name); ?>">
                <?php else: ?>
                    <i class="fas fa-user" aria-hidden="true"></i>
                <?php endif; ?>
                <span class="pc-view__dot pc-view__dot--<?php echo $dot_tone; ?>" title="<?php echo htmlspecialchars($status); ?>"></span>
            </div>

            <div class="pc-view__identity">
                <h4 class="pc-view__name"><?php echo htmlspecialchars($full_name); ?></h4>
                <p class="pc-view__meta">
                    <?php echo trim((string) $collector['employee_number']) !== '' ? 'Employee #' . htmlspecialchars((string) $collector['employee_number']) : 'No employee number'; ?>
                    &middot; <?php echo htmlspecialchars((string) $collector['account_type']); ?>
                </p>
                <div class="pc-view__pills">
                    <span class="pc-view__pill pc-view__pill--dot pc-view__pill--<?php echo $status_tone; ?>">
                        <i class="fas fa-circle" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($status); ?>
                    </span>
                    <span class="pc-view__pill pc-view__pill--brand"><i class="fas fa-layer-group" aria-hidden="true"></i> <?php echo $show($collector['area'], 'No area'); ?></span>
                    <span class="pc-view__pill"><i class="fas fa-code-branch" aria-hidden="true"></i> <?php echo $show($collector['branch'], 'No branch'); ?></span>
                </div>
            </div>
        </div>

        <section class="pc-view__section">
            <h6 class="pc-view__title"><span class="pc-view__icon"><i class="fas fa-id-card" aria-hidden="true"></i></span> Personal Information</h6>
            <dl class="pc-view__grid">
                <div class="pc-view__item">
                    <dt>Employee Number</dt>
                    <dd class="<?php echo trim((string) $collector['employee_number']) === '' ? 'is-muted' : ''; ?>"><?php echo $show($collector['employee_number'], 'Not set'); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Account Type</dt>
                    <dd><?php echo $show($collector['account_type']); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Birth Date</dt>
                    <dd class="<?php echo $birth_display === '' ? 'is-muted' : ''; ?>"><?php echo $birth_display === '' ? 'Not set' : $birth_display; ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Place of Birth</dt>
                    <dd class="<?php echo trim((string) $collector['place_of_birth']) === '' ? 'is-muted' : ''; ?>"><?php echo $show($collector['place_of_birth'], 'Not set'); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Gender</dt>
                    <dd><?php echo $show($collector['gender']); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Civil Status</dt>
                    <dd><?php echo $show($collector['status']); ?></dd>
                </div>
                <?php if (!empty($collector['spouse_name'])): ?>
                <div class="pc-view__item pc-view__item--wide">
                    <dt>Spouse</dt>
                    <dd><?php echo htmlspecialchars((string) $collector['spouse_name']); ?></dd>
                </div>
                <?php endif; ?>
            </dl>
        </section>

        <section class="pc-view__section">
            <h6 class="pc-view__title"><span class="pc-view__icon"><i class="fas fa-location-dot" aria-hidden="true"></i></span> Contact Information</h6>
            <dl class="pc-view__grid">
                <div class="pc-view__item pc-view__item--wide">
                    <dt>Address</dt>
                    <dd class="<?php echo trim((string) $collector['address']) === '' ? 'is-muted' : ''; ?>"><?php echo $show($collector['address'], 'Not set'); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Contact Number</dt>
                    <dd><?php echo $show($collector['contact_number'], 'Not set'); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Area</dt>
                    <dd><?php echo $show($collector['area'], 'Not set'); ?></dd>
                </div>
            </dl>
        </section>

        <section class="pc-view__section">
            <h6 class="pc-view__title"><span class="pc-view__icon"><i class="fas fa-shield-halved" aria-hidden="true"></i></span> Account Information</h6>
            <dl class="pc-view__grid">
                <div class="pc-view__item">
                    <dt>Username</dt>
                    <dd><?php echo $show($collector['username']); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Branch</dt>
                    <dd><?php echo $show($collector['branch'], 'Not set'); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Registered</dt>
                    <dd class="<?php echo $registration_date === '' ? 'is-muted' : ''; ?>"><?php echo $registration_date === '' ? 'Not recorded' : htmlspecialchars($registration_date); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Account Status</dt>
                    <dd>
                        <span class="pc-view__pill pc-view__pill--dot pc-view__pill--<?php echo $status_tone; ?>">
                            <i class="fas fa-circle" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($status); ?>
                        </span>
                    </dd>
                </div>
            </dl>
        </section>
    </div>
    <?php

    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
