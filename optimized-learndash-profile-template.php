<?php
/**
 * Optimized LearnDash Profile Template
 * 
 * This template provides a comprehensive user profile page with:
 * - Enhanced performance through proper caching
 * - Improved security and data sanitization
 * - Better LearnDash API usage
 * - Optimized database queries
 * - Profile information display and editing
 * - LearnDash course and certificate tracking
 * - External course management
 * - Profile image preview functionality
 * - Password management
 * 
 * @version 2.0
 * @package LearnDash
 */

if (!defined('ABSPATH')) {
    exit;
}

// Security: Check user permissions
if (!is_user_logged_in()) {
    wp_redirect(wp_login_url(get_permalink()));
    exit;
}

// Get current user data with validation
$user_id = get_current_user_id();
if (!$user_id) {
    wp_die(__('Invalid user session.', 'learndash'));
}

$current_user = wp_get_current_user();
if (!$current_user->exists()) {
    wp_die(__('User not found.', 'learndash'));
}

// Enhanced avatar handling
$avatar = get_avatar($user_id, 120, '', '', array('class' => 'profile-avatar'));
$avatar_url = get_avatar_url($user_id, array('size' => 120, 'default' => 'identicon'));

// Prepare user display name with better fallback
$user_name = '';
if (!empty($current_user->first_name) || !empty($current_user->last_name)) {
    $user_name = trim($current_user->first_name . ' ' . $current_user->last_name);
}
if (empty($user_name)) {
    $user_name = !empty($current_user->display_name) ? $current_user->display_name : $current_user->user_login;
}

$user_email = sanitize_email($current_user->user_email);
$username = sanitize_user($current_user->user_login);

// Enhanced LearnDash data retrieval with caching
$cache_key = 'ld_profile_data_' . $user_id;
$profile_data = wp_cache_get($cache_key, 'learndash_profile');

if (false === $profile_data) {
    $profile_data = array();
    
    // Get LearnDash user stats
    if (function_exists('learndash_get_user_stats')) {
        $profile_data['learndash_stats'] = learndash_get_user_stats($user_id);
    } else {
        $profile_data['learndash_stats'] = array();
    }
    
    // Get enrolled courses with progress
    if (function_exists('learndash_user_get_enrolled_courses')) {
        $profile_data['enrolled_courses'] = learndash_user_get_enrolled_courses($user_id, array(), true);
    } else {
        $profile_data['enrolled_courses'] = array();
    }
    
    // Get certificate count using multiple methods for compatibility
    $profile_data['certificate_count'] = 0;
    if (function_exists('learndash_get_certificate_count')) {
        $profile_data['certificate_count'] = learndash_get_certificate_count($user_id);
    }
    
    // Get completed courses
    $profile_data['completed_courses'] = array();
    if (!empty($profile_data['enrolled_courses'])) {
        foreach ($profile_data['enrolled_courses'] as $course_id) {
            if (function_exists('learndash_course_completed') && learndash_course_completed($user_id, $course_id)) {
                $profile_data['completed_courses'][] = $course_id;
            }
        }
    }
    
    // Cache for 5 minutes
    wp_cache_set($cache_key, $profile_data, 'learndash_profile', 300);
}

// Extract cached data
$learndash_user_stats = $profile_data['learndash_stats'];
$user_courses_list = $profile_data['enrolled_courses'];
$learndash_certificate_count = $profile_data['certificate_count'];
$completed_courses = $profile_data['completed_courses'];

// Enhanced external courses data with prepared statements
global $wpdb;
$table_name = $wpdb->prefix . 'external_courses';

// Check if table exists
$table_exists = $wpdb->get_var($wpdb->prepare(
    "SHOW TABLES LIKE %s",
    $table_name
)) === $table_name;

$external_courses_approved = array();
$external_certificate_count = 0;
$external_course_hours = 0;

if ($table_exists) {
    $external_courses_approved = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE user_id = %d AND status = 'approved' ORDER BY date_submitted DESC",
        $user_id
    ));
    
    if ($external_courses_approved) {
        foreach ($external_courses_approved as $course) {
            $external_course_hours += floatval($course->course_time);
            if (!empty($course->certificate_file)) {
                $external_certificate_count++;
            }
        }
    }
}

// Enhanced course time calculation with proper LearnDash methods
$learndash_hours = 0;
if (!empty($user_courses_list)) {
    foreach ($user_courses_list as $course_id) {
        // Try to get course duration using multiple methods
        $course_duration = 0;
        
        // Method 1: Check for course points (common practice: 1 point = 1 hour)
        $course_points = get_post_meta($course_id, '_ld_course_points', true);
        if (!empty($course_points) && is_numeric($course_points)) {
            $course_duration = intval($course_points);
        }
        
        // Method 2: Check for custom duration meta
        if (empty($course_duration)) {
            $course_duration_meta = get_post_meta($course_id, '_ld_course_duration', true);
            if (!empty($course_duration_meta) && is_numeric($course_duration_meta)) {
                $course_duration = intval($course_duration_meta);
            }
        }
        
        // Method 3: Calculate based on lessons/topics (fallback)
        if (empty($course_duration)) {
            $course_steps = array();
            if (function_exists('learndash_get_course_steps')) {
                $course_steps = learndash_get_course_steps($course_id);
            }
            
            // Estimate 30 minutes per lesson, 15 minutes per topic
            $lessons = isset($course_steps[$course_id]['sfwd-lessons']) ? count($course_steps[$course_id]['sfwd-lessons']) : 0;
            $topics = isset($course_steps[$course_id]['sfwd-topic']) ? count($course_steps[$course_id]['sfwd-topic']) : 0;
            
            $course_duration = ($lessons * 0.5) + ($topics * 0.25); // Hours
        }
        
        // Fallback: minimum 1 hour per course
        if (empty($course_duration)) {
            $course_duration = 1;
        }
        
        $learndash_hours += $course_duration;
    }
}

// Calculate totals
$total_ld_courses = count($user_courses_list);
$total_external_courses = count($external_courses_approved);
$total_courses = $total_ld_courses + $total_external_courses;
$total_hours = $learndash_hours + $external_course_hours;
$total_certificates = $learndash_certificate_count + $external_certificate_count;

// Enhanced certificate retrieval with better error handling
$ld_certificates = array();
$certificates_cache_key = 'ld_user_certificates_' . $user_id;
$ld_certificates = wp_cache_get($certificates_cache_key, 'learndash_certificates');

if (false === $ld_certificates) {
    $ld_certificates = array();
    
    // Method 1: Get certificates from completed courses
    if (!empty($completed_courses)) {
        foreach ($completed_courses as $course_id) {
            $certificate_id = get_post_meta($course_id, '_ld_certificate', true);
            if (!empty($certificate_id) && $certificate_id > 0) {
                if (function_exists('learndash_get_course_certificate_link')) {
                    $certificate_link = learndash_get_course_certificate_link($course_id, $user_id);
                    if (!empty($certificate_link)) {
                        $course = get_post($course_id);
                        if ($course && !is_wp_error($course)) {
                            $ld_certificates[] = (object) array(
                                'post_title' => sanitize_text_field($course->post_title),
                                'permalink' => esc_url($certificate_link),
                                'type' => 'course'
                            );
                        }
                    }
                }
            }
        }
    }
    
    // Method 2: Get quiz certificates
    if (function_exists('learndash_get_user_quiz_attempts')) {
        $quiz_attempts = learndash_get_user_quiz_attempts($user_id);
        if (!empty($quiz_attempts)) {
            foreach ($quiz_attempts as $attempt) {
                if (isset($attempt['certificate']['certificateLink']) && !empty($attempt['certificate']['certificateLink'])) {
                    $quiz_id = isset($attempt['quiz']) ? intval($attempt['quiz']) : 0;
                    if ($quiz_id > 0) {
                        $quiz = get_post($quiz_id);
                        if ($quiz && !is_wp_error($quiz)) {
                            $ld_certificates[] = (object) array(
                                'post_title' => sanitize_text_field($quiz->post_title . ' (Quiz)'),
                                'permalink' => esc_url($attempt['certificate']['certificateLink']),
                                'type' => 'quiz'
                            );
                        }
                    }
                }
            }
        }
    }
    
    // Cache certificates for 10 minutes
    wp_cache_set($certificates_cache_key, $ld_certificates, 'learndash_certificates', 600);
}

