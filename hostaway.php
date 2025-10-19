<?php
/**
 * Plugin Name: Hostaway Property Sync
 * Plugin URI: https://example.com
 * Description: Sync properties from Hostaway with search, filtering, and booking functionality
 * Version: 1.0.1
 * Author: Your Name
 * License: GPL2
 */

if (!defined('ABSPATH')) exit;

class Hostaway_Property_Sync {
    
    private $table_name;
    private $amenities_table;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'hostaway_properties';
        $this->amenities_table = $wpdb->prefix . 'hostaway_amenities';
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('wp_ajax_hostaway_sync', array($this, 'ajax_sync_properties'));
        add_action('wp_ajax_hostaway_search', array($this, 'ajax_search_properties'));
        add_action('wp_ajax_nopriv_hostaway_search', array($this, 'ajax_search_properties'));
        add_action('wp_ajax_get_price_details', array($this, 'ajax_get_price_details'));
        add_action('wp_ajax_nopriv_get_price_details', array($this, 'ajax_get_price_details'));
        add_action('wp_ajax_create_booking', array($this, 'ajax_create_booking'));
        add_action('wp_ajax_delete_property', array($this, 'ajax_delete_property'));
        add_action('wp_ajax_hostaway_create_tables', array($this, 'ajax_create_tables'));
        add_action('init', array($this, 'register_shortcodes'));
        add_action('init', array($this, 'register_rewrite_rules'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_filter('template_include', array($this, 'property_template'));
        
        // WooCommerce hooks
        add_action('woocommerce_order_status_completed', array($this, 'sync_booking_to_hostaway'));
        add_action('woocommerce_order_status_processing', array($this, 'sync_booking_to_hostaway'));
        
        // Cron job actions
        add_action('hostaway_sync_availability_prices', array($this, 'sync_availability_prices'));
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function add_cron_schedules($schedules) {
        $schedules['every_10_minutes'] = array(
            'interval' => 600,
            'display' => __('Every 10 Minutes')
        );
        return $schedules;
    }
    
    public function deactivate() {
        wp_clear_scheduled_hook('hostaway_sync_availability_prices');
        flush_rewrite_rules();
    }
    
    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            listing_id varchar(100) NOT NULL,
            slug varchar(255) NOT NULL,
            title varchar(255) NOT NULL,
            description longtext,
            city varchar(100),
            country varchar(100),
            address text,
            latitude decimal(10, 8),
            longitude decimal(11, 8),
            bedrooms int(11),
            bathrooms int(11),
            guests int(11),
            base_price decimal(10, 2),
            images longtext,
            amenities longtext,
            property_type varchar(100),
            check_in_time varchar(50),
            check_out_time varchar(50),
            house_rules text,
            created_at datetime,
            last_synced datetime,
            PRIMARY KEY (id),
            UNIQUE KEY listing_id (listing_id),
            UNIQUE KEY slug (slug),
            KEY city (city)
        ) $charset_collate;";
        
        $sql_amenities = "CREATE TABLE IF NOT EXISTS {$this->amenities_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            amenity_id varchar(100) NOT NULL,
            amenity_name varchar(255) NOT NULL,
            is_active tinyint(1) DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY amenity_id (amenity_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($sql_amenities);
        
        $this->register_rewrite_rules();
        flush_rewrite_rules();
        
        if (!wp_next_scheduled('hostaway_sync_availability_prices')) {
            wp_schedule_event(time(), 'every_10_minutes', 'hostaway_sync_availability_prices');
        }
    }
    
    public function register_rewrite_rules() {
        add_rewrite_rule('^properties/([^/]+)/?$', 'index.php?property_slug=$matches[1]', 'top');
        add_rewrite_rule('^properties/?$', 'index.php?properties_page=1', 'top');
        add_rewrite_rule('^search/?$', 'index.php?hostaway_search=1', 'top');
    }
    
    public function add_query_vars($vars) {
        $vars[] = 'property_slug';
        $vars[] = 'properties_page';
        $vars[] = 'hostaway_search';
        return $vars;
    }
    
    public function property_template($template) {
        if (get_query_var('property_slug')) {
            $custom_template = plugin_dir_path(__FILE__) . 'templates/single-property.php';
            if (file_exists($custom_template)) {
                return $custom_template;
            }
        }
        if (get_query_var('properties_page')) {
            $custom_template = plugin_dir_path(__FILE__) . 'templates/properties-page.php';
            if (file_exists($custom_template)) {
                return $custom_template;
            }
        }
        return $template;
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Hostaway Settings',
            'Hostaway Sync',
            'manage_options',
            'hostaway-settings',
            array($this, 'settings_page'),
            'dashicons-update',
            30
        );
        
        add_submenu_page(
            'hostaway-settings',
            'All Properties',
            'All Properties',
            'manage_options',
            'hostaway-properties',
            array($this, 'properties_page')
        );
        
        add_submenu_page(
            'hostaway-settings',
            'Settings',
            'Settings',
            'manage_options',
            'hostaway-settings'
        );
    }
    
