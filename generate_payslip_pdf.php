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

// Get filter parameters from URL
$filter_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filter_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$filter_branch = isset($_GET['branch']) ? trim($_GET['branch']) : '';
$employee_filter = isset($_GET['employee']) ? trim($_GET['employee']) : '';

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
        return 0;
    }
    $start = createSafeDateTime($date_from);
    $end = createSafeDateTime($date_to);
    if (!$start || !$end) {
        return 0;
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

// Function to calculate number of months between two dates
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

// Count monthly periods (end of month - 16th to end)
function countMonthlyPeriods($date_from, $date_to, $effective_date = null, $start_date = null, $end_date = null) {
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
    if ($start_date) {
        $loan_start = createSafeDateTime($start_date);
        if ($loan_start && $loan_start > $start) {
            $start = clone $loan_start;
        }
    }
    if ($end_date) {
        $loan_end = createSafeDateTime($end_date);
        if ($loan_end && $loan_end < $end) {
            $end = clone $loan_end;
            $end->modify('+1 day');
        }
    }
    if ($start >= $end) {
        return 0;
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

// Function to count semi-monthly periods
function countSemiMonthlyPeriods($date_from, $date_to, $effective_date = null, $start_date = null, $end_date = null) {
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
    if ($start_date) {
        $loan_start = createSafeDateTime($start_date);
        if ($loan_start && $loan_start > $start) {
            $start = clone $loan_start;
        }
    }
    if ($end_date) {
        $loan_end = createSafeDateTime($end_date);
        if ($loan_end && $loan_end < $end) {
            $end = clone $loan_end;
            $end->modify('+1 day');
        }
    }
    if ($start >= $end) {
        return 0;
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

// Get salary history
function getEmployeeSalaryHistory($conn, $account_id, $source_table, $date_from, $date_to) {
    $salary_history = [];
    if (empty($date_from) && empty($date_to)) {
        $sql = "SELECT daily_salary, effective_date 
                FROM salary_history 
                WHERE account_id = ? AND source_table = ? 
                ORDER BY effective_date ASC";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("is", $account_id, $source_table);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $salary_history[$row['effective_date']] = $row['daily_salary'];
            }
            $stmt->close();
        }
        return $salary_history;
    }
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
        while ($row = $result->fetch_assoc()) {
            $salary_history[$row['effective_date']] = $row['daily_salary'];
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

// SSS deductions
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
        $date_from_obj = !empty($date_from) ? createSafeDateTime($date_from) : null;
        $date_to_obj = !empty($date_to) ? createSafeDateTime($date_to) : null;
        $current_date = new DateTime();
        while ($row = $result->fetch_assoc()) {
            $deduction_amount = 0;
            $deduction_description = "";
            $effective_date = isset($row['effective_date']) ? createSafeDateTime($row['effective_date']) : null;
            if ($date_to_obj && $effective_date && $effective_date > $date_to_obj) {
                continue;
            }
            switch ($row['frequency']) {
                case 'one_time':
                    if ($effective_date) {
                        if (empty($date_from) && empty($date_to)) {
                            if ($effective_date <= $current_date) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                            }
                        } elseif ($date_from_obj && $date_to_obj && $effective_date >= $date_from_obj && $effective_date <= $date_to_obj) {
                            $deduction_amount = $row['amount'];
                            $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                        }
                    }
                    break;
                case 'monthly':
                    if (empty($date_from) || empty($date_to)) {
                        $current_day = (int)$current_date->format('d');
                        if ($current_day >= 16) {
                            $deduction_amount = $row['amount'];
                            $deduction_description = "Monthly (current month - end of month)";
                        }
                    } else {
                        $periods = countMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                        $deduction_amount = $row['amount'] * $periods;
                        $deduction_description = "Monthly (end of month) × " . $periods . " months";
                    }
                    break;
                case 'semi_monthly':
                    if (empty($date_from) || empty($date_to)) {
                        $deduction_amount = $row['amount'] * 2;
                        $deduction_description = "Semi-monthly × 2 periods (current month)";
                    } else {
                        $periods = countSemiMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                        $deduction_amount = $row['amount'] * $periods;
                        $deduction_description = "Semi-monthly × " . $periods . " periods";
                    }
                    break;
                default:
                    $deduction_amount = $row['amount'];
                    $deduction_description = $row['frequency'];
            }
            if ($deduction_amount > 0) {
                $total_sss_deduction += $deduction_amount;
                $sss_details[] = ['amount' => $deduction_amount, 'description' => $deduction_description];
            }
        }
        $stmt->close();
    }
    return ['total' => $total_sss_deduction, 'details' => $sss_details];
}

// Pag-IBIG deductions
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
        $date_from_obj = !empty($date_from) ? createSafeDateTime($date_from) : null;
        $date_to_obj = !empty($date_to) ? createSafeDateTime($date_to) : null;
        $current_date = new DateTime();
        while ($row = $result->fetch_assoc()) {
            $deduction_amount = 0;
            $deduction_description = "";
            $effective_date = isset($row['effective_date']) ? createSafeDateTime($row['effective_date']) : null;
            if ($date_to_obj && $effective_date && $effective_date > $date_to_obj) {
                continue;
            }
            switch ($row['frequency']) {
                case 'one_time':
                    if ($effective_date) {
                        if (empty($date_from) && empty($date_to)) {
                            if ($effective_date <= $current_date) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                            }
                        } elseif ($date_from_obj && $date_to_obj && $effective_date >= $date_from_obj && $effective_date <= $date_to_obj) {
                            $deduction_amount = $row['amount'];
                            $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                        }
                    }
                    break;
                case 'monthly':
                    if (empty($date_from) || empty($date_to)) {
                        $current_day = (int)$current_date->format('d');
                        if ($current_day >= 16) {
                            $deduction_amount = $row['amount'];
                            $deduction_description = "Monthly (current month - end of month)";
                        }
                    } else {
                        $periods = countMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                        $deduction_amount = $row['amount'] * $periods;
                        $deduction_description = "Monthly (end of month) × " . $periods . " months";
                    }
                    break;
                case 'semi_monthly':
                    if (empty($date_from) || empty($date_to)) {
                        $deduction_amount = $row['amount'] * 2;
                        $deduction_description = "Semi-monthly × 2 periods (current month)";
                    } else {
                        $periods = countSemiMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                        $deduction_amount = $row['amount'] * $periods;
                        $deduction_description = "Semi-monthly × " . $periods . " periods";
                    }
                    break;
                default:
                    $deduction_amount = $row['amount'];
                    $deduction_description = $row['frequency'];
            }
            if ($deduction_amount > 0) {
                $total_pagibig_deduction += $deduction_amount;
                $pagibig_details[] = ['amount' => $deduction_amount, 'description' => $deduction_description];
            }
        }
        $stmt->close();
    }
    return ['total' => $total_pagibig_deduction, 'details' => $pagibig_details];
}