// Prepare external certificates
$external_certificates = array();
if ($external_courses_approved) {
    foreach ($external_courses_approved as $course) {
        if (!empty($course->certificate_file)) {
            $external_certificates[] = (object) array(
                'post_title' => sanitize_text_field($course->course_name),
                'permalink' => esc_url($course->certificate_file),
                'type' => 'external'
            );
        }
    }
}
?>

<!-- Profile Header -->
<div class="ld-profile-gradient-header">
    <div class="ld-profile-header-content">
        <div class="ld-profile-avatar-large" id="ldProfileImage" style="cursor: pointer;" tabindex="0" role="button" aria-label="<?php esc_attr_e('Click to view profile image', 'learndash'); ?>">
            <?php echo $avatar; ?>
        </div>
        <div class="ld-profile-greeting">
            <h1><?php printf(esc_html__('Hello %s', 'learndash'), esc_html($user_name)); ?></h1>
            <p><?php esc_html_e('This is your profile page. You can see your LearnDash progress and manage your account details.', 'learndash'); ?></p>
            <?php if (current_user_can('edit_user', $user_id)): ?>
                <button id="ldEditProfileBtn" class="ld-profile-edit-btn" type="button" aria-label="<?php esc_attr_e('Edit Profile', 'learndash'); ?>">
                    <?php esc_html_e('Edit Profile', 'learndash'); ?>
                </button>
            <?php endif; ?>
            <?php if ($table_exists): ?>
                <button class="ld-add-course-btn" id="ldAddCourseBtn" type="button" aria-label="<?php esc_attr_e('Add New External Course', 'learndash'); ?>">
                    <?php esc_html_e('Add New External Course', 'learndash'); ?>
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="ld-profile-main">
    <!-- Account Information Card -->
    <div class="ld-profile-card">
        <h2><?php esc_html_e('My Account', 'learndash'); ?></h2>
        <div class="ld-profile-info-row">
            <div class="ld-profile-info-label"><?php esc_html_e('Username', 'learndash'); ?></div>
            <div class="ld-profile-info-value"><?php echo esc_html($username); ?></div>
        </div>
        <div class="ld-profile-info-row">
            <div class="ld-profile-info-label"><?php esc_html_e('Email', 'learndash'); ?></div>
            <div class="ld-profile-info-value"><?php echo esc_html($user_email); ?></div>
        </div>
        <div class="ld-profile-info-row">
            <div class="ld-profile-info-label"><?php esc_html_e('Name', 'learndash'); ?></div>
            <div class="ld-profile-info-value"><?php echo esc_html($user_name); ?></div>
        </div>

        <?php if ($table_exists): ?>
        <!-- External Course Form -->
        <div style="max-width:1100px;margin:0 auto;">
            <div id="ldAddCourseFormWrap" style="display:none;margin-top:24px;">
                <h3><?php esc_html_e('Add External Course', 'learndash'); ?></h3>
                <form class="ld-modal-form" id="ldAddCourseForm" enctype="multipart/form-data"
                    style="background:#fff;border-radius:12px;box-shadow:0 2px 16px rgba(0,0,0,0.08);padding:32px 28px;width:100%;max-width:600px;">
                    <?php wp_nonce_field('ld_external_course_submit', 'ld_external_course_nonce'); ?>
                    
                    <label for="ld-course-name"><?php esc_html_e('Course Name', 'learndash'); ?> <span class="required">*</span></label>
                    <input type="text" id="ld-course-name" name="course_name" required maxlength="255" aria-describedby="course-name-desc">
                    <small id="course-name-desc"><?php esc_html_e('Enter the full name of the course', 'learndash'); ?></small>
                    
                    <label for="ld-certificate-provider"><?php esc_html_e('Certificate Provider', 'learndash'); ?> <span class="required">*</span></label>
                    <input type="text" id="ld-certificate-provider" name="certificate_provider" required maxlength="255" aria-describedby="provider-desc">
                    <small id="provider-desc"><?php esc_html_e('Organization or institution that provided the certificate', 'learndash'); ?></small>
                    
                    <label for="ld-instructor"><?php esc_html_e('Instructor', 'learndash'); ?> <span class="required">*</span></label>
                    <input type="text" id="ld-instructor" name="instructor" required maxlength="255" aria-describedby="instructor-desc">
                    <small id="instructor-desc"><?php esc_html_e('Name of the course instructor', 'learndash'); ?></small>
                    
                    <label for="ld-certificate-upload"><?php esc_html_e('Certificate Upload', 'learndash'); ?></label>
                    <input type="file" id="ld-certificate-upload" name="certificate_file" accept=".pdf,.jpg,.jpeg,.png" aria-describedby="upload-desc">
                    <small id="upload-desc"><?php esc_html_e('Allowed formats: PDF, JPG, PNG (Max: 5MB)', 'learndash'); ?></small>
                    
                    <label for="ld-course-time"><?php esc_html_e('Course Time (hours)', 'learndash'); ?> <span class="required">*</span></label>
                    <input type="number" id="ld-course-time" name="course_time" min="0" max="1000" step="0.1" required aria-describedby="time-desc">
                    <small id="time-desc"><?php esc_html_e('Total duration of the course in hours', 'learndash'); ?></small>
                    
                    <div class="ld-modal-actions">
                        <button type="submit" class="ld-modal-submit"><?php esc_html_e('Submit', 'learndash'); ?></button>
                        <button type="button" class="ld-modal-cancel" onclick="document.getElementById('ldAddCourseFormWrap').style.display='none'"><?php esc_html_e('Cancel', 'learndash'); ?></button>
                    </div>
                    <div id="ldAddCourseMsg" style="margin-top:12px;" role="alert" aria-live="polite"></div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- LearnDash Stats Card -->
    <div class="ld-profile-card">
        <h2><?php esc_html_e('Learning Progress', 'learndash'); ?></h2>
        <div class="ld-profile-stats">
            <div class="ld-profile-stat ld-stat-tab active" data-tab="courses" tabindex="0" role="button" aria-label="<?php esc_attr_e('View courses', 'learndash'); ?>">
                <div class="stat-number"><?php echo esc_html($total_courses); ?></div>
                <div class="stat-label"><?php esc_html_e('Courses', 'learndash'); ?></div>
            </div>
            <div class="ld-profile-stat ld-stat-tab" data-tab="certificates" tabindex="0" role="button" aria-label="<?php esc_attr_e('View certificates', 'learndash'); ?>">
                <div class="stat-number"><?php echo esc_html($total_certificates); ?></div>
                <div class="stat-label"><?php esc_html_e('Certificates', 'learndash'); ?></div>
            </div>
            <div class="ld-profile-stat">
                <div class="stat-number"><?php echo esc_html(number_format($total_hours, 1)); ?></div>
                <div class="stat-label"><?php esc_html_e('Hours', 'learndash'); ?></div>
            </div>
        </div>

        <!-- Tab Content -->
        <div id="ld-tab-content-courses" class="ld-profile-tab-content active">
            <h3 class="ld-list-group-title"><?php esc_html_e('LearnDash Courses', 'learndash'); ?></h3>
            <ul class="ld-item-list" role="list">
                <?php if (!empty($user_courses_list)): ?>
                    <?php foreach ($user_courses_list as $course_id):
                        $course = get_post($course_id);
                        if (!$course || is_wp_error($course)) continue;
                        
                        $course_points = get_post_meta($course_id, '_ld_course_points', true);
                        $course_duration_meta = get_post_meta($course_id, '_ld_course_duration', true);
                        
                        $course_hours = 1; // Default
                        if (!empty($course_points) && is_numeric($course_points)) {
                            $course_hours = intval($course_points);
                        } elseif (!empty($course_duration_meta) && is_numeric($course_duration_meta)) {
                            $course_hours = intval($course_duration_meta);
                        }
                        
                        $is_completed = in_array($course_id, $completed_courses);
                        $completion_status = $is_completed ? __('Completed', 'learndash') : __('In Progress', 'learndash');
                        $completion_class = $is_completed ? 'completed' : 'in-progress';
                        ?>
                        <li role="listitem">
                            <div class="ld-course-info">
                                <span class="ld-item-title"><?php echo esc_html($course->post_title); ?></span>
                                <span class="ld-completion-status <?php echo esc_attr($completion_class); ?>"><?php echo esc_html($completion_status); ?></span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span style="color: #666; font-size: 12px;" title="<?php esc_attr_e('Estimated duration', 'learndash'); ?>">
                                    <?php echo esc_html($course_hours); ?> <?php esc_html_e('hrs', 'learndash'); ?>
                                </span>
                                <a href="<?php echo esc_url(get_permalink($course_id)); ?>" class="ld-item-link" aria-label="<?php echo esc_attr(sprintf(__('View %s course', 'learndash'), $course->post_title)); ?>">
                                    <?php esc_html_e('View Course', 'learndash'); ?>
                                </a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li role="listitem"><?php esc_html_e('No LearnDash courses found.', 'learndash'); ?></li>
                <?php endif; ?>
            </ul>

            <?php if ($table_exists): ?>
            <h3 class="ld-list-group-title" style="margin-top: 24px;"><?php esc_html_e('External Courses', 'learndash'); ?></h3>
            <ul class="ld-item-list" role="list">
                <?php if (!empty($external_courses_approved)): ?>
                    <?php foreach ($external_courses_approved as $course): ?>
                        <li role="listitem">
                            <div class="ld-course-info">
                                <span class="ld-item-title">
                                    <?php echo esc_html($course->course_name); ?> 
                                    <em style="font-weight:normal; color:#777;">
                                        (<?php echo esc_html(number_format($course->course_time, 1)); ?> <?php esc_html_e('hrs', 'learndash'); ?>)
                                    </em>
                                </span>
                                <span style="color:#555;"><?php echo esc_html($course->certificate_provider); ?></span>
                            </div>
                            <?php if (!empty($course->instructor)): ?>
                                <span style="color:#888; font-size: 0.9em;"><?php printf(esc_html__('Instructor: %s', 'learndash'), esc_html($course->instructor)); ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li role="listitem"><?php esc_html_e('No approved external courses found.', 'learndash'); ?></li>
                <?php endif; ?>
            </ul>
            <?php endif; ?>
        </div>

        <div id="ld-tab-content-certificates" class="ld-profile-tab-content">
            <h3 class="ld-list-group-title"><?php esc_html_e('LearnDash Certificates', 'learndash'); ?></h3>
            <ul class="ld-item-list" role="list">
                <?php if (!empty($ld_certificates)): ?>
                    <?php foreach ($ld_certificates as $certificate): ?>
                        <li role="listitem">
                            <span class="ld-item-title"><?php echo esc_html($certificate->post_title); ?></span>
                            <a href="<?php echo esc_url($certificate->permalink); ?>" class="ld-item-link" target="_blank" rel="noopener" aria-label="<?php echo esc_attr(sprintf(__('View certificate for %s', 'learndash'), $certificate->post_title)); ?>">
                                <?php esc_html_e('View Certificate', 'learndash'); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li role="listitem"><?php esc_html_e('No LearnDash certificates found.', 'learndash'); ?></li>
                <?php endif; ?>
            </ul>

            <?php if (!empty($external_certificates)): ?>
            <h3 class="ld-list-group-title" style="margin-top: 24px;"><?php esc_html_e('External Certificates', 'learndash'); ?></h3>
            <ul class="ld-item-list" role="list">
                <?php foreach ($external_certificates as $certificate): ?>
                    <li role="listitem">
                        <span class="ld-item-title"><?php echo esc_html($certificate->post_title); ?></span>
                        <a href="<?php echo esc_url($certificate->permalink); ?>" class="ld-item-link" target="_blank" rel="noopener" aria-label="<?php echo esc_attr(sprintf(__('View certificate for %s', 'learndash'), $certificate->post_title)); ?>">
                            <?php esc_html_e('View Certificate', 'learndash'); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Profile Edit Modal -->
