<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['logged_in'])) {
    header("Location: index.php");
    exit;
}

// Database configuration
require 'includes/db_config.php';

// Require Composer's autoloader (adjust path as needed)
require_once 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Get filter parameters from URL and validate/format them
$filter_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filter_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$filter_branch = isset($_GET['branch']) ? trim($_GET['branch']) : '';

// Validate and format dates to Y-m-d format
function validateAndFormatDate($date) {
    if (empty($date)) {
        return '';
    }
    
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
            $day = (int)$matches[3];
            if (checkdate($month, $day, $year)) {
                return sprintf("%04d-%02d-%02d", $year, $month, $day);
            }
        }
        elseif (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $date, $matches)) {
            $month = (int)$matches[1];
            $day = (int)$matches[2];
            $year = (int)$matches[3];
            if (checkdate($month, $day, $year)) {
                return sprintf("%04d-%02d-%02d", $year, $month, $day);
            }
        }
        elseif (preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $date, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
            $day = (int)$matches[3];
            if (checkdate($month, $day, $year)) {
                return sprintf("%04d-%02d-%02d", $year, $month, $day);
            }
        }
        return '';
    }
    
    return date('Y-m-d', $timestamp);
}

$filter_date_from = validateAndFormatDate($filter_date_from);
$filter_date_to = validateAndFormatDate($filter_date_to);

// Function to safely create DateTime object
function createSafeDateTime($date_string) {
    if (empty($date_string)) {
        return null;
    }
    try {
        $formatted_date = validateAndFormatDate($date_string);
        if (empty($formatted_date)) {
            return null;
        }
        return new DateTime($formatted_date);
    } catch (Exception $e) {
        error_log("Date parsing error: " . $e->getMessage() . " for date: " . $date_string);
        return null;
    }
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
 * - If either time is within 12:00–13:00 inclusive => Halfday
 * - Both present => Present
 * - Only one present => Halfday
 * - None present => Absent
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

// Function to calculate working days excluding Sundays
function calculateWorkingDays($date_from, $date_to) {
    if (empty($date_from) || empty($date_to)) {
        return 'N/A';
    }
    $start = createSafeDateTime($date_from);
    $end = createSafeDateTime($date_to);
    if (!$start || !$end) {
        return 'N/A';
    }
    $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);
    $working_days = 0;
    foreach ($period as $date) {
        if ($date->format('w') != 0) {
            $working_days++;
        }
    }
    return $working_days;
}

// Function to get all working days in a date range
function getWorkingDays($date_from, $date_to) {
    $working_days = [];
    if (empty($date_from) || empty($date_to)) {
        return $working_days;
    }
    $start = createSafeDateTime($date_from);
    $end = createSafeDateTime($date_to);
    if (!$start || !$end) {
        return $working_days;
    }
    $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);
    foreach ($period as $date) {
        if ($date->format('w') != 0) {
            $working_days[] = $date->format('Y-m-d');
        }
    }
    return $working_days;
}

// Function to calculate number of months between two dates (including partial months)
function getMonthsInRange($date_from, $date_to) {
    $start = createSafeDateTime($date_from);
    $end = createSafeDateTime($date_to);
    if (!$start || !$end) {
        return 0;
    }
    $end->modify('+1 day');
    $interval = $start->diff($end);
    $total_months = ($interval->y * 12) + $interval->m;
    if ($interval->d > 0) {
        $last_month = clone $end;
        $last_month->modify('first day of this month');
        $days_in_last_month = (int)$last_month->format('t');
        $total_months += $interval->d / $days_in_last_month;
    }
    return $total_months;
}

// Count monthly periods (end-of-month application: 16th to end)
function countMonthlyPeriods($date_from, $date_to, $effective_date = null) {
    $start = createSafeDateTime($date_from);
    $end = createSafeDateTime($date_to);
    if (!$start || !$end) {
        return 0;
    }
    $end->modify('+1 day');
    if ($effective_date) {
        $deduction_effective = createSafeDateTime($effective_date);
        if ($deduction_effective && $deduction_effective > $start) {
            $start = clone $deduction_effective;
        }
    }
    $periods = 0;
    $current = clone $start;
    while ($current < $end) {
        $year = (int)$current->format('Y');
        $month = (int)$current->format('m');
        $day = (int)$current->format('d');
        if ($day >= 16) {
            $last_day_of_month = (int)(new DateTime("$year-$month-01"))->format('t');
            $period_end = new DateTime("$year-$month-$last_day_of_month 23:59:59");
            if ($period_end >= $end) {
                $periods++;
                break;
            }
            $periods++;
            if ($month == 12) {
                $current = new DateTime(($year + 1) . "-01-01");
            } else {
                $current = new DateTime("$year-" . ($month + 1) . "-01");
            }
        } else {
            $sixteenth = new DateTime("$year-$month-16");
            if ($sixteenth < $end) {
                $periods++;
                if ($month == 12) {
                    $current = new DateTime(($year + 1) . "-01-01");
                } else {
                    $current = new DateTime("$year-" . ($month + 1) . "-01");
                }
            } else {
                break;
            }
        }
    }
    return $periods;
}

// Count semi-monthly periods
function countSemiMonthlyPeriods($date_from, $date_to, $effective_date = null) {
    $start = createSafeDateTime($date_from);
    $end = createSafeDateTime($date_to);
    if (!$start || !$end) {
        return 0;
    }
    $end->modify('+1 day');
    if ($effective_date) {
        $deduction_effective = createSafeDateTime($effective_date);
        if ($deduction_effective && $deduction_effective > $start) {
            $start = clone $deduction_effective;
        }
    }
    $periods = 0;
    $current = clone $start;
    while ($current < $end) {
        $year = (int)$current->format('Y');
        $month = (int)$current->format('m');
        $day = (int)$current->format('d');
        $last_day_of_month = (int)(new DateTime("$year-$month-01"))->format('t');
        if ($day <= 15) {
            $period_end = new DateTime("$year-$month-15 23:59:59");
            if ($period_end >= $end) {
                $periods++;
                break;
            }
            $periods++;
            $current = new DateTime("$year-$month-16");
        } else {
            $period_end = new DateTime("$year-$month-$last_day_of_month 23:59:59");
            if ($period_end >= $end) {
                $periods++;
                break;
            }
            $periods++;
            if ($month == 12) {
                $current = new DateTime(($year + 1) . "-01-01");
            } else {
                $current = new DateTime("$year-" . ($month + 1) . "-01");
            }
        }
    }
    return $periods;
}

// Get account_id and employee_type from employee_number
function getAccountIdByEmployeeNumber($conn, $employee_number) {
    $tables = [
        'collector' => 'collector_accounts',
        'proxy' => 'proxy_accounts',
        'accounts' => 'accounts'
    ];
    foreach ($tables as $source => $table) {
        $sql = "SELECT id FROM $table WHERE employee_number = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) continue;
        $stmt->bind_param("s", $employee_number);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return [
                'account_id' => $row['id'],
                'source_table' => $source,
                'employee_type' => $source
            ];
        }
        $stmt->close();
    }
    return null;
}

// Get salary history for an employee within a date range
function getEmployeeSalaryHistory($conn, $account_id, $source_table, $date_from, $date_to) {
    $salary_history = [];
    $sql = "SELECT daily_salary, effective_date 
            FROM salary_history 
            WHERE account_id = ? AND source_table = ? 
            AND effective_date BETWEEN ? AND ?
            ORDER BY effective_date ASC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("isss", $account_id, $source_table, $date_from, $date_to);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $salary_history[$row['effective_date']] = $row['daily_salary'];
            }
        }
        $stmt->close();
    }
    $sql = "SELECT daily_salary, effective_date 
            FROM salary_history 
            WHERE account_id = ? AND source_table = ? AND effective_date < ?
            ORDER BY effective_date DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("iss", $account_id, $source_table, $date_from);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $salary_history['BEFORE_RANGE'] = $row['daily_salary'];
        }
        $stmt->close();
    }
    return $salary_history;
}

