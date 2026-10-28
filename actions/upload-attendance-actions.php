<?php
/**
 * Prime City - Attendance upload actions.
 *
 * Server-side logic for upload_attendance.php: the signed-in account, the
 * attendance helpers, the delete / edit / manual-entry handlers, the Excel
 * import and the filtered record list.
 *
 * Produces no markup - the page includes it and renders.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Start the session once.
 *
 * @return void
 */
function ua_start_session()
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
function ua_is_authenticated()
{
    return isset($_SESSION['logged_in']);
}

/**
 * Redirect, falling back to JS when headers are already sent.
 *
 * @param  string $location Target path.
 * @return void
 */
function ua_redirect($location)
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
function ua_sign_out()
{
    ua_start_session();

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
function ua_handle_logout()
{
    if (!isset($_GET['logout'])) {
        return;
    }

    ua_sign_out();
    ua_redirect('index.php');
    exit;
}

/**
 * Determine if a given time string (H:i:s or H:i) falls within the half-day window (12:00 to 13:00 inclusive).
 */
function isHalfDayTime($timeStr) {
    if (empty($timeStr) || $timeStr === 'NULL') {
        return false;
    }
    $timeStr = str_replace("'", "", $timeStr);
    $parts = explode(':', $timeStr);
    if (count($parts) < 2) {
        return false;
    }
    $hours = (int)$parts[0];
    $minutes = (int)$parts[1];
    $totalMinutes = ($hours * 60) + $minutes;
    return ($totalMinutes >= 720 && $totalMinutes <= 780);
}

/**
 * Determine attendance status based on time in / time out.
 */
function determineAttendanceStatus($hasTimeIn, $hasTimeOut, $timeIn = null, $timeOut = null) {
    $timeInIsHalfday = isHalfDayTime($timeIn);
    $timeOutIsHalfday = isHalfDayTime($timeOut);

    if ($timeInIsHalfday || $timeOutIsHalfday) {
        return 'Halfday';
    }

    if ($hasTimeIn && $hasTimeOut) {
        return 'Present';
    } elseif ($hasTimeIn || $hasTimeOut) {
        return 'Halfday';
    }
    return 'Absent';
}

/**
 * Handle `?delete=`.
 *
 * @param  mysqli $conn Open connection.
 * @return bool True when a redirect should follow.
 */
function ua_handle_delete(mysqli $conn)
{
    if (!isset($_GET['delete'])) {
        return false;
    }

    $id = $conn->real_escape_string((string) $_GET['delete']);

    if ($conn->query("DELETE FROM attendance WHERE id = '$id'")) {
        $_SESSION['alert_type'] = 'success';
        $_SESSION['alert_message'] = 'Attendance record deleted successfully!';
    } else {
        $_SESSION['alert_type'] = 'error';
        $_SESSION['alert_message'] = 'Error deleting record: ' . $conn->error;
    }

    return true;
}

/**
 * Load the record requested via `?edit=`.
 *
 * @param  mysqli $conn Open connection.
 * @return array<string, mixed>|null
 */
function ua_edit_record(mysqli $conn)
{
    if (!isset($_GET['edit'])) {
        return null;
    }

    $id = $conn->real_escape_string((string) $_GET['edit']);
    $result = $conn->query("SELECT * FROM attendance WHERE id = '$id'");

    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }

    return null;
}

/**
 * Handle the manual add / update form.
 *
 * @param  mysqli $conn Open connection.
 * @return bool True when a redirect should follow.
 */
