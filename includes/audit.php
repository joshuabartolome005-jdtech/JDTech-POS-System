<?php
/**
 * Records an entry in the audit log. Call this after any action worth
 * tracking for accountability (product changes, user management, voids,
 * stock adjustments, shift closings, etc).
 */
function log_audit(PDO $pdo, ?int $userId, string $action, string $details = ''): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $action, $details]);
    } catch (Exception $e) {
        // Intentionally ignored - audit logging must never break the caller.
    }
}