// Get SSS deductions
function getEmployeeSSSDeductions($conn, $account_id, $employee_type, $date_from, $date_to) {
    $total_sss_deduction = 0;
    $sss_details = [];
    $sql = "SELECT deduction_type, amount, frequency, effective_date 
            FROM employee_deductions 
            WHERE employee_id = ? AND employee_type = ? 
            AND deduction_type = 'sss'
            AND is_active = 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("is", $account_id, $employee_type);
        $stmt->execute();
        $result = $stmt->get_result();
        $date_from_obj = createSafeDateTime($date_from);
        $date_to_obj = createSafeDateTime($date_to);
        if (!$date_from_obj || !$date_to_obj) {
            return ['total' => 0, 'details' => []];
        }
        while ($row = $result->fetch_assoc()) {
            $deduction_amount = 0;
            $deduction_description = "";
            $effective_date = isset($row['effective_date']) ? createSafeDateTime($row['effective_date']) : null;
            if ($effective_date && $effective_date > $date_to_obj) {
                continue;
            }
            switch ($row['frequency']) {
                case 'one_time':
                    if ($effective_date && $effective_date >= $date_from_obj && $effective_date <= $date_to_obj) {
                        $deduction_amount = $row['amount'];
                        $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                    }
                    break;
                case 'monthly':
                    $periods = countMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                    $deduction_amount = $row['amount'] * $periods;
                    $deduction_description = "Monthly (end of month) × " . $periods . " months";
                    break;
                case 'semi_monthly':
                    $periods = countSemiMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                    $deduction_amount = $row['amount'] * $periods;
                    $deduction_description = "Semi-monthly × " . $periods . " periods";
                    break;
                default:
                    $deduction_amount = $row['amount'];
                    $deduction_description = $row['frequency'];
            }
            if ($deduction_amount > 0) {
                $total_sss_deduction += $deduction_amount;
                $sss_details[] = [
                    'type' => $row['deduction_type'],
                    'amount' => $deduction_amount,
                    'frequency' => $row['frequency'],
                    'description' => $deduction_description,
                    'effective_date' => $effective_date ? $effective_date->format('Y-m-d') : 'N/A'
                ];
            }
        }
        $stmt->close();
    }
    return ['total' => $total_sss_deduction, 'details' => $sss_details];
}

// Get Pag-IBIG deductions
function getEmployeePagIbigDeductions($conn, $account_id, $employee_type, $date_from, $date_to) {
    $total_pagibig_deduction = 0;
    $pagibig_details = [];
    $sql = "SELECT deduction_type, amount, frequency, effective_date 
            FROM employee_deductions 
            WHERE employee_id = ? AND employee_type = ? 
            AND deduction_type = 'pagibig'
            AND is_active = 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("is", $account_id, $employee_type);
        $stmt->execute();
        $result = $stmt->get_result();
        $date_from_obj = createSafeDateTime($date_from);
        $date_to_obj = createSafeDateTime($date_to);
        if (!$date_from_obj || !$date_to_obj) {
            return ['total' => 0, 'details' => []];
        }
        while ($row = $result->fetch_assoc()) {
            $deduction_amount = 0;
            $deduction_description = "";
            $effective_date = isset($row['effective_date']) ? createSafeDateTime($row['effective_date']) : null;
            if ($effective_date && $effective_date > $date_to_obj) {
                continue;
            }
            switch ($row['frequency']) {
                case 'one_time':
                    if ($effective_date && $effective_date >= $date_from_obj && $effective_date <= $date_to_obj) {
                        $deduction_amount = $row['amount'];
                        $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                    }
                    break;
                case 'monthly':
                    $periods = countMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                    $deduction_amount = $row['amount'] * $periods;
                    $deduction_description = "Monthly (end of month) × " . $periods . " months";
                    break;
                case 'semi_monthly':
                    $periods = countSemiMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                    $deduction_amount = $row['amount'] * $periods;
                    $deduction_description = "Semi-monthly × " . $periods . " periods";
                    break;
                default:
                    $deduction_amount = $row['amount'];
                    $deduction_description = $row['frequency'];
            }
            if ($deduction_amount > 0) {
                $total_pagibig_deduction += $deduction_amount;
                $pagibig_details[] = [
                    'type' => $row['deduction_type'],
                    'amount' => $deduction_amount,
                    'frequency' => $row['frequency'],
                    'description' => $deduction_description,
                    'effective_date' => $effective_date ? $effective_date->format('Y-m-d') : 'N/A'
                ];
            }
        }
        $stmt->close();
    }
    return ['total' => $total_pagibig_deduction, 'details' => $pagibig_details];
}

// Get PhilHealth deductions
function getEmployeePhilHealthDeductions($conn, $account_id, $employee_type, $date_from, $date_to) {
    $total_philhealth_deduction = 0;
    $philhealth_details = [];
    $sql = "SELECT deduction_type, amount, frequency, effective_date 
            FROM employee_deductions 
            WHERE employee_id = ? AND employee_type = ? 
            AND deduction_type = 'philhealth'
            AND is_active = 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("is", $account_id, $employee_type);
        $stmt->execute();
        $result = $stmt->get_result();
        $date_from_obj = createSafeDateTime($date_from);
        $date_to_obj = createSafeDateTime($date_to);
        if (!$date_from_obj || !$date_to_obj) {
            return ['total' => 0, 'details' => []];
        }
        while ($row = $result->fetch_assoc()) {
            $deduction_amount = 0;
            $deduction_description = "";
            $effective_date = isset($row['effective_date']) ? createSafeDateTime($row['effective_date']) : null;
            if ($effective_date && $effective_date > $date_to_obj) {
                continue;
            }
            switch ($row['frequency']) {
                case 'one_time':
                    if ($effective_date && $effective_date >= $date_from_obj && $effective_date <= $date_to_obj) {
                        $deduction_amount = $row['amount'];
                        $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                    }
                    break;
                case 'monthly':
                    $periods = countMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                    $deduction_amount = $row['amount'] * $periods;
                    $deduction_description = "Monthly (end of month) × " . $periods . " months";
                    break;
                case 'semi_monthly':
                    $periods = countSemiMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                    $deduction_amount = $row['amount'] * $periods;
                    $deduction_description = "Semi-monthly × " . $periods . " periods";
                    break;
                default:
                    $deduction_amount = $row['amount'];
                    $deduction_description = $row['frequency'];
            }
            if ($deduction_amount > 0) {
                $total_philhealth_deduction += $deduction_amount;
                $philhealth_details[] = [
                    'type' => $row['deduction_type'],
                    'amount' => $deduction_amount,
                    'frequency' => $row['frequency'],
                    'description' => $deduction_description,
                    'effective_date' => $effective_date ? $effective_date->format('Y-m-d') : 'N/A'
                ];
            }
        }
        $stmt->close();
    }
    return ['total' => $total_philhealth_deduction, 'details' => $philhealth_details];
}