function ua_handle_manual_add(mysqli $conn)
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_POST['manual_add'])) {
        return false;
    }

        $employee_number = $conn->real_escape_string($_POST['employee_number']);
        $date = $conn->real_escape_string($_POST['date']);
        
        $time_in = (!empty($_POST['time_in'])) ? "'" . $conn->real_escape_string($_POST['time_in'] . ':00') . "'" : "NULL";
        $time_out = (!empty($_POST['time_out'])) ? "'" . $conn->real_escape_string($_POST['time_out'] . ':00') . "'" : "NULL";
        $department = $conn->real_escape_string($_POST['department']);
        $record_id = isset($_POST['record_id']) && !empty($_POST['record_id']) ? $conn->real_escape_string($_POST['record_id']) : null;
        
        if (strcasecmp(trim($department), 'Apayao') === 0) {
            $_SESSION['alert_type'] = 'error';
            $_SESSION['alert_message'] = "Records with branch 'Apayao' are not allowed to be saved.";
            $conn->close();
            return true;
        }
        
        $name = getEmployeeFormattedName($conn, $employee_number);
        
        if ($name === null) {
            $_SESSION['alert_type'] = 'error';
            $_SESSION['alert_message'] = "Employee not found or inactive. Please check the employee number.";
        } else {
            $hasTimeIn = ($time_in != "NULL");
            $hasTimeOut = ($time_out != "NULL");
            $isCompleteRecord = ($hasTimeIn && $hasTimeOut);
            
            $late_minutes = "NULL";
            if ($hasTimeIn) {
                $timeInValue = str_replace("'", "", $time_in);
                $calculatedLateMinutes = calculateLateMinutesFromTime($timeInValue, $department);
                if ($calculatedLateMinutes !== null) {
                    $late_minutes = "'" . $calculatedLateMinutes . "'";
                }
            }
            
            if ($record_id) {
                $status = determineAttendanceStatus($hasTimeIn, $hasTimeOut, $time_in, $time_out);
                $remark = ($status == 'Absent') ? 'Absence' : '';
                
                $sql = "UPDATE attendance SET
                        user_id = '$employee_number',
                        name = '$name',
                        department = '$department',
                        date = '$date',
                        before_noon_in = $time_in,
                        before_noon_out = NULL,
                        after_noon_in = NULL,
                        after_noon_out = $time_out,
                        late_minutes = $late_minutes,
                        remark = '$remark',
                        status = '$status',
                        updated_at = NOW()
                        WHERE id = '$record_id'";
                
                if ($conn->query($sql)) {
                    $_SESSION['alert_type'] = 'success';
                    $_SESSION['alert_message'] = "Attendance record updated successfully!";
                } else {
                    $_SESSION['alert_type'] = 'error';
                    $_SESSION['alert_message'] = "Error updating record: " . $conn->error;
                }
            } else {
                $checkSql = "SELECT id, before_noon_in, after_noon_out, late_minutes FROM attendance WHERE user_id = '$employee_number' AND date = '$date'";
                $checkResult = $conn->query($checkSql);
                
                if ($checkResult->num_rows > 0) {
                    $existingRecord = $checkResult->fetch_assoc();
                    
                    if ($isCompleteRecord) {
                        $finalTimeIn = $time_in;
                        $finalTimeOut = $time_out;
                        $finalLateMinutes = $late_minutes;
                        $action = "overwritten";
                    } else {
                        $finalTimeIn = ($existingRecord['before_noon_in'] !== null) ? "'" . $existingRecord['before_noon_in'] . "'" : $time_in;
                        $finalTimeOut = ($existingRecord['after_noon_out'] !== null) ? "'" . $existingRecord['after_noon_out'] . "'" : $time_out;
                        
                        $finalLateMinutes = "NULL";
                        if ($finalTimeIn != "NULL") {
                            $timeInValue = str_replace("'", "", $finalTimeIn);
                            $calculatedLateMinutes = calculateLateMinutesFromTime($timeInValue, $department);
                            if ($calculatedLateMinutes !== null) {
                                $finalLateMinutes = "'" . $calculatedLateMinutes . "'";
                            }
                        }
                        $action = "merged";
                    }
                    
                    $hasFinalTimeIn = ($finalTimeIn != "NULL");
                    $hasFinalTimeOut = ($finalTimeOut != "NULL");
                    $status = determineAttendanceStatus($hasFinalTimeIn, $hasFinalTimeOut, $finalTimeIn, $finalTimeOut);
                    $remark = ($status == 'Absent') ? 'Absence' : '';
                    
                    $sql = "UPDATE attendance SET
                            name = '$name',
                            department = '$department',
                            before_noon_in = $finalTimeIn,
                            before_noon_out = NULL,
                            after_noon_in = NULL,
                            after_noon_out = $finalTimeOut,
                            late_minutes = $finalLateMinutes,
                            remark = '$remark',
                            status = '$status',
                            updated_at = NOW()
                            WHERE user_id = '$employee_number' AND date = '$date'";
                    
                    if ($conn->query($sql)) {
                        $_SESSION['alert_type'] = 'success';
                        $_SESSION['alert_message'] = "Attendance record $action successfully!";
                    } else {
                        $_SESSION['alert_type'] = 'error';
                        $_SESSION['alert_message'] = "Error updating record: " . $conn->error;
                    }
                } else {
                    $status = determineAttendanceStatus($hasTimeIn, $hasTimeOut, $time_in, $time_out);
                    $remark = ($status == 'Absent') ? 'Absence' : '';
                    
                    $sql = "INSERT INTO attendance 
                            (user_id, name, department, date, before_noon_in, before_noon_out, after_noon_in, after_noon_out, late_minutes, remark, status, created_at, updated_at)
                            VALUES ('$employee_number', '$name', '$department', '$date', $time_in, NULL, NULL, $time_out, $late_minutes, '$remark', '$status', NOW(), NOW())";
                    
                    if ($conn->query($sql)) {
                        $_SESSION['alert_type'] = 'success';
                        $_SESSION['alert_message'] = "Attendance record added successfully!";
                    } else {
                        $_SESSION['alert_type'] = 'error';
                        $_SESSION['alert_message'] = "Error adding record: " . $conn->error;
                    }
                }
            }
        }
        

    return true;
}

/**
 * Handle the Excel upload.
 *
 * @param  mysqli               $conn Open connection.
 * @param  array<string, mixed> $file Entry from $_FILES.
 * @return bool Always true - the caller redirects afterwards.
 */