<?php if (current_user_can('edit_user', $user_id)): ?>
<div id="ldProfileModalBg" class="ld-modal-bg" role="dialog" aria-labelledby="profile-modal-title" aria-hidden="true">
    <div class="ld-modal" style="max-width:500px;">
        <button class="ld-modal-close" id="ldProfileModalClose" aria-label="<?php esc_attr_e('Close modal', 'learndash'); ?>">&times;</button>
        <h2 id="profile-modal-title" class="sr-only"><?php esc_html_e('Edit Profile', 'learndash'); ?></h2>
        <form id="ldProfileEditForm" class="ld-modal-form" enctype="multipart/form-data" autocomplete="off">
            <?php wp_nonce_field('ld_profile_update', 'ld_profile_update_nonce'); ?>

            <!-- Profile Picture Upload -->
            <div class="w-100">
                <div class="w-50 full-width">
                    <div class="profile-upload-icon">
                        <img src="<?php echo esc_url($avatar_url); ?>" alt="<?php esc_attr_e('Profile Picture', 'learndash'); ?>" class="profile-image" id="profileImg">
                        <div class="upload-icon" onclick="document.getElementById('fileInput').click()" tabindex="0" role="button" aria-label="<?php esc_attr_e('Upload profile picture', 'learndash'); ?>">
                            +
                        </div>
                        <input type="file" id="fileInput" class="hidden-input" name="profile_picture" accept=".jpg,.jpeg,.png,.gif" onchange="previewImage(this, 'profileImg')" aria-describedby="file-upload-desc">
                        <small id="file-upload-desc"><?php esc_html_e('Supported formats: JPG, PNG, GIF (Max: 2MB)', 'learndash'); ?></small>
                    </div>
                </div>
            </div>

            <!-- Basic Information -->
            <div class="w-100">
                <div class="w-50">
                    <label for="first-name"><?php esc_html_e('First Name', 'learndash'); ?></label>
                    <input type="text" id="first-name" name="first_name" value="<?php echo esc_attr($current_user->first_name); ?>" maxlength="50" required>
                </div>
                <div class="w-50">
                    <label for="last-name"><?php esc_html_e('Last Name', 'learndash'); ?></label>
                    <input type="text" id="last-name" name="last_name" value="<?php echo esc_attr($current_user->last_name); ?>" maxlength="50" required>
                </div>
            </div>

            <div class="w-100">
                <div class="w-50 full-width">
                    <label for="user-email"><?php esc_html_e('Email (required)', 'learndash'); ?></label>
                    <input type="email" id="user-email" name="email" value="<?php echo esc_attr($current_user->user_email); ?>" required>
                </div>
            </div>

            <div class="w-100">
                <div class="w-50 full-width">
                    <label for="user-website"><?php esc_html_e('Website', 'learndash'); ?></label>
                    <input type="url" id="user-website" name="website" value="<?php echo esc_attr($current_user->user_url); ?>" placeholder="https://">
                </div>
            </div>

            <div class="w-100">
                <div class="w-50 full-width">
                    <label for="user-description"><?php esc_html_e('Biographical Info', 'learndash'); ?></label>
                    <textarea id="user-description" name="description" maxlength="500" rows="4"><?php echo esc_textarea($current_user->description); ?></textarea>
                    <small><?php esc_html_e('Max 500 characters', 'learndash'); ?></small>
                </div>
            </div>

            <!-- Password Change Section -->
            <div class="w-100">
                <div class="w-50 full-width">
                    <label for="current-password"><?php esc_html_e('Current Password (required for password change)', 'learndash'); ?></label>
                    <input type="password" id="current-password" name="current_password" placeholder="<?php esc_attr_e('Enter current password to change password', 'learndash'); ?>" autocomplete="current-password">
                    <small style="color: #666; font-size: 12px;"><?php esc_html_e('Only required if you want to change your password', 'learndash'); ?></small>
                </div>
            </div>

            <div class="w-100">
                <div class="w-50">
                    <label for="newPassword"><?php esc_html_e('New Password', 'learndash'); ?></label>
                    <input type="password" id="newPassword" name="pass1" placeholder="<?php esc_attr_e('Enter new password', 'learndash'); ?>" autocomplete="new-password">
                    <div class="password-strength" id="passwordStrength" style="margin-top: 5px; font-size: 12px;" aria-live="polite"></div>
                </div>
                <div class="w-50">
                    <label for="confirm-password"><?php esc_html_e('Repeat New Password', 'learndash'); ?></label>
                    <input type="password" id="confirm-password" name="pass2" placeholder="<?php esc_attr_e('Confirm new password', 'learndash'); ?>" autocomplete="new-password">
                </div>
            </div>

            <div class="ld-modal-actions">
                <button type="submit" class="ld-modal-submit"><?php esc_html_e('Update Profile', 'learndash'); ?></button>
                <button type="button" class="ld-modal-cancel" onclick="document.getElementById('ldProfileModalBg').classList.remove('active')"><?php esc_html_e('Cancel', 'learndash'); ?></button>
            </div>
            <div id="ldProfileEditMsg" style="margin-top:12px;" role="alert" aria-live="polite"></div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Profile Image Preview Modal -->