// PhilHealth deductions
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
        $date_from_obj = !empty($date_from) ? createSafeDateTime($date_from) : null;
        $date_to_obj = !empty($date_to) ? createSafeDateTime($date_to) : null;
        $current_date = new DateTime();
        while ($row = $result->fetch_assoc()) {
            $deduction_amount = 0;
            $deduction_description = "";
            $effective_date = isset($row['effective_date']) ? createSafeDateTime($row['effective_date']) : null;
            if ($date_to_obj && $effective_date && $effective_date > $date_to_obj) {
                continue;
            }
            switch ($row['frequency']) {
                case 'one_time':
                    if ($effective_date) {
                        if (empty($date_from) && empty($date_to)) {
                            if ($effective_date <= $current_date) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                            }
                        } elseif ($date_from_obj && $date_to_obj && $effective_date >= $date_from_obj && $effective_date <= $date_to_obj) {
                            $deduction_amount = $row['amount'];
                            $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                        }
                    }
                    break;
                case 'monthly':
                    if (empty($date_from) || empty($date_to)) {
                        $current_day = (int)$current_date->format('d');
                        if ($current_day >= 16) {
                            $deduction_amount = $row['amount'];
                            $deduction_description = "Monthly (current month - end of month)";
                        }
                    } else {
                        $periods = countMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                        $deduction_amount = $row['amount'] * $periods;
                        $deduction_description = "Monthly (end of month) × " . $periods . " months";
                    }
                    break;
                case 'semi_monthly':
                    if (empty($date_from) || empty($date_to)) {
                        $deduction_amount = $row['amount'] * 2;
                        $deduction_description = "Semi-monthly × 2 periods (current month)";
                    } else {
                        $periods = countSemiMonthlyPeriods($date_from, $date_to, $effective_date ? $effective_date->format('Y-m-d') : null);
                        $deduction_amount = $row['amount'] * $periods;
                        $deduction_description = "Semi-monthly × " . $periods . " periods";
                    }
                    break;
                default:
                    $deduction_amount = $row['amount'];
                    $deduction_description = $row['frequency'];
            }
            if ($deduction_amount > 0) {
                $total_philhealth_deduction += $deduction_amount;
                $philhealth_details[] = ['amount' => $deduction_amount, 'description' => $deduction_description];
            }
        }
        $stmt->close();
    }
    return ['total' => $total_philhealth_deduction, 'details' => $philhealth_details];
}

// SSS Loan deductions
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
        $date_from_obj = !empty($date_from) ? createSafeDateTime($date_from) : null;
        $date_to_obj = !empty($date_to) ? createSafeDateTime($date_to) : null;
        $current_date = new DateTime();
        while ($row = $result->fetch_assoc()) {
            $deduction_amount = 0;
            $deduction_description = "";
            $effective_date = isset($row['effective_date']) ? createSafeDateTime($row['effective_date']) : null;
            $start_date = isset($row['start_date']) ? createSafeDateTime($row['start_date']) : null;
            $end_date = isset($row['end_date']) ? createSafeDateTime($row['end_date']) : null;
            if ($date_to_obj && $effective_date && $effective_date > $date_to_obj) {
                continue;
            }
            switch ($row['frequency']) {
                case 'one_time':
                    if ($start_date && $end_date) {
                        if (empty($date_from) && empty($date_to)) {
                            if ($start_date <= $current_date && $end_date >= $current_date) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "One-time (active " . $start_date->format('Y-m-d') . " to " . $end_date->format('Y-m-d') . ")";
                            }
                        } elseif ($date_from_obj && $date_to_obj) {
                            if ($start_date <= $date_to_obj && $end_date >= $date_from_obj) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "One-time (active " . $start_date->format('Y-m-d') . " to " . $end_date->format('Y-m-d') . ")";
                            }
                        }
                    } elseif ($effective_date) {
                        if (empty($date_from) && empty($date_to)) {
                            if ($effective_date <= $current_date) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                            }
                        } elseif ($date_from_obj && $date_to_obj && $effective_date >= $date_from_obj && $effective_date <= $date_to_obj) {
                            $deduction_amount = $row['amount'];
                            $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                        }
                    }
                    break;
                case 'monthly':
                    if (empty($date_from) || empty($date_to)) {
                        if ((!$start_date || $start_date <= $current_date) && (!$end_date || $end_date >= $current_date)) {
                            $current_day = (int)$current_date->format('d');
                            if ($current_day >= 16) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "Monthly (current month - end of month)";
                            }
                        }
                    } else {
                        $periods = countMonthlyPeriods(
                            $date_from, $date_to, 
                            $effective_date ? $effective_date->format('Y-m-d') : null,
                            $start_date ? $start_date->format('Y-m-d') : null,
                            $end_date ? $end_date->format('Y-m-d') : null
                        );
                        $deduction_amount = $row['amount'] * $periods;
                        $deduction_description = "Monthly (end of month) × " . $periods . " months";
                    }
                    break;
                case 'semi_monthly':
                    if (empty($date_from) || empty($date_to)) {
                        if ((!$start_date || $start_date <= $current_date) && (!$end_date || $end_date >= $current_date)) {
                            $deduction_amount = $row['amount'] * 2;
                            $deduction_description = "Semi-monthly × 2 periods (current month)";
                        }
                    } else {
                        $periods = countSemiMonthlyPeriods(
                            $date_from, $date_to, 
                            $effective_date ? $effective_date->format('Y-m-d') : null,
                            $start_date ? $start_date->format('Y-m-d') : null,
                            $end_date ? $end_date->format('Y-m-d') : null
                        );
                        $deduction_amount = $row['amount'] * $periods;
                        $deduction_description = "Semi-monthly × " . $periods . " periods";
                    }
                    break;
                default:
                    $deduction_amount = $row['amount'];
                    $deduction_description = $row['frequency'];
            }
            if ($deduction_amount > 0) {
                $total_sss_loan_deduction += $deduction_amount;
                $sss_loan_details[] = ['amount' => $deduction_amount, 'description' => $deduction_description];
            }
        }
        $stmt->close();
    }
    return ['total' => $total_sss_loan_deduction, 'details' => $sss_loan_details];
}

// Pag-IBIG Loan deductions
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
        $date_from_obj = !empty($date_from) ? createSafeDateTime($date_from) : null;
        $date_to_obj = !empty($date_to) ? createSafeDateTime($date_to) : null;
        $current_date = new DateTime();
        while ($row = $result->fetch_assoc()) {
            $deduction_amount = 0;
            $deduction_description = "";
            $effective_date = isset($row['effective_date']) ? createSafeDateTime($row['effective_date']) : null;
            $start_date = isset($row['start_date']) ? createSafeDateTime($row['start_date']) : null;
            $end_date = isset($row['end_date']) ? createSafeDateTime($row['end_date']) : null;
            if ($date_to_obj && $effective_date && $effective_date > $date_to_obj) {
                continue;
            }
            switch ($row['frequency']) {
                case 'one_time':
                    if ($start_date && $end_date) {
                        if (empty($date_from) && empty($date_to)) {
                            if ($start_date <= $current_date && $end_date >= $current_date) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "One-time (active " . $start_date->format('Y-m-d') . " to " . $end_date->format('Y-m-d') . ")";
                            }
                        } elseif ($date_from_obj && $date_to_obj) {
                            if ($start_date <= $date_to_obj && $end_date >= $date_from_obj) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "One-time (active " . $start_date->format('Y-m-d') . " to " . $end_date->format('Y-m-d') . ")";
                            }
                        }
                    } elseif ($effective_date) {
                        if (empty($date_from) && empty($date_to)) {
                            if ($effective_date <= $current_date) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                            }
                        } elseif ($date_from_obj && $date_to_obj && $effective_date >= $date_from_obj && $effective_date <= $date_to_obj) {
                            $deduction_amount = $row['amount'];
                            $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                        }
                    }
                    break;
                case 'monthly':
                    if (empty($date_from) || empty($date_to)) {
                        if ((!$start_date || $start_date <= $current_date) && (!$end_date || $end_date >= $current_date)) {
                            $current_day = (int)$current_date->format('d');
                            if ($current_day >= 16) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "Monthly (current month - end of month)";
                            }
                        }
                    } else {
                        $periods = countMonthlyPeriods(
                            $date_from, $date_to, 
                            $effective_date ? $effective_date->format('Y-m-d') : null,
                            $start_date ? $start_date->format('Y-m-d') : null,
                            $end_date ? $end_date->format('Y-m-d') : null
                        );
                        $deduction_amount = $row['amount'] * $periods;
                        $deduction_description = "Monthly (end of month) × " . $periods . " months";
                    }
                    break;
                case 'semi_monthly':
                    if (empty($date_from) || empty($date_to)) {
                        if ((!$start_date || $start_date <= $current_date) && (!$end_date || $end_date >= $current_date)) {
                            $deduction_amount = $row['amount'] * 2;
                            $deduction_description = "Semi-monthly × 2 periods (current month)";
                        }
                    } else {
                        $periods = countSemiMonthlyPeriods(
                            $date_from, $date_to, 
                            $effective_date ? $effective_date->format('Y-m-d') : null,
                            $start_date ? $start_date->format('Y-m-d') : null,
                            $end_date ? $end_date->format('Y-m-d') : null
                        );
                        $deduction_amount = $row['amount'] * $periods;
                        $deduction_description = "Semi-monthly × " . $periods . " periods";
                    }
                    break;
                default:
                    $deduction_amount = $row['amount'];
                    $deduction_description = $row['frequency'];
            }
            if ($deduction_amount > 0) {
                $total_pagibig_loan_deduction += $deduction_amount;
                $pagibig_loan_details[] = ['amount' => $deduction_amount, 'description' => $deduction_description];
            }
        }
        $stmt->close();
    }
    return ['total' => $total_pagibig_loan_deduction, 'details' => $pagibig_loan_details];
}