    public function properties_page() {
        global $wpdb;
        
        // Handle bulk actions
        if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete' && isset($_POST['property_ids'])) {
            check_admin_referer('bulk_delete_properties');
            $property_ids = array_map('intval', $_POST['property_ids']);
            $placeholders = implode(',', array_fill(0, count($property_ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_name} WHERE id IN ($placeholders)", $property_ids));
            echo '<div class="notice notice-success"><p>Selected properties deleted successfully.</p></div>';
        }
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Search
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $where = '';
        if ($search) {
            $where = $wpdb->prepare(" WHERE title LIKE %s OR city LIKE %s OR listing_id LIKE %s", 
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }
        
        $total_properties = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}" . $where);
        $total_pages = ceil($total_properties / $per_page);
        
        $properties = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}" . $where . " ORDER BY id DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">All Properties</h1>
            <a href="<?php echo admin_url('admin.php?page=hostaway-settings'); ?>" class="page-title-action">Sync Properties</a>
            <hr class="wp-header-end">
            
            <?php if ($search): ?>
                <div class="notice notice-info">
                    <p>Searching for: <strong><?php echo esc_html($search); ?></strong> - 
                    <a href="<?php echo admin_url('admin.php?page=hostaway-properties'); ?>">Clear search</a></p>
                </div>
            <?php endif; ?>
            
            <form method="get" style="margin: 20px 0;">
                <input type="hidden" name="page" value="hostaway-properties">
                <p class="search-box">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search properties...">
                    <button type="submit" class="button">Search</button>
                </p>
            </form>
            
            <form method="post">
                <?php wp_nonce_field('bulk_delete_properties'); ?>
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="action">
                            <option value="">Bulk Actions</option>
                            <option value="bulk_delete">Delete</option>
                        </select>
                        <button type="submit" class="button action">Apply</button>
                    </div>
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo $total_properties; ?> items</span>
                        <?php if ($total_pages > 1): ?>
                            <?php
                            $base_url = admin_url('admin.php?page=hostaway-properties');
                            if ($search) $base_url .= '&s=' . urlencode($search);
                            ?>
                            <span class="pagination-links">
                                <?php if ($current_page > 1): ?>
                                    <a class="button" href="<?php echo $base_url . '&paged=1'; ?>">«</a>
                                    <a class="button" href="<?php echo $base_url . '&paged=' . ($current_page - 1); ?>">‹</a>
                                <?php endif; ?>
                                <span class="paging-input">
                                    Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
                                </span>
                                <?php if ($current_page < $total_pages): ?>
                                    <a class="button" href="<?php echo $base_url . '&paged=' . ($current_page + 1); ?>">›</a>
                                    <a class="button" href="<?php echo $base_url . '&paged=' . $total_pages; ?>">»</a>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="select-all">
                            </td>
                            <th>Image</th>
                            <th>Title</th>
                            <th>Listing ID</th>
                            <th>Location</th>
                            <th>Details</th>
                            <th>Price</th>
                            <th>Last Synced</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($properties)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 40px;">
                                    <p><strong>No properties found.</strong></p>
                                    <p><a href="<?php echo admin_url('admin.php?page=hostaway-settings'); ?>" class="button button-primary">Sync Properties Now</a></p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($properties as $property): ?>
                                <?php
                                $images = json_decode($property->images, true);
                                $first_image = !empty($images) ? $images[0] : 'https://via.placeholder.com/80x60';
                                ?>
                                <tr>
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" name="property_ids[]" value="<?php echo $property->id; ?>">
                                    </th>
                                    <td>
                                        <img src="<?php echo esc_url($first_image); ?>" alt="<?php echo esc_attr($property->title); ?>" style="width: 80px; height: 60px; object-fit: cover; border-radius: 4px;">
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($property->title); ?></strong><br>
                                        <small><a href="/properties/<?php echo esc_attr($property->slug); ?>" target="_blank">View on Site</a></small>
                                    </td>
                                    <td><code><?php echo esc_html($property->listing_id); ?></code></td>
                                    <td>
                                        <?php echo esc_html($property->city); ?><br>
                                        <small><?php echo esc_html($property->country); ?></small>
                                    </td>
                                    <td>
                                        🛏️ <?php echo $property->bedrooms; ?> beds<br>
                                        🚿 <?php echo $property->bathrooms; ?> baths<br>
                                        👥 <?php echo $property->guests; ?> guests
                                    </td>
                                    <td>
                                        <strong>$<?php echo number_format($property->base_price, 0); ?></strong><br>
                                        <small>per night</small>
                                    </td>
                                    <td>
                                        <?php echo $property->last_synced ? date('M j, Y', strtotime($property->last_synced)) : 'Never'; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="button button-small delete-property" data-id="<?php echo $property->id; ?>" data-title="<?php echo esc_attr($property->title); ?>">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Select all checkbox
            $('#select-all').on('change', function() {
                $('input[name="property_ids[]"]').prop('checked', this.checked);
            });
            
            // Delete single property
            $('.delete-property').on('click', function() {
                var propertyId = $(this).data('id');
                var propertyTitle = $(this).data('title');
                var row = $(this).closest('tr');
                
                if (!confirm('Are you sure you want to delete "' + propertyTitle + '"?')) {
                    return;
                }
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'delete_property',
                        property_id: propertyId,
                        nonce: '<?php echo wp_create_nonce('delete_property'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            row.fadeOut(function() {
                                $(this).remove();
                            });
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('Error deleting property');
                    }
                });
            });
        });
        </script>
        
        <style>
        .wp-list-table img {
            display: block;
        }
        .wp-list-table td, .wp-list-table th {
            vertical-align: middle;
        }
        .check-column {
            width: 2.2em;
        }
        </style>
        <?php
    }
    
    public function ajax_delete_property() {
        check_ajax_referer('delete_property', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $property_id = isset($_POST['property_id']) ? intval($_POST['property_id']) : 0;
        
        if (!$property_id) {
            wp_send_json_error(array('message' => 'Invalid property ID'));
        }
        
        global $wpdb;
        $result = $wpdb->delete($this->table_name, array('id' => $property_id), array('%d'));
        
        if ($result) {
            wp_send_json_success(array('message' => 'Property deleted successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to delete property'));
        }
    }
    
    public function register_settings() {
        register_setting('hostaway_settings', 'hostaway_account_id');
        register_setting('hostaway_settings', 'hostaway_api_key');
        register_setting('hostaway_settings', 'hostaway_google_maps_key');
    }
    
    public function settings_page() {
        global $wpdb;
        $property_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $amenities = $wpdb->get_results("SELECT * FROM {$this->amenities_table}");
        ?>
        <div class="wrap">
            <h1>Hostaway Property Sync Settings</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('hostaway_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Account ID</th>
                        <td>
                            <input type="text" name="hostaway_account_id" 
                                   value="<?php echo esc_attr(get_option('hostaway_account_id')); ?>" 
                                   class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="text" name="hostaway_api_key" 
                                   value="<?php echo esc_attr(get_option('hostaway_api_key')); ?>" 
                                   class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Google Maps API Key</th>
                        <td>
                            <input type="text" name="hostaway_google_maps_key" 
                                   value="<?php echo esc_attr(get_option('hostaway_google_maps_key')); ?>" 
                                   class="regular-text" />
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2>Sync Properties</h2>
            <p>Total Properties: <strong><?php echo $property_count; ?></strong></p>
            <button id="sync-properties" class="button button-primary">Sync Now</button>
            <button id="create-tables" class="button button-secondary" style="margin-left: 10px;">Create Tables</button>
            <div id="sync-status" style="margin-top: 10px;"></div>
            
            <hr>
            
            <h2>Manage Amenities</h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="update_amenities">
                <?php wp_nonce_field('update_amenities_action', 'amenities_nonce'); ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Show in Filter</th>
                            <th>Amenity Name</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($amenities): ?>
                            <?php foreach ($amenities as $amenity): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" 
                                               name="amenities[<?php echo esc_attr($amenity->amenity_id); ?>]" 
                                               value="1" 
                                               <?php checked($amenity->is_active, 1); ?> />
                                    </td>
                                    <td><?php echo esc_html($amenity->amenity_name); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="2">No amenities found. Please sync properties first.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php if ($amenities): ?>
                    <?php submit_button('Update Amenities'); ?>
                <?php endif; ?>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#sync-properties').on('click', function() {
                var button = $(this);
                var status = $('#sync-status');
                
                button.prop('disabled', true).text('Syncing...');
                status.html('<p>Syncing properties, please wait...</p>');
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'hostaway_sync'
                    },
                    success: function(response) {
                        if (response.success) {
                            status.html('<p style="color: green;">✓ ' + response.data.message + '</p>');
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            status.html('<p style="color: red;">✗ Error: ' + response.data.message + '</p>');
                            if (response.data.debug) {
                                status.append('<details style="margin-top: 10px;"><summary>Debug Info</summary><pre>' + response.data.debug + '</pre></details>');
                            }
                        }
                        button.prop('disabled', false).text('Sync Now');
                    },
                    error: function(xhr, status, error) {
                        var errorMsg = 'Error syncing properties. ';
                        if (xhr.responseJSON && xhr.responseJSON.data) {
                            errorMsg += xhr.responseJSON.data.message;
                        } else {
                            errorMsg += error;
                        }
                        $('#sync-status').html('<p style="color: red;">✗ ' + errorMsg + '</p>');
                        button.prop('disabled', false).text('Sync Now');
                    }
                });
            });
            
            $('#create-tables').on('click', function() {
                var button = $(this);
                var status = $('#sync-status');
                
                button.prop('disabled', true).text('Creating...');
                status.html('<p>Creating database tables, please wait...</p>');
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'hostaway_create_tables'
                    },
                    success: function(response) {
                        if (response.success) {
                            status.html('<p style="color: green;">✓ ' + response.data.message + '</p>');
                        } else {
                            status.html('<p style="color: red;">✗ Error: ' + response.data.message + '</p>');
                        }
                        button.prop('disabled', false).text('Create Tables');
                    },
                    error: function(xhr, status, error) {
                        $('#sync-status').html('<p style="color: red;">✗ Error creating tables: ' + error + '</p>');
                        button.prop('disabled', false).text('Create Tables');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    // ... (rest of the methods remain the same as in the original file)
    // I'll include the key AJAX methods below
    
    public function ajax_sync_properties() {
        // Ensure we're in an AJAX context
        if (!wp_doing_ajax()) {
            wp_die('This method can only be called via AJAX');
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access'));
        }
        
        try {
            $account_id = get_option('hostaway_account_id');
            $api_key = get_option('hostaway_api_key');
            
            if (!$account_id || !$api_key) {
                wp_send_json_error(array('message' => 'Please configure Account ID and API Key first.'));
            }
            
            $access_token = $this->get_access_token($account_id, $api_key);
            
            if (!$access_token) {
                wp_send_json_error(array('message' => 'Failed to authenticate with Hostaway. Please check your credentials.'));
            }
            
            $listings = $this->fetch_listings($access_token);
            
            if (!$listings || empty($listings)) {
                wp_send_json_error(array('message' => 'No listings found. Please check your Hostaway account has properties.'));
            }
            
            global $wpdb;
            $synced = 0;
            $all_amenities = array();
            $errors = array();
            
            foreach ($listings as $listing) {
                try {
                    $title = $listing->name ?? $listing->title ?? 'Untitled Property';
                    $slug = sanitize_title($title);
                    
                    $original_slug = $slug;
                    $counter = 1;
                    while ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table_name} WHERE slug = %s AND listing_id != %s", $slug, $listing->id))) {
                        $slug = $original_slug . '-' . $counter;
                        $counter++;
                    }
                    
                    $images = array();
                    if (isset($listing->photos) && is_array($listing->photos)) {
                        foreach ($listing->photos as $photo) {
                            if (is_object($photo) && isset($photo->url)) {
                                $images[] = $photo->url;
                            } elseif (is_string($photo)) {
                                $images[] = $photo;
                            }
                        }
                    } elseif (isset($listing->images) && is_array($listing->images)) {
                        foreach ($listing->images as $image) {
                            if (is_object($image) && isset($image->url)) {
                                $images[] = $image->url;
                            } elseif (is_string($image)) {
                                $images[] = $image;
                            }
                        }
                    }
                    
                    $amenities = array();
                    if (isset($listing->amenities) && is_array($listing->amenities)) {
                        foreach ($listing->amenities as $amenity) {
                            if (is_object($amenity)) {
                                $amenity_name = $amenity->name ?? $amenity->title ?? '';
                                $amenity_id = $amenity->id ?? sanitize_title($amenity_name);
                            } else {
                                $amenity_name = $amenity;
                                $amenity_id = sanitize_title($amenity);
                            }
                            
                            if ($amenity_name) {
                                $amenities[] = $amenity_name;
                                $all_amenities[$amenity_id] = $amenity_name;
                            }
                        }
                    }
                    
                    $property_data = array(
                        'listing_id' => $listing->id,
                        'slug' => $slug,
                        'title' => $title,
                        'description' => $listing->description ?? $listing->summary ?? '',
                        'city' => $listing->city ?? '',
                        'country' => $listing->country ?? $listing->countryCode ?? '',
                        'address' => $listing->address ?? ($listing->street ?? ''),
                        'latitude' => $listing->latitude ?? $listing->lat ?? 0,
                        'longitude' => $listing->longitude ?? $listing->lng ?? $listing->lon ?? 0,
                        'bedrooms' => $listing->bedrooms ?? $listing->bedroomCount ?? 0,
                        'bathrooms' => $listing->bathrooms ?? $listing->bathroomCount ?? 0,
                        'guests' => $listing->accommodates ?? $listing->maxGuests ?? $listing->guests ?? 0,
                        'base_price' => $listing->price ?? $listing->basePrice ?? $listing->nightlyPrice ?? 0,
                        'images' => json_encode($images),
                        'amenities' => json_encode($amenities),
                        'property_type' => $listing->propertyType ?? $listing->type ?? '',
                        'check_in_time' => $listing->checkInTime ?? $listing->checkIn ?? '15:00',
                        'check_out_time' => $listing->checkOutTime ?? $listing->checkOut ?? '11:00',
                        'house_rules' => $listing->houseRules ?? $listing->rules ?? '',
                        'created_at' => current_time('mysql'),
                        'last_synced' => current_time('mysql')
                    );
                    
                    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table_name} WHERE listing_id = %s", $listing->id));
                    
                    if ($exists) {
                        unset($property_data['created_at']);
                        $result = $wpdb->update($this->table_name, $property_data, array('listing_id' => $listing->id));
                        if ($result === false) {
                            $errors[] = "Failed to update property: {$title}";
                        }
                    } else {
                        $result = $wpdb->insert($this->table_name, $property_data);
                        if ($result === false) {
                            $errors[] = "Failed to insert property: {$title}";
                        }
                    }
                    
                    $synced++;
                } catch (Exception $e) {
                    $errors[] = "Error processing property {$title}: " . $e->getMessage();
                }
            }
            
            // Sync amenities
            foreach ($all_amenities as $amenity_id => $amenity_name) {
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$this->amenities_table} (amenity_id, amenity_name, is_active) 
                     VALUES (%s, %s, 1) 
                     ON DUPLICATE KEY UPDATE amenity_name = %s",
                    $amenity_id, $amenity_name, $amenity_name
                ));
            }
            
            $message = "Successfully synced {$synced} properties and " . count($all_amenities) . " amenities.";
            if (!empty($errors)) {
                $message .= " Errors: " . implode(', ', $errors);
            }
            
            wp_send_json_success(array(
                'message' => $message,
                'properties' => $synced,
                'amenities' => count($all_amenities),
                'errors' => $errors
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Sync failed: ' . $e->getMessage(),
                'debug' => $e->getTraceAsString()
            ));
        }
    }
    
    private function get_access_token($account_id, $api_key) {
        $test_response = wp_remote_get('https://api.hostaway.com/v1/listings?limit=1', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (!is_wp_error($test_response) && wp_remote_retrieve_response_code($test_response) === 200) {
            return $api_key;
        }
        
        $response = wp_remote_post('https://api.hostaway.com/v1/accessTokens', array(
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Cache-Control' => 'no-cache'
            ),
            'body' => array(
                'grant_type' => 'client_credentials',
                'client_id' => $account_id,
                'client_secret' => $api_key,
                'scope' => 'general'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response));
        
        if ($response_code === 200 && isset($body->access_token)) {
            return $body->access_token;
        }
        
        return false;
    }
    
    private function fetch_listings($access_token) {
        $headers = array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $access_token
        );
        
        $response = wp_remote_get('https://api.hostaway.com/v1/listings', array(
            'headers' => $headers,
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response));
        
        if (isset($body->result)) {
            return $body->result;
        } elseif (isset($body->data)) {
            return $body->data;
        } elseif (is_array($body)) {
            return $body;
        }
        
        return false;
    }
    
    public function ajax_search_properties() {
        global $wpdb;
        
        $location = isset($_POST['location']) ? sanitize_text_field($_POST['location']) : '';
        $check_in = isset($_POST['check_in']) ? sanitize_text_field($_POST['check_in']) : '';
        $check_out = isset($_POST['check_out']) ? sanitize_text_field($_POST['check_out']) : '';
        $adults = isset($_POST['adults']) ? intval($_POST['adults']) : 1;
        $children = isset($_POST['children']) ? intval($_POST['children']) : 0;
        $amenities = isset($_POST['amenities']) ? $_POST['amenities'] : array();
        
        $total_guests = $adults + $children;
        
        $query = "SELECT * FROM {$this->table_name} WHERE 1=1";
        
        if ($location) {
            $query .= $wpdb->prepare(" AND (city LIKE %s OR country LIKE %s)", 
                '%' . $wpdb->esc_like($location) . '%',
                '%' . $wpdb->esc_like($location) . '%'
            );
        }
        
        if ($total_guests > 0) {
            $query .= $wpdb->prepare(" AND guests >= %d", $total_guests);
        }
        
        if (!empty($amenities) && is_array($amenities)) {
            foreach ($amenities as $amenity) {
                $query .= $wpdb->prepare(" AND amenities LIKE %s", '%' . $wpdb->esc_like($amenity) . '%');
            }
        }
        
        $query .= " ORDER BY title ASC";
        
        $properties = $wpdb->get_results($query);
        
        foreach ($properties as &$property) {
            $property->images = json_decode($property->images);
            $property->amenities = json_decode($property->amenities);
            
            if (!is_array($property->images)) $property->images = array();
            if (!is_array($property->amenities)) $property->amenities = array();
        }
        
        wp_send_json_success(array(
            'properties' => $properties,
            'count' => count($properties)
        ));
    }
    
    public function ajax_get_price_details() {
        $listing_id = isset($_POST['listing_id']) ? sanitize_text_field($_POST['listing_id']) : '';
        $check_in = isset($_POST['check_in']) ? sanitize_text_field($_POST['check_in']) : '';
        $check_out = isset($_POST['check_out']) ? sanitize_text_field($_POST['check_out']) : '';
        
        if (!$listing_id || !$check_in || !$check_out) {
            wp_send_json_error(array('message' => 'Missing parameters'));
        }
        
        $account_id = get_option('hostaway_account_id');
        $api_key = get_option('hostaway_api_key');
        $access_token = $this->get_access_token($account_id, $api_key);
        
        if (!$access_token) {
            wp_send_json_error(array('message' => 'Authentication failed'));
        }
        
        $url = "https://api.hostaway.com/v1/listings/{$listing_id}/calendar/priceDetails?checkIn={$check_in}&checkOut={$check_out}";
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Failed to fetch price'));
        }
        
        $body = json_decode(wp_remote_retrieve_body($response));
        
        if (isset($body->result->totalPrice)) {
            wp_send_json_success(array('total_price' => $body->result->totalPrice));
        } elseif (isset($body->totalPrice)) {
            wp_send_json_success(array('total_price' => $body->totalPrice));
        } else {
            wp_send_json_error(array('message' => 'Price not available'));
        }
    }
    
    public function ajax_create_booking() {
        check_ajax_referer('hostaway_nonce', 'nonce');
        
        $listing_id = isset($_POST['listing_id']) ? sanitize_text_field($_POST['listing_id']) : '';
        $check_in = isset($_POST['check_in']) ? sanitize_text_field($_POST['check_in']) : '';
        $check_out = isset($_POST['check_out']) ? sanitize_text_field($_POST['check_out']) : '';
        $guests = isset($_POST['guests']) ? intval($_POST['guests']) : 1;
        $guest_name = isset($_POST['guest_name']) ? sanitize_text_field($_POST['guest_name']) : '';
        $guest_email = isset($_POST['guest_email']) ? sanitize_email($_POST['guest_email']) : '';
        $guest_phone = isset($_POST['guest_phone']) ? sanitize_text_field($_POST['guest_phone']) : '';
        
        if (!$listing_id || !$check_in || !$check_out || !$guest_name || !$guest_email) {
            wp_send_json_error(array('message' => 'Missing required fields'));
        }
        
        // Get property details
        global $wpdb;
        $property = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE listing_id = %s",
            $listing_id
        ));
        
        if (!$property) {
            wp_send_json_error(array('message' => 'Property not found'));
        }
        
        // Calculate total price
        $account_id = get_option('hostaway_account_id');
        $api_key = get_option('hostaway_api_key');
        $access_token = $this->get_access_token($account_id, $api_key);
        
        $total_price = $property->base_price;
        if ($access_token) {
            $price_response = $this->get_price_details_api($listing_id, $check_in, $check_out, $access_token);
            if ($price_response && isset($price_response['total_price'])) {
                $total_price = $price_response['total_price'];
            }
        }
        
        // Create WooCommerce order if WooCommerce is active
        if (class_exists('WooCommerce')) {
            $order_id = $this->create_woocommerce_order($property, $guest_name, $guest_email, $guest_phone, $check_in, $check_out, $guests, $total_price);
            
            if ($order_id) {
                wp_send_json_success(array(
                    'message' => 'Booking created successfully',
                    'order_id' => $order_id,
                    'payment_url' => wc_get_order($order_id)->get_checkout_payment_url()
                ));
                return;
            }
        }
        
        // Fallback to direct Hostaway API booking
        $account_id = get_option('hostaway_account_id');
        $api_key = get_option('hostaway_api_key');
        $access_token = $this->get_access_token($account_id, $api_key);
        
        if (!$access_token) {
            wp_send_json_error(array('message' => 'Authentication failed'));
        }
        
        $reservation_data = array(
            'listingMapId' => intval($listing_id),
            'channelId' => 2000,
            'source' => 'website',
            'arrivalDate' => $check_in,
            'departureDate' => $check_out,
            'guestName' => $guest_name,
            'guestEmail' => $guest_email,
            'phone' => $guest_phone,
            'numberOfGuests' => $guests,
            'status' => 'new'
        );
        
        $response = wp_remote_post('https://api.hostaway.com/v1/reservations', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($reservation_data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Failed to create booking'));
        }
        
        $body = json_decode(wp_remote_retrieve_body($response));
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200 || $response_code === 201) {
            $reservation_id = isset($body->result->id) ? $body->result->id : (isset($body->id) ? $body->id : null);
            
            wp_send_json_success(array(
                'message' => 'Booking created successfully',
                'reservation_id' => $reservation_id
            ));
        } else {
            $error_message = isset($body->message) ? $body->message : 'Failed to create booking';
            wp_send_json_error(array('message' => $error_message));
        }
    }
    
    public function sync_availability_prices() {
        // Placeholder for cron job
    }
    
    private function get_price_details_api($listing_id, $check_in, $check_out, $access_token) {
        $url = "https://api.hostaway.com/v1/listings/{$listing_id}/calendar/priceDetails?checkIn={$check_in}&checkOut={$check_out}";
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response));
        
        if (isset($body->result->totalPrice)) {
            return array('total_price' => $body->result->totalPrice);
        } elseif (isset($body->totalPrice)) {
            return array('total_price' => $body->totalPrice);
        }
        
        return false;
    }
    
    private function create_woocommerce_order($property, $guest_name, $guest_email, $guest_phone, $check_in, $check_out, $guests, $total_price) {
        if (!class_exists('WooCommerce')) {
            return false;
        }
        
        // Create order
        $order = wc_create_order();
        
        if (!$order) {
            return false;
        }
        
        // Add customer details
        $order->set_billing_first_name($guest_name);
        $order->set_billing_email($guest_email);
        $order->set_billing_phone($guest_phone);
        
        // Create product for the booking
        $product = new WC_Product_Simple();
        $product->set_name($property->title . ' - Booking');
        $product->set_price($total_price);
        $product->set_regular_price($total_price);
        $product->set_status('publish');
        $product->save();
        
        // Add product to order
        $order->add_product($product, 1);
        
        // Add booking details as meta
        $order->add_meta_data('_hostaway_listing_id', $property->listing_id);
        $order->add_meta_data('_hostaway_check_in', $check_in);
        $order->add_meta_data('_hostaway_check_out', $check_out);
        $order->add_meta_data('_hostaway_guests', $guests);
        $order->add_meta_data('_hostaway_property_title', $property->title);
        
        // Calculate totals
        $order->calculate_totals();
        $order->save();
        
        return $order->get_id();
    }
    
    public function sync_booking_to_hostaway($order_id) {
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $listing_id = $order->get_meta('_hostaway_listing_id');
        $check_in = $order->get_meta('_hostaway_check_in');
        $check_out = $order->get_meta('_hostaway_check_out');
        $guests = $order->get_meta('_hostaway_guests');
        
        if (!$listing_id || !$check_in || !$check_out) {
            return;
        }
        
        // Check if already synced
        if ($order->get_meta('_hostaway_synced')) {
            return;
        }
        
        $account_id = get_option('hostaway_account_id');
        $api_key = get_option('hostaway_api_key');
        $access_token = $this->get_access_token($account_id, $api_key);
        
        if (!$access_token) {
            return;
        }
        
        $reservation_data = array(
            'listingMapId' => intval($listing_id),
            'channelId' => 2000,
            'source' => 'website',
            'arrivalDate' => $check_in,
            'departureDate' => $check_out,
            'guestName' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'guestEmail' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'numberOfGuests' => $guests,
            'status' => 'confirmed'
        );
        
        $response = wp_remote_post('https://api.hostaway.com/v1/reservations', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($reservation_data),
            'timeout' => 30
        ));
        
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response));
            $response_code = wp_remote_retrieve_response_code($response);
            
            if ($response_code === 200 || $response_code === 201) {
                $reservation_id = isset($body->result->id) ? $body->result->id : (isset($body->id) ? $body->id : null);
                $order->add_meta_data('_hostaway_reservation_id', $reservation_id);
                $order->add_meta_data('_hostaway_synced', true);
                $order->save();
            }
        }
    }
    
    public function ajax_create_tables() {
        if (!wp_doing_ajax()) {
            wp_die('This method can only be called via AJAX');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access'));
        }
        
        try {
            global $wpdb;
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                listing_id varchar(100) NOT NULL,
                slug varchar(255) NOT NULL,
                title varchar(255) NOT NULL,
                description longtext,
                city varchar(100),
                country varchar(100),
                address text,
                latitude decimal(10, 8),
                longitude decimal(11, 8),
                bedrooms int(11),
                bathrooms int(11),
                guests int(11),
                base_price decimal(10, 2),
                images longtext,
                amenities longtext,
                property_type varchar(100),
                check_in_time varchar(50),
                check_out_time varchar(50),
                house_rules text,
                created_at datetime,
                last_synced datetime,
                PRIMARY KEY (id),
                UNIQUE KEY listing_id (listing_id),
                UNIQUE KEY slug (slug),
                KEY city (city)
            ) $charset_collate;";
            
            $sql_amenities = "CREATE TABLE IF NOT EXISTS {$this->amenities_table} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                amenity_id varchar(100) NOT NULL,
                amenity_name varchar(255) NOT NULL,
                is_active tinyint(1) DEFAULT 1,
                PRIMARY KEY (id),
                UNIQUE KEY amenity_id (amenity_id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            $result1 = dbDelta($sql);
            $result2 = dbDelta($sql_amenities);
            
            wp_send_json_success(array(
                'message' => 'Database tables created successfully!',
                'properties_table' => $result1,
                'amenities_table' => $result2
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Failed to create tables: ' . $e->getMessage()
            ));
        }
    }
    
    public function register_shortcodes() {
        add_shortcode('hostaway_search', array($this, 'search_shortcode'));
        add_shortcode('hostaway_properties', array($this, 'properties_shortcode'));
        add_shortcode('hostaway_debug', array($this, 'debug_shortcode'));
    }
    
    public function search_shortcode() {
        global $wpdb;
        $cities = $wpdb->get_col("SELECT DISTINCT city FROM {$this->table_name} WHERE city != ''");
        
        ob_start();
        include plugin_dir_path(__FILE__) . 'templates/search-form.php';
        return ob_get_clean();
    }
    
    public function properties_shortcode() {
        global $wpdb;
        $amenities = $wpdb->get_results("SELECT * FROM {$this->amenities_table} WHERE is_active = 1");
        
        ob_start();
        include plugin_dir_path(__FILE__) . 'templates/properties-shortcode.php';
        return ob_get_clean();
    }
    
    public function debug_shortcode() {
        if (!current_user_can('manage_options')) {
            return '<p>Access denied. Admin only.</p>';
        }
        
        global $wpdb;
        $property_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $amenity_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->amenities_table}");
        
        ob_start();
        echo '<div style="background: #f5f5f5; padding: 20px; margin: 20px 0; border: 1px solid #ddd;">';
        echo '<h2>Hostaway Plugin Debug Information</h2>';
        echo '<p><strong>Plugin Version:</strong> 1.0.1</p>';
        echo '<p><strong>WordPress Version:</strong> ' . get_bloginfo('version') . '</p>';
        echo '<p><strong>PHP Version:</strong> ' . PHP_VERSION . '</p>';
        echo '<p><strong>Total Properties:</strong> ' . $property_count . '</p>';
        echo '<p><strong>Total Amenities:</strong> ' . $amenity_count . '</p>';
        echo '<p><strong>Account ID:</strong> ' . (get_option('hostaway_account_id') ? 'Set (' . strlen(get_option('hostaway_account_id')) . ' chars)' : 'Not Set') . '</p>';
        echo '<p><strong>API Key:</strong> ' . (get_option('hostaway_api_key') ? 'Set (' . strlen(get_option('hostaway_api_key')) . ' chars)' : 'Not Set') . '</p>';
        echo '<p><strong>Google Maps Key:</strong> ' . (get_option('hostaway_google_maps_key') ? 'Set (' . strlen(get_option('hostaway_google_maps_key')) . ' chars)' : 'Not Set') . '</p>';
        
        // Test API connection
        if (get_option('hostaway_account_id') && get_option('hostaway_api_key')) {
            echo '<h3>API Connection Test</h3>';
            $access_token = $this->get_access_token(get_option('hostaway_account_id'), get_option('hostaway_api_key'));
            if ($access_token) {
                echo '<p style="color: green;">✓ API connection successful</p>';
                $listings = $this->fetch_listings($access_token);
                if ($listings && is_array($listings)) {
                    echo '<p style="color: green;">✓ Found ' . count($listings) . ' listings from API</p>';
                } else {
                    echo '<p style="color: red;">✗ No listings returned from API</p>';
                }
            } else {
                echo '<p style="color: red;">✗ API connection failed</p>';
            }
        }
        
        // Database table check
        echo '<h3>Database Tables</h3>';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        if ($table_exists) {
            echo '<p style="color: green;">✓ Properties table exists</p>';
        } else {
            echo '<p style="color: red;">✗ Properties table missing</p>';
        }
        
        $amenities_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->amenities_table}'");
        if ($amenities_table_exists) {
            echo '<p style="color: green;">✓ Amenities table exists</p>';
        } else {
            echo '<p style="color: red;">✗ Amenities table missing</p>';
        }
        
        echo '</div>';
        return ob_get_clean();
    }
    
    public function enqueue_scripts() {
        wp_enqueue_style('hostaway-styles', plugin_dir_url(__FILE__) . 'assets/style.css', array(), '1.0.1');
        wp_enqueue_script('hostaway-datepicker', 'https://cdn.jsdelivr.net/npm/flatpickr', array(), '1.0.0', true);
        wp_enqueue_style('hostaway-datepicker-css', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css');
        
        $google_maps_key = get_option('hostaway_google_maps_key');
        if ($google_maps_key) {
            wp_enqueue_script('google-maps', "https://maps.googleapis.com/maps/api/js?key={$google_maps_key}", array(), null, true);
        }
        
        wp_enqueue_script('hostaway-script', plugin_dir_url(__FILE__) . 'assets/script.js', array('jquery', 'hostaway-datepicker'), '1.0.1', true);
        
        wp_localize_script('hostaway-script', 'hostawayData', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hostaway_nonce')
        ));
    }
    
    public function admin_enqueue_scripts($hook) {
        wp_enqueue_script('jquery');
    }
}

new Hostaway_Property_Sync();

add_action('admin_post_update_amenities', function() {
    if (!isset($_POST['amenities_nonce']) || !wp_verify_nonce($_POST['amenities_nonce'], 'update_amenities_action')) {
        wp_die('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'hostaway_amenities';
    
    $wpdb->update($table_name, array('is_active' => 0), array(), array('%d'));
    
    if (isset($_POST['amenities']) && is_array($_POST['amenities'])) {
        foreach ($_POST['amenities'] as $amenity_id => $value) {
            $wpdb->update(
                $table_name,
                array('is_active' => 1),
                array('amenity_id' => $amenity_id),
                array('%d'),
                array('%s')
            );
        }
    }
    
    wp_redirect(admin_url('admin.php?page=hostaway-settings&updated=true'));
    exit;
});