// Get SSS Loan deductions
function getEmployeeSSSLoanDeductions($conn, $account_id, $employee_type, $date_from, $date_to) {
    $total_sss_loan_deduction = 0;
    $sss_loan_details = [];
    $sql = "SELECT deduction_type, amount, frequency, effective_date, start_date, end_date 
            FROM employee_deductions 
            WHERE employee_id = ? AND employee_type = ? 
            AND deduction_type = 'sss_loan'
            AND is_active = 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("is", $account_id, $employee_type);
        $stmt->execute();
        $result = $stmt->get_result();
        $date_from_obj = createSafeDateTime($date_from);
        $date_to_obj = createSafeDateTime($date_to);
        if (!$date_from_obj || !$date_to_obj) {
            return ['total' => 0, 'details' => []];
        }
        while ($row = $result->fetch_assoc()) {
            $deduction_amount = 0;
            $deduction_description = "";
            $effective_date = isset($row['effective_date']) ? createSafeDateTime($row['effective_date']) : null;
            $start_date = isset($row['start_date']) ? createSafeDateTime($row['start_date']) : null;
            $end_date = isset($row['end_date']) ? createSafeDateTime($row['end_date']) : null;
            if ($effective_date && $effective_date > $date_to_obj) {
                continue;
            }
            switch ($row['frequency']) {
                case 'one_time':
                    if ($start_date && $end_date) {
                        if ($start_date <= $date_to_obj && $end_date >= $date_from_obj) {
                            $deduction_amount = $row['amount'];
                            $deduction_description = "One-time (active from " . $start_date->format('Y-m-d') . " to " . $end_date->format('Y-m-d') . ")";
                        }
                    } elseif ($effective_date && $effective_date >= $date_from_obj && $effective_date <= $date_to_obj) {
                        $deduction_amount = $row['amount'];
                        $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                    }
                    break;
                case 'monthly':
                    $periods = countMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                    $deduction_amount = $row['amount'] * $periods;
                    $deduction_description = "Monthly (end of month) × " . $periods . " months";
                    break;
                case 'semi_monthly':
                    $periods = countSemiMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                    $deduction_amount = $row['amount'] * $periods;
                    $deduction_description = "Semi-monthly × " . $periods . " periods";
                    break;
                default:
                    $deduction_amount = $row['amount'];
                    $deduction_description = $row['frequency'];
            }
            if ($deduction_amount > 0) {
                $total_sss_loan_deduction += $deduction_amount;
                $sss_loan_details[] = [
                    'type' => $row['deduction_type'],
                    'amount' => $deduction_amount,
                    'frequency' => $row['frequency'],
                    'description' => $deduction_description,
                    'effective_date' => $effective_date ? $effective_date->format('Y-m-d') : 'N/A',
                    'start_date' => $start_date ? $start_date->format('Y-m-d') : 'N/A',
                    'end_date' => $end_date ? $end_date->format('Y-m-d') : 'N/A'
                ];
            }
        }
        $stmt->close();
    }
    return ['total' => $total_sss_loan_deduction, 'details' => $sss_loan_details];
}

// Get Pag-IBIG Loan deductions
function getEmployeePagIbigLoanDeductions($conn, $account_id, $employee_type, $date_from, $date_to) {
    $total_pagibig_loan_deduction = 0;
    $pagibig_loan_details = [];
    $sql = "SELECT deduction_type, amount, frequency, effective_date, start_date, end_date 
            FROM employee_deductions 
            WHERE employee_id = ? AND employee_type = ? 
            AND deduction_type = 'pagibig_loan'
            AND is_active = 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("is", $account_id, $employee_type);
        $stmt->execute();
        $result = $stmt->get_result();
        $date_from_obj = createSafeDateTime($date_from);
        $date_to_obj = createSafeDateTime($date_to);
        if (!$date_from_obj || !$date_to_obj) {
            return ['total' => 0, 'details' => []];
        }
        while ($row = $result->fetch_assoc()) {
            $deduction_amount = 0;
            $deduction_description = "";
            $effective_date = isset($row['effective_date']) ? createSafeDateTime($row['effective_date']) : null;
            $start_date = isset($row['start_date']) ? createSafeDateTime($row['start_date']) : null;
            $end_date = isset($row['end_date']) ? createSafeDateTime($row['end_date']) : null;
            if ($effective_date && $effective_date > $date_to_obj) {
                continue;
            }
            switch ($row['frequency']) {
                case 'one_time':
                    if ($start_date && $end_date) {
                        if ($start_date <= $date_to_obj && $end_date >= $date_from_obj) {
                            $deduction_amount = $row['amount'];
                            $deduction_description = "One-time (active from " . $start_date->format('Y-m-d') . " to " . $end_date->format('Y-m-d') . ")";
                        }
                    } elseif ($effective_date && $effective_date >= $date_from_obj && $effective_date <= $date_to_obj) {
                        $deduction_amount = $row['amount'];
                        $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                    }
                    break;
                case 'monthly':
                    $periods = countMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                    $deduction_amount = $row['amount'] * $periods;
                    $deduction_description = "Monthly (end of month) × " . $periods . " months";
                    break;
                case 'semi_monthly':
                    $periods = countSemiMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                    $deduction_amount = $row['amount'] * $periods;
                    $deduction_description = "Semi-monthly × " . $periods . " periods";
                    break;
                default:
                    $deduction_amount = $row['amount'];
                    $deduction_description = $row['frequency'];
            }
            if ($deduction_amount > 0) {
                $total_pagibig_loan_deduction += $deduction_amount;
                $pagibig_loan_details[] = [
                    'type' => $row['deduction_type'],
                    'amount' => $deduction_amount,
                    'frequency' => $row['frequency'],
                    'description' => $deduction_description,
                    'effective_date' => $effective_date ? $effective_date->format('Y-m-d') : 'N/A',
                    'start_date' => $start_date ? $start_date->format('Y-m-d') : 'N/A',
                    'end_date' => $end_date ? $end_date->format('Y-m-d') : 'N/A'
                ];
            }
        }
        $stmt->close();
    }
    return ['total' => $total_pagibig_loan_deduction, 'details' => $pagibig_loan_details];
}

// Get loan payments
function getEmployeeLoanPayments($conn, $account_id, $employee_type, $date_from, $date_to) {
    $total_loan_payment = 0;
    $loan_details = [];
    $sql = "SELECT deduction_type, amount, frequency, effective_date, start_date, end_date 
            FROM employee_deductions 
            WHERE employee_id = ? AND employee_type = ? 
            AND deduction_type = 'loan_payment'
            AND is_active = 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("is", $account_id, $employee_type);
        $stmt->execute();
        $result = $stmt->get_result();
        $date_from_obj = createSafeDateTime($date_from);
        $date_to_obj = createSafeDateTime($date_to);
        if (!$date_from_obj || !$date_to_obj) {
            return ['total' => 0, 'details' => []];
        }
        while ($row = $result->fetch_assoc()) {
            $deduction_amount = 0;
            $deduction_description = "";
            $effective_date = isset($row['effective_date']) ? createSafeDateTime($row['effective_date']) : null;
            $start_date = isset($row['start_date']) ? createSafeDateTime($row['start_date']) : null;
            $end_date = isset($row['end_date']) ? createSafeDateTime($row['end_date']) : null;
            if ($effective_date && $effective_date > $date_to_obj) {
                continue;
            }
            switch ($row['frequency']) {
                case 'one_time':
                    if ($start_date && $end_date) {
                        if ($start_date <= $date_to_obj && $end_date >= $date_from_obj) {
                            $deduction_amount = $row['amount'];
                            $deduction_description = "One-time (active from " . $start_date->format('Y-m-d') . " to " . $end_date->format('Y-m-d') . ")";
                        }
                    } elseif ($effective_date && $effective_date >= $date_from_obj && $effective_date <= $date_to_obj) {
                        $deduction_amount = $row['amount'];
                        $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                    }
                    break;
                case 'monthly':
                    $periods = countMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                    $deduction_amount = $row['amount'] * $periods;
                    $deduction_description = "Monthly (end of month) × " . $periods . " months";
                    break;
                case 'semi_monthly':
                    $periods = countSemiMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                    $deduction_amount = $row['amount'] * $periods;
                    $deduction_description = "Semi-monthly × " . $periods . " periods";
                    break;
                default:
                    $deduction_amount = $row['amount'];
                    $deduction_description = $row['frequency'];
            }
            if ($deduction_amount > 0) {
                $total_loan_payment += $deduction_amount;
                $loan_details[] = [
                    'type' => $row['deduction_type'],
                    'amount' => $deduction_amount,
                    'frequency' => $row['frequency'],
                    'description' => $deduction_description,
                    'effective_date' => $effective_date ? $effective_date->format('Y-m-d') : 'N/A',
                    'start_date' => $start_date ? $start_date->format('Y-m-d') : 'N/A',
                    'end_date' => $end_date ? $end_date->format('Y-m-d') : 'N/A'
                ];
            }
        }
        $stmt->close();
    }
    return ['total' => $total_loan_payment, 'details' => $loan_details];
}

