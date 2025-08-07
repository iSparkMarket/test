<?php
/**
 * LearnDash Course Duration Implementation
 * Using Utilities::get_duration() method
 * 
 * @since 4.21.4
 * @package LearnDash / Core
 */

// Ensure LearnDash is active
if (!defined('LEARNDASH_VERSION')) {
    return;
}

/**
 * Get course duration using LearnDash Utilities class
 * 
 * @param int $course_id The course ID
 * @return string|null The formatted duration string or null if not available
 */
function get_learndash_course_duration($course_id) {
    // Ensure the Utilities class exists (available since LearnDash 4.21.4)
    if (!class_exists('LearnDash\Core\Utilities\CourseGrid\Utilities')) {
        error_log('LearnDash Utilities class not found. Requires LearnDash 4.21.4 or higher.');
        return null;
    }
    
    // Validate course ID
    if (empty($course_id) || !is_numeric($course_id)) {
        return null;
    }
    
    // Verify the post is a LearnDash course
    if (get_post_type($course_id) !== 'sfwd-courses') {
        return null;
    }
    
    try {
        // Use the Utilities::get_duration() method
        $duration = \LearnDash\Core\Utilities\CourseGrid\Utilities::get_duration($course_id);
        
        // Return the duration if available
        return !empty($duration) ? $duration : null;
        
    } catch (Exception $e) {
        error_log('Error retrieving course duration: ' . $e->getMessage());
        return null;
    }
}

/**
 * Display course duration in profile or shortcode context
 * 
 * @param int $course_id The course ID
 * @param bool $show_label Whether to show duration label
 * @return string HTML output for course duration
 */
function display_course_duration($course_id, $show_label = true) {
    $duration = get_learndash_course_duration($course_id);
    
    if (empty($duration)) {
        return '';
    }
    
    $label = $show_label ? __('Duration: ', 'learndash') : '';
    
    return sprintf(
        '<span class="course-duration">%s%s</span>',
        esc_html($label),
        esc_html($duration)
    );
}

/**
 * Example usage in a profile shortcode context
 * This would replace existing duration logic in profile.php
 */
function profile_course_duration_example() {
    // Example: Get current user's enrolled courses
    if (!function_exists('learndash_user_get_enrolled_courses')) {
        return;
    }
    
    $user_id = get_current_user_id();
    $enrolled_courses = learndash_user_get_enrolled_courses($user_id);
    
    if (empty($enrolled_courses)) {
        return;
    }
    
    echo '<div class="user-course-durations">';
    echo '<h3>' . esc_html__('Course Durations', 'learndash') . '</h3>';
    
    foreach ($enrolled_courses as $course_id) {
        $course_title = get_the_title($course_id);
        $duration_html = display_course_duration($course_id);
        
        if (!empty($duration_html)) {
            echo '<div class="course-duration-item">';
            echo '<strong>' . esc_html($course_title) . '</strong>: ' . $duration_html;
            echo '</div>';
        }
    }
    
    echo '</div>';
}

/**
 * AJAX handler for getting course duration
 * Useful for dynamic loading in profile contexts
 */
function ajax_get_course_duration() {
    // Verify nonce for security
    if (!wp_verify_nonce($_POST['nonce'], 'course_duration_nonce')) {
        wp_die('Security check failed');
    }
    
    $course_id = intval($_POST['course_id']);
    $duration = get_learndash_course_duration($course_id);
    
    if ($duration) {
        wp_send_json_success([
            'duration' => $duration,
            'formatted' => display_course_duration($course_id)
        ]);
    } else {
        wp_send_json_error('Duration not available');
    }
}

// Hook the AJAX handler
add_action('wp_ajax_get_course_duration', 'ajax_get_course_duration');
add_action('wp_ajax_nopriv_get_course_duration', 'ajax_get_course_duration');

/**
 * Integration example for existing profile.php file
 * Replace existing duration logic with this implementation
 */

// Example of how this would be integrated into an existing profile template:
/*
// In your profile.php file, replace existing duration code with:

foreach ($user_courses as $course_id) {
    $course = get_post($course_id);
    $course_title = $course->post_title;
    
    // OLD CODE (remove this):
    // $duration = get_post_meta($course_id, 'course_duration', true);
    
    // NEW CODE (use this instead):
    $duration = get_learndash_course_duration($course_id);
    
    // Display the course with duration
    echo '<div class="profile-course-item">';
    echo '<h4>' . esc_html($course_title) . '</h4>';
    if ($duration) {
        echo '<p class="course-duration">' . sprintf(__('Duration: %s', 'learndash'), esc_html($duration)) . '</p>';
    }
    echo '</div>';
}
*/