// Loan payments
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
        $date_from_obj = !empty($date_from) ? createSafeDateTime($date_from) : null;
        $date_to_obj = !empty($date_to) ? createSafeDateTime($date_to) : null;
        $current_date = new DateTime();
        while ($row = $result->fetch_assoc()) {
            $deduction_amount = 0;
            $deduction_description = "";
            $effective_date = isset($row['effective_date']) ? createSafeDateTime($row['effective_date']) : null;
            $start_date = isset($row['start_date']) ? createSafeDateTime($row['start_date']) : null;
            $end_date = isset($row['end_date']) ? createSafeDateTime($row['end_date']) : null;
            if ($date_to_obj && $effective_date && $effective_date > $date_to_obj) {
                continue;
            }
            switch ($row['frequency']) {
                case 'one_time':
                    if ($start_date && $end_date) {
                        if (empty($date_from) && empty($date_to)) {
                            if ($start_date <= $current_date && $end_date >= $current_date) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "One-time (active " . $start_date->format('Y-m-d') . " to " . $end_date->format('Y-m-d') . ")";
                            }
                        } elseif ($date_from_obj && $date_to_obj) {
                            if ($start_date <= $date_to_obj && $end_date >= $date_from_obj) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "One-time (active " . $start_date->format('Y-m-d') . " to " . $end_date->format('Y-m-d') . ")";
                            }
                        }
                    } elseif ($effective_date) {
                        if (empty($date_from) && empty($date_to)) {
                            if ($effective_date <= $current_date) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                            }
                        } elseif ($date_from_obj && $date_to_obj && $effective_date >= $date_from_obj && $effective_date <= $date_to_obj) {
                            $deduction_amount = $row['amount'];
                            $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                        }
                    }
                    break;
                case 'monthly':
                    if (empty($date_from) || empty($date_to)) {
                        if ((!$start_date || $start_date <= $current_date) && (!$end_date || $end_date >= $current_date)) {
                            $current_day = (int)$current_date->format('d');
                            if ($current_day >= 16) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "Monthly (current month - end of month)";
                            }
                        }
                    } else {
                        $periods = countMonthlyPeriods(
                            $date_from, $date_to, 
                            $effective_date ? $effective_date->format('Y-m-d') : null,
                            $start_date ? $start_date->format('Y-m-d') : null,
                            $end_date ? $end_date->format('Y-m-d') : null
                        );
                        $deduction_amount = $row['amount'] * $periods;
                        $deduction_description = "Monthly (end of month) × " . $periods . " months";
                    }
                    break;
                case 'semi_monthly':
                    if (empty($date_from) || empty($date_to)) {
                        if ((!$start_date || $start_date <= $current_date) && (!$end_date || $end_date >= $current_date)) {
                            $deduction_amount = $row['amount'] * 2;
                            $deduction_description = "Semi-monthly × 2 periods (current month)";
                        }
                    } else {
                        $periods = countSemiMonthlyPeriods(
                            $date_from, $date_to, 
                            $effective_date ? $effective_date->format('Y-m-d') : null,
                            $start_date ? $start_date->format('Y-m-d') : null,
                            $end_date ? $end_date->format('Y-m-d') : null
                        );
                        $deduction_amount = $row['amount'] * $periods;
                        $deduction_description = "Semi-monthly × " . $periods . " periods";
                    }
                    break;
                default:
                    $deduction_amount = $row['amount'];
                    $deduction_description = $row['frequency'];
            }
            if ($deduction_amount > 0) {
                $total_loan_payment += $deduction_amount;
                $loan_details[] = ['amount' => $deduction_amount, 'description' => $deduction_description];
            }
        }
        $stmt->close();
    }
    return ['total' => $total_loan_payment, 'details' => $loan_details];
}