// Get cash advances
function getEmployeeCashAdvances($conn, $account_id, $employee_type, $date_from, $date_to) {
    $total_cash_advance = 0;
    $cash_advance_details = [];
    $sql = "SELECT deduction_type, amount, frequency, effective_date, start_date, end_date 
            FROM employee_deductions 
            WHERE employee_id = ? AND employee_type = ? 
            AND deduction_type = 'cash_advance'
            AND is_active = 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("is", $account_id, $employee_type);
        $stmt->execute();
        $result = $stmt->get_result();
        $date_from_obj = createSafeDateTime($date_from);
        $date_to_obj = createSafeDateTime($date_to);
        if (!$date_from_obj || !$date_to_obj) {
            return ['total' => 0, 'details' => []];
        }
        while ($row = $result->fetch_assoc()) {
            $deduction_amount = 0;
            $deduction_description = "";
            $effective_date = isset($row['effective_date']) ? createSafeDateTime($row['effective_date']) : null;
            $start_date = isset($row['start_date']) ? createSafeDateTime($row['start_date']) : null;
            $end_date = isset($row['end_date']) ? createSafeDateTime($row['end_date']) : null;
            if ($effective_date && $effective_date > $date_to_obj) {
                continue;
            }
            switch ($row['frequency']) {
                case 'one_time':
                    if ($start_date && $end_date) {
                        if ($start_date <= $date_to_obj && $end_date >= $date_from_obj) {
                            $deduction_amount = $row['amount'];
                            $deduction_description = "One-time (active from " . $start_date->format('Y-m-d') . " to " . $end_date->format('Y-m-d') . ")";
                        }
                    } elseif ($effective_date && $effective_date >= $date_from_obj && $effective_date <= $date_to_obj) {
                        $deduction_amount = $row['amount'];
                        $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                    }
                    break;
                case 'monthly':
                    $periods = countMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                    $deduction_amount = $row['amount'] * $periods;
                    $deduction_description = "Monthly (end of month) × " . $periods . " months";
                    break;
                case 'semi_monthly':
                    $periods = countSemiMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                    $deduction_amount = $row['amount'] * $periods;
                    $deduction_description = "Semi-monthly × " . $periods . " periods";
                    break;
                default:
                    $deduction_amount = $row['amount'];
                    $deduction_description = $row['frequency'];
            }
            if ($deduction_amount > 0) {
                $total_cash_advance += $deduction_amount;
                $cash_advance_details[] = [
                    'type' => $row['deduction_type'],
                    'amount' => $deduction_amount,
                    'frequency' => $row['frequency'],
                    'description' => $deduction_description,
                    'effective_date' => $effective_date ? $effective_date->format('Y-m-d') : 'N/A',
                    'start_date' => $start_date ? $start_date->format('Y-m-d') : 'N/A',
                    'end_date' => $end_date ? $end_date->format('Y-m-d') : 'N/A'
                ];
            }
        }
        $stmt->close();
    }
    return ['total' => $total_cash_advance, 'details' => $cash_advance_details];
}

// Get allowances
function getEmployeeAllowances($conn, $account_id, $employee_type, $date_from, $date_to) {
    $allowances = [
        'Rice Allowance' => 0,
        'COLA' => 0,
        'Motor Rental' => 0,
        'Incentive' => 0
    ];
    $allowance_details = [];

    $sql = "SELECT allowance_type, amount, effective_date, end_date, is_active 
            FROM employee_allowances 
            WHERE employee_id = ? AND employee_type = ? 
            AND is_active = 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("is", $account_id, $employee_type);
        $stmt->execute();
        $result = $stmt->get_result();
        $date_from_obj = createSafeDateTime($date_from);
        $date_to_obj = createSafeDateTime($date_to);
        if (!$date_from_obj || !$date_to_obj) {
            return ['totals' => $allowances, 'details' => $allowance_details];
        }
        while ($row = $result->fetch_assoc()) {
            $effective_date = isset($row['effective_date']) ? createSafeDateTime($row['effective_date']) : null;
            $end_date = isset($row['end_date']) ? createSafeDateTime($row['end_date']) : null;
            if ($effective_date && $effective_date > $date_to_obj) {
                continue;
            }
            if ($end_date && $end_date < $date_from_obj) {
                continue;
            }
            $periods = countMonthlyPeriods(
                $date_from,
                $date_to,
                $effective_date ? $effective_date->format('Y-m-d') : null
            );
            if ($end_date && $end_date < $date_to_obj) {
                $periods = countMonthlyPeriods(
                    $date_from,
                    $end_date->format('Y-m-d'),
                    $effective_date ? $effective_date->format('Y-m-d') : null
                );
            }
            $total_amount = $row['amount'] * $periods;
            if ($total_amount > 0 && $periods > 0) {
                $type = $row['allowance_type'];
                if (array_key_exists($type, $allowances)) {
                    $allowances[$type] += $total_amount;
                }
                $allowance_details[] = [
                    'type' => $type,
                    'amount' => $total_amount,
                    'periods' => $periods,
                    'effective_date' => $effective_date ? $effective_date->format('Y-m-d') : 'N/A',
                    'end_date' => $end_date ? $end_date->format('Y-m-d') : 'N/A'
                ];
            }
        }
        $stmt->close();
    }
    return ['totals' => $allowances, 'details' => $allowance_details];
}

// Get salary for a specific date based on effective_date
function getSalaryForDate($salary_history, $attendance_date) {
    if (empty($salary_history)) {
        return 0;
    }
    $attendance_date_obj = createSafeDateTime($attendance_date);
    if (!$attendance_date_obj) {
        return 0;
    }
    $attendance_timestamp = $attendance_date_obj->getTimestamp();
    $effective_dates = array_keys($salary_history);
    $effective_dates = array_filter($effective_dates, function($date) {
        return $date !== 'BEFORE_RANGE';
    });
    $effective_timestamps = [];
    foreach ($effective_dates as $date) {
        $date_obj = createSafeDateTime($date);
        if ($date_obj) {
            $effective_timestamps[$date] = $date_obj->getTimestamp();
        }
    }
    asort($effective_timestamps);
    $selected_salary = isset($salary_history['BEFORE_RANGE']) ? $salary_history['BEFORE_RANGE'] : 0;
    foreach ($effective_timestamps as $effective_date => $timestamp) {
        if ($timestamp <= $attendance_timestamp) {
            $selected_salary = $salary_history[$effective_date];
        } else {
            break;
        }
    }
    return $selected_salary;
}

