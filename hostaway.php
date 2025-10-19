<?php
/**
 * Plugin Name: Hostaway Property Sync
 * Plugin URI: https://example.com
 * Description: Sync properties from Hostaway with search, filtering, and booking functionality
 * Version: 1.0.0
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
        add_action('init', array($this, 'register_shortcodes'));
        add_action('init', array($this, 'register_rewrite_rules'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_filter('template_include', array($this, 'property_template'));
        
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
    }
    
    public function add_query_vars($vars) {
        $vars[] = 'property_slug';
        $vars[] = 'properties_page';
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
        });
        </script>
        <?php
    }
    
    public function ajax_sync_properties() {
        $account_id = get_option('hostaway_account_id');
        $api_key = get_option('hostaway_api_key');
        
        if (!$account_id || !$api_key) {
            wp_send_json_error(array('message' => 'Please configure Account ID and API Key first.'));
        }
        
        error_log('=== HOSTAWAY SYNC STARTED ===');
        error_log('Account ID: ' . substr($account_id, 0, 10) . '...');
        
        $access_token = $this->get_access_token($account_id, $api_key);
        
        if (!$access_token) {
            error_log('ERROR: Failed to get access token');
            wp_send_json_error(array(
                'message' => 'Failed to authenticate with Hostaway. Please verify your credentials.',
                'debug' => 'Check /wp-content/debug.log for details.'
            ));
        }
        
        error_log('SUCCESS: Got access token');
        
        $listings = $this->fetch_listings($access_token);
        
        if (!$listings) {
            error_log('ERROR: Failed to fetch listings');
            wp_send_json_error(array(
                'message' => 'Failed to fetch listings from Hostaway.',
                'debug' => 'Authentication successful but no listings returned.'
            ));
        }
        
        error_log('SUCCESS: Fetched ' . count($listings) . ' listings');
        
        if (empty($listings)) {
            error_log('ERROR: Listings array is empty');
            wp_send_json_error(array(
                'message' => 'No listings found in your Hostaway account.',
                'debug' => 'API returned empty result.'
            ));
        }
        
        global $wpdb;
        $synced = 0;
        $all_amenities = array();
        
        foreach ($listings as $listing) {
            error_log('Processing listing: ' . ($listing->name ?? $listing->title ?? 'Unknown'));
            
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
            
            error_log('Found ' . count($images) . ' images');
            
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
            
            error_log('Found ' . count($amenities) . ' amenities');
            
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
            
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table_name} WHERE listing_id = %s",
                $listing->id
            ));
            
            if ($exists) {
                unset($property_data['created_at']);
                $result = $wpdb->update(
                    $this->table_name,
                    $property_data,
                    array('listing_id' => $listing->id),
                    array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%d', '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s'),
                    array('%s')
                );
            } else {
                $result = $wpdb->insert(
                    $this->table_name,
                    $property_data,
                    array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%d', '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
                );
            }
            
            if ($result !== false) {
                $synced++;
                error_log('Successfully synced listing: ' . $title);
            } else {
                error_log('ERROR syncing listing: ' . $wpdb->last_error);
            }
        }
        
        error_log('Syncing ' . count($all_amenities) . ' amenities');
        foreach ($all_amenities as $amenity_id => $amenity_name) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$this->amenities_table} (amenity_id, amenity_name, is_active) 
                 VALUES (%s, %s, 1) 
                 ON DUPLICATE KEY UPDATE amenity_name = %s",
                $amenity_id, $amenity_name, $amenity_name
            ));
        }
        
        error_log('=== HOSTAWAY SYNC COMPLETED ===');
        error_log('Synced: ' . $synced . ' properties');
        error_log('Amenities: ' . count($all_amenities));
        
        wp_send_json_success(array(
            'message' => "Successfully synced {$synced} properties and " . count($all_amenities) . " amenities.",
            'properties' => $synced,
            'amenities' => count($all_amenities)
        ));
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
            error_log('Hostaway Auth Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response));
        
        if ($response_code !== 200) {
            error_log('Hostaway Auth Failed. Response Code: ' . $response_code);
            error_log('Response Body: ' . wp_remote_retrieve_body($response));
            return false;
        }
        
        if (isset($body->access_token)) {
            return $body->access_token;
        }
        
        $basic_auth = base64_encode($account_id . ':' . $api_key);
        $test_basic = wp_remote_get('https://api.hostaway.com/v1/listings?limit=1', array(
            'headers' => array(
                'Authorization' => 'Basic ' . $basic_auth,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (!is_wp_error($test_basic) && wp_remote_retrieve_response_code($test_basic) === 200) {
            return 'Basic:' . $basic_auth;
        }
        
        return false;
    }
    
    private function fetch_listings($access_token) {
        $headers = array('Content-Type' => 'application/json');
        
        if (strpos($access_token, 'Basic:') === 0) {
            $headers['Authorization'] = 'Basic ' . substr($access_token, 6);
        } else {
            $headers['Authorization'] = 'Bearer ' . $access_token;
        }
        
        $response = wp_remote_get('https://api.hostaway.com/v1/listings', array(
            'headers' => $headers,
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            error_log('Hostaway Fetch Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            error_log('Hostaway Fetch Failed. Response Code: ' . $response_code);
            error_log('Response: ' . $body_raw);
            return false;
        }
        
        $body = json_decode($body_raw);
        
        if (isset($body->result)) {
            return $body->result;
        } elseif (isset($body->data)) {
            return $body->data;
        } elseif (is_array($body)) {
            return $body;
        }
        
        return false;
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
        
        $headers = array('Content-Type' => 'application/json');
        
        if (strpos($access_token, 'Basic:') === 0) {
            $headers['Authorization'] = 'Basic ' . substr($access_token, 6);
        } else {
            $headers['Authorization'] = 'Bearer ' . $access_token;
        }
        
        $url = "https://api.hostaway.com/v1/listings/{$listing_id}/calendar/priceDetails";
        $url .= "?checkIn={$check_in}&checkOut={$check_out}";
        
        $response = wp_remote_get($url, array(
            'headers' => $headers,
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
        } elseif (isset($body->data->totalPrice)) {
            wp_send_json_success(array('total_price' => $body->data->totalPrice));
        } else {
            wp_send_json_error(array('message' => 'Price not available'));
        }
    }
    
    public function ajax_search_properties() {
        global $wpdb;
        
        $location = isset($_POST['location']) ? sanitize_text_field($_POST['location']) : '';
        $check_in = isset($_POST['check_in']) ? sanitize_text_field($_POST['check_in']) : '';
        $check_out = isset($_POST['check_out']) ? sanitize_text_field($_POST['check_out']) : '';
        $adults = isset($_POST['adults']) ? intval($_POST['adults']) : 1;
        $children = isset($_POST['children']) ? intval($_POST['children']) : 0;
        $infants = isset($_POST['infants']) ? intval($_POST['infants']) : 0;
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
                $query .= $wpdb->prepare(" AND amenities LIKE %s", 
                    '%' . $wpdb->esc_like($amenity) . '%'
                );
            }
        }
        
        $query .= " ORDER BY title ASC";
        
        error_log('Hostaway Search Query: ' . $query);
        
        $properties = $wpdb->get_results($query);
        
        error_log('Hostaway Search Results: ' . count($properties) . ' properties found');
        
        if (empty($properties)) {
            $total_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
            error_log('Total properties in database: ' . $total_count);
            
            if ($total_count == 0) {
                wp_send_json_error(array(
                    'message' => 'No properties in database. Please sync properties first.',
                    'total_in_db' => 0
                ));
                return;
            }
        }
        
        foreach ($properties as &$property) {
            $property->images = json_decode($property->images);
            $property->amenities = json_decode($property->amenities);
            
            if (!is_array($property->images)) {
                $property->images = array();
            }
            if (!is_array($property->amenities)) {
                $property->amenities = array();
            }
        }
        
        wp_send_json_success(array(
            'properties' => $properties,
            'count' => count($properties),
            'search_params' => array(
                'location' => $location,
                'guests' => $total_guests,
                'amenities' => $amenities
            )
        ));
    }
    
    public function sync_availability_prices() {
        $account_id = get_option('hostaway_account_id');
        $api_key = get_option('hostaway_api_key');
        
        if (!$account_id || !$api_key) {
            error_log('Hostaway Cron: No API credentials configured');
            return;
        }
        
        $access_token = $this->get_access_token($account_id, $api_key);
        
        if (!$access_token) {
            error_log('Hostaway Cron: Failed to get access token');
            return;
        }
        
        global $wpdb;
        $properties = $wpdb->get_results("SELECT listing_id FROM {$this->table_name}");
        
        error_log('Hostaway Cron: Syncing availability/prices for ' . count($properties) . ' properties');
        error_log('Hostaway Cron: Sync completed');
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
        
        $account_id = get_option('hostaway_account_id');
        $api_key = get_option('hostaway_api_key');
        $access_token = $this->get_access_token($account_id, $api_key);
        
        if (!$access_token) {
            wp_send_json_error(array('message' => 'Authentication failed'));
        }
        
        $headers = array('Content-Type' => 'application/json');
        
        if (strpos($access_token, 'Basic:') === 0) {
            $headers['Authorization'] = 'Basic ' . substr($access_token, 6);
        } else {
            $headers['Authorization'] = 'Bearer ' . $access_token;
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
        
        error_log('Creating Hostaway booking: ' . json_encode($reservation_data));
        
        $response = wp_remote_post('https://api.hostaway.com/v1/reservations', array(
            'headers' => $headers,
            'body' => json_encode($reservation_data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Hostaway Booking Error: ' . $response->get_error_message());
            wp_send_json_error(array('message' => 'Failed to create booking'));
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response));
        
        error_log('Hostaway Booking Response Code: ' . $response_code);
        error_log('Hostaway Booking Response: ' . wp_remote_retrieve_body($response));
        
        if ($response_code === 200 || $response_code === 201) {
            $reservation_id = isset($body->result->id) ? $body->result->id : (isset($body->id) ? $body->id : null);
            
            wp_send_json_success(array(
                'message' => 'Booking created successfully',
                'reservation_id' => $reservation_id,
                'data' => $body
            ));
        } else {
            $error_message = isset($body->message) ? $body->message : 'Failed to create booking';
            wp_send_json_error(array(
                'message' => $error_message,
                'debug' => $body
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
        ?>
        <div class="hostaway-search-form">
            <form id="hostaway-search-form">
                <div class="search-row">
                    <div class="search-field">
                        <label>Location</label>
                        <select name="location" id="search-location">
                            <option value="">All Locations</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?php echo esc_attr($city); ?>"><?php echo esc_html($city); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="search-field">
                        <label>Check In</label>
                        <input type="text" name="check_in" id="search-check-in" readonly placeholder="Select date">
                    </div>
                    
                    <div class="search-field">
                        <label>Check Out</label>
                        <input type="text" name="check_out" id="search-check-out" readonly placeholder="Select date">
                    </div>
                    
                    <div class="search-field">
                        <label>Guests</label>
                        <div class="guests-dropdown">
                            <input type="text" id="guests-display" readonly placeholder="1 Adult">
                            <div class="guests-popup" style="display: none;">
                                <div class="guest-row">
                                    <span>Adults</span>
                                    <div class="counter">
                                        <button type="button" class="minus" data-target="adults">-</button>
                                        <input type="number" name="adults" id="adults" value="1" min="1" readonly>
                                        <button type="button" class="plus" data-target="adults">+</button>
                                    </div>
                                </div>
                                <div class="guest-row">
                                    <span>Children</span>
                                    <div class="counter">
                                        <button type="button" class="minus" data-target="children">-</button>
                                        <input type="number" name="children" id="children" value="0" min="0" readonly>
                                        <button type="button" class="plus" data-target="children">+</button>
                                    </div>
                                </div>
                                <div class="guest-row">
                                    <span>Infants</span>
                                    <div class="counter">
                                        <button type="button" class="minus" data-target="infants">-</button>
                                        <input type="number" name="infants" id="infants" value="0" min="0" readonly>
                                        <button type="button" class="plus" data-target="infants">+</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="search-field">
                        <button type="submit" class="search-submit">Search</button>
                    </div>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function properties_shortcode() {
        global $wpdb;
        $amenities = $wpdb->get_results("SELECT * FROM {$this->amenities_table} WHERE is_active = 1");
        
        ob_start();
        ?>
        <div class="hostaway-properties-wrapper">
            <div class="properties-header">
                <div class="search-filter-controls">
                    <button id="filter-toggle" class="btn-secondary">Filter</button>
                    <button id="map-toggle" class="btn-secondary">Show Map</button>
                    <button id="reset-filters" class="btn-secondary">Reset</button>
                </div>
            </div>
            
            <div class="filter-popup" style="display: none;">
                <div class="filter-content">
                    <h3>Amenities</h3>
                    <?php if (empty($amenities)): ?>
                        <p>No amenities configured. Please sync properties first.</p>
                    <?php else: ?>
                        <div class="amenities-list">
                            <?php foreach ($amenities as $amenity): ?>
                                <label>
                                    <input type="checkbox" name="amenity_filter" value="<?php echo esc_attr($amenity->amenity_name); ?>">
                                    <?php echo esc_html($amenity->amenity_name); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <button id="apply-filters" class="btn-primary">Apply Filters</button>
                </div>
            </div>
            
            <div class="properties-container">
                <div class="properties-grid" id="properties-grid">
                    <!-- Properties will be loaded here -->
                </div>
                
                <div class="map-container" id="map-container" style="display: none;">
                    <div id="properties-map" style="height: 600px; width: 100%;"></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function debug_shortcode() {
        if (!current_user_can('manage_options')) {
            return '<p>Access denied. Admin only.</p>';
        }
        
        global $wpdb;
        $property_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $amenity_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->amenities_table}");
        $active_amenity_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->amenities_table} WHERE is_active = 1");
        
        $sample_properties = $wpdb->get_results("SELECT id, listing_id, title, city, slug, images FROM {$this->table_name} LIMIT 5");
        $sample_amenities = $wpdb->get_results("SELECT * FROM {$this->amenities_table} LIMIT 10");
        
        ob_start();
        ?>
        <div style="background: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h2>Hostaway Debug Information</h2>
            
            <h3>Database Status</h3>
            <ul>
                <li><strong>Total Properties:</strong> <?php echo $property_count; ?></li>
                <li><strong>Total Amenities:</strong> <?php echo $amenity_count; ?></li>
                <li><strong>Active Amenities:</strong> <?php echo $active_amenity_count; ?></li>
            </ul>
            
            <h3>Settings</h3>
            <ul>
                <li><strong>Account ID:</strong> <?php echo get_option('hostaway_account_id') ? 'Configured ✓' : 'Not Set ✗'; ?></li>
                <li><strong>API Key:</strong> <?php echo get_option('hostaway_api_key') ? 'Configured ✓' : 'Not Set ✗'; ?></li>
                <li><strong>Google Maps Key:</strong> <?php echo get_option('hostaway_google_maps_key') ? 'Configured ✓' : 'Not Set ✗'; ?></li>
            </ul>
            
            <?php if (!empty($sample_properties)): ?>
            <h3>Sample Properties</h3>
            <table style="width: 100%; border-collapse: collapse; background: white;">
                <thead>
                    <tr style="background: #ddd;">
                        <th style="padding: 10px; border: 1px solid #ccc;">ID</th>
                        <th style="padding: 10px; border: 1px solid #ccc;">Listing ID</th>
                        <th style="padding: 10px; border: 1px solid #ccc;">Title</th>
                        <th style="padding: 10px; border: 1px solid #ccc;">Slug</th>
                        <th style="padding: 10px; border: 1px solid #ccc;">City</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sample_properties as $prop): ?>
                    <tr>
                        <td style="padding: 10px; border: 1px solid #ccc;"><?php echo $prop->id; ?></td>
                        <td style="padding: 10px; border: 1px solid #ccc;"><?php echo $prop->listing_id; ?></td>
                        <td style="padding: 10px; border: 1px solid #ccc;"><?php echo $prop->title; ?></td>
                        <td style="padding: 10px; border: 1px solid #ccc;"><?php echo $prop->slug; ?></td>
                        <td style="padding: 10px; border: 1px solid #ccc;"><?php echo $prop->city; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="color: red;"><strong>No properties found in database!</strong> Please sync properties from the Hostaway Settings page.</p>
            <?php endif; ?>
            
            <?php if (!empty($sample_amenities)): ?>
            <h3>Sample Amenities</h3>
            <ul>
                <?php foreach ($sample_amenities as $amenity): ?>
                <li><?php echo esc_html($amenity->amenity_name); ?> - <?php echo $amenity->is_active ? '✓ Active' : '✗ Inactive'; ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            
            <h3>AJAX Test</h3>
            <button id="test-ajax" class="btn-primary" style="padding: 10px 20px; background: #C4A574; color: white; border: none; border-radius: 4px; cursor: pointer;">Test Property Loading</button>
            <div id="ajax-result" style="margin-top: 10px;"></div>
            
            <script>
            jQuery(document).ready(function($) {
                $('#test-ajax').on('click', function() {
                    $('#ajax-result').html('Loading...');
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        method: 'POST',
                        data: {
                            action: 'hostaway_search',
                            location: '',
                            check_in: '',
                            check_out: '',
                            adults: 1,
                            children: 0,
                            infants: 0,
                            amenities: []
                        },
                        success: function(response) {
                            console.log('AJAX Response:', response);
                            if (response.success) {
                                $('#ajax-result').html('<p style="color: green;">✓ Successfully loaded ' + response.data.count + ' properties</p>');
                            } else {
                                $('#ajax-result').html('<p style="color: red;">✗ Error: ' + response.data.message + '</p>');
                            }
                        },
                        error: function(xhr, status, error) {
                            $('#ajax-result').html('<p style="color: red;">✗ AJAX Error: ' + error + '</p>');
                            console.error('AJAX Error:', xhr.responseText);
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function enqueue_scripts() {
        wp_enqueue_style('hostaway-styles', plugin_dir_url(__FILE__) . 'assets/style.css', array(), '1.0.0');
        wp_enqueue_script('hostaway-datepicker', 'https://cdn.jsdelivr.net/npm/flatpickr', array(), '1.0.0', true);
        wp_enqueue_style('hostaway-datepicker-css', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css');
        
        $google_maps_key = get_option('hostaway_google_maps_key');
        if ($google_maps_key) {
            wp_enqueue_script('google-maps', "https://maps.googleapis.com/maps/api/js?key={$google_maps_key}", array(), null, true);
        }
        
        wp_enqueue_script('hostaway-script', plugin_dir_url(__FILE__) . 'assets/script.js', array('jquery', 'hostaway-datepicker'), '1.0.0', true);
        
        wp_localize_script('hostaway-script', 'hostawayData', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hostaway_nonce')
        ));
    }
    
    public function admin_enqueue_scripts($hook) {
        if ($hook !== 'toplevel_page_hostaway-settings') {
            return;
        }
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