// Cash advances
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
        $date_from_obj = !empty($date_from) ? createSafeDateTime($date_from) : null;
        $date_to_obj = !empty($date_to) ? createSafeDateTime($date_to) : null;
        $current_date = new DateTime();
        while ($row = $result->fetch_assoc()) {
            $deduction_amount = 0;
            $deduction_description = "";
            $effective_date = isset($row['effective_date']) ? createSafeDateTime($row['effective_date']) : null;
            $start_date = isset($row['start_date']) ? createSafeDateTime($row['start_date']) : null;
            $end_date = isset($row['end_date']) ? createSafeDateTime($row['end_date']) : null;
            if ($date_to_obj && $effective_date && $effective_date > $date_to_obj) {
                continue;
            }
            switch ($row['frequency']) {
                case 'one_time':
                    if ($start_date && $end_date) {
                        if (empty($date_from) && empty($date_to)) {
                            if ($start_date <= $current_date && $end_date >= $current_date) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "One-time (active " . $start_date->format('Y-m-d') . " to " . $end_date->format('Y-m-d') . ")";
                            }
                        } elseif ($date_from_obj && $date_to_obj) {
                            if ($start_date <= $date_to_obj && $end_date >= $date_from_obj) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "One-time (active " . $start_date->format('Y-m-d') . " to " . $end_date->format('Y-m-d') . ")";
                            }
                        }
                    } elseif ($effective_date) {
                        if (empty($date_from) && empty($date_to)) {
                            if ($effective_date <= $current_date) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                            }
                        } elseif ($date_from_obj && $date_to_obj && $effective_date >= $date_from_obj && $effective_date <= $date_to_obj) {
                            $deduction_amount = $row['amount'];
                            $deduction_description = "One-time on " . $effective_date->format('Y-m-d');
                        }
                    }
                    break;
                case 'monthly':
                    if (empty($date_from) || empty($date_to)) {
                        if ((!$start_date || $start_date <= $current_date) && (!$end_date || $end_date >= $current_date)) {
                            $current_day = (int)$current_date->format('d');
                            if ($current_day >= 16) {
                                $deduction_amount = $row['amount'];
                                $deduction_description = "Monthly (current month - end of month)";
                            }
                        }
                    } else {
                        $periods = countMonthlyPeriods(
                            $date_from, $date_to, 
                            $effective_date ? $effective_date->format('Y-m-d') : null,
                            $start_date ? $start_date->format('Y-m-d') : null,
                            $end_date ? $end_date->format('Y-m-d') : null
                        );
                        $deduction_amount = $row['amount'] * $periods;
                        $deduction_description = "Monthly (end of month) × " . $periods . " months";
                    }
                    break;
                case 'semi_monthly':
                    if (empty($date_from) || empty($date_to)) {
                        if ((!$start_date || $start_date <= $current_date) && (!$end_date || $end_date >= $current_date)) {
                            $deduction_amount = $row['amount'] * 2;
                            $deduction_description = "Semi-monthly × 2 periods (current month)";
                        }
                    } else {
                        $periods = countSemiMonthlyPeriods(
                            $date_from, $date_to, 
                            $effective_date ? $effective_date->format('Y-m-d') : null,
                            $start_date ? $start_date->format('Y-m-d') : null,
                            $end_date ? $end_date->format('Y-m-d') : null
                        );
                        $deduction_amount = $row['amount'] * $periods;
                        $deduction_description = "Semi-monthly × " . $periods . " periods";
                    }
                    break;
                default:
                    $deduction_amount = $row['amount'];
                    $deduction_description = $row['frequency'];
            }
            if ($deduction_amount > 0) {
                $total_cash_advance += $deduction_amount;
                $cash_advance_details[] = ['amount' => $deduction_amount, 'description' => $deduction_description];
            }
        }
        $stmt->close();
    }
    return ['total' => $total_cash_advance, 'details' => $cash_advance_details];
}