function ua_handle_upload(mysqli $conn, array $file)
{
    $error = '';
    $message = '';

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = "File upload error: " . $file['error'];
        } 
        elseif (!in_array(pathinfo($file['name'], PATHINFO_EXTENSION), ['xls', 'xlsx'])) {
            $error = "Only Excel files (XLS, XLSX) are allowed.";
        } 
        elseif ($file['size'] > 30 * 1024 * 1024) {
            $error = "File size must be less than 30MB.";
        }
        else {
            $filename = $file['name'];
            $pattern = '/^1_\((January|February|March|April|May|June|July|August|September|October|November|December)\)Employee Attendance Record\.(xls|xlsx)$/';
            
            if (!preg_match($pattern, $filename)) {
                $error = "The file has been edited. The admin will be notified of this incident.";
                error_log("SECURITY ALERT: Suspicious file upload attempt - Filename: $filename - User: {$_SESSION['user_id']} - IP: {$_SERVER['REMOTE_ADDR']}");
                
                $_SESSION['alert_type'] = 'error';
                $_SESSION['alert_message'] = $error;
                return;
            }
            else {
                try {
                    $inputFileName = $file['tmp_name'];
                    $spreadsheet = IOFactory::load($inputFileName);
                    $worksheet = $spreadsheet->getActiveSheet();
                    $rows = $worksheet->toArray();
                    
                    // ========== FIND ATTENDANCE DATE RANGE ==========
                    $startDate = null;
                    foreach ($rows as $row) {
                        foreach ($row as $cell) {
                            if (is_string($cell) && stripos($cell, 'Attendance date:') !== false) {
                                $parts = explode('~', $cell);
                                if (count($parts) == 2) {
                                    $startDateStr = trim(str_replace('Attendance date:', '', $parts[0]));
    
                                    // Try multiple date formats for robustness
                                    $parsedStartDate = null;
                                    $formats = ['Y-m-d', 'Y/m/d', 'd-m-Y', 'd/m/Y'];
                                    foreach ($formats as $fmt) {
                                        $d = DateTime::createFromFormat($fmt, $startDateStr);
                                        if ($d !== false && $d->format($fmt) === $startDateStr) {
                                            $parsedStartDate = $d;
                                            break;
                                        }
                                    }
                                    if ($parsedStartDate === null) {
                                        try {
                                            $parsedStartDate = new DateTime($startDateStr);
                                        } catch (Exception $e) {
                                            $parsedStartDate = new DateTime('first day of this month');
                                        }
                                    }
                                    $startDate = $parsedStartDate;
                                }
                                break 2;
                            }
                        }
                    }
                    if ($startDate === null) {
                        $startDate = new DateTime('first day of this month');
                    }
                    
                    $successCount = 0;
                    $errorCount = 0;
                    $duplicateCount = 0;
                    $mergedCount = 0;
                    $overwrittenCount = 0;
                    $duplicateRecords = [];
                    $notFoundCount = 0;
                    $apayaoSkippedCount = 0;
                    
                    $i = 0;
                    while ($i < count($rows)) {
                        $row = $rows[$i];
                        $hasUserID = false;
                        foreach ($row as $cell) {
                            if (is_string($cell) && stripos($cell, 'User ID:') !== false) {
                                $hasUserID = true;
                                break;
                            }
                        }
                        if ($hasUserID) {
                            // ===== Extract employee info =====
                            $employee_number = null;
                            $department = null;
                            foreach ($row as $colIndex => $cell) {
                                if (is_string($cell) && stripos($cell, 'User ID:') !== false) {
                                    if (isset($row[$colIndex + 1])) {
                                        $employee_number = trim($row[$colIndex + 1]);
                                    }
                                    break;
                                }
                            }
                            foreach ($row as $colIndex => $cell) {
                                if (is_string($cell) && stripos($cell, 'Department:') !== false) {
                                    if (isset($row[$colIndex + 1])) {
                                        $department = trim($row[$colIndex + 1]);
                                    }
                                    break;
                                }
                            }
                            
                            if (empty($employee_number) || empty($department)) {
                                $i++;
                                continue;
                            }
                            
                            if (strcasecmp($department, 'Tuguegarao') === 0) {
                                $department = 'Tuguegarao City';
                            }
                            
                            if (strcasecmp(trim($department), 'Apayao') === 0) {
                                $apayaoSkippedCount++;
                                $i++;
                                continue;
                            }
                            
                            // ===== Move to day-number row =====
                            $i++; // now at day numbers row
                            if ($i >= count($rows)) break;
                            $dayRow = $rows[$i];
                            
                            // ========== FIX: Detect actual day-column mapping ==========
                            // The day numbers (1, 2, 3, ...) may not start at column index 1.
                            // We map each day number to its actual column index in the sheet.
                            $dayColumnMap = []; // day_number => column_index
                            foreach ($dayRow as $colIndex => $cell) {
                                if ($cell === null || $cell === '') continue;
                                // Normalize: could be int, float, or numeric string
                                $dayNum = null;
                                if (is_int($cell) || is_float($cell)) {
                                    $dayNum = (int)$cell;
                                } elseif (is_string($cell) && preg_match('/^\d+$/', trim($cell))) {
                                    $dayNum = (int)trim($cell);
                                }
                                if ($dayNum !== null && $dayNum >= 1 && $dayNum <= 31) {
                                    // Only keep the first occurrence (in case of duplicates)
                                    if (!isset($dayColumnMap[$dayNum])) {
                                        $dayColumnMap[$dayNum] = $colIndex;
                                    }
                                }
                            }
    
                            // ========== FIX: Build day-number => offset map (in column order) ==========
                            // The date for each day must be computed from the START date of the range,
                            // NOT from the raw day number. For example, if the range is 2026-09-16
                            // to 2026-09-30, the day-number row will contain 16, 17, ..., 30.
                            // We must treat the FIRST day column as startDate, the SECOND as
                            // startDate + 1 day, etc. — regardless of what the day number is.
                            //
                            // This handles:
                            //   - Day-of-month style (16-30)
                            //   - Sequential style (1-15)
                            //   - Cross-month ranges (25-31, 1-5)
                            $dayOffsets = []; // day_number => offset_in_days_from_startDate
                            $sortedByColumn = $dayColumnMap;
                            asort($sortedByColumn); // sort by column index ascending (left → right)
                            $offset = 0;
                            foreach ($sortedByColumn as $dayNum => $colIdx) {
                                $dayOffsets[$dayNum] = $offset;
                                $offset++;
                            }
                            
                            // Move past the day row to collect time rows
                            $i++;
                            
                            // Collect time rows until next info row or end
                            $timeRows = [];
                            while ($i < count($rows)) {
                                $row = $rows[$i];
                                $isInfoRow = false;
                                foreach ($row as $cell) {
                                    if (is_string($cell) && stripos($cell, 'User ID:') !== false) {
                                        $isInfoRow = true;
                                        break;
                                    }
                                }
                                if ($isInfoRow) {
                                    break;
                                }
                                // Check if this row has any non-empty time values in mapped day columns
                                $hasTime = false;
                                foreach ($dayColumnMap as $dayNum => $colIdx) {
                                    if (isset($row[$colIdx]) && trim((string)$row[$colIdx]) !== '') {
                                        $hasTime = true;
                                        break;
                                    }
                                }
                                if ($hasTime) {
                                    $timeRows[] = $row;
                                }
                                $i++;
                            }
                            
                            // ===== Aggregate times per day using the mapped columns =====
                            $dayTimes = []; // day_number => array of time strings
                            foreach ($dayColumnMap as $dayNum => $colIdx) {
                                $dayTimes[$dayNum] = [];
                            }
                            
                            foreach ($timeRows as $timeRow) {
                                foreach ($dayColumnMap as $dayNum => $colIdx) {
                                    $cellValue = $timeRow[$colIdx] ?? '';
                                    if ($cellValue === null || trim((string)$cellValue) === '') continue;
                                    
                                    // Split by <br>, newline, or multiple whitespace
                                    $times = preg_split('/<br\s*\/?>|\\n|\r\n?/i', (string)$cellValue);
                                    foreach ($times as $timeStr) {
                                        $timeStr = trim($timeStr);
                                        if ($timeStr !== '') {
                                            // Validate HH:MM format
                                            if (preg_match('/^\d{1,2}:\d{2}$/', $timeStr)) {
                                                $dayTimes[$dayNum][] = $timeStr;
                                            }
                                        }
                                    }
                                }
                            }
                            
                            // ===== Process each day =====
                            foreach ($dayTimes as $dayNum => $times) {
                                if (empty($times)) continue;
                                
                                // Separate AM/PM
                                $amTimes = [];
                                $pmTimes = [];
                                foreach ($times as $t) {
                                    $dt = DateTime::createFromFormat('H:i', $t);
                                    if ($dt !== false) {
                                        $hour = (int)$dt->format('G');
                                        if ($hour < 12) {
                                            $amTimes[] = $dt;
                                        } else {
                                            $pmTimes[] = $dt;
                                        }
                                    }
                                }
                                
                                $before_noon_in = null;
                                $after_noon_out = null;
                                if (!empty($amTimes)) {
                                    usort($amTimes, function($a, $b) { return $a->getTimestamp() - $b->getTimestamp(); });
                                    $before_noon_in = $amTimes[0]->format('H:i:s');
                                }
                                if (!empty($pmTimes)) {
                                    usort($pmTimes, function($a, $b) { return $b->getTimestamp() - $a->getTimestamp(); });
                                    $after_noon_out = $pmTimes[0]->format('H:i:s');
                                }
                                
                                // ========== FIX: Compute date using offset from startDate ==========
                                // The offset is derived from the column position (left-to-right),
                                // NOT from the raw day number. This correctly handles ranges like
                                // 2026-09-16 → 2026-09-30 where day numbers are 16..30.
                                $dateOffset = isset($dayOffsets[$dayNum]) ? $dayOffsets[$dayNum] : ($dayNum - 1);
                                $dateObj = clone $startDate;
                                if ($dateOffset > 0) {
                                    $dateObj->modify('+' . $dateOffset . ' days');
                                }
                                $date = $dateObj->format('Y-m-d');
                                
                                // Get employee name
                                $name = getEmployeeFormattedName($conn, $employee_number);
                                if ($name === null) {
                                    $notFoundCount++;
                                    continue;
                                }
                                
                                $hasTimeIn = ($before_noon_in !== null);
                                $hasTimeOut = ($after_noon_out !== null);
                                $isCompleteRecord = ($hasTimeIn && $hasTimeOut);
                                
                                $late_minutes = "NULL";
                                if ($hasTimeIn) {
                                    $calculatedLateMinutes = calculateLateMinutesFromTime($before_noon_in, $department);
                                    if ($calculatedLateMinutes !== null) {
                                        $late_minutes = "'" . $calculatedLateMinutes . "'";
                                    }
                                }
                                
                                $status = determineAttendanceStatus($hasTimeIn, $hasTimeOut, $before_noon_in, $after_noon_out);
                                $remark = ($status == 'Absent') ? 'Absence' : '';
                                
                                // Check if record exists
                                $checkSql = "SELECT id, before_noon_in, after_noon_out FROM attendance WHERE user_id = '$employee_number' AND date = '$date'";
                                $checkResult = $conn->query($checkSql);
                                
                                if ($checkResult->num_rows > 0) {
                                    $duplicateCount++;
                                    $existingRecord = $checkResult->fetch_assoc();
                                    $duplicateRecords[] = ['user_id' => $employee_number, 'date' => $date];
                                    
                                    if ($isCompleteRecord) {
                                        $finalTimeIn = ($before_noon_in !== null) ? "'" . $before_noon_in . "'" : "NULL";
                                        $finalTimeOut = ($after_noon_out !== null) ? "'" . $after_noon_out . "'" : "NULL";
                                        $finalLateMinutes = $late_minutes;
                                        $overwrittenCount++;
                                    } else {
                                        $finalTimeIn = ($existingRecord['before_noon_in'] !== null) ? "'" . $existingRecord['before_noon_in'] . "'" : (($before_noon_in !== null) ? "'" . $before_noon_in . "'" : "NULL");
                                        $finalTimeOut = ($existingRecord['after_noon_out'] !== null) ? "'" . $existingRecord['after_noon_out'] . "'" : (($after_noon_out !== null) ? "'" . $after_noon_out . "'" : "NULL");
                                        
                                        $finalLateMinutes = "NULL";
                                        if ($finalTimeIn != "NULL") {
                                            $timeInValue = str_replace("'", "", $finalTimeIn);
                                            $calculated = calculateLateMinutesFromTime($timeInValue, $department);
                                            if ($calculated !== null) {
                                                $finalLateMinutes = "'" . $calculated . "'";
                                            }
                                        }
                                        $mergedCount++;
                                    }
                                    
                                    $hasFinalTimeIn = ($finalTimeIn != "NULL");
                                    $hasFinalTimeOut = ($finalTimeOut != "NULL");
                                    $status = determineAttendanceStatus($hasFinalTimeIn, $hasFinalTimeOut, $finalTimeIn, $finalTimeOut);
                                    $remark = ($status == 'Absent') ? 'Absence' : '';
                                    
                                    $updateSql = "UPDATE attendance SET
                                                name = '$name',
                                                department = '$department',
                                                before_noon_in = $finalTimeIn,
                                                before_noon_out = NULL,
                                                after_noon_in = NULL,
                                                after_noon_out = $finalTimeOut,
                                                late_minutes = $finalLateMinutes,
                                                remark = '$remark',
                                                status = '$status',
                                                updated_at = NOW()
                                                WHERE id = '{$existingRecord['id']}'";
                                    if ($conn->query($updateSql)) {
                                        $successCount++;
                                    } else {
                                        $errorCount++;
                                        error_log("Error updating attendance: " . $conn->error);
                                    }
                                } else {
                                    $insertSql = "INSERT INTO attendance 
                                                (user_id, name, department, date, before_noon_in, before_noon_out, after_noon_in, after_noon_out, late_minutes, remark, status, created_at, updated_at)
                                                VALUES (
                                                '$employee_number', '$name', '$department', '$date', 
                                                " . ($before_noon_in !== null ? "'$before_noon_in'" : "NULL") . ", NULL, NULL, " . ($after_noon_out !== null ? "'$after_noon_out'" : "NULL") . ",
                                                $late_minutes,
                                                '$remark', '$status', NOW(), NOW())";
                                    if ($conn->query($insertSql)) {
                                        $successCount++;
                                    } else {
                                        $errorCount++;
                                        error_log("Error inserting attendance: " . $conn->error);
                                    }
                                }
                            }
                            // Continue outer loop; $i is already positioned at next info row or end
                        } else {
                            $i++;
                        }
                    }
                    
                    $message = "File processed successfully. $successCount total records processed. ";
                    if ($mergedCount > 0) {
                        $message .= "$mergedCount records merged (partial records combined). ";
                    }
                    if ($overwrittenCount > 0) {
                        $message .= "$overwrittenCount records overwritten (complete records replaced existing). ";
                    }
                    if ($notFoundCount > 0) {
                        $message .= "$notFoundCount records skipped (employee numbers not found or inactive). ";
                    }
                    if ($apayaoSkippedCount > 0) {
                        $message .= "$apayaoSkippedCount records skipped (Apayao branch not allowed). ";
                    }
                    if ($errorCount > 0) {
                        $message .= "$errorCount records failed to process.";
                    }
                    
                    $_SESSION['alert_type'] = 'success';
                    $_SESSION['alert_message'] = $message;
                    $_SESSION['refresh_page'] = true;
                    $_SESSION['duplicate_count'] = $duplicateCount;
                    $_SESSION['merged_count'] = $mergedCount;
                    $_SESSION['overwritten_count'] = $overwrittenCount;
                    $_SESSION['duplicate_records'] = $duplicateRecords;
                    
                } catch (Exception $e) {
                    $error = "Error processing file: " . $e->getMessage();
                    $_SESSION['alert_type'] = 'error';
                    $_SESSION['alert_message'] = $error;
                }
            }
        }

    if (!empty($error)) {
        $_SESSION['alert_type'] = 'error';
        $_SESSION['alert_message'] = $error;
    }

    return true;
}

