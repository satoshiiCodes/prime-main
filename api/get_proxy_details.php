<?php
/**
 * Proxy account details.
 *
 * ?format=json  -> raw record for the edit modal (unchanged contract)
 * default       -> read-only view markup for the modal body (.pc-view)
 */

session_start();
require '../includes/db_config.php';

$format = isset($_GET['format']) ? $_GET['format'] : 'html';

if (!isset($_SESSION['logged_in'])) {
    if ($format === 'html') {
        echo '<div class="alert alert-danger">Access denied.</div>';
    } else {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
    }
    exit;
}

if (!isset($_GET['id'])) {
    if ($format === 'html') {
        echo '<div class="alert alert-danger">No proxy ID provided.</div>';
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID parameter is required']);
    }
    exit;
}

$proxy_id = intval($_GET['id']);

try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("SELECT * FROM proxy_accounts WHERE id = ?");
    $stmt->bind_param("i", $proxy_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        if ($format === 'html') {
            echo '<div class="alert alert-danger">Proxy account not found.</div>';
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Proxy account not found']);
        }

        $stmt->close();
        $conn->close();
        exit;
    }

    $proxy = $result->fetch_assoc();

    if ($format !== 'html') {
        echo json_encode([
            'success' => true,
            'proxy' => $proxy
        ]);

        $stmt->close();
        $conn->close();
        exit;
    }

    $full_name = $proxy['last_name'] . ', ' . $proxy['first_name'];

    if (!empty($proxy['middle_name'])) {
        $full_name .= ' ' . $proxy['middle_name'];
    }

    if (!empty($proxy['suffix']) && $proxy['suffix'] !== 'None') {
        $full_name .= ' ' . $proxy['suffix'];
    }

    $age = '';

    if (!empty($proxy['birth_date']) && $proxy['birth_date'] !== '0000-00-00') {
        $age = (new DateTime($proxy['birth_date']))->diff(new DateTime())->y;
    }

    $birth_display = '';

    if (!empty($proxy['birth_date']) && $proxy['birth_date'] !== '0000-00-00') {
        $birth_display = date('M j, Y', strtotime($proxy['birth_date']));

        if ($age !== '') {
            $birth_display .= ' &middot; ' . $age . ' yrs';
        }
    }

    $created = !empty($proxy['created_at']) ? date('M j, Y g:i A', strtotime($proxy['created_at'])) : '';
    $updated = !empty($proxy['updated_at']) ? date('M j, Y g:i A', strtotime($proxy['updated_at'])) : '';

    $status = (string) ($proxy['account_status'] ?? '');
    $status_tone = $status === 'Active' ? 'success' : ($status === 'Inactive' ? 'secondary' : 'warning');
    $dot_tone = $status === 'Active' ? 'success' : ($status === 'Inactive' ? 'secondary' : 'warning');

    $has_photo = !empty($proxy['profile_image']) && file_exists(__DIR__ . '/../' . $proxy['profile_image']);

    $show = static function ($value, $fallback = '—') {
        $value = trim((string) $value);

        return $value === '' ? $fallback : htmlspecialchars($value);
    };

    $type = (string) ($proxy['account_type'] ?? '');
    ?>
    <div class="pc-view">
        <div class="pc-view__hero">
            <div class="pc-view__avatar">
                <?php if ($has_photo): ?>
                    <img src="<?php echo htmlspecialchars($proxy['profile_image']); ?>" alt="<?php echo htmlspecialchars($full_name); ?>">
                <?php else: ?>
                    <i class="fas fa-user" aria-hidden="true"></i>
                <?php endif; ?>
                <span class="pc-view__dot pc-view__dot--<?php echo $dot_tone; ?>" title="<?php echo htmlspecialchars($status); ?>"></span>
            </div>

            <div class="pc-view__identity">
                <h4 class="pc-view__name"><?php echo htmlspecialchars($full_name); ?></h4>
                <p class="pc-view__meta">
                    <?php echo trim((string) $proxy['employee_number']) !== '' ? 'Employee #' . htmlspecialchars((string) $proxy['employee_number']) : 'No employee number'; ?>
                    &middot; <?php echo $show($type); ?>
                </p>
                <div class="pc-view__pills">
                    <span class="pc-view__pill pc-view__pill--dot pc-view__pill--<?php echo $status_tone; ?>">
                        <i class="fas fa-circle" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($status); ?>
                    </span>
                    <span class="pc-view__pill pc-view__pill--brand"><i class="fas fa-layer-group" aria-hidden="true"></i> <?php echo $show($proxy['area'], 'No area'); ?></span>
                    <span class="pc-view__pill"><i class="fas fa-code-branch" aria-hidden="true"></i> <?php echo $show($proxy['branch'], 'No branch'); ?></span>
                </div>
            </div>
        </div>

        <section class="pc-view__section">
            <h6 class="pc-view__title"><span class="pc-view__icon"><i class="fas fa-id-card" aria-hidden="true"></i></span> Personal Information</h6>
            <dl class="pc-view__grid">
                <div class="pc-view__item">
                    <dt>Employee Number</dt>
                    <dd class="<?php echo trim((string) $proxy['employee_number']) === '' ? 'is-muted' : ''; ?>"><?php echo $show($proxy['employee_number'], 'Not set'); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Account Type</dt>
                    <dd><?php echo $show($type); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Birth Date</dt>
                    <dd class="<?php echo $birth_display === '' ? 'is-muted' : ''; ?>"><?php echo $birth_display === '' ? 'Not set' : $birth_display; ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Place of Birth</dt>
                    <dd class="<?php echo trim((string) $proxy['place_of_birth']) === '' ? 'is-muted' : ''; ?>"><?php echo $show($proxy['place_of_birth'], 'Not set'); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Gender</dt>
                    <dd><?php echo $show($proxy['gender']); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Civil Status</dt>
                    <dd><?php echo $show($proxy['status']); ?></dd>
                </div>
                <?php if (!empty($proxy['spouse_name'])): ?>
                <div class="pc-view__item pc-view__item--wide">
                    <dt>Spouse</dt>
                    <dd><?php echo htmlspecialchars((string) $proxy['spouse_name']); ?></dd>
                </div>
                <?php endif; ?>
            </dl>
        </section>

        <section class="pc-view__section">
            <h6 class="pc-view__title"><span class="pc-view__icon"><i class="fas fa-location-dot" aria-hidden="true"></i></span> Contact Information</h6>
            <dl class="pc-view__grid">
                <div class="pc-view__item pc-view__item--wide">
                    <dt>Address</dt>
                    <dd class="<?php echo trim((string) $proxy['address']) === '' ? 'is-muted' : ''; ?>"><?php echo $show($proxy['address'], 'Not set'); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Contact Number</dt>
                    <dd><?php echo $show($proxy['contact_number'], 'Not set'); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Area</dt>
                    <dd><?php echo $show($proxy['area'], 'Not set'); ?></dd>
                </div>
            </dl>
        </section>

        <section class="pc-view__section">
            <h6 class="pc-view__title"><span class="pc-view__icon"><i class="fas fa-shield-halved" aria-hidden="true"></i></span> Account Information</h6>
            <dl class="pc-view__grid">
                <div class="pc-view__item">
                    <dt>Username</dt>
                    <dd><?php echo $show($proxy['username']); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Branch</dt>
                    <dd><?php echo $show($proxy['branch'], 'Not set'); ?></dd>
                </div>
                <div class="pc-view__item">
                    <dt>Registered</dt>
                    <dd class="<?php echo $created === '' ? 'is-muted' : ''; ?>"><?php echo $created === '' ? 'Not recorded' : htmlspecialchars($created); ?></dd>
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
                <div class="pc-view__item pc-view__item--wide">
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
    error_log($e->getMessage());

    if ($format === 'html') {
        echo '<div class="alert alert-danger">Error loading proxy details: ' . htmlspecialchars($e->getMessage()) . '</div>';
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error loading proxy details: ' . $e->getMessage()]);
    }
}