<div id="ldImagePreviewModalBg" class="ld-modal-bg" role="dialog" aria-labelledby="image-preview-title" aria-hidden="true">
    <div class="ld-modal" style="max-width:400px; text-align: center;">
        <button class="ld-modal-close" id="ldImagePreviewModalClose" aria-label="<?php esc_attr_e('Close image preview', 'learndash'); ?>">&times;</button>
        <div class="ld-image-preview-content">
            <h3 id="image-preview-title" style="margin-bottom: 20px; color: #667eea;"><?php esc_html_e('Profile Picture', 'learndash'); ?></h3>
            <div class="ld-image-preview-container">
                <img src="<?php echo esc_url($avatar_url); ?>" alt="<?php esc_attr_e('Profile Picture', 'learndash'); ?>" class="ld-preview-large-image">
            </div>
            <p style="margin-top: 15px; color: #666; font-size: 14px;"><?php esc_html_e('Click outside or press ESC to close', 'learndash'); ?></p>
        </div>
    </div>
</div>

<!-- External Courses List -->
<?php if ($table_exists): ?>
<div id="ldExternalCoursesList" style="max-width:1100px;margin:32px auto 0 auto;"></div>
<?php endif; ?>

<style>
    /* Enhanced CSS with accessibility improvements */
    .sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    .required {
        color: #d63384;
    }

    .ld-completion-status {
        font-size: 0.8em;
        padding: 2px 6px;
        border-radius: 12px;
        font-weight: 500;
    }

    .ld-completion-status.completed {
        background-color: #d4edda;
        color: #155724;
    }

    .ld-completion-status.in-progress {
        background-color: #fff3cd;
        color: #856404;
    }

    .ld-course-info {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    /* Focus states for accessibility */
    .ld-stat-tab:focus,
    .ld-profile-avatar-large:focus,
    button:focus,
    input:focus,
    textarea:focus,
    select:focus {
        outline: 2px solid #667eea;
        outline-offset: 2px;
    }

    /* Improved button styles */
    .ld-modal-cancel {
        background: #6c757d;
        color: white;
        border: none;
        padding: 10px 22px;
        border-radius: 6px;
        cursor: pointer;
        margin-left: 10px;
    }

    .ld-modal-cancel:hover {
        background: #5a6268;
    }

    /* Profile Image Styles */
    .profile-image {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid #ddd;
        transition: opacity 0.3s ease;
    }

    .hidden-input {
        display: none;
    }

    .profile-upload-icon {
        position: relative;
        display: inline-block;
        margin: 20px;
    }

    .upload-icon {
        position: absolute;
        bottom: 0;
        right: 0;
        width: 24px;
        height: 24px;
        background: #007cba;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: white;
        font-size: 14px;
        border: 2px solid white;
        transition: all 0.3s ease;
    }

    .upload-icon:hover,
    .upload-icon:focus {
        background: #005a87;
        transform: scale(1.1);
    }

    /* Container */
    .ld-profile-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }

    /* Header Section */
    .ld-profile-header,
    .ld-profile-gradient-header {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 24px;
        padding: 40px;
        margin-bottom: 30px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        position: relative;
        overflow: hidden;
    }

    .ld-profile-gradient-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #fff;
        border-radius: 12px;
        padding: 48px 40px 80px;
        text-align: left;
    }

    .ld-profile-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #667eea, #764ba2, #f093fb, #f5576c);
        animation: shimmer 3s ease-in-out infinite;
    }

    @keyframes shimmer {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.7; }
    }

    .ld-profile-header-content {
        display: flex;
        align-items: center;
        gap: 40px;
        max-width: 1100px;
        margin: 0 auto;
    }

    /* Avatar Styles */
    .ld-profile-avatar,
    .ld-profile-avatar-large {
        width: 140px;
        height: 140px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea, #764ba2);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 48px;
        font-weight: 700;
        box-shadow: 0 20px 40px rgba(102, 126, 234, 0.3);
        position: relative;
        overflow: hidden;
    }

    .ld-profile-avatar-large {
        width: 120px;
        height: 120px;
        border: 4px solid #fff;
        background: #fff;
        transition: transform 0.2s ease;
    }

    .ld-profile-avatar-large:hover,
    .ld-profile-avatar-large:focus {
        transform: scale(1.05);
    }

    .ld-profile-avatar::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(45deg, rgba(255, 255, 255, 0.1), transparent);
        border-radius: 50%;
    }

    /* Profile Info */
    .ld-profile-info,
    .ld-profile-greeting {
        flex: 1;
    }

    .ld-profile-greeting h1 {
        font-size: 3rem;
        font-weight: 800;
        background: linear-gradient(135deg, #667eea, #764ba2);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 10px;
    }

    .ld-profile-gradient-header .ld-profile-greeting h1 {
        font-size: 2.5rem;
        font-weight: 700;
        color: #fff;
        background: none;
        -webkit-text-fill-color: #fff;
    }

    .ld-profile-gradient-header .ld-profile-greeting p {
        font-size: 1.1rem;
        color: rgba(255, 255, 255, 0.95);
    }

    /* Buttons */
    .ld-btn,
    .ld-profile-edit-btn,
    .ld-add-course-btn {
        padding: 14px 28px;
        border: none;
        border-radius: 12px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        position: relative;
        overflow: hidden;
    }

    .ld-btn-primary,
    .ld-profile-edit-btn {
        background: linear-gradient(135deg, #2ea3f2, #2271b1);
        color: white;
        box-shadow: 0 8px 20px rgba(46, 163, 242, 0.3);
    }

    .ld-add-course-btn {
        background: #28a745;
        color: #fff;
        margin: 32px 0 0 0;
    }

    .ld-btn-secondary {
        background: rgba(255, 255, 255, 0.9);
        color: #667eea;
        border: 2px solid #667eea;
        backdrop-filter: blur(10px);
    }

    .ld-btn-primary:hover,
    .ld-profile-edit-btn:hover {
        background: linear-gradient(135deg, #2271b1, #1a5a8a);
        transform: translateY(-2px);
        box-shadow: 0 12px 30px rgba(46, 163, 242, 0.4);
    }

    .ld-add-course-btn:hover {
        background: #218838;
        transform: translateY(-2px);
    }

    .ld-btn-secondary:hover {
        background: #667eea;
        color: white;
        transform: translateY(-2px);
    }

    .ld-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: left 0.5s ease;
    }

    .ld-btn:hover::before {
        left: 100%;
    }

    /* Main Content Grid */
    .ld-profile-main {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
        margin: 60px auto 0 auto;
        max-width: 1100px;
    }

    /* Cards */
    .ld-profile-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 20px;
        padding: 32px 28px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.08);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        flex: 1 1 320px;
        min-width: 320px;
    }

    .ld-profile-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
    }

    .ld-card-title,
    .ld-profile-card h2 {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 24px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .ld-profile-card h2 {
        font-size: 1.3rem;
        font-weight: 600;
        margin-bottom: 18px;
    }

    /* Info Rows */
    .ld-info-row,
    .ld-profile-info-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 0;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        gap: 24px;
        margin-bottom: 12px;
    }

    .ld-info-row:last-child,
    .ld-profile-info-row:last-child {
        border-bottom: none;
    }

    .ld-info-label,
    .ld-profile-info-label {
        font-weight: 600;
        color: #64748b;
        min-width: 90px;
        font-size: 1rem;
    }

    .ld-profile-info-label {
        color: #888;
    }

    .ld-info-value,
    .ld-profile-info-value {
        font-weight: 600;
        color: #1a202c;
    }

    .ld-profile-info-value {
        font-weight: 500;
        color: #222;
    }

    /* Stats Section */
    .ld-stats-grid,
    .ld-profile-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin: 30px 0;
    }

    .ld-profile-stats {
        display: flex;
        gap: 24px;
        margin-top: 12px;
    }

    .ld-stat-card,
    .ld-profile-stat {
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(10px);
        border-radius: 16px;
        padding: 24px 20px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        flex: 1 1 120px;
    }

    .ld-profile-stat {
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 10px;
        padding: 24px 18px;
        box-shadow: 0 1px 6px rgba(102, 126, 234, 0.08);
    }

    .ld-stat-card.active,
    .ld-stat-tab.active {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        transform: scale(1.05);
    }

    .ld-stat-card:hover,
    .ld-stat-tab:hover {
        transform: translateY(-5px);
    }

    .ld-stat-tab:not(.active) {
        opacity: 0.7;
    }

    .ld-stat-tab:not(.active):hover {
        opacity: 0.9;
    }

    .ld-stat-number {
        font-size: 2.5rem;
        font-weight: 800;
        margin-bottom: 8px;
        color: #667eea;
    }

    .ld-profile-stat .stat-number {
        font-size: 2.1rem;
        font-weight: 700;
        color: white;
        margin-bottom: 6px;
    }

    .ld-stat-tab:not(.active) .stat-number {
        color: #fff;
    }

    .ld-stat-label,
    .ld-profile-stat .stat-label {
        font-size: 1rem;
        font-weight: 600;
        opacity: 0.8;
        color: white;
        font-weight: 500;
    }

    /* Content Sections */
    .ld-content-section {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 20px;
        padding: 32px;
        margin-bottom: 30px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.08);
    }

    .ld-tab-content,
    .ld-profile-tab-content {
        display: none;
        margin-top: 24px;
    }

    .ld-tab-content.active,
    .ld-profile-tab-content.active {
        display: block;
        animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .ld-section-title,
    .ld-list-group-title {
        font-size: 1.3rem;
        font-weight: 700;
        margin-bottom: 20px;
        color: #667eea;
    }

    .ld-list-group-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: #444;
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 1px solid #eee;
    }

    /* Item Lists */
    .ld-item-list {
        list-style: none;
        padding: 0;
    }

    .ld-item,
    .ld-item-list li {
        background: rgba(255, 255, 255, 0.8);
        border: 1px solid rgba(102, 126, 234, 0.1);
        border-radius: 12px;
        padding: 20px 16px;
        margin-bottom: 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: all 0.3s ease;
    }

    .ld-item-list li {
        background: #fff;
        border: 1px solid #eee;
        border-radius: 8px;
        margin-bottom: 12px;
    }

    .ld-item:hover,
    .ld-item-list li:hover {
        background: rgba(102, 126, 234, 0.05);
        border-color: rgba(102, 126, 234, 0.2);
        transform: translateX(5px);
    }

    .ld-item-title,
    .ld-item-list .ld-item-title {
        font-weight: 600;
        color: #1a202c;
    }

    .ld-item-meta {
        font-size: 0.9rem;
        color: #64748b;
        margin-top: 4px;
    }

    .ld-item-link,
    .ld-item-list a.ld-item-link {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        padding: 8px 16px;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.9rem;
        transition: all 0.3s ease;
    }

    .ld-item-link:hover,
    .ld-item-list a.ld-item-link:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        background: #5a67d8;
        color: white;
        text-decoration: none;
    }

    /* Modal Styles */
    .ld-modal-bg {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(10px);
        z-index: 9999;
        align-items: center;
        justify-content: center;
    }

    .ld-modal-bg.active {
        display: flex;
        overflow-y: auto;
    }

    .ld-modal {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border-radius: 20px;
        padding: 40px 32px;
        max-width: 500px;
        min-width: 320px;
        top: 25% !important;
        width: 90%;
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
        position: relative;
        animation: modalSlideIn 0.3s ease;
    }

    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: scale(0.9) translateY(20px);
        }
        to {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
    }

    .ld-modal-close {
        position: absolute;
        top: 25px;
        right: 25px;
        background: none;
        border: none;
        font-size: 32px;
        color: #64748b;
        cursor: pointer;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: all 0.3s ease;
    }

    .ld-modal-close:hover,
    .ld-modal-close:focus {
        background: rgba(0, 0, 0, 0.1);
        color: #1a202c;
    }

    .ld-modal-form label {
        display: block;
        margin-top: 16px;
        font-weight: 500;
        margin-bottom: 4px;
    }

    .ld-form-label {
        display: block;
        font-weight: 600;
        color: #1a202c;
        margin-bottom: 8px;
    }

    .ld-form-input,
    .ld-form-textarea,
    .ld-modal-form input[type="text"],
    .ld-modal-form input[type="number"],
    .ld-modal-form input[type="file"],
    .ld-modal-form input[type="password"],
    .ld-modal-form input[type="email"],
    .ld-modal-form input[type="url"],
    .ld-modal-form textarea {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid rgba(102, 126, 234, 0.2);
        border-radius: 12px;
        font-size: 1rem;
        transition: all 0.3s ease;
        background: rgba(255, 255, 255, 0.8);
        margin-top: 6px;
        box-sizing: border-box;
    }

    .ld-modal-form input[type="text"],
    .ld-modal-form input[type="number"],
    .ld-modal-form input[type="password"],
    .ld-modal-form input[type="email"],
    .ld-modal-form input[type="url"],
    .ld-modal-form textarea {
        padding: 8px 10px;
        border: 1px solid #ccc;
        border-radius: 5px;
    }

    .ld-modal-form input[type="file"] {
        padding: 4px 0;
    }

    .ld-form-input:focus,
    .ld-form-textarea:focus,
    .ld-modal-form input:focus,
    .ld-modal-form textarea:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .ld-form-textarea {
        min-height: 100px;
        resize: vertical;
    }

    .ld-modal-form .ld-modal-actions {
        margin-top: 24px;
        text-align: right;
    }

    .ld-modal-form .ld-modal-submit {
        background: #2ea3f2;
        color: #fff;
        border: none;
        border-radius: 6px;
        padding: 10px 22px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
    }

    .ld-modal-form .ld-modal-submit:hover,
    .ld-modal-form .ld-modal-submit:focus {
        background: #2271b1;
    }

    /* Utility Classes */
    .w-100 {
        width: 100%;
        display: flex;
        gap: 10px;
        margin-bottom: 16px;
    }

    .w-50.full-width,
    input,
    textarea {
        width: 100%;
        resize: none;
    }

    .w-50 {
        width: 50%;
    }

    /* Profile Image Preview Styles */
    .ld-image-preview-content {
        padding: 20px 0;
    }

    .ld-image-preview-container {
        display: flex;
        justify-content: center;
        align-items: center;
        margin: 20px 0;
    }

    .ld-preview-large-image {
        width: 250px;
        height: 250px;
        border-radius: 50%;
        object-fit: cover;
        border: 4px solid #fff;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        transition: transform 0.3s ease;
    }

    .ld-preview-large-image:hover {
        transform: scale(1.05);
    }

    /* Password Strength Styles */
    .password-strength {
        padding: 5px 10px;
        border-radius: 4px;
        font-weight: 500;
        margin-top: 5px;
    }

    /* Error and Success Messages */
    .error-message {
        color: #dc3545;
        background-color: #f8d7da;
        border: 1px solid #f5c6cb;
        padding: 8px 12px;
        border-radius: 4px;
        margin: 8px 0;
    }

    .success-message {
        color: #155724;
        background-color: #d4edda;
        border: 1px solid #c3e6cb;
        padding: 8px 12px;
        border-radius: 4px;
        margin: 8px 0;
    }

    /* Responsive Design */
    @media (max-width: 900px) {
        .ld-profile-header-content,
        .ld-profile-main {
            flex-direction: column;
            gap: 24px;
        }

        .ld-profile-main {
            margin-top: 0;
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .ld-profile-container {
            padding: 15px;
        }

        .ld-profile-header-content {
            text-align: center;
            gap: 20px;
        }

        .ld-profile-greeting h1 {
            font-size: 2rem;
        }

        .ld-stats-grid,
        .ld-profile-stats {
            grid-template-columns: 1fr;
            flex-direction: column;
        }

        .ld-item,
        .ld-item-list li {
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
        }

        .ld-modal {
            width: 95%;
            max-width: 95vw;
        }

        .w-100 {
            flex-direction: column;
            gap: 16px;
        }

        .w-50 {
            width: 100%;
        }
    }

    div#ldImagePreviewModalBg .ld-modal {
        top: 0 !important;
    }

    /* Loading States */
    .loading {
        opacity: 0.6;
        pointer-events: none;
    }

    .loading::after {
        content: "";
        position: absolute;
        top: 50%;
        left: 50%;
        width: 20px;
        height: 20px;
        margin: -10px 0 0 -10px;
        border: 2px solid #f3f3f3;
        border-top: 2px solid #667eea;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* High contrast mode support */
    @media (prefers-contrast: high) {
        .ld-profile-gradient-header {
            background: #000;
            color: #fff;
        }

        .ld-profile-card {
            border: 2px solid #000;
        }

        .ld-stat-tab.active {
            background: #000;
            color: #fff;
        }
    }

    /* Reduced motion support */
    @media (prefers-reduced-motion: reduce) {
        * {
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.01ms !important;
        }
    }
</style>

<script>
(function() {
    'use strict';

    // Enhanced image preview functionality with error handling
    function previewImage(input, imgId) {
        if (!input.files || !input.files[0]) return;
        
        const file = input.files[0];
        const maxSize = 2 * 1024 * 1024; // 2MB
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        
        // Validate file size
        if (file.size > maxSize) {
            alert('<?php echo esc_js(__("File size must be less than 2MB", "learndash")); ?>');
            input.value = '';
            return;
        }
        
        // Validate file type
        if (!allowedTypes.includes(file.type)) {
            alert('<?php echo esc_js(__("Please select a valid image file (JPG, PNG, GIF)", "learndash")); ?>');
            input.value = '';
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = document.getElementById(imgId);
            if (img) {
                img.src = e.target.result;
            }
        };
        reader.onerror = function() {
            alert('<?php echo esc_js(__("Error reading file", "learndash")); ?>');
        };
        reader.readAsDataURL(file);
    }

    // Make previewImage available globally
    window.previewImage = previewImage;

    // Enhanced form handlers
    function initializeEventHandlers() {
        // Show/hide external course form
        const ldAddCourseBtn = document.getElementById('ldAddCourseBtn');
        const ldAddCourseFormWrap = document.getElementById('ldAddCourseFormWrap');
        
        if (ldAddCourseBtn && ldAddCourseFormWrap) {
            ldAddCourseBtn.addEventListener('click', function() {
                const isVisible = ldAddCourseFormWrap.style.display !== 'none';
                ldAddCourseFormWrap.style.display = isVisible ? 'none' : 'block';
                this.setAttribute('aria-expanded', !isVisible);
                
                if (!isVisible) {
                    // Focus first input when form opens
                    const firstInput = ldAddCourseFormWrap.querySelector('input[type="text"]');
                    if (firstInput) {
                        setTimeout(() => firstInput.focus(), 100);
                    }
                }
            });
        }

        // Enhanced external course form submission with validation
        const ldAddCourseForm = document.getElementById('ldAddCourseForm');
        const ldAddCourseMsg = document.getElementById('ldAddCourseMsg');
        
        if (ldAddCourseForm && ldAddCourseMsg) {
            ldAddCourseForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Clear previous messages
                ldAddCourseMsg.textContent = '';
                ldAddCourseMsg.className = '';
                
                // Client-side validation
                const courseName = this.querySelector('#ld-course-name').value.trim();
                const provider = this.querySelector('#ld-certificate-provider').value.trim();
                const instructor = this.querySelector('#ld-instructor').value.trim();
                const courseTime = parseFloat(this.querySelector('#ld-course-time').value);
                
                if (!courseName || !provider || !instructor || !courseTime) {
                    ldAddCourseMsg.textContent = '<?php echo esc_js(__("Please fill in all required fields", "learndash")); ?>';
                    ldAddCourseMsg.className = 'error-message';
                    return;
                }
                
                if (courseTime <= 0 || courseTime > 1000) {
                    ldAddCourseMsg.textContent = '<?php echo esc_js(__("Course time must be between 0.1 and 1000 hours", "learndash")); ?>';
                    ldAddCourseMsg.className = 'error-message';
                    return;
                }
                
                // Add loading state
                const submitBtn = this.querySelector('.ld-modal-submit');
                const originalText = submitBtn.textContent;
                submitBtn.textContent = '<?php echo esc_js(__("Submitting...", "learndash")); ?>';
                submitBtn.disabled = true;
                this.classList.add('loading');
                
                const formData = new FormData(ldAddCourseForm);
                formData.append('action', 'submit_external_course');
                
                // Get nonce from form or fallback to inline script
                const nonce = this.querySelector('[name="ld_external_course_nonce"]')?.value || 
                             (typeof externalCourseAjax !== 'undefined' ? externalCourseAjax.nonce : '');
                
                if (nonce) {
                    formData.append('nonce', nonce);
                }

                const ajaxUrl = '<?php echo esc_js(admin_url("admin-ajax.php")); ?>';
                
                fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        ldAddCourseMsg.textContent = data.data?.message || '<?php echo esc_js(__("Course submitted successfully", "learndash")); ?>';
                        ldAddCourseMsg.className = 'success-message';
                        
                        setTimeout(() => {
                            ldAddCourseForm.reset();
                            ldAddCourseMsg.textContent = '';
                            ldAddCourseMsg.className = '';
                            if (ldAddCourseFormWrap) {
                                ldAddCourseFormWrap.style.display = 'none';
                            }
                            if (typeof reloadExternalCourses === 'function') {
                                reloadExternalCourses();
                            }
                        }, 2000);
                    } else {
                        throw new Error(data.data?.message || '<?php echo esc_js(__("Submission failed", "learndash")); ?>');
                    }
                })
                .catch(error => {
                    ldAddCourseMsg.textContent = error.message || '<?php echo esc_js(__("Submission failed. Please try again.", "learndash")); ?>';
                    ldAddCourseMsg.className = 'error-message';
                })
                .finally(() => {
                    // Remove loading state
                    submitBtn.textContent = originalText;
                    submitBtn.disabled = false;
                    this.classList.remove('loading');
                });
            });
        }

        // Enhanced tab functionality with keyboard support
        const statTabs = document.querySelectorAll('.ld-stat-tab');
        statTabs.forEach(tab => {
            // Click handler
            tab.addEventListener('click', function() {
                switchTab(this.dataset.tab);
            });
            
            // Keyboard handler
            tab.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    switchTab(this.dataset.tab);
                }
            });
        });

        function switchTab(tabId) {
            // Update active tab
            statTabs.forEach(t => {
                t.classList.remove('active');
                t.setAttribute('aria-selected', 'false');
            });
            
            const activeTab = document.querySelector(`[data-tab="${tabId}"]`);
            if (activeTab) {
                activeTab.classList.add('active');
                activeTab.setAttribute('aria-selected', 'true');
            }
            
            // Update active content
            document.querySelectorAll('.ld-profile-tab-content').forEach(c => {
                c.classList.remove('active');
                c.setAttribute('aria-hidden', 'true');
            });
            
            const activeContent = document.getElementById('ld-tab-content-' + tabId);
            if (activeContent) {
                activeContent.classList.add('active');
                activeContent.setAttribute('aria-hidden', 'false');
            }
        }

        // Enhanced modal functionality
        const ldEditProfileBtn = document.getElementById('ldEditProfileBtn');
        const ldProfileModalBg = document.getElementById('ldProfileModalBg');
        const ldProfileModalClose = document.getElementById('ldProfileModalClose');
        const ldImagePreviewModalBg = document.getElementById('ldImagePreviewModalBg');
        const ldImagePreviewModalClose = document.getElementById('ldImagePreviewModalClose');
        const ldProfileImage = document.getElementById('ldProfileImage');

        // Profile edit modal
        if (ldEditProfileBtn && ldProfileModalBg && ldProfileModalClose) {
            ldEditProfileBtn.addEventListener('click', function() {
                openModal(ldProfileModalBg);
            });
            
            ldProfileModalClose.addEventListener('click', function() {
                closeModal(ldProfileModalBg);
            });
            
            ldProfileModalBg.addEventListener('click', function(e) {
                if (e.target === ldProfileModalBg) {
                    closeModal(ldProfileModalBg);
                }
            });
        }

        // Image preview modal
        if (ldProfileImage && ldImagePreviewModalBg && ldImagePreviewModalClose) {
            ldProfileImage.addEventListener('click', function() {
                openModal(ldImagePreviewModalBg);
            });
            
            ldProfileImage.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openModal(ldImagePreviewModalBg);
                }
            });
            
            ldImagePreviewModalClose.addEventListener('click', function() {
                closeModal(ldImagePreviewModalBg);
            });
            
            ldImagePreviewModalBg.addEventListener('click', function(e) {
                if (e.target === ldImagePreviewModalBg) {
                    closeModal(ldImagePreviewModalBg);
                }
            });
        }

        function openModal(modal) {
            modal.classList.add('active');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            
            // Focus first focusable element
            const focusableElements = modal.querySelectorAll('button, input, textarea, select, [tabindex]:not([tabindex="-1"])');
            if (focusableElements.length > 0) {
                focusableElements[0].focus();
            }
        }

        function closeModal(modal) {
            modal.classList.remove('active');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        // ESC key to close modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (ldImagePreviewModalBg && ldImagePreviewModalBg.classList.contains('active')) {
                    closeModal(ldImagePreviewModalBg);
                } else if (ldProfileModalBg && ldProfileModalBg.classList.contains('active')) {
                    closeModal(ldProfileModalBg);
                }
            }
        });

        // Enhanced profile form submission with validation
        const ldProfileEditForm = document.getElementById('ldProfileEditForm');
        const ldProfileEditMsg = document.getElementById('ldProfileEditMsg');
        
        if (ldProfileEditForm && ldProfileEditMsg) {
            ldProfileEditForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                ldProfileEditMsg.textContent = '';
                ldProfileEditMsg.className = '';
                
                // Client-side validation
                const firstName = this.querySelector('[name="first_name"]').value.trim();
                const lastName = this.querySelector('[name="last_name"]').value.trim();
                const email = this.querySelector('[name="email"]').value.trim();
                const pass1 = this.querySelector('[name="pass1"]').value;
                const pass2 = this.querySelector('[name="pass2"]').value;
                const currentPassword = this.querySelector('[name="current_password"]').value;
                
                if (!firstName || !lastName || !email) {
                    ldProfileEditMsg.textContent = '<?php echo esc_js(__("Please fill in all required fields", "learndash")); ?>';
                    ldProfileEditMsg.className = 'error-message';
                    return;
                }
                
                // Email validation
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    ldProfileEditMsg.textContent = '<?php echo esc_js(__("Please enter a valid email address", "learndash")); ?>';
                    ldProfileEditMsg.className = 'error-message';
                    return;
                }
                
                // Password validation
                if (pass1 && pass1 !== pass2) {
                    ldProfileEditMsg.textContent = '<?php echo esc_js(__("New passwords do not match", "learndash")); ?>';
                    ldProfileEditMsg.className = 'error-message';
                    return;
                }
                
                if (pass1 && !currentPassword) {
                    ldProfileEditMsg.textContent = '<?php echo esc_js(__("Current password is required to change password", "learndash")); ?>';
                    ldProfileEditMsg.className = 'error-message';
                    return;
                }
                
                if (pass1 && pass1.length < 8) {
                    ldProfileEditMsg.textContent = '<?php echo esc_js(__("New password must be at least 8 characters long", "learndash")); ?>';
                    ldProfileEditMsg.className = 'error-message';
                    return;
                }
                
                // Add loading state
                const submitBtn = this.querySelector('.ld-modal-submit');
                const originalText = submitBtn.textContent;
                submitBtn.textContent = '<?php echo esc_js(__("Updating...", "learndash")); ?>';
                submitBtn.disabled = true;
                this.classList.add('loading');

                const formData = new FormData(ldProfileEditForm);
                formData.append('action', 'ld_profile_update_ajax');

                const ajaxUrl = '<?php echo esc_js(admin_url("admin-ajax.php")); ?>';

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        ldProfileEditMsg.textContent = data.data?.message || '<?php echo esc_js(__("Profile updated successfully", "learndash")); ?>';
                        ldProfileEditMsg.className = 'success-message';
                        
                        setTimeout(() => {
                            ldProfileEditMsg.textContent = '';
                            ldProfileEditMsg.className = '';
                            closeModal(ldProfileModalBg);
                            
                            // Reload page to show updated information
                            window.location.reload();
                        }, 2000);
                    } else {
                        throw new Error(data.data?.message || '<?php echo esc_js(__("Update failed", "learndash")); ?>');
                    }
                })
                .catch(error => {
                    ldProfileEditMsg.textContent = error.message || '<?php echo esc_js(__("Update failed. Please try again.", "learndash")); ?>';
                    ldProfileEditMsg.className = 'error-message';
                })
                .finally(() => {
                    // Remove loading state
                    submitBtn.textContent = originalText;
                    submitBtn.disabled = false;
                    this.classList.remove('loading');
                });
            });
        }

        // Enhanced password strength checker
        const newPasswordInput = document.getElementById('newPassword');
        const passwordStrengthDiv = document.getElementById('passwordStrength');

        if (newPasswordInput && passwordStrengthDiv) {
            newPasswordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;
                let message = '';
                let color = '';

                if (password.length === 0) {
                    passwordStrengthDiv.textContent = '';
                    return;
                }

                if (password.length >= 8) strength++;
                if (/[a-z]/.test(password)) strength++;
                if (/[A-Z]/.test(password)) strength++;
                if (/[0-9]/.test(password)) strength++;
                if (/[^A-Za-z0-9]/.test(password)) strength++;

                switch (strength) {
                    case 0:
                    case 1:
                        message = '<?php echo esc_js(__("Very Weak", "learndash")); ?>';
                        color = '#dc3545';
                        break;
                    case 2:
                        message = '<?php echo esc_js(__("Weak", "learndash")); ?>';
                        color = '#fd7e14';
                        break;
                    case 3:
                        message = '<?php echo esc_js(__("Fair", "learndash")); ?>';
                        color = '#ffc107';
                        break;
                    case 4:
                        message = '<?php echo esc_js(__("Good", "learndash")); ?>';
                        color = '#28a745';
                        break;
                    case 5:
                        message = '<?php echo esc_js(__("Strong", "learndash")); ?>';
                        color = '#20c997';
                        break;
                }

                passwordStrengthDiv.textContent = '<?php echo esc_js(__("Password Strength: ", "learndash")); ?>' + message;
                passwordStrengthDiv.style.color = color;
            });
        }
    }

    // Enhanced external courses list reload
    function reloadExternalCourses() {
        const coursesListElement = document.getElementById('ldExternalCoursesList');
        if (!coursesListElement) return;
        
        const currentUrl = new URL(window.location.href);
        
        fetch(currentUrl.href, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'external-courses'
            },
            credentials: 'same-origin'
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.text();
        })
        .then(html => {
            const match = html.match(/<div id="ldExternalCoursesList"[^>]*>([\s\S]*?)<\/div>/);
            if (match) {
                coursesListElement.innerHTML = match[1];
            }
        })
        .catch(error => {
            console.error('Error reloading external courses:', error);
        });
    }

    // Make function available globally
    window.reloadExternalCourses = reloadExternalCourses;

    // Initialize everything when DOM is loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initializeEventHandlers();
            reloadExternalCourses();
        });
    } else {
        initializeEventHandlers();
        reloadExternalCourses();
    }

})();
</script>

