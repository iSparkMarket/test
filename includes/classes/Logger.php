<?php
declare(strict_types=1);

namespace RoleUserManager;

/**
 * Logger class for audit logging
 */
class Logger {
    
    private const LOG_OPTION = 'rum_audit_log';
    private const MAX_LOG_ENTRIES = 1000;
    
    /**
     * Log an audit message
     */
    public static function log(string $message, array $context = []): void {
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'message' => sanitize_text_field($message),
            'context' => $context,
            'ip' => self::get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];
        
        $log = get_option(self::LOG_OPTION, []);
        $log[] = $log_entry;
        
        // Keep only the last MAX_LOG_ENTRIES entries
        if (count($log) > self::MAX_LOG_ENTRIES) {
            $log = array_slice($log, -self::MAX_LOG_ENTRIES);
        }
        
        update_option(self::LOG_OPTION, $log);
    }
    
    /**
     * Get audit log entries
     */
    public static function get_log(int $limit = 100): array {
        $log = get_option(self::LOG_OPTION, []);
        return array_slice($log, -$limit);
    }
    
    /**
     * Clear audit log
     */
    public static function clear_log(): void {
        delete_option(self::LOG_OPTION);
    }
    
    /**
     * Get client IP address
     */
    private static function get_client_ip(): string {
        $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
    
    /**
     * Log user role change
     */
    public static function log_role_change(int $user_id, string $old_role, string $new_role): void {
        $user = get_user_by('id', $user_id);
        $message = sprintf(
            'User role changed for %s (ID: %d) from "%s" to "%s"',
            $user ? $user->user_login : 'Unknown',
            $user_id,
            $old_role,
            $new_role
        );
        
        self::log($message, [
            'user_id' => $user_id,
            'old_role' => $old_role,
            'new_role' => $new_role,
        ]);
    }
    
    /**
     * Log capability change
     */
    public static function log_capability_change(string $role, array $old_caps, array $new_caps): void {
        $added = array_diff($new_caps, $old_caps);
        $removed = array_diff($old_caps, $new_caps);
        
        if (!empty($added) || !empty($removed)) {
            $message = sprintf(
                'Capabilities changed for role "%s"',
                $role
            );
            
            self::log($message, [
                'role' => $role,
                'added_capabilities' => $added,
                'removed_capabilities' => $removed,
            ]);
        }
    }
    
    /**
     * Log promotion request
     */
    public static function log_promotion_request(int $requester_id, int $user_id, string $current_role, string $requested_role, string $reason): void {
        $requester = get_user_by('id', $requester_id);
        $user = get_user_by('id', $user_id);
        
        $message = sprintf(
            'Promotion request: %s (ID: %d) requested promotion for %s (ID: %d) from "%s" to "%s"',
            $requester ? $requester->user_login : 'Unknown',
            $requester_id,
            $user ? $user->user_login : 'Unknown',
            $user_id,
            $current_role,
            $requested_role
        );
        
        self::log($message, [
            'requester_id' => $requester_id,
            'user_id' => $user_id,
            'current_role' => $current_role,
            'requested_role' => $requested_role,
            'reason' => $reason,
        ]);
    }
} 