// Find effective date used for a salary
function findEffectiveDateForSalary($salary_history, $attendance_date) {
    if (empty($salary_history)) {
        return 'No history';
    }
    $attendance_date_obj = createSafeDateTime($attendance_date);
    if (!$attendance_date_obj) {
        return 'Invalid date';
    }
    $attendance_timestamp = $attendance_date_obj->getTimestamp();
    $effective_dates = array_keys($salary_history);
    $effective_dates = array_filter($effective_dates, function($date) {
        return $date !== 'BEFORE_RANGE';
    });
    $effective_timestamps = [];
    foreach ($effective_dates as $date) {
        $date_obj = createSafeDateTime($date);
        if ($date_obj) {
            $effective_timestamps[$date] = $date_obj->getTimestamp();
        }
    }
    asort($effective_timestamps);
    $selected_effective_date = isset($salary_history['BEFORE_RANGE']) ? 'Before ' . $attendance_date : '';
    foreach ($effective_timestamps as $effective_date => $timestamp) {
        if ($timestamp <= $attendance_timestamp) {
            $selected_effective_date = $effective_date;
        } else {
            break;
        }
    }
    return $selected_effective_date;
}

// Format salary history for display
function formatSalaryHistory($salary_history) {
    if (empty($salary_history)) {
        return 'No salary history';
    }
    $formatted = [];
    if (isset($salary_history['BEFORE_RANGE'])) {
        $formatted[] = 'Before ' . date('M d', strtotime($GLOBALS['filter_date_from'])) . ': ' . number_format($salary_history['BEFORE_RANGE'], 0);
    }
    $effective_dates = array_filter(array_keys($salary_history), function($date) {
        return $date !== 'BEFORE_RANGE';
    });
    $dated_entries = [];
    foreach ($effective_dates as $date) {
        $date_obj = createSafeDateTime($date);
        if ($date_obj) {
            $dated_entries[$date] = $date_obj->getTimestamp();
        }
    }
    arsort($dated_entries);
    foreach (array_keys($dated_entries) as $date) {
        $date_obj = createSafeDateTime($date);
        if ($date_obj) {
            $formatted[] = $date_obj->format('M d') . ': ' . number_format($salary_history[$date], 0);
        }
    }
    return implode('<br>', $formatted);
}

// Format date for display
function formatDateForDisplay($date_string) {
    $date_obj = createSafeDateTime($date_string);
    if ($date_obj) {
        return $date_obj->format('F d, Y');
    }
    return $date_string;
}

// Format short date for display
function formatShortDate($date_string) {
    $date_obj = createSafeDateTime($date_string);
    if ($date_obj) {
        return $date_obj->format('m/d');
    }
    return $date_string;
}

/**
 * Calculate late deduction amount.
 * NEW RULE: 1 peso per minute.
 */
function calculateLateDeduction($daily_salary, $late_minutes) {
    if ($late_minutes <= 0) {
        return 0;
    }
    // 1 peso per minute
    return round($late_minutes * 1.00, 2);
}