/**
 * Calculate late minutes based on branch-specific cutoff time
 */
function calculateLateMinutesFromTime($timeIn, $department = '') {
    if (empty($timeIn)) {
        return null;
    }
    
    $timeParts = explode(':', $timeIn);
    if (count($timeParts) < 2) {
        return null;
    }
    
    $hours = (int)$timeParts[0];
    $minutes = (int)$timeParts[1];
    
    $timeInDate = new DateTime();
    $timeInDate->setTime($hours, $minutes, 0);
    
    $cutoffDate = new DateTime();
    if (strcasecmp(trim($department), 'Abulug') === 0) {
        $cutoffDate->setTime(8, 40, 0);
    } else {
        $cutoffDate->setTime(8, 30, 0);
    }
    
    if ($timeInDate > $cutoffDate) {
        $interval = $timeInDate->diff($cutoffDate);
        $lateMinutes = ($interval->h * 60) + $interval->i;
        return $lateMinutes;
    }
    
    return 0;
}

/**
 * Get employee formatted name from accounts tables based on employee_number
 */
function getEmployeeFormattedName($conn, $employee_number) {
    $tables = [
        'accounts' => ['last_name', 'first_name', 'middle_name', 'suffix'],
        'collector_accounts' => ['last_name', 'first_name', 'middle_name', 'suffix'],
        'proxy_accounts' => ['last_name', 'first_name', 'middle_name', 'suffix']
    ];
    
    foreach ($tables as $table => $fields) {
        $sql = "SELECT 
                {$fields[0]} as last_name, 
                {$fields[1]} as first_name, 
                {$fields[2]} as middle_name, 
                {$fields[3]} as suffix 
                FROM $table 
                WHERE employee_number = '$employee_number' 
                AND account_status = 'Active'
                LIMIT 1";
        
        $result = $conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            
            $last_name = trim($row['last_name'] ?? '');
            $first_name = trim($row['first_name'] ?? '');
            $middle_name = trim($row['middle_name'] ?? '');
            $suffix = trim($row['suffix'] ?? '');
            
            $formattedName = $last_name;
            
            if (!empty($first_name)) {
                $formattedName .= ', ' . $first_name;
            }
            
            if (!empty($suffix) && $suffix !== 'None' && $suffix !== 'none') {
                $formattedName .= ' ' . $suffix;
            }
            
            if (!empty($middle_name)) {
                $middle_initial = strtoupper(substr($middle_name, 0, 1)) . '.';
                $formattedName .= ' ' . $middle_initial;
            }
            
            $formattedName = preg_replace('/\s+/', ' ', $formattedName);
            
            return $conn->real_escape_string($formattedName);
        }
    }
    
    return null;
}