// Employee allowances
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
        $current_date = new DateTime();

        $effective_date_from = !empty($date_from) ? $date_from_obj : $current_date;
        $effective_date_to = !empty($date_to) ? $date_to_obj : $current_date;

        if (!$effective_date_from || !$effective_date_to) {
            return ['totals' => $allowances, 'details' => $allowance_details];
        }

        while ($row = $result->fetch_assoc()) {
            $effective_date = isset($row['effective_date']) ? createSafeDateTime($row['effective_date']) : null;
            $end_date = isset($row['end_date']) ? createSafeDateTime($row['end_date']) : null;

            if ($effective_date && $effective_date > $effective_date_to) {
                continue;
            }
            if ($end_date && $end_date < $effective_date_from) {
                continue;
            }

            $periods = countMonthlyPeriods(
                $date_from,
                $date_to,
                $effective_date ? $effective_date->format('Y-m-d') : null,
                null,
                $end_date ? $end_date->format('Y-m-d') : null
            );
            
            if (empty($date_from) || empty($date_to)) {
                $periods = 1;
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

// Get salary for a specific date
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

/**
 * Calculate late deduction.
 * Rule: 1 peso per minute.
 */
function calculateLateDeduction($daily_salary, $late_minutes) {
    if ($late_minutes <= 0) {
        return 0;
    }
    return round($late_minutes * 1.00, 2);
}

// Format date for display
function formatDateForDisplay($date_string) {
    $date_obj = createSafeDateTime($date_string);
    if ($date_obj) {
        return $date_obj->format('F d, Y');
    }
    return $date_string;
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
    $employee_payslips = [];

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
    if (!empty($employee_filter)) {
        $sql .= " AND user_id = ?";
        $params[] = $employee_filter;
        $types .= "s";
    }

    $sql .= " ORDER BY date ASC, user_id";
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    // Determine date range
    if (empty($filter_date_from) || empty($filter_date_to)) {
        $date_range_sql = "SELECT MIN(date) as min_date, MAX(date) as max_date FROM attendance WHERE 1=1";
        $date_params = [];
        $date_types = "";
        if (!empty($filter_branch)) {
            $date_range_sql .= " AND department = ?";
            $date_params[] = $filter_branch;
            $date_types .= "s";
        } elseif (!empty($available_branches) && strtoupper($user_branch) !== 'ALL BRANCHES') {
            $placeholders = implode(',', array_fill(0, count($available_branches), '?'));
            $date_range_sql .= " AND department IN ($placeholders)";
            foreach ($available_branches as $branch) {
                $date_params[] = $branch;
                $date_types .= "s";
            }
        }
        if (!empty($employee_filter)) {
            $date_range_sql .= " AND user_id = ?";
            $date_params[] = $employee_filter;
            $date_types .= "s";
        }
        $date_stmt = $conn->prepare($date_range_sql);
        if (!empty($date_params)) {
            $date_stmt->bind_param($date_types, ...$date_params);
        }
        $date_stmt->execute();
        $date_result = $date_stmt->get_result();
        if ($date_result && $date_result->num_rows > 0) {
            $date_range = $date_result->fetch_assoc();
            if ($date_range['min_date'] && $date_range['max_date']) {
                $filter_date_from = $date_range['min_date'];
                $filter_date_to = $date_range['max_date'];
            }
        }
        $date_stmt->close();
    }

    $total_working_days = calculateWorkingDays($filter_date_from, $filter_date_to);
    $all_working_days = getWorkingDays($filter_date_from, $filter_date_to);
    if (empty($all_working_days)) {
        $total_working_days = 0;
    }

    // Get unique employees
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

    if (empty($employees_with_records)) {
        throw new Exception("No attendance records found for the selected criteria.");
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

    // Process each employee for payslip generation
    $counter = 0;
    foreach ($employees_with_records as $user_id => $employee_info) {
        $counter++;

        $payslip_data = [
            '#' => $counter,
            'employee_number' => $user_id,
            'employee_name' => strtoupper($employee_info['name']),
            'branch' => strtoupper($employee_info['department']),
            'working_days' => $total_working_days,
            'days_present' => 0,
            'days_halfday' => 0,
            'days_absent' => 0,
            'late_minutes' => 0,
            'late_deduction' => 0,
            'sss' => 0,
            'pagibig' => 0,
            'philhealth' => 0,
            'loan_payment' => 0,
            'cash_advance' => 0,
            'sss_loan' => 0,
            'pagibig_loan' => 0,
            'total_salary' => 0,
            'total_allowances' => 0,
            'gross_salary' => 0,
            'net_pay' => 0,
            'salary_details' => [],
            'salary_history' => [],
            'late_details' => [],
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
            'allowance_details' => [],
            'daily_rate' => '0.00'
        ];

        // Get account info and salary history
        $account_info = getAccountIdByEmployeeNumber($conn, $user_id);
        if ($account_info) {
            $salary_history = getEmployeeSalaryHistory(
                $conn,
                $account_info['account_id'],
                $account_info['source_table'],
                $filter_date_from,
                $filter_date_to
            );
            $payslip_data['salary_history'] = $salary_history;

            $sss_result = getEmployeeSSSDeductions($conn, $account_info['account_id'], $account_info['employee_type'], $filter_date_from, $filter_date_to);
            $payslip_data['sss'] = $sss_result['total'];
            $payslip_data['sss_details'] = $sss_result['details'];

            $pagibig_result = getEmployeePagIbigDeductions($conn, $account_info['account_id'], $account_info['employee_type'], $filter_date_from, $filter_date_to);
            $payslip_data['pagibig'] = $pagibig_result['total'];
            $payslip_data['pagibig_details'] = $pagibig_result['details'];

            $philhealth_result = getEmployeePhilHealthDeductions($conn, $account_info['account_id'], $account_info['employee_type'], $filter_date_from, $filter_date_to);
            $payslip_data['philhealth'] = $philhealth_result['total'];
            $payslip_data['philhealth_details'] = $philhealth_result['details'];

            $loan_result = getEmployeeLoanPayments($conn, $account_info['account_id'], $account_info['employee_type'], $filter_date_from, $filter_date_to);
            $payslip_data['loan_payment'] = $loan_result['total'];
            $payslip_data['loan_details'] = $loan_result['details'];

            $cash_advance_result = getEmployeeCashAdvances($conn, $account_info['account_id'], $account_info['employee_type'], $filter_date_from, $filter_date_to);
            $payslip_data['cash_advance'] = $cash_advance_result['total'];
            $payslip_data['cash_advance_details'] = $cash_advance_result['details'];

            $sss_loan_result = getEmployeeSSSLoanDeductions($conn, $account_info['account_id'], $account_info['employee_type'], $filter_date_from, $filter_date_to);
            $payslip_data['sss_loan'] = $sss_loan_result['total'];
            $payslip_data['sss_loan_details'] = $sss_loan_result['details'];

            $pagibig_loan_result = getEmployeePagIbigLoanDeductions($conn, $account_info['account_id'], $account_info['employee_type'], $filter_date_from, $filter_date_to);
            $payslip_data['pagibig_loan'] = $pagibig_loan_result['total'];
            $payslip_data['pagibig_loan_details'] = $pagibig_loan_result['details'];

            $allowance_result = getEmployeeAllowances($conn, $account_info['account_id'], $account_info['employee_type'], $filter_date_from, $filter_date_to);
            $payslip_data['allowances'] = $allowance_result['totals'];
            $payslip_data['allowance_details'] = $allowance_result['details'];
        }

        // Calculate days, late minutes, salary
        $days_present = 0;
        $days_halfday = 0;
        $total_late_minutes = 0;
        $total_late_deduction = 0;
        $total_salary = 0;

        if (!empty($all_working_days)) {
            foreach ($all_working_days as $working_date) {
                if (isset($attendance_lookup[$user_id][$working_date])) {
                    $record = $attendance_lookup[$user_id][$working_date];

                    // Compute the effective status dynamically (considers 12:00–13:00 halfday window)
                    $hasTimeIn = isset($record['before_noon_in']) && !is_null($record['before_noon_in']) && $record['before_noon_in'] !== '';
                    $hasTimeOut = isset($record['after_noon_out']) && !is_null($record['after_noon_out']) && $record['after_noon_out'] !== '';
                    $computed_status = determineAttendanceStatus(
                        $hasTimeIn,
                        $hasTimeOut,
                        $record['before_noon_in'] ?? null,
                        $record['after_noon_out'] ?? null
                    );

                    // Late deduction applies on any day with a time in
                    if ($account_info && isset($record['late_minutes']) && $record['late_minutes'] > 0) {
                        $daily_salary_for_late = getSalaryForDate($salary_history, $working_date);
                        $late_minutes = (int)$record['late_minutes'];
                        $late_deduction = calculateLateDeduction($daily_salary_for_late, $late_minutes);
                        $total_late_minutes += $late_minutes;
                        $total_late_deduction += $late_deduction;
                        $payslip_data['late_details'][] = [
                            'date' => $working_date,
                            'minutes' => $late_minutes,
                            'deduction' => $late_deduction
                        ];
                    }

                    // Salary contribution based on computed status
                    if ($computed_status === 'Present') {
                        $days_present++;
                        if ($account_info) {
                            $daily_salary = getSalaryForDate($salary_history, $working_date);
                            $total_salary += $daily_salary;
                        }
                    } elseif ($computed_status === 'Halfday') {
                        $days_halfday++;
                        if ($account_info) {
                            $daily_salary = getSalaryForDate($salary_history, $working_date);
                            // Half day = 0.5 x daily salary
                            $total_salary += ($daily_salary * 0.5);
                        }
                    }
                    // Absent contributes 0
                }
                // No record at all => absent
            }
        }

        // Absent = working days - present - halfday
        $days_absent = $total_working_days - $days_present - $days_halfday;
        if ($days_absent < 0) $days_absent = 0;

        $payslip_data['days_present'] = $days_present;
        $payslip_data['days_halfday'] = $days_halfday;
        $payslip_data['days_absent'] = $days_absent;
        $payslip_data['late_minutes'] = $total_late_minutes;
        $payslip_data['late_deduction'] = $total_late_deduction;
        $payslip_data['total_salary'] = $total_salary;

        // Total allowances
        $total_allowances = $payslip_data['allowances']['Rice Allowance'] +
                           $payslip_data['allowances']['COLA'] +
                           $payslip_data['allowances']['Motor Rental'] +
                           $payslip_data['allowances']['Incentive'];
        $payslip_data['total_allowances'] = $total_allowances;

        // Gross Salary = Basic Salary + Allowances
        $payslip_data['gross_salary'] = $total_salary + $total_allowances;

        // Net Pay
        $payslip_data['net_pay'] = $payslip_data['gross_salary']
            - $total_late_deduction
            - $payslip_data['sss']
            - $payslip_data['pagibig']
            - $payslip_data['philhealth']
            - $payslip_data['loan_payment']
            - $payslip_data['cash_advance']
            - $payslip_data['sss_loan']
            - $payslip_data['pagibig_loan'];

        // Effective days = present + (halfday * 0.5)
        $effective_days = $days_present + ($days_halfday * 0.5);
        if ($effective_days > 0) {
            $payslip_data['daily_rate'] = number_format($total_salary / $effective_days, 2);
        }

        $employee_payslips[] = $payslip_data;
    }

    // Date range text
    $date_range_text = 'ALL RECORDS';
    $branch_text = '';
    if (!empty($filter_branch)) {
        $branch_text = ' - BRANCH: ' . strtoupper($filter_branch);
    } elseif (!empty($available_branches) && strtoupper($user_branch) !== 'ALL BRANCHES') {
        $branch_text = ' - ALL MY BRANCHES';
    }
    if (!empty($filter_date_from) && !empty($filter_date_to)) {
        $date_range_text = strtoupper(formatDateForDisplay($filter_date_from) . ' - ' . formatDateForDisplay($filter_date_to)) . $branch_text;
    } elseif (!empty($filter_date_from)) {
        $date_range_text = 'FROM ' . strtoupper(formatDateForDisplay($filter_date_from)) . $branch_text;
    } elseif (!empty($filter_date_to)) {
        $date_range_text = 'UNTIL ' . strtoupper(formatDateForDisplay($filter_date_to)) . $branch_text;
    } elseif (!empty($filter_branch)) {
        $date_range_text = 'ALL RECORDS - BRANCH: ' . strtoupper($filter_branch);
    } elseif (!empty($available_branches) && strtoupper($user_branch) !== 'ALL BRANCHES') {
        $date_range_text = 'ALL RECORDS - ALL MY BRANCHES';
    }

} catch (Exception $e) {
    error_log($e->getMessage());
    die("Error generating payslips: " . $e->getMessage());
}

// Build HTML
$html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Payslips - Prime City</title>
    <style>
        @page {
            size: legal portrait;
            margin-left: 12px;
            margin-top: 12px;
            margin-bottom: -20px;
        }
        body {
            font-family: "Courier New", Courier, monospace;
            font-size: 8.5pt;
            line-height: 1.2;
            width: 100%;
            background: white;
        }
        .page {
            page-break-after: always;
            width: 100%;
        }
        .page:last-child {
            page-break-after: auto;
        }
        .payslip-row {
            width: 100%;
            margin-bottom: 5px;
        }
        .payslip {
            width: 48%;
            float: left;
            border: 2px solid #000;
            padding: 10px 8px;
            background-color: #fff;
            min-height: 420px;
        }
        .payslip-left { margin-right: 1%; }
        .payslip-right { margin-left: 1%; }
        .clearfix:after {
            content: "";
            display: table;
            clear: both;
        }
        .payslip-header {
            text-align: left;
            margin-bottom: 5px;
            font-weight: bold;
            font-size: 10pt;
        }
        .payslip-period {
            text-align: left;
            font-size: 8pt;
            font-weight: bold;
            margin-bottom: 8px;
            border-bottom: 1px solid #000;
            padding-bottom: 10px;
        }
        .attendance-line {
            display: flex;
            justify-content: space-between;
            font-size: 9pt;
            margin-bottom: 10px;
            border-bottom: 1px solid #000;
            padding-bottom: 3px;
        }
        .earnings-title {
            font-weight: bold;
            font-size: 9pt;
            margin-top: 5px;
            margin-bottom: 10px;
        }
        .earnings-row {
            display: flex;
            justify-content: space-between;
            font-size: 9pt;
            margin-bottom: 10px;
            padding-left: 10px;
        }
        .deductions-other-wrapper {
            display: flex;
            width: 100%;
            margin-top: 10px;
        }
        .deductions-column { width: 50%; padding-right: 5px; }
        .other-earnings-column { width: 50%; padding-left: 5px; }
        .deductions-title {
            font-weight: bold;
            font-size: 9pt;
            margin-bottom: 8px;
        }
        .other-earnings-title {
            font-weight: bold;
            font-size: 9pt;
            margin-bottom: 8px;
            color: #006600;
        }
        .deductions-row {
            display: flex;
            justify-content: space-between;
            font-size: 8.5pt;
            margin-bottom: 8px;
            padding-left: 10px;
        }
        .other-earnings-row {
            font-size: 8.5pt;
            margin-bottom: 8px;
            padding-left: 10px;
            color: #006600;
            white-space: nowrap;
        }
        .total-deductions-row {
            display: flex;
            justify-content: space-between;
            font-weight: bold;
            font-size: 9pt;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 3px 0;
            margin: 10px 0;
        }
        .total-other-earnings-row {
            display: flex;
            justify-content: space-between;
            font-weight: bold;
            font-size: 9pt;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 3px 0;
            margin: 10px 0;
            color: #006600;
        }
        .total-earnings-row {
            display: flex;
            justify-content: space-between;
            font-weight: bold;
            font-size: 9pt;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 3px 0;
            margin: 10px 0;
            color: #000000;
        }
        .net-pay-row {
            display: flex;
            justify-content: space-between;
            font-weight: bold;
            font-size: 11pt;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            padding: 4px 0;
            margin: 5px 0;
        }
        .footer-line {
            display: flex;
            justify-content: space-between;
            font-size: 8pt;
            margin-top: 8px;
        }
        .footer-line span:first-child { max-width: 60%; }
        .signature-line {
            display: inline-block;
            width: 120px;
            border-bottom: 1px solid #000;
            margin-left: 5px;
        }
        .tooltip {
            position: relative;
            display: inline-block;
            cursor: help;
            color: red;
        }
        .tooltip .tooltiptext {
            visibility: hidden;
            width: 180px;
            background-color: #555;
            color: #fff;
            text-align: center;
            border-radius: 3px;
            padding: 3px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -90px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 7pt;
            white-space: normal;
            pointer-events: none;
        }
        .tooltip:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
        }
        .deduction-label { color: red; }
        .date-label {
            text-align: right;
            float: right;
            margin-left: auto;
        }
        .small-text { font-size: 7.5pt; }
    </style>
</head>
<body>';

// Build payslips - 2 per row
$payslip_count = count($employee_payslips);

for ($i = 0; $i < $payslip_count; $i += 2) {
    if ($i > 0 && $i % 4 == 0) {
        $html .= '<div class="page-break" style="page-break-before: always;"></div>';
    }
    $html .= '<div class="payslip-row clearfix">';

    // Left payslip
    if (isset($employee_payslips[$i])) {
        $p = $employee_payslips[$i];
        $name_parts = explode(',', $p['employee_name']);
        $last_name = trim($name_parts[0]);
        $first_name = isset($name_parts[1]) ? trim($name_parts[1]) : '';
        $formatted_name = $last_name . ', ' . $first_name;

        $late_tooltip = '';
        if (!empty($p['late_details'])) {
            $late_items = [];
            foreach ($p['late_details'] as $late) {
                $late_items[] = date('m/d/Y', strtotime($late['date'])) . ': ' . $late['minutes'] . ' mins (' . number_format($late['deduction'], 2) . ')';
            }
            $late_tooltip = htmlspecialchars(implode(' | ', $late_items));
        }
        $sss_tooltip = '';
        if (!empty($p['sss_details'])) {
            $sss_items = [];
            foreach ($p['sss_details'] as $sss) {
                $sss_items[] = number_format($sss['amount'], 2) . ' (' . $sss['description'] . ')';
            }
            $sss_tooltip = htmlspecialchars(implode(' | ', $sss_items));
        }
        $pagibig_tooltip = '';
        if (!empty($p['pagibig_details'])) {
            $pagibig_items = [];
            foreach ($p['pagibig_details'] as $pagibig) {
                $pagibig_items[] = number_format($pagibig['amount'], 2) . ' (' . $pagibig['description'] . ')';
            }
            $pagibig_tooltip = htmlspecialchars(implode(' | ', $pagibig_items));
        }
        $philhealth_tooltip = '';
        if (!empty($p['philhealth_details'])) {
            $philhealth_items = [];
            foreach ($p['philhealth_details'] as $philhealth) {
                $philhealth_items[] = number_format($philhealth['amount'], 2) . ' (' . $philhealth['description'] . ')';
            }
            $philhealth_tooltip = htmlspecialchars(implode(' | ', $philhealth_items));
        }
        $loan_tooltip = '';
        if (!empty($p['loan_details'])) {
            $loan_items = [];
            foreach ($p['loan_details'] as $loan) {
                $loan_items[] = number_format($loan['amount'], 2) . ' (' . $loan['description'] . ')';
            }
            $loan_tooltip = htmlspecialchars(implode(' | ', $loan_items));
        }
        $cash_advance_tooltip = '';
        if (!empty($p['cash_advance_details'])) {
            $cash_items = [];
            foreach ($p['cash_advance_details'] as $cash) {
                $cash_items[] = number_format($cash['amount'], 2) . ' (' . $cash['description'] . ')';
            }
            $cash_advance_tooltip = htmlspecialchars(implode(' | ', $cash_items));
        }
        $sss_loan_tooltip = '';
        if (!empty($p['sss_loan_details'])) {
            $sss_loan_items = [];
            foreach ($p['sss_loan_details'] as $sss_loan) {
                $sss_loan_items[] = number_format($sss_loan['amount'], 2) . ' (' . $sss_loan['description'] . ')';
            }
            $sss_loan_tooltip = htmlspecialchars(implode(' | ', $sss_loan_items));
        }
        $pagibig_loan_tooltip = '';
        if (!empty($p['pagibig_loan_details'])) {
            $pagibig_loan_items = [];
            foreach ($p['pagibig_loan_details'] as $pagibig_loan) {
                $pagibig_loan_items[] = number_format($pagibig_loan['amount'], 2) . ' (' . $pagibig_loan['description'] . ')';
            }
            $pagibig_loan_tooltip = htmlspecialchars(implode(' | ', $pagibig_loan_items));
        }

        $total_deductions = $p['late_deduction']
            + $p['sss']
            + $p['pagibig']
            + $p['philhealth']
            + $p['loan_payment']
            + $p['cash_advance']
            + $p['sss_loan']
            + $p['pagibig_loan'];

        $html .= '
        <div class="payslip payslip-left">
            <div class="payslip-header">' . htmlspecialchars($formatted_name) . '</div>
            <div class="payslip-period">' . $date_range_text . '</div>
            <div class="attendance-line">
                <strong>DAYS: ' . $p['days_present'] . '/' . $p['working_days'] . ' </strong>';
        if ($p['late_minutes'] > 0) {
            $html .= '<span class="tooltip" title="' . $late_tooltip . '">LATE: ' . $p['late_minutes'] . ' MIN</span>';
        } else {
            $html .= '<span class="tooltip" title="' . $late_tooltip . '">LATE: 0 MIN</span>';
        }
        $html .= '<strong class="date-label">BRANCH: ' . htmlspecialchars($p['branch']) . '</strong>
            </div>
            <div class="earnings-title">EARNINGS</div>
            <div class="earnings-row">
                <span>- BASIC (' . $p['days_present'] . ' DAYS' . ($p['days_halfday'] > 0 ? ' + ' . $p['days_halfday'] . ' HALF' : '') . ')</span>
                <span>- ' . number_format($p['total_salary'], 2) . '</span>
            </div>
            <div class="deductions-other-wrapper">
                <div class="deductions-column">
                    <div class="deductions-title">DEDUCTIONS</div>
                    <div class="deductions-row">
                        <span class="deduction-label">- LATE</span>
                        <span>(' . number_format($p['late_deduction'], 2) . ')</span>
                    </div>
                    <div class="deductions-row">
                        <span class="tooltip deduction-label" title="' . $sss_tooltip . '">- SSS</span>
                        <span>(' . number_format($p['sss'], 2) . ')</span>
                    </div>
                    <div class="deductions-row">
                        <span class="tooltip deduction-label" title="' . $pagibig_tooltip . '">- PAG-IBIG</span>
                        <span>(' . number_format($p['pagibig'], 2) . ')</span>
                    </div>
                    <div class="deductions-row">
                        <span class="tooltip deduction-label" title="' . $philhealth_tooltip . '">- PHILHEALTH</span>
                        <span>(' . number_format($p['philhealth'], 2) . ')</span>
                    </div>
                    <div class="deductions-row">
                        <span class="tooltip deduction-label" title="' . $loan_tooltip . '">- LOAN</span>
                        <span>(' . number_format($p['loan_payment'], 2) . ')</span>
                    </div>
                    <div class="deductions-row">
                        <span class="tooltip deduction-label" title="' . $cash_advance_tooltip . '">- CASH ADV</span>
                        <span>(' . number_format($p['cash_advance'], 2) . ')</span>
                    </div>
                    <div class="deductions-row">
                        <span class="tooltip deduction-label" title="' . $sss_loan_tooltip . '">- SSS LOAN</span>
                        <span>(' . number_format($p['sss_loan'], 2) . ')</span>
                    </div>
                    <div class="deductions-row">
                        <span class="tooltip deduction-label" title="' . $pagibig_loan_tooltip . '">- PAG-IBIG LOAN</span>
                        <span>(' . number_format($p['pagibig_loan'], 2) . ')</span>
                    </div>
                </div>
                <div class="other-earnings-column">
                    <div class="other-earnings-title">OTHER EARNINGS</div>
                    <div class="other-earnings-row">- RICE ALLOWANCE (' . number_format($p['allowances']['Rice Allowance'], 2) . ')</div>
                    <div class="other-earnings-row">- COLA (' . number_format($p['allowances']['COLA'], 2) . ')</div>
                    <div class="other-earnings-row">- MOTOR RENTAL (' . number_format($p['allowances']['Motor Rental'], 2) . ')</div>
                    <div class="other-earnings-row">- INCENTIVE (' . number_format($p['allowances']['Incentive'], 2) . ')</div>
                </div>
            </div>
            <div class="total-deductions-row">
                <span>TOTAL DEDUCTIONS</span>
                <span class="deduction-label">(' . number_format($total_deductions, 2) . ')</span>
            </div>
            <div class="total-other-earnings-row">
                <span>TOTAL OTHER EARNINGS</span>
                <span>' . number_format($p['total_allowances'], 2) . '</span>
            </div>
            <div class="total-earnings-row">
                <span>TOTAL EARNINGS</span>
                <span>' . number_format($p['total_salary'], 2) . '</span>
            </div>
            <div class="net-pay-row">
                <span>NET PAY</span>
                <span>= ' . number_format($p['net_pay'], 2) . '</span>
            </div>
            <div class="footer-line">
                <span>RATE: ' . $p['daily_rate'] . '</span>
                <span class="date-label">DATE: ' . date('m/d/Y') . '</span>
            </div>
        </div>';
    } else {
        $html .= '<div class="payslip payslip-left" style="border: 2px dashed #ccc; background-color: #f9f9f9;"></div>';
    }

    // Right payslip
    if (isset($employee_payslips[$i + 1])) {
        $p = $employee_payslips[$i + 1];
        $name_parts = explode(',', $p['employee_name']);
        $last_name = trim($name_parts[0]);
        $first_name = isset($name_parts[1]) ? trim($name_parts[1]) : '';
        $formatted_name = $last_name . ', ' . $first_name;

        $late_tooltip = '';
        if (!empty($p['late_details'])) {
            $late_items = [];
            foreach ($p['late_details'] as $late) {
                $late_items[] = date('m/d/Y', strtotime($late['date'])) . ': ' . $late['minutes'] . ' mins (' . number_format($late['deduction'], 2) . ')';
            }
            $late_tooltip = htmlspecialchars(implode(' | ', $late_items));
        }
        $sss_tooltip = '';
        if (!empty($p['sss_details'])) {
            $sss_items = [];
            foreach ($p['sss_details'] as $sss) {
                $sss_items[] = number_format($sss['amount'], 2) . ' (' . $sss['description'] . ')';
            }
            $sss_tooltip = htmlspecialchars(implode(' | ', $sss_items));
        }
        $pagibig_tooltip = '';
        if (!empty($p['pagibig_details'])) {
            $pagibig_items = [];
            foreach ($p['pagibig_details'] as $pagibig) {
                $pagibig_items[] = number_format($pagibig['amount'], 2) . ' (' . $pagibig['description'] . ')';
            }
            $pagibig_tooltip = htmlspecialchars(implode(' | ', $pagibig_items));
        }
        $philhealth_tooltip = '';
        if (!empty($p['philhealth_details'])) {
            $philhealth_items = [];
            foreach ($p['philhealth_details'] as $philhealth) {
                $philhealth_items[] = number_format($philhealth['amount'], 2) . ' (' . $philhealth['description'] . ')';
            }
            $philhealth_tooltip = htmlspecialchars(implode(' | ', $philhealth_items));
        }
        $loan_tooltip = '';
        if (!empty($p['loan_details'])) {
            $loan_items = [];
            foreach ($p['loan_details'] as $loan) {
                $loan_items[] = number_format($loan['amount'], 2) . ' (' . $loan['description'] . ')';
            }
            $loan_tooltip = htmlspecialchars(implode(' | ', $loan_items));
        }
        $cash_advance_tooltip = '';
        if (!empty($p['cash_advance_details'])) {
            $cash_items = [];
            foreach ($p['cash_advance_details'] as $cash) {
                $cash_items[] = number_format($cash['amount'], 2) . ' (' . $cash['description'] . ')';
            }
            $cash_advance_tooltip = htmlspecialchars(implode(' | ', $cash_items));
        }
        $sss_loan_tooltip = '';
        if (!empty($p['sss_loan_details'])) {
            $sss_loan_items = [];
            foreach ($p['sss_loan_details'] as $sss_loan) {
                $sss_loan_items[] = number_format($sss_loan['amount'], 2) . ' (' . $sss_loan['description'] . ')';
            }
            $sss_loan_tooltip = htmlspecialchars(implode(' | ', $sss_loan_items));
        }
        $pagibig_loan_tooltip = '';
        if (!empty($p['pagibig_loan_details'])) {
            $pagibig_loan_items = [];
            foreach ($p['pagibig_loan_details'] as $pagibig_loan) {
                $pagibig_loan_items[] = number_format($pagibig_loan['amount'], 2) . ' (' . $pagibig_loan['description'] . ')';
            }
            $pagibig_loan_tooltip = htmlspecialchars(implode(' | ', $pagibig_loan_items));
        }

        $total_deductions = $p['late_deduction']
            + $p['sss']
            + $p['pagibig']
            + $p['philhealth']
            + $p['loan_payment']
            + $p['cash_advance']
            + $p['sss_loan']
            + $p['pagibig_loan'];

        $html .= '
        <div class="payslip payslip-right">
            <div class="payslip-header">' . htmlspecialchars($formatted_name) . '</div>
            <div class="payslip-period">' . $date_range_text . '</div>
            <div class="attendance-line">
                <strong>DAYS: ' . $p['days_present'] . '/' . $p['working_days'] . ' </strong>';
        if ($p['late_minutes'] > 0) {
            $html .= '<span class="tooltip" title="' . $late_tooltip . '">LATE: ' . $p['late_minutes'] . ' MIN</span>';
        } else {
            $html .= '<span class="tooltip" title="' . $late_tooltip . '">LATE: 0 MIN</span>';
        }
        $html .= '<strong class="date-label">BRANCH:' . htmlspecialchars($p['branch']) . '</strong>
            </div>
            <div class="earnings-title">EARNINGS</div>
            <div class="earnings-row">
                <span>- BASIC (' . $p['days_present'] . ' DAYS' . ($p['days_halfday'] > 0 ? ' + ' . $p['days_halfday'] . ' HALF' : '') . ')</span>
                <span>- ' . number_format($p['total_salary'], 2) . '</span>
            </div>
            <div class="deductions-other-wrapper">
                <div class="deductions-column">
                    <div class="deductions-title">DEDUCTIONS</div>
                    <div class="deductions-row">
                        <span class="deduction-label">- LATE</span>
                        <span>(' . number_format($p['late_deduction'], 2) . ')</span>
                    </div>
                    <div class="deductions-row">
                        <span class="tooltip deduction-label" title="' . $sss_tooltip . '">- SSS</span>
                        <span>(' . number_format($p['sss'], 2) . ')</span>
                    </div>
                    <div class="deductions-row">
                        <span class="tooltip deduction-label" title="' . $pagibig_tooltip . '">- PAG-IBIG</span>
                        <span>(' . number_format($p['pagibig'], 2) . ')</span>
                    </div>
                    <div class="deductions-row">
                        <span class="tooltip deduction-label" title="' . $philhealth_tooltip . '">- PHILHEALTH</span>
                        <span>(' . number_format($p['philhealth'], 2) . ')</span>
                    </div>
                    <div class="deductions-row">
                        <span class="tooltip deduction-label" title="' . $loan_tooltip . '">- LOAN</span>
                        <span>(' . number_format($p['loan_payment'], 2) . ')</span>
                    </div>
                    <div class="deductions-row">
                        <span class="tooltip deduction-label" title="' . $cash_advance_tooltip . '">- CASH ADV</span>
                        <span>(' . number_format($p['cash_advance'], 2) . ')</span>
                    </div>
                    <div class="deductions-row">
                        <span class="tooltip deduction-label" title="' . $sss_loan_tooltip . '">- SSS LOAN</span>
                        <span>(' . number_format($p['sss_loan'], 2) . ')</span>
                    </div>
                    <div class="deductions-row">
                        <span class="tooltip deduction-label" title="' . $pagibig_loan_tooltip . '">- PAG-IBIG LOAN</span>
                        <span>(' . number_format($p['pagibig_loan'], 2) . ')</span>
                    </div>
                </div>
                <div class="other-earnings-column">
                    <div class="other-earnings-title">OTHER EARNINGS</div>
                    <div class="other-earnings-row">- RICE ALLOWANCE (' . number_format($p['allowances']['Rice Allowance'], 2) . ')</div>
                    <div class="other-earnings-row">- COLA (' . number_format($p['allowances']['COLA'], 2) . ')</div>
                    <div class="other-earnings-row">- MOTOR RENTAL (' . number_format($p['allowances']['Motor Rental'], 2) . ')</div>
                    <div class="other-earnings-row">- INCENTIVE (' . number_format($p['allowances']['Incentive'], 2) . ')</div>
                </div>
            </div>
            <div class="total-deductions-row">
                <span>TOTAL DEDUCTIONS</span>
                <span class="deduction-label">(' . number_format($total_deductions, 2) . ')</span>
            </div>
            <div class="total-other-earnings-row">
                <span>TOTAL OTHER EARNINGS</span>
                <span>' . number_format($p['total_allowances'], 2) . '</span>
            </div>
            <div class="total-earnings-row">
                <span>TOTAL EARNINGS</span>
                <span>' . number_format($p['total_salary'], 2) . '</span>
            </div>
            <div class="net-pay-row">
                <span>NET PAY</span>
                <span>= ' . number_format($p['net_pay'], 2) . '</span>
            </div>
            <div class="footer-line">
                <span>RATE: ' . $p['daily_rate'] . '</span>
                <span class="date-label">DATE: ' . date('m/d/Y') . '</span>
            </div>
        </div>';
    } else {
        $html .= '<div class="payslip payslip-right" style="border: 2px dashed #ccc; background-color: #f9f9f9;"></div>';
    }

    $html .= '</div>';
}

$html .= '
</body>
</html>';

// Create PDF options
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultPaperSize', 'legal');
$options->set('defaultPaperOrientation', 'portrait');
$options->set('fontDir', __DIR__ . '/fonts/');
$options->set('fontCache', __DIR__ . '/fonts/');
$options->set('chroot', __DIR__);
$options->set('dpi', 96);
$options->set('defaultFont', 'Courier');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('legal', 'portrait');
$dompdf->render();

// Filename
$filename = 'payslips_';
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
if (!empty($employee_filter)) {
    $filename .= '_employee_' . $employee_filter;
}
$filename .= '_' . date('Ymd_His') . '.pdf';

$dompdf->stream($filename, array('Attachment' => false));
exit;
?>