// Main execution
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Get user details for branch access
    $account_source = $_SESSION['account_source'] ?? 'accounts';
    if ($account_source === 'accounts') {
        $stmt = $conn->prepare("SELECT branch, account_type FROM accounts WHERE id = ?");
    } else {
        $stmt = $conn->prepare("SELECT branch, account_type FROM proxy_accounts WHERE id = ?");
    }
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_branch = '';
    $user_account_type = '';
    $available_branches = [];
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $user_branch = $user['branch'] ?? '';
        $user_account_type = $user['account_type'] ?? '';
        if (strtoupper($user_branch) === 'ALL BRANCHES') {
            $available_branches = ['Tuguegarao City', 'Gattaran', 'Abulug', 'Ilagan'];
        } else {
            if (!empty($user_branch)) {
                $available_branches = array_map('trim', explode(',', $user_branch));
            }
        }
    }
    $stmt->close();

    // Get attendance records with filters
    $attendance_records = [];
    $unique_employees = [];
    $all_working_days = [];

    $sql = "SELECT * FROM attendance WHERE 1=1";
    $params = [];
    $types = "";

    if (!empty($filter_branch)) {
        $sql .= " AND department = ?";
        $params[] = $filter_branch;
        $types .= "s";
    } elseif (!empty($available_branches) && strtoupper($user_branch) !== 'ALL BRANCHES') {
        $placeholders = implode(',', array_fill(0, count($available_branches), '?'));
        $sql .= " AND department IN ($placeholders)";
        foreach ($available_branches as $branch) {
            $params[] = $branch;
            $types .= "s";
        }
    }

    if (!empty($filter_date_from)) {
        $sql .= " AND date >= ?";
        $params[] = $filter_date_from;
        $types .= "s";
    }
    if (!empty($filter_date_to)) {
        $sql .= " AND date <= ?";
        $params[] = $filter_date_to;
        $types .= "s";
    }

    $sql .= " ORDER BY date ASC, user_id";
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    // Calculate total working days
    if (!empty($filter_date_from) && !empty($filter_date_to)) {
        $total_working_days = calculateWorkingDays($filter_date_from, $filter_date_to);
        $all_working_days = getWorkingDays($filter_date_from, $filter_date_to);
    } else {
        $date_range_sql = "SELECT MIN(date) as min_date, MAX(date) as max_date FROM attendance";
        if (!empty($filter_branch)) {
            $date_range_sql .= " WHERE department = '$filter_branch'";
        } elseif (!empty($available_branches) && strtoupper($user_branch) !== 'ALL BRANCHES') {
            $branches_list = implode("','", array_map([$conn, 'real_escape_string'], $available_branches));
            $date_range_sql .= " WHERE department IN ('$branches_list')";
        }
        $date_range_result = $conn->query($date_range_sql);
        if ($date_range_result && $date_range_result->num_rows > 0) {
            $date_range = $date_range_result->fetch_assoc();
            if ($date_range['min_date'] && $date_range['max_date']) {
                $total_working_days = calculateWorkingDays($date_range['min_date'], $date_range['max_date']);
                $all_working_days = getWorkingDays($date_range['min_date'], $date_range['max_date']);
                $filter_date_from = $date_range['min_date'];
                $filter_date_to = $date_range['max_date'];
            }
        }
    }

    // Get unique employees with records
    $employees_with_records = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $attendance_records[] = $row;
            if (!isset($employees_with_records[$row['user_id']])) {
                $employees_with_records[$row['user_id']] = [
                    'user_id' => $row['user_id'],
                    'name' => $row['name'],
                    'department' => $row['department']
                ];
            }
        }
    }
    $stmt->close();

    $all_employees = $employees_with_records;
    $counter = 1;
    foreach ($all_employees as $user_id => $employee) {
        $unique_employees[$user_id] = [
            '#' => $counter++,
            'Employee #' => $user_id,
            'Employee Name' => $employee['name'],
            'Branch' => $employee['department'],
            'Daily Salary' => 'Variable',
            'Working Days' => $total_working_days,
            'Days Present' => 0,
            'Days Halfday' => 0,
            'Days Absent' => 0,
            'Late Minutes' => 0,
            'Late Deduction' => 0,
            'SSS' => 0,
            'PagIbig' => 0,
            'PhilHealth' => 0,
            'Loan Payment' => 0,
            'Cash Advance' => 0,
            'SSS Loan' => 0,
            'PagIbig Loan' => 0,
            'Gross Salary' => 0,
            'Net Pay' => 0,
            'salary_details' => [],
            'salary_history' => [],
            'salary_history_text' => '',
            'sss_details' => [],
            'pagibig_details' => [],
            'philhealth_details' => [],
            'loan_details' => [],
            'cash_advance_details' => [],
            'sss_loan_details' => [],
            'pagibig_loan_details' => [],
            'allowances' => [
                'Rice Allowance' => 0,
                'COLA' => 0,
                'Motor Rental' => 0,
                'Incentive' => 0
            ],
            'allowance_details' => []
        ];
    }

    // Build attendance lookup
    $attendance_lookup = [];
    foreach ($attendance_records as $record) {
        $user_id = $record['user_id'];
        $date = $record['date'];
        if (!isset($attendance_lookup[$user_id])) {
            $attendance_lookup[$user_id] = [];
        }
        $attendance_lookup[$user_id][$date] = $record;
    }

    // Calculate for each employee
    foreach ($unique_employees as $user_id => &$employee_data) {
        $days_present = 0;
        $days_halfday = 0;
        $days_absent = 0;
        $total_late_minutes = 0;
        $total_late_deduction = 0;
        $total_salary = 0;
        $total_sss = 0;
        $total_pagibig = 0;
        $total_philhealth = 0;
        $total_loan_payment = 0;
        $total_cash_advance = 0;
        $total_sss_loan = 0;
        $total_pagibig_loan = 0;
        $salary_details = [];
        $salary_history = [];
        $late_details = [];
        $sss_details = [];
        $pagibig_details = [];
        $philhealth_details = [];
        $loan_details = [];
        $cash_advance_details = [];
        $sss_loan_details = [];
        $pagibig_loan_details = [];
        $allowances = ['Rice Allowance' => 0, 'COLA' => 0, 'Motor Rental' => 0, 'Incentive' => 0];
        $allowance_details = [];

        $account_info = getAccountIdByEmployeeNumber($conn, $user_id);
        if ($account_info) {
            $salary_history = getEmployeeSalaryHistory(
                $conn,
                $account_info['account_id'],
                $account_info['source_table'],
                $filter_date_from,
                $filter_date_to
            );
            $employee_data['salary_history'] = $salary_history;
            $employee_data['salary_history_text'] = formatSalaryHistory($salary_history);

            if (!empty($filter_date_from) && !empty($filter_date_to)) {
                $sss_result = getEmployeeSSSDeductions($conn, $account_info['account_id'], $account_info['employee_type'], $filter_date_from, $filter_date_to);
                $total_sss = $sss_result['total'];
                $sss_details = $sss_result['details'];
                $employee_data['sss_details'] = $sss_details;

                $pagibig_result = getEmployeePagIbigDeductions($conn, $account_info['account_id'], $account_info['employee_type'], $filter_date_from, $filter_date_to);
                $total_pagibig = $pagibig_result['total'];
                $pagibig_details = $pagibig_result['details'];
                $employee_data['pagibig_details'] = $pagibig_details;

                $philhealth_result = getEmployeePhilHealthDeductions($conn, $account_info['account_id'], $account_info['employee_type'], $filter_date_from, $filter_date_to);
                $total_philhealth = $philhealth_result['total'];
                $philhealth_details = $philhealth_result['details'];
                $employee_data['philhealth_details'] = $philhealth_details;

                $loan_result = getEmployeeLoanPayments($conn, $account_info['account_id'], $account_info['employee_type'], $filter_date_from, $filter_date_to);
                $total_loan_payment = $loan_result['total'];
                $loan_details = $loan_result['details'];
                $employee_data['loan_details'] = $loan_details;

                $cash_advance_result = getEmployeeCashAdvances($conn, $account_info['account_id'], $account_info['employee_type'], $filter_date_from, $filter_date_to);
                $total_cash_advance = $cash_advance_result['total'];
                $cash_advance_details = $cash_advance_result['details'];
                $employee_data['cash_advance_details'] = $cash_advance_details;

                $sss_loan_result = getEmployeeSSSLoanDeductions($conn, $account_info['account_id'], $account_info['employee_type'], $filter_date_from, $filter_date_to);
                $total_sss_loan = $sss_loan_result['total'];
                $sss_loan_details = $sss_loan_result['details'];
                $employee_data['sss_loan_details'] = $sss_loan_details;

                $pagibig_loan_result = getEmployeePagIbigLoanDeductions($conn, $account_info['account_id'], $account_info['employee_type'], $filter_date_from, $filter_date_to);
                $total_pagibig_loan = $pagibig_loan_result['total'];
                $pagibig_loan_details = $pagibig_loan_result['details'];
                $employee_data['pagibig_loan_details'] = $pagibig_loan_details;

                $allowance_result = getEmployeeAllowances($conn, $account_info['account_id'], $account_info['employee_type'], $filter_date_from, $filter_date_to);
                $allowances = $allowance_result['totals'];
                $allowance_details = $allowance_result['details'];
                $employee_data['allowances'] = $allowances;
                $employee_data['allowance_details'] = $allowance_details;
            }
        }

        // Check each working day
        foreach ($all_working_days as $working_date) {
            if (isset($attendance_lookup[$user_id][$working_date])) {
                $record = $attendance_lookup[$user_id][$working_date];

                // Compute status dynamically, considering 12:00-13:00 halfday window
                $hasTimeIn = isset($record['before_noon_in']) && !is_null($record['before_noon_in']) && $record['before_noon_in'] !== '';
                $hasTimeOut = isset($record['after_noon_out']) && !is_null($record['after_noon_out']) && $record['after_noon_out'] !== '';
                $computed_status = determineAttendanceStatus(
                    $hasTimeIn,
                    $hasTimeOut,
                    $record['before_noon_in'] ?? null,
                    $record['after_noon_out'] ?? null
                );

                // Late deduction (applies on any day with a time in)
                if ($account_info && isset($record['late_minutes']) && $record['late_minutes'] > 0) {
                    $daily_salary_for_late = getSalaryForDate($salary_history, $working_date);
                    $late_minutes = (int)$record['late_minutes'];
                    $late_deduction = calculateLateDeduction($daily_salary_for_late, $late_minutes);

                    $total_late_minutes += $late_minutes;
                    $total_late_deduction += $late_deduction;

                    $late_details[] = [
                        'date' => $working_date,
                        'minutes' => $late_minutes,
                        'deduction' => $late_deduction,
                        'salary' => $daily_salary_for_late
                    ];
                }

                // Salary contribution based on computed status
                if ($computed_status === 'Present') {
                    $days_present++;
                    if ($account_info) {
                        $daily_salary = getSalaryForDate($salary_history, $working_date);
                        $total_salary += $daily_salary;
                        $effective_date = findEffectiveDateForSalary($salary_history, $working_date);
                        $salary_details[$working_date] = [
                            'salary' => $daily_salary,
                            'effective_date' => $effective_date,
                            'status' => 'Present'
                        ];
                    }
                } elseif ($computed_status === 'Halfday') {
                    // Count as half day present
                    $days_halfday++;
                    if ($account_info) {
                        $daily_salary = getSalaryForDate($salary_history, $working_date);
                        // Half day = 0.5 x daily salary
                        $half_salary = $daily_salary * 0.5;
                        $total_salary += $half_salary;
                        $effective_date = findEffectiveDateForSalary($salary_history, $working_date);
                        $salary_details[$working_date] = [
                            'salary' => $half_salary,
                            'effective_date' => $effective_date,
                            'status' => 'Halfday'
                        ];
                    }
                } elseif ($computed_status === 'Absent') {
                    $days_absent++;
                }
            } else {
                // No record for this working day = absent
                $days_absent++;
            }
        }

        $total_allowances = $allowances['Rice Allowance'] + $allowances['COLA'] + $allowances['Motor Rental'] + $allowances['Incentive'];
        $gross_salary = $total_salary + $total_allowances;
        $net_pay = $gross_salary - $total_late_deduction - $total_sss - $total_pagibig - $total_philhealth - $total_loan_payment - $total_cash_advance - $total_sss_loan - $total_pagibig_loan;

        $employee_data['Days Present'] = $days_present;
        $employee_data['Days Halfday'] = $days_halfday;
        $employee_data['Days Absent'] = $days_absent;
        $employee_data['Late Minutes'] = $total_late_minutes;
        $employee_data['Late Deduction'] = $total_late_deduction;
        $employee_data['SSS'] = $total_sss;
        $employee_data['PagIbig'] = $total_pagibig;
        $employee_data['PhilHealth'] = $total_philhealth;
        $employee_data['Loan Payment'] = $total_loan_payment;
        $employee_data['Cash Advance'] = $total_cash_advance;
        $employee_data['SSS Loan'] = $total_sss_loan;
        $employee_data['PagIbig Loan'] = $total_pagibig_loan;
        $employee_data['Gross Salary'] = $gross_salary;
        $employee_data['Net Pay'] = $net_pay;
        $employee_data['salary_details'] = $salary_details;
        $employee_data['late_details'] = $late_details;

        // Daily Salary display: use total paid / (present + halfday/2) for an average
        $effective_days = $days_present + ($days_halfday * 0.5);
        if ($effective_days > 0) {
            $employee_data['Daily Salary'] = number_format($total_salary / $effective_days, 2) . ' (avg)';
        }
    }

    // Build display strings
    $date_range_text = 'All Records';
    $branch_text = '';
    if (!empty($filter_branch)) {
        $branch_text = ' - Branch: ' . $filter_branch;
    } elseif (!empty($available_branches) && strtoupper($user_branch) !== 'ALL BRANCHES') {
        $branch_text = ' - All My Branches';
    }
    if (!empty($filter_date_from) && !empty($filter_date_to)) {
        $date_range_text = formatDateForDisplay($filter_date_from) . ' - ' . formatDateForDisplay($filter_date_to) . $branch_text;
    } elseif (!empty($filter_date_from)) {
        $date_range_text = 'From ' . formatDateForDisplay($filter_date_from) . $branch_text;
    } elseif (!empty($filter_date_to)) {
        $date_range_text = 'Until ' . formatDateForDisplay($filter_date_to) . $branch_text;
    } elseif (!empty($filter_branch)) {
        $date_range_text = 'All Records - Branch: ' . $filter_branch;
    } elseif (!empty($available_branches) && strtoupper($user_branch) !== 'ALL BRANCHES') {
        $date_range_text = 'All Records - All My Branches';
    }

    $report_date = date('F d, Y h:i A');
    $total_months = 0;
    if (!empty($filter_date_from) && !empty($filter_date_to)) {
        $total_months = getMonthsInRange($filter_date_from, $filter_date_to);
    }

} catch (Exception $e) {
    error_log($e->getMessage());
    die("Error fetching attendance records: " . $e->getMessage());
}