/**
 * Get all active employees from all account tables for dropdown
 */
function getAllActiveEmployees($conn) {
    $employees = [];
    
    $sql = "SELECT 'accounts' as source, employee_number, last_name, first_name, middle_name, suffix, branch, account_type 
            FROM accounts 
            WHERE account_status = 'Active' 
            AND employee_number IS NOT NULL 
            AND employee_number != ''
            AND account_type != 'Admin'";
    
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            if (strcasecmp(trim($row['branch'] ?? ''), 'Apayao') !== 0) {
                $employees[] = [
                    'employee_number' => $row['employee_number'],
                    'formatted_name' => formatEmployeeName($row['last_name'], $row['first_name'], $row['middle_name'], $row['suffix']),
                    'branch' => $row['branch'] ?? '',
                    'account_type' => $row['account_type'] ?? ''
                ];
            }
        }
    }
    
    $sql = "SELECT 'collector_accounts' as source, employee_number, last_name, first_name, middle_name, suffix, branch, account_type 
            FROM collector_accounts 
            WHERE account_status = 'Active' 
            AND employee_number IS NOT NULL 
            AND employee_number != ''
            AND account_type != 'Admin'";
    
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            if (strcasecmp(trim($row['branch'] ?? ''), 'Apayao') !== 0) {
                $employees[] = [
                    'employee_number' => $row['employee_number'],
                    'formatted_name' => formatEmployeeName($row['last_name'], $row['first_name'], $row['middle_name'], $row['suffix']),
                    'branch' => $row['branch'] ?? '',
                    'account_type' => $row['account_type'] ?? ''
                ];
            }
        }
    }
    
    $sql = "SELECT 'proxy_accounts' as source, employee_number, last_name, first_name, middle_name, suffix, branch, account_type 
            FROM proxy_accounts 
            WHERE account_status = 'Active' 
            AND employee_number IS NOT NULL 
            AND employee_number != ''
            AND account_type != 'Admin'";
    
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            if (strcasecmp(trim($row['branch'] ?? ''), 'Apayao') !== 0) {
                $employees[] = [
                    'employee_number' => $row['employee_number'],
                    'formatted_name' => formatEmployeeName($row['last_name'], $row['first_name'], $row['middle_name'], $row['suffix']),
                    'branch' => $row['branch'] ?? '',
                    'account_type' => $row['account_type'] ?? ''
                ];
            }
        }
    }
    
    usort($employees, function($a, $b) {
        return strcmp($a['formatted_name'], $b['formatted_name']);
    });
    
    return $employees;
}

