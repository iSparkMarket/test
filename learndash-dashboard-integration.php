<?php
/**
 * LearnDash Dashboard Integration Example
 * Demonstrates how to integrate Utilities::get_duration() into existing dashboard.php
 * 
 * This shows how to modify the existing LearnDash course display logic
 * in /workspace/includes/dashboard.php to include course duration
 */

/**
 * Enhanced function to get LearnDash course data with duration
 * This would replace or enhance the existing course data retrieval
 */
function get_enhanced_learndash_course_data($user_id) {
    // Check if LearnDash is available
    if (!function_exists('learndash_user_get_enrolled_courses')) {
        return [];
    }
    
    $courses = learndash_user_get_enrolled_courses($user_id);
    $enhanced_courses = [];
    
    foreach ($courses as $course_id) {
        $course_data = [
            'id' => $course_id,
            'title' => get_the_title($course_id),
            'permalink' => get_permalink($course_id),
        ];
        
        // Get completion status
        if (function_exists('learndash_course_completed')) {
            $course_data['completed'] = learndash_course_completed($user_id, $course_id);
        }
        
        // Get progress
        if (function_exists('learndash_course_progress')) {
            $progress = learndash_course_progress($user_id, $course_id);
            $course_data['progress'] = $progress;
        }
        
        // Get course duration using Utilities::get_duration()
        $course_data['duration'] = get_learndash_course_duration($course_id);
        
        // Get certificate link
        if (function_exists('learndash_get_course_certificate_link')) {
            $course_data['certificate_link'] = learndash_get_course_certificate_link($course_id, $user_id);
        }
        
        $enhanced_courses[] = $course_data;
    }
    
    return $enhanced_courses;
}

/**
 * Helper function to get course duration (same as in main example)
 * Include this function in your dashboard.php or functions.php
 */
function get_learndash_course_duration($course_id) {
    // Ensure the Utilities class exists (available since LearnDash 4.21.4)
    if (!class_exists('LearnDash\Core\Utilities\CourseGrid\Utilities')) {
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
        return !empty($duration) ? $duration : null;
    } catch (Exception $e) {
        error_log('Error retrieving course duration: ' . $e->getMessage());
        return null;
    }
}

/**
 * Example modification for the AJAX response in dashboard.php
 * This shows how to enhance the existing AJAX response around line 1958
 */
function enhanced_ajax_get_user_learndash_data() {
    // ... existing validation code ...
    
    $user_id = intval($_POST['user_id']);
    
    // Enhanced course data with duration
    $enhanced_courses = get_enhanced_learndash_course_data($user_id);
    
    // Build the response
    $courses_html = '';
    if (!empty($enhanced_courses)) {
        foreach ($enhanced_courses as $course) {
            $status_class = $course['completed'] ? 'completed' : 'in-progress';
            $status_text = $course['completed'] ? 'Completed' : 'In Progress';
            
            $progress_percentage = 0;
            if (isset($course['progress']['percentage'])) {
                $progress_percentage = $course['progress']['percentage'];
            }
            
            $courses_html .= '<div class="course-item ' . $status_class . '">';
            $courses_html .= '<h5><a href="' . esc_url($course['permalink']) . '">' . esc_html($course['title']) . '</a></h5>';
            
            // Add duration display
            if (!empty($course['duration'])) {
                $courses_html .= '<p class="course-duration"><i class="fas fa-clock"></i> Duration: ' . esc_html($course['duration']) . '</p>';
            }
            
            $courses_html .= '<div class="progress mb-2">';
            $courses_html .= '<div class="progress-bar bg-success" style="width: ' . $progress_percentage . '%"></div>';
            $courses_html .= '</div>';
            $courses_html .= '<span class="badge badge-' . ($course['completed'] ? 'success' : 'warning') . '">' . $status_text . '</span>';
            
            // Certificate link if available
            if (!empty($course['certificate_link'])) {
                $courses_html .= '<br><a href="' . esc_url($course['certificate_link']) . '" target="_blank" class="btn btn-sm btn-outline-primary mt-2">View Certificate</a>';
            }
            
            $courses_html .= '</div>';
        }
    }
    
    wp_send_json_success([
        'courses' => $enhanced_courses,
        'courses_html' => $courses_html,
        // ... other existing response data ...
    ]);
}

/**
 * CSS additions for course duration display
 * Add this to your admin.css file
 */
$css_additions = '
/* Course duration styling */
.course-duration {
    font-size: 0.9em;
    color: #666;
    margin: 5px 0;
}

.course-duration i {
    margin-right: 5px;
    color: #007cba;
}

.course-item {
    border: 1px solid #ddd;
    padding: 15px;
    margin-bottom: 10px;
    border-radius: 5px;
}

.course-item.completed {
    border-left: 4px solid #46b450;
}

.course-item.in-progress {
    border-left: 4px solid #ffb900;
}
';

/**
 * JavaScript enhancement for dynamic loading
 * Add this to your admin.js file
 */
$js_additions = '
// Enhanced course display with duration
function displayCourseWithDuration(course) {
    let html = `
        <div class="course-item ${course.completed ? "completed" : "in-progress"}">
            <h5><a href="${course.permalink}">${course.title}</a></h5>
    `;
    
    // Add duration if available
    if (course.duration) {
        html += `<p class="course-duration"><i class="fas fa-clock"></i> Duration: ${course.duration}</p>`;
    }
    
    // Add progress bar
    let progress = course.progress ? course.progress.percentage : 0;
    html += `
        <div class="progress mb-2">
            <div class="progress-bar bg-success" style="width: ${progress}%"></div>
        </div>
        <span class="badge badge-${course.completed ? "success" : "warning"}">
            ${course.completed ? "Completed" : "In Progress"}
        </span>
    `;
    
    // Add certificate link if available
    if (course.certificate_link) {
        html += `<br><a href="${course.certificate_link}" target="_blank" class="btn btn-sm btn-outline-primary mt-2">View Certificate</a>`;
    }
    
    html += `</div>`;
    return html;
}
';

/**
 * Integration instructions for dashboard.php:
 * 
 * 1. Add the get_learndash_course_duration() function to the top of dashboard.php
 * 
 * 2. Replace the existing course data retrieval around line 1960 with:
 *    $enhanced_courses = get_enhanced_learndash_course_data($user_id);
 * 
 * 3. Update the courses HTML generation to include duration display
 * 
 * 4. Add the CSS styles to assets/css/admin.css
 * 
 * 5. Update the JavaScript in assets/js/admin.js to handle duration display
 * 
 * 6. Ensure LearnDash 4.21.4+ is installed for Utilities::get_duration() availability
 */

// Example of specific line modifications for dashboard.php:

/*
BEFORE (around line 1960):
if (function_exists('learndash_user_get_enrolled_courses')) {
    $courses = learndash_user_get_enrolled_courses($user_id);
    
    foreach ($courses as $course_id) {
        $course_progress = function_exists('learndash_user_get_course_progress') ? learndash_user_get_course_progress($user_id, $course_id) : null;
        // ... existing code ...
    }
}

AFTER:
if (function_exists('learndash_user_get_enrolled_courses')) {
    $enhanced_courses = get_enhanced_learndash_course_data($user_id);
    
    foreach ($enhanced_courses as $course) {
        // Use $course array with duration included
        // ... enhanced display code ...
    }
}
*/