// ===== HTML generation with pagination: 3 employees per page =====
$html = '';

$employee_list = array_values($unique_employees);

$grand_total_salary = 0;
$grand_total_net_pay = 0;
foreach ($employee_list as $emp) {
    $grand_total_salary += $emp['Gross Salary'];
    $grand_total_net_pay += $emp['Net Pay'];
}

$chunks = array_chunk($employee_list, 3);

$header_html = '
    <div class="header">
        <h1>PRIME CITY</h1>
        <h2>Payroll</h2>
        <div class="date-range">
            ' . $date_range_text . '
        </div>
    </div>';

$html .= '
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
    <meta charset="UTF-8">
    <title>Payroll Report - Prime City</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 7pt;
            line-height: 1.2;
            margin: -20px;
        }
        .header {
            text-align: center;
            margin-top: -30px;
            margin-bottom: 20px;
        }
        .header h1 {
            font-size: 24pt;
            font-weight: bold;
        }
        .header h2 {
            margin-top: -25px;
            font-size: 16pt;
            font-weight: normal;
        }
        .header .date-range {
            font-size: 9pt;
            margin-top: -15px;
        }
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 6.5pt;
        }
        .summary-table th {
            background-color: #3069e3;
            color: white;
            font-weight: bold;
            padding: 4px 1px;
            text-align: center;
            border: 1px solid #ffffff;
            font-size: 6.5pt;
        }
        .summary-table td {
            padding: 3px 1px;
            border: 1px solid #cccccc;
            text-align: left;
            vertical-align: middle;
        }
        .summary-table td.center {
            text-align: center;
        }
        .summary-table td.right {
            text-align: right;
            padding-right: 3px;
        }
        .summary-table td.employee-info {
            font-weight: bold;
        }
        .deduction-amount, .sss-amount, .pagibig-amount, .philhealth-amount, .loan-amount, .cash-advance-amount, .sss-loan-amount, .pagibig-loan-amount, .lp-amount, .ca-amount {
            color: #dc3545;
            font-weight: bold;
        }
        .net-pay {
            font-weight: bold;
        }
        .signature-column {
            width: 80px;
        }
        .label-cell {
            font-weight: bold;
            background-color: #f2f2f2;
            text-align: center;
        }
        .page {
            page-break-after: always;
            page-break-inside: avoid;
        }
        .page:last-child {
            page-break-after: auto;
        }
        .footer {
            position: fixed;
            bottom: -30px;
            right: 0px;
            font-size: 8pt;
            color: #333;
        }
        @page {
            margin: 0.5in;
        }
    </style>
</head>
<body>';

$html .= $header_html;