/**
 * Format employee name
 */
function formatEmployeeName($last_name, $first_name, $middle_name, $suffix) {
    $last_name = trim($last_name ?? '');
    $first_name = trim($first_name ?? '');
    $middle_name = trim($middle_name ?? '');
    $suffix = trim($suffix ?? '');
    
    $formattedName = $last_name;
    
    if (!empty($first_name)) {
        $formattedName .= ', ' . $first_name;
    }
    
    if (!empty($suffix) && $suffix !== 'None' && $suffix !== 'none') {
        $formattedName .= ' ' . $suffix;
    }
    
    if (!empty($middle_name)) {
        $middle_initial = strtoupper(substr($middle_name, 0, 1)) . '.';
        $formattedName .= ' ' . $middle_initial;
    }
    
    $formattedName = preg_replace('/\s+/', ' ', $formattedName);
    
    return $formattedName;
}

// Helper function to format time values (kept for compatibility)
function formatTimeValue($conn, $value) {
    if (empty($value) || $value === '' || $value === 'None' || $value === null) {
        return "NULL";
    }
    
    if (is_numeric($value)) {
        $timeValue = ExcelDate::excelToTimestamp($value);
        return "'" . $conn->real_escape_string(date('H:i:s', $timeValue)) . "'";
    }
    
    if (is_string($value) && preg_match('/\d{1,2}:\d{2}/', $value)) {
        return "'" . $conn->real_escape_string($value . ':00') . "'";
    }
    
    return "NULL";
}

