<?php
/**
 * Determines whether a cashier is currently blocked from processing new
 * checkouts, and why. Returns null if they're clear to sell.
 */
function getShiftCheckoutBlockReason(PDO $pdo, int $userId): ?string
{
    // Kuhanin ang huling time stamp kung kailan huling nag-close ng shift ang cashier
    $stmt = $pdo->prepare("SELECT period_end FROM shift_closings WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $lastClosing = $stmt->fetchColumn();

    if ($lastClosing) {
        $periodStart = $lastClosing;
    } else {
        // Kung wala pang kahit anong shift closing record, kuhanin ang kauna-unahang sale
        $stmt = $pdo->prepare("SELECT MIN(created_at) FROM sales WHERE user_id = ? AND status = 'completed'");
        $stmt->execute([$userId]);
        $periodStart = $stmt->fetchColumn();
    }

    // Tiyakin kung may mga naunang benta o nakalipas na petsa na HINDI PA napapasara
    // Chine-check kung may 'completed' sales na NANGYARI BAGO NGAYONG ARAW at MAS BAGO pa sa huling shift closing
    if ($periodStart) {
        $stmtUnclosedSales = $pdo->prepare("
            SELECT id FROM sales 
            WHERE user_id = ? 
              AND status = 'completed' 
              AND created_at > ? 
              AND DATE(created_at) < CURDATE() 
            LIMIT 1
        ");
        $stmtUnclosedSales->execute([$userId, $periodStart]);
        
        if ($stmtUnclosedSales->fetch()) {
            return 'You have an unclosed shift from a previous day. Please close it in Shift Closing before making new transactions.';
        }
    }

    // Clear na at pwedeng mag-checkout ulit!
    return null;
}