if (empty($employee_list)) {
    $html .= '
        <div style="text-align: center; padding: 50px; color: #999; border: 1px solid #ddd; margin-top: 20px;">
            <h3>No attendance records found</h3>
            <p>' . (!empty($filter_date_from) || !empty($filter_date_to) || !empty($filter_branch) ? 'No records match the selected filters.' : 'No records available.') . '</p>
        </div>';
} else {
    foreach ($chunks as $page_index => $chunk) {
        $html .= '<div class="page">';

        $html .= '
        <table class="summary-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Emp #</th>
                    <th>Employee Name</th>
                    <th>Branch</th>
                    <th>Daily Rate</th>
                    <th>Work Days</th>
                    <th>Present</th>
                    <th>Halfday</th>
                    <th>Absent</th>
                    <th>Late Mins</th>
                    <th colspan="3"></th>
                    <th>Gross Salary</th>
                    <th>Net Pay</th>
                    <th>Signature</th>
                </tr>
            </thead>
            <tbody>';

        $page_total_salary = 0;
        $page_total_net_pay = 0;

        foreach ($chunk as $employee) {
            $page_total_salary += $employee['Gross Salary'];
            $page_total_net_pay += $employee['Net Pay'];

            $rowspan = 10;

            $emp_num = $employee['Employee #'];
            $emp_name = htmlspecialchars($employee['Employee Name']);
            $branch = htmlspecialchars($employee['Branch']);
            $daily_rate_display = $employee['Daily Salary'];
            $work_days = $employee['Working Days'];
            $present = $employee['Days Present'];
            $halfday = $employee['Days Halfday'];
            $absent = $employee['Days Absent'];
            $late_minutes = $employee['Late Minutes'] . ' mins';
            if ($employee['Late Deduction'] > 0) {
                $late_minutes = '<span class="late-display" title="Total deduction: ' . number_format($employee['Late Deduction'], 2) . '">' . 
                               $employee['Late Minutes'] . ' mins (<span class="deduction-amount">' . number_format($employee['Late Deduction'], 2) . '</span>)</span>';
            }
            $gross_salary = number_format($employee['Gross Salary'], 2);
            $net_pay = number_format($employee['Net Pay'], 2);

            $lp = $employee['Loan Payment'];
            $ca = $employee['Cash Advance'];
            $sss = $employee['SSS'];
            $pagibig = $employee['PagIbig'];
            $philhealth = $employee['PhilHealth'];
            $sss_loan = $employee['SSS Loan'];
            $pagibig_loan = $employee['PagIbig Loan'];
            $rice = $employee['allowances']['Rice Allowance'] ?? 0;
            $cola = $employee['allowances']['COLA'] ?? 0;
            $motor = $employee['allowances']['Motor Rental'] ?? 0;
            $incentive = $employee['allowances']['Incentive'] ?? 0;

            // ROW 1
            $html .= '<tr>';
            $html .= '<td class="center" rowspan="' . $rowspan . '">' . $employee['#'] . '</td>';
            $html .= '<td class="center" rowspan="' . $rowspan . '">' . $emp_num . '</td>';
            $html .= '<td class="center employee-info" rowspan="' . $rowspan . '">' . $emp_name . '</td>';
            $html .= '<td class="center" rowspan="' . $rowspan . '">' . $branch . '</td>';
            $html .= '<td class="center" rowspan="' . $rowspan . '">' . $daily_rate_display . '</td>';
            $html .= '<td class="center" rowspan="' . $rowspan . '">' . $work_days . '</td>';
            $html .= '<td class="center" rowspan="' . $rowspan . '">' . $present . '</td>';
            $html .= '<td class="center" rowspan="' . $rowspan . '">' . $halfday . '</td>';
            $html .= '<td class="center" rowspan="' . $rowspan . '">' . $absent . '</td>';
            $html .= '<td class="center" rowspan="' . $rowspan . '">' . $late_minutes . '</td>';
            $html .= '<td class="center label-cell" colspan="3">LP</td>';
            $html .= '<td class="right" rowspan="' . $rowspan . '">' . $gross_salary . '</td>';
            $html .= '<td class="right net-pay" rowspan="' . $rowspan . '">' . $net_pay . '</td>';
            $html .= '<td class="signature-column" rowspan="' . $rowspan . '"></td>';
            $html .= '</tr>';

            // ROW 2
            $html .= '<tr>';
            $html .= '<td class="center" colspan="3"><span class="lp-amount">' . number_format($lp, 2) . '</span></td>';
            $html .= '</tr>';

            // ROW 3
            $html .= '<tr>';
            $html .= '<td class="center label-cell">CA</td>';
            $html .= '<td class="center label-cell">SSS</td>';
            $html .= '<td class="center label-cell">Pag-IBIG</td>';
            $html .= '</tr>';

            // ROW 4
            $html .= '<tr>';
            $html .= '<td class="center"><span class="ca-amount">' . number_format($ca, 2) . '</span></td>';
            $html .= '<td class="center"><span class="sss-amount">' . number_format($sss, 2) . '</span></td>';
            $html .= '<td class="center"><span class="pagibig-amount">' . number_format($pagibig, 2) . '</span></td>';
            $html .= '</tr>';

            // ROW 5
            $html .= '<tr>';
            $html .= '<td class="center label-cell">PhilHealth</td>';
            $html .= '<td class="center label-cell">SSS Loan</td>';
            $html .= '<td class="center label-cell">Pag-IBIG Loan</td>';
            $html .= '</tr>';

            // ROW 6
            $html .= '<tr>';
            $html .= '<td class="center"><span class="philhealth-amount">' . number_format($philhealth, 2) . '</span></td>';
            $html .= '<td class="center"><span class="sss-loan-amount">' . number_format($sss_loan, 2) . '</span></td>';
            $html .= '<td class="center"><span class="pagibig-loan-amount">' . number_format($pagibig_loan, 2) . '</span></td>';
            $html .= '</tr>';

            // ROW 7
            $html .= '<tr>';
            $html .= '<td class="center label-cell" colspan="3">Rice Allowance</td>';
            $html .= '</tr>';

            // ROW 8
            $html .= '<tr>';
            $html .= '<td class="center" colspan="3">' . number_format($rice, 2) . '</td>';
            $html .= '</tr>';

            // ROW 9
            $html .= '<tr>';
            $html .= '<td class="center label-cell">COLA</td>';
            $html .= '<td class="center label-cell">Motor Rental</td>';
            $html .= '<td class="center label-cell">Incentive</td>';
            $html .= '</tr>';

            // ROW 10
            $html .= '<tr>';
            $html .= '<td class="center">' . number_format($cola, 2) . '</td>';
            $html .= '<td class="center">' . number_format($motor, 2) . '</td>';
            $html .= '<td class="center">' . number_format($incentive, 2) . '</td>';
            $html .= '</tr>';
        }

        // Page totals
        $html .= '
            <tr>
                <td colspan="13" style="text-align: right; font-weight: bold; background-color: #f0f0f0;">
                    PAGE TOTALS:
                </td>
                <td class="right" style="font-weight: bold; background-color: #f0f0f0;">
                    ' . number_format($page_total_salary, 2) . '
                </td>
                <td class="right" style="font-weight: bold; background-color: #f0f0f0;">
                    ' . number_format($page_total_net_pay, 2) . '
                </td>
                <td colspan="1" style="background-color: #f0f0f0;"></td>
            </tr>';

        if ($page_index == count($chunks) - 1 && count($chunks) > 1) {
            $html .= '
            <tr>
                <td colspan="13" style="text-align: right; font-weight: bold; background-color: #e0e0e0;">
                    GRAND TOTALS:
                </td>
                <td class="right" style="font-weight: bold; background-color: #e0e0e0;">
                    ' . number_format($grand_total_salary, 2) . '
                </td>
                <td class="right" style="font-weight: bold; background-color: #e0e0e0;">
                    ' . number_format($grand_total_net_pay, 2) . '
                </td>
                <td colspan="1" style="background-color: #e0e0e0;"></td>
            </tr>';
        }

        $html .= '
            </tbody>
        </table>';

        $current_page = $page_index + 1;
        $total_pages = count($chunks);
        $html .= '<div class="footer">Page ' . $current_page . ' of ' . $total_pages . '</div>';

        $html .= '</div>';
    }
}

$html .= '
</body>
</html>';

// Create PDF options
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultPaperSize', 'a4');
$options->set('defaultPaperOrientation', 'landscape');
$options->set('fontDir', __DIR__ . '/fonts/');
$options->set('fontCache', __DIR__ . '/fonts/');
$options->set('chroot', __DIR__);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('a4', 'landscape');
$dompdf->render();

$filename = 'payroll_report_';
if (!empty($filter_branch)) {
    $filename .= $filter_branch . '_';
} elseif (!empty($available_branches) && strtoupper($user_branch) !== 'ALL BRANCHES') {
    $filename .= 'all_my_branches_';
}
if (!empty($filter_date_from) && !empty($filter_date_to)) {
    $filename .= $filter_date_from . '_to_' . $filter_date_to;
} elseif (!empty($filter_date_from)) {
    $filename .= 'from_' . $filter_date_from;
} elseif (!empty($filter_date_to)) {
    $filename .= 'until_' . $filter_date_to;
} else {
    $filename .= 'all_records';
}
$filename .= '_' . date('Ymd_His') . '.pdf';

$dompdf->stream($filename, array('Attachment' => false));
exit;
?>