/**
 * Attendance rows matching the filters, newest first.
 *
 * @param  mysqli               $conn              Open connection.
 * @param  array<string, string> $filters          name / branch / from / to / status.
 * @param  string               $userBranch        Branch column of the account.
 * @param  array<int, string>   $availableBranches Branches the account may see.
 * @return array<int, array<string, mixed>>
 */
function ua_records(mysqli $conn, array $filters, $userBranch, array $availableBranches)
{
    $sql = "SELECT * FROM attendance WHERE department != 'Apayao'";
    $params = array();
    $types = '';

    if (!empty($filters['branch'])) {
        $sql .= ' AND department = ?';
        $params[] = $filters['branch'];
        $types .= 's';
    } elseif (strtoupper($userBranch) !== 'ALL BRANCHES' && !empty($availableBranches)) {
        $placeholders = implode(',', array_fill(0, count($availableBranches), '?'));
        $sql .= " AND department IN ($placeholders)";

        foreach ($availableBranches as $branch) {
            $params[] = $branch;
            $types .= 's';
        }
    }

    if (!empty($filters['name'])) {
        $sql .= ' AND name LIKE ?';
        $params[] = '%' . $filters['name'] . '%';
        $types .= 's';
    }

    if (!empty($filters['date_from'])) {
        $sql .= ' AND date >= ?';
        $params[] = $filters['date_from'];
        $types .= 's';
    }

    if (!empty($filters['date_to'])) {
        $sql .= ' AND date <= ?';
        $params[] = $filters['date_to'];
        $types .= 's';
    }

    if (!empty($filters['status'])) {
        if ($filters['status'] === 'Present') {
            $sql .= " AND before_noon_in IS NOT NULL AND after_noon_out IS NOT NULL
                      AND NOT (
                          (HOUR(before_noon_in) * 60 + MINUTE(before_noon_in)) BETWEEN 720 AND 780
                          OR (HOUR(after_noon_out) * 60 + MINUTE(after_noon_out)) BETWEEN 720 AND 780
                      )";
        } elseif ($filters['status'] === 'Halfday') {
            $sql .= " AND (
                        (
                            (before_noon_in IS NOT NULL AND after_noon_out IS NULL)
                            OR (before_noon_in IS NULL AND after_noon_out IS NOT NULL)
                            OR (
                                (HOUR(before_noon_in) * 60 + MINUTE(before_noon_in)) BETWEEN 720 AND 780
                                OR (HOUR(after_noon_out) * 60 + MINUTE(after_noon_out)) BETWEEN 720 AND 780
                            )
                        )
                      )";
        } elseif ($filters['status'] === 'Absent') {
            $sql .= ' AND before_noon_in IS NULL AND after_noon_out IS NULL';
        }
    }

    $sql .= ' ORDER BY date DESC, user_id';

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $records = array();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
    }

    $stmt->close();

    return $records;
}

