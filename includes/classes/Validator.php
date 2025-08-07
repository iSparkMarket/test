<?php
declare(strict_types=1);

namespace RoleUserManager;

/**
 * Validator class for input validation
 */
class Validator {
    
    /**
     * Validate user ID
     */
    public static function validate_user_id($user_id): bool {
        return is_numeric($user_id) && $user_id > 0 && get_user_by('id', $user_id);
    }
    
    /**
     * Validate role name
     */
    public static function validate_role(string $role): bool {
        return !empty($role) && preg_match('/^[a-zA-Z0-9_-]+$/', $role);
    }
    
    /**
     * Validate capability name
     */
    public static function validate_capability(string $capability): bool {
        return !empty($capability) && preg_match('/^[a-zA-Z0-9_-]+$/', $capability);
    }
    
    /**
     * Validate email address
     */
    public static function validate_email(string $email): bool {
        return !empty($email) && is_email($email);
    }
    
    /**
     * Validate nonce
     */
    public static function validate_nonce(string $nonce, string $action): bool {
        return wp_verify_nonce($nonce, $action);
    }
    
    /**
     * Validate date format
     */
    public static function validate_date(string $date, string $format = 'Y-m-d'): bool {
        $d = \DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
    
    /**
     * Validate date range
     */
    public static function validate_date_range(string $start_date, string $end_date): bool {
        if (!self::validate_date($start_date) || !self::validate_date($end_date)) {
            return false;
        }
        
        $start = new \DateTime($start_date);
        $end = new \DateTime($end_date);
        
        return $start <= $end;
    }
    
    /**
     * Validate file upload
     */
    public static function validate_file_upload(array $file, array $allowed_types = ['csv']): array {
        $errors = [];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed';
            return $errors;
        }
        
        if ($file['size'] > wp_max_upload_size()) {
            $errors[] = 'File size exceeds maximum allowed size';
        }
        
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($file_extension, $allowed_types, true)) {
            $errors[] = 'File type not allowed';
        }
        
        return $errors;
    }
    
    /**
     * Sanitize and validate CSV data
     */
    public static function validate_csv_data(array $data): array {
        $errors = [];
        $required_fields = ['program', 'site'];
        
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        if (!empty($data['program']) && !preg_match('/^[a-zA-Z0-9\s_-]+$/', $data['program'])) {
            $errors[] = 'Invalid program name format';
        }
        
        if (!empty($data['site']) && !preg_match('/^[a-zA-Z0-9\s_-]+$/', $data['site'])) {
            $errors[] = 'Invalid site name format';
        }
        
        return $errors;
    }
    
    /**
     * Validate promotion request
     */
    public static function validate_promotion_request(int $requester_id, int $user_id, string $current_role, string $requested_role, string $reason): array {
        $errors = [];
        
        if (!self::validate_user_id($requester_id)) {
            $errors[] = 'Invalid requester ID';
        }
        
        if (!self::validate_user_id($user_id)) {
            $errors[] = 'Invalid user ID';
        }
        
        if (!self::validate_role($current_role)) {
            $errors[] = 'Invalid current role';
        }
        
        if (!self::validate_role($requested_role)) {
            $errors[] = 'Invalid requested role';
        }
        
        if (empty(trim($reason))) {
            $errors[] = 'Reason is required';
        }
        
        if (strlen($reason) > 1000) {
            $errors[] = 'Reason is too long (max 1000 characters)';
        }
        
        return $errors;
    }
    
    /**
     * Sanitize text input
     */
    public static function sanitize_text(string $text): string {
        return sanitize_text_field(trim($text));
    }
    
    /**
     * Sanitize email
     */
    public static function sanitize_email(string $email): string {
        return sanitize_email(trim($email));
    }
    
    /**
     * Sanitize role name
     */
    public static function sanitize_role(string $role): string {
        return sanitize_key(trim($role));
    }
    
    /**
     * Sanitize capability name
     */
    public static function sanitize_capability(string $capability): string {
        return sanitize_key(trim($capability));
    }
} 