<?php
// Enhanced external courses list AJAX request handler
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'external-courses') {
    show_external_courses_list();
    exit;
}

if (!function_exists('show_external_courses_list')) {
    function show_external_courses_list() {
        global $wpdb;
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            echo '<div style="padding:24px 0;color:#dc3545;">' . esc_html__('Invalid user session.', 'learndash') . '</div>';
            return;
        }
        
        $table = $wpdb->prefix . 'external_courses';
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table
        )) === $table;
        
        if (!$table_exists) {
            echo '<div style="padding:24px 0;color:#888;">' . esc_html__('External courses feature is not available.', 'learndash') . '</div>';
            return;
        }
        
        $courses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY date_submitted DESC",
            $user_id
        ));

        if (!$courses) {
            echo '<div style="padding:24px 0;color:#888;">' . esc_html__('No external courses submitted yet.', 'learndash') . '</div>';
            return;
        }

        echo '<div style="overflow-x:auto;">';
        echo '<table style="width:100%;background:#fff;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,0.07);overflow:hidden;border-collapse:collapse;">';
        echo '<thead><tr style="background:#f8f9fa;">';
        echo '<th style="padding:12px 8px;text-align:left;font-weight:600;">' . esc_html__('Course Name', 'learndash') . '</th>';
        echo '<th style="padding:12px 8px;text-align:left;font-weight:600;">' . esc_html__('Provider', 'learndash') . '</th>';
        echo '<th style="padding:12px 8px;text-align:left;font-weight:600;">' . esc_html__('Instructor', 'learndash') . '</th>';
        echo '<th style="padding:12px 8px;text-align:left;font-weight:600;">' . esc_html__('Time (hrs)', 'learndash') . '</th>';
        echo '<th style="padding:12px 8px;text-align:left;font-weight:600;">' . esc_html__('Certificate', 'learndash') . '</th>';
        echo '<th style="padding:12px 8px;text-align:left;font-weight:600;">' . esc_html__('Status', 'learndash') . '</th>';
        echo '<th style="padding:12px 8px;text-align:left;font-weight:600;">' . esc_html__('Submitted', 'learndash') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($courses as $c) {
            echo '<tr style="border-bottom:1px solid #eee;">';
            echo '<td style="padding:10px 8px;">' . esc_html($c->course_name) . '</td>';
            echo '<td style="padding:10px 8px;">' . esc_html($c->certificate_provider) . '</td>';
            echo '<td style="padding:10px 8px;">' . esc_html($c->instructor) . '</td>';
            echo '<td style="padding:10px 8px;">' . esc_html(number_format($c->course_time, 1)) . '</td>';

            if ($c->certificate_file) {
                echo '<td style="padding:10px 8px;"><a href="' . esc_url($c->certificate_file) . '" target="_blank" rel="noopener" style="color:#667eea;text-decoration:none;">' . esc_html__('View', 'learndash') . '</a></td>';
            } else {
                echo '<td style="padding:10px 8px;color:#aaa;">-</td>';
            }

            $status_color = '';
            $status_label = '';
            
            switch ($c->status) {
                case 'approved':
                    $status_color = '#28a745';
                    $status_label = __('Approved', 'learndash');
                    break;
                case 'rejected':
                    $status_color = '#dc3545';
                    $status_label = __('Rejected', 'learndash');
                    break;
                case 'pending':
                default:
                    $status_color = '#ffc107';
                    $status_label = __('Pending', 'learndash');
                    break;
            }
            
            echo '<td style="padding:10px 8px;"><span style="color:' . esc_attr($status_color) . ';font-weight:600;">' . esc_html($status_label) . '</span></td>';
            echo '<td style="padding:10px 8px;">' . esc_html(date_i18n(get_option('date_format'), strtotime($c->date_submitted))) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
}
?>