/**
 * Branch list plus the effective branch filter.
 *
 * @param  string $userBranch   Branch column of the account.
 * @param  string $filterBranch Requested branch filter.
 * @return array<string, mixed>
 */
function ua_branch_context($userBranch, $filterBranch)
{
    $availableBranches = array();

    if (strtoupper($userBranch) === 'ALL BRANCHES') {
        $availableBranches = array('Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan');

        // An empty filter stays empty; a chosen branch is honoured.
        if (empty($filterBranch)) {
            $filterBranch = '';
        }
    } else {
        if (!empty($userBranch)) {
            $allUserBranches = array_map('trim', explode(',', $userBranch));

            $availableBranches = array_values(array_filter($allUserBranches, function ($branch) {
                return strcasecmp(trim($branch), 'Apayao') !== 0;
            }));

            if (empty($filterBranch)) {
                $filterBranch = '';
            } elseif (!in_array($filterBranch, $availableBranches)) {
                $filterBranch = '';
            }
        } else {
            $availableBranches = array('Tuguegarao City');
            $filterBranch = 'Tuguegarao City';
        }
    }

    return array(
        'available_branches' => $availableBranches,
        'filter_branch'      => $filterBranch,
    );
}

/**
 * Load the signed-in account.
 *
 * @param  mysqli $conn Open connection.
 * @return array<string, mixed>
 */
function ua_fetch_user(mysqli $conn)
{
    $source = $_SESSION['account_source'] ?? 'accounts';
    $table = $source === 'accounts' ? 'accounts' : 'proxy_accounts';
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, suffix, account_type, branch FROM {$table} WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $user ?: array();
}

/**
 * Everything the view needs, in one call.
 *
 * @return array<string, mixed>
 */
function ua_context()
{
    ua_start_session();
    ua_handle_logout();

    if (!ua_is_authenticated()) {
        ua_redirect('index.php');
        exit;
    }

    $conn = db();

    // Delete
    if (ua_handle_delete($conn)) {
        ua_redirect('upload_attendance.php');
        exit;
    }

    // Edit lookup
    $editRecord = ua_edit_record($conn);

    // Manual add / update
    if (ua_handle_manual_add($conn)) {
        ua_redirect($_SERVER['PHP_SELF']);
        exit;
    }

    // Excel upload
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_FILES['attendance_file'])) {
        ua_handle_upload($conn, $_FILES['attendance_file']);
        ua_redirect($_SERVER['PHP_SELF']);
        exit;
    }

    $user = ua_fetch_user($conn);
    $activeEmployees = getAllActiveEmployees($conn);

    $userBranch = $user['branch'] ?? '';
    $userAccountType = $user['account_type'] ?? '';

    $filters = array(
        'name'      => isset($_GET['filter_name']) ? (string) $_GET['filter_name'] : '',
        'branch'    => isset($_GET['filter_branch']) ? (string) $_GET['filter_branch'] : '',
        'date_from' => isset($_GET['filter_date_from']) ? (string) $_GET['filter_date_from'] : '',
        'date_to'   => isset($_GET['filter_date_to']) ? (string) $_GET['filter_date_to'] : '',
        'status'    => isset($_GET['filter_status']) ? (string) $_GET['filter_status'] : '',
    );

    $branch = ua_branch_context($userBranch, $filters['branch']);
    $filters['branch'] = $branch['filter_branch'];

    $attendanceRecords = array();
    $error = '';

    try {
        $attendanceRecords = ua_records($conn, $filters, $userBranch, $branch['available_branches']);
    } catch (Throwable $e) {
        error_log('[upload-attendance] ' . $e->getMessage());
        $error = 'Error fetching attendance records: ' . $e->getMessage();
    }

    return array(
        'user'                 => $user,
        'user_branch'          => $userBranch,
        'user_account_type'    => $userAccountType,
        'available_branches'   => $branch['available_branches'],
        'branches'             => $branch['available_branches'],
        'filter_name'          => $filters['name'],
        'filter_branch'        => $filters['branch'],
        'filter_date_from'     => $filters['date_from'],
        'filter_date_to'       => $filters['date_to'],
        'filter_status'        => $filters['status'],
        'attendance_records'   => $attendanceRecords,
        'activeEmployees'      => $activeEmployees,
        'editRecord'           => $editRecord,
        'error'                => $error,
        'layout_menu'          => 'admin',
    );
}
