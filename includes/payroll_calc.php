<?php
/**
 * Computes one day's pay for an employee, following DOLE holiday pay
 * rules (Regular Holiday / Special Non-Working Day) plus a standard
 * regular-day and overtime calculation.
 *
 * $hoursWorked  - hours actually worked that day (break time excluded)
 * $worked       - whether they had a completed time in/out that day
 * $holidayType  - null | 'regular' | 'special'
 * $isRestDay    - whether this date is the employee's designated rest day
 * $dailyRate    - the employee's basic daily wage (e.g. 600)
 * $cola         - Cost of Living Allowance for the day (often 0)
 *
 * Returns ['basic' => float, 'ot' => float, 'label' => string]
 */
function computeDailyPay(
    float $hoursWorked,
    bool $worked,
    ?string $holidayType,
    bool $isRestDay,
    float $dailyRate,
    float $cola
): array {
    $hourlyRate = $dailyRate / 8;
    $regularHours = min($hoursWorked, 8);
    $otHours = max(0, $hoursWorked - 8);

    if ($holidayType === 'regular') {
        if (!$worked) {
            return [
                'basic' => ($dailyRate + $cola) * 1.00,
                'ot' => 0,
                'label' => 'Regular Holiday (No Work)'
            ];
        }
        if ($isRestDay) {
            $basic = ($dailyRate + $cola) * 2.00 + (0.30 * ($dailyRate * 2.00));
            $ot = $hourlyRate * 2.00 * 1.30 * 1.30 * $otHours;
            return ['basic' => $basic, 'ot' => $ot, 'label' => 'Regular Holiday + Rest Day'];
        }
        $basic = ($dailyRate + $cola) * 2.00;
        $ot = $hourlyRate * 2.00 * 1.30 * $otHours;
        return ['basic' => $basic, 'ot' => $ot, 'label' => 'Regular Holiday (Worked)'];
    }

    if ($holidayType === 'special') {
        if (!$worked) {
            return ['basic' => 0, 'ot' => 0, 'label' => 'Special Non-Working Day (No Work)'];
        }
        if ($isRestDay) {
            $basic = ($dailyRate * 1.50) + $cola;
            $ot = $hourlyRate * 1.50 * 1.30 * $otHours;
            return ['basic' => $basic, 'ot' => $ot, 'label' => 'Special Day + Rest Day'];
        }
        $basic = ($dailyRate * 1.30) + $cola;
        $ot = $hourlyRate * 1.30 * 1.30 * $otHours;
        return ['basic' => $basic, 'ot' => $ot, 'label' => 'Special Non-Working Day (Worked)'];
    }

    // Regular (non-holiday) day
    if (!$worked) {
        return ['basic' => 0, 'ot' => 0, 'label' => 'Absent'];
    }
    $basic = $hourlyRate * $regularHours;
    $ot = $hourlyRate * 1.25 * $otHours;
    return ['basic' => $basic, 'ot' => $ot, 'label' => $isRestDay ? 'Rest Day Worked' : 'Regular Day'];
}

/**
 * Builds the full daily breakdown + totals for one employee over a
 * date range. Shared by payroll.php and print_payslip.php so the
 * numbers can never drift between the two.
 */
function buildPayslip(PDO $pdo, array $employee, string $dateFrom, string $dateTo): array
{
    $employeeId = (int)$employee['id'];

    $holidaysMap = [];
    foreach ($pdo->query("SELECT holiday_date, type FROM holidays")->fetchAll() as $h) {
        $holidaysMap[$h['holiday_date']] = $h['type'];
    }

    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND DATE(time_in) BETWEEN ? AND ?");
    $stmt->execute([$employeeId, $dateFrom, $dateTo]);
    $attendanceMap = [];
    foreach ($stmt->fetchAll() as $a) {
        $attendanceMap[date('Y-m-d', strtotime($a['time_in']))] = $a;
    }

    $dailyBreakdown = [];
    $totalBasic = 0;
    $totalOt = 0;

    $cursor = strtotime($dateFrom);
    $end = strtotime($dateTo);
    while ($cursor <= $end) {
        $d = date('Y-m-d', $cursor);
        $dow = (int)date('w', $cursor);
        $isRestDay = ($employee['rest_day'] !== null && (int)$employee['rest_day'] === $dow);
        $holidayType = $holidaysMap[$d] ?? null;

        $worked = false;
        $hoursWorked = 0;
        $timeInStr = '-';
        $timeOutStr = '-';

        if (isset($attendanceMap[$d]) && $attendanceMap[$d]['time_out']) {
            $a = $attendanceMap[$d];
            $worked = true;
            $seconds = strtotime($a['time_out']) - strtotime($a['time_in']);
            if ($a['break_start'] && $a['break_end']) {
                $seconds -= (strtotime($a['break_end']) - strtotime($a['break_start']));
            }
            $hoursWorked = max(0, $seconds) / 3600;
            $timeInStr = date('h:i A', strtotime($a['time_in']));
            $timeOutStr = date('h:i A', strtotime($a['time_out']));
        }

        $pay = computeDailyPay($hoursWorked, $worked, $holidayType, $isRestDay, (float)$employee['daily_rate'], (float)$employee['cola_rate']);

        $dailyBreakdown[] = [
            'date' => $d,
            'day_name' => date('D', $cursor),
            'time_in' => $timeInStr,
            'time_out' => $timeOutStr,
            'hours' => $hoursWorked,
            'label' => $pay['label'],
            'basic' => $pay['basic'],
            'ot' => $pay['ot'],
        ];

        $totalBasic += $pay['basic'];
        $totalOt += $pay['ot'];

        $cursor = strtotime('+1 day', $cursor);
    }

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(deduction_amount),0) FROM violations WHERE user_id = ? AND violation_date BETWEEN ? AND ?");
    $stmt->execute([$employeeId, $dateFrom, $dateTo]);
    $totalDeductions = (float)$stmt->fetchColumn();

    // Semi-monthly cutoffs get half the monthly reward; a full-month
    // range (28+ days) gets the whole amount.
    $periodDays = (int)round((strtotime($dateTo) - strtotime($dateFrom)) / 86400) + 1;
    $rewardForPeriod = $periodDays >= 28 ? (float)$employee['monthly_reward'] : (float)$employee['monthly_reward'] / 2;
    $netReward = max(0, $rewardForPeriod - $totalDeductions);

    $stmt = $pdo->prepare("SELECT * FROM violations WHERE user_id = ? AND violation_date BETWEEN ? AND ? ORDER BY violation_date");
    $stmt->execute([$employeeId, $dateFrom, $dateTo]);
    $periodViolations = $stmt->fetchAll();

    return [
        'employee' => $employee,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'daily' => $dailyBreakdown,
        'total_basic' => $totalBasic,
        'total_ot' => $totalOt,
        'reward_gross' => $rewardForPeriod,
        'deductions' => $totalDeductions,
        'reward_net' => $netReward,
        'grand_total' => $totalBasic + $totalOt + $netReward,
        'violations' => $periodViolations,
    ];
}