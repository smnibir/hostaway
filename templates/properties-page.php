<?php
/**
 * Template for Properties Listing Page
 */

get_header();

global $wpdb;
$cities_table = $wpdb->prefix . 'hostaway_properties';
$cities = $wpdb->get_col("SELECT DISTINCT city FROM {$cities_table} WHERE city != '' ORDER BY city ASC");

$amenities_table = $wpdb->prefix . 'hostaway_amenities';
$amenities = $wpdb->get_results("SELECT * FROM {$amenities_table} WHERE is_active = 1 ORDER BY amenity_name ASC");

// Get URL parameters for pre-filling search
$location = isset($_GET['location']) ? sanitize_text_field($_GET['location']) : '';
$check_in = isset($_GET['check_in']) ? sanitize_text_field($_GET['check_in']) : '';
$check_out = isset($_GET['check_out']) ? sanitize_text_field($_GET['check_out']) : '';
$adults = isset($_GET['adults']) ? intval($_GET['adults']) : 1;
$children = isset($_GET['children']) ? intval($_GET['children']) : 0;
$infants = isset($_GET['infants']) ? intval($_GET['infants']) : 0;
?>

<div class="container" style="max-width: 1400px; margin: 0 auto; padding: 20px;">
    
    <h1 style="margin-bottom: 30px;">Find Your Perfect Property</h1>
    
    <!-- Search Form -->
    <div class="hostaway-search-form">
        <form id="hostaway-search-form">
            <div class="search-row">
                <div class="search-field">
                    <label>Location</label>
                    <select name="location" id="search-location">
                        <option value="">All Locations</option>
                        <?php foreach ($cities as $city): ?>
                            <option value="<?php echo esc_attr($city); ?>" <?php selected($location, $city); ?>>
                                <?php echo esc_html($city); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-field">
                    <label>Check In</label>
                    <input type="text" name="check_in" id="search-check-in" value="<?php echo esc_attr($check_in); ?>" readonly placeholder="Select date">
                </div>
                
                <div class="search-field">
                    <label>Check Out</label>
                    <input type="text" name="check_out" id="search-check-out" value="<?php echo esc_attr($check_out); ?>" readonly placeholder="Select date">
                </div>
                
                <div class="search-field">
                    <label>Guests</label>
                    <div class="guests-dropdown">
                        <input type="text" id="guests-display" readonly placeholder="1 Adult" value="<?php 
                            $display = array();
                            if ($adults > 0) $display[] = $adults . ' Adult' . ($adults > 1 ? 's' : '');
                            if ($children > 0) $display[] = $children . ' Child' . ($children > 1 ? 'ren' : '');
                            if ($infants > 0) $display[] = $infants . ' Infant' . ($infants > 1 ? 's' : '');
                            echo esc_attr(implode(', ', $display) ?: '1 Adult');
                        ?>">
                        <div class="guests-popup" style="display: none;">
                            <div class="guest-row">
                                <span>Adults</span>
                                <div class="counter">
                                    <button type="button" class="minus" data-target="adults">-</button>
                                    <input type="number" name="adults" id="adults" value="<?php echo $adults; ?>" min="1" readonly>
                                    <button type="button" class="plus" data-target="adults">+</button>
                                </div>
                            </div>
                            <div class="guest-row">
                                <span>Children</span>
                                <div class="counter">
                                    <button type="button" class="minus" data-target="children">-</button>
                                    <input type="number" name="children" id="children" value="<?php echo $children; ?>" min="0" readonly>
                                    <button type="button" class="plus" data-target="children">+</button>
                                </div>
                            </div>
                            <div class="guest-row">
                                <span>Infants</span>
                                <div class="counter">
                                    <button type="button" class="minus" data-target="infants">-</button>
                                    <input type="number" name="infants" id="infants" value="<?php echo $infants; ?>" min="0" readonly>
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
    
    <!-- Properties Wrapper -->
    <div class="hostaway-properties-wrapper" style="margin-top: 40px;">
        <div class="properties-header">
            <div class="search-filter-controls">
                <button id="filter-toggle" class="btn-secondary">
                    <span>🔍</span> Filter
                </button>
                <button id="map-toggle" class="btn-secondary">
                    <span>🗺️</span> Show Map
                </button>
                <button id="reset-filters" class="btn-secondary">
                    <span>↻</span> Reset
                </button>
            </div>
        </div>
        
        <!-- Filter Popup -->
        <div class="filter-popup" style="display: none;">
            <div class="filter-content">
                <h3>Filter by Amenities</h3>
                <div class="amenities-list">
                    <?php foreach ($amenities as $amenity): ?>
                        <label>
                            <input type="checkbox" name="amenity_filter" value="<?php echo esc_attr($amenity->amenity_name); ?>">
                            <?php echo esc_html($amenity->amenity_name); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button id="apply-filters" class="btn-primary">Apply Filters</button>
            </div>
        </div>
        
        <!-- Properties Container -->
        <div class="properties-container">
            <div class="properties-grid" id="properties-grid">
                <div class="loading">Loading properties...</div>
            </div>
            
            <div class="map-container" id="map-container" style="display: none;">
                <div id="properties-map" style="height: 600px; width: 100%;"></div>
            </div>
        </div>
    </div>
    
</div>

<script>
// Auto-load properties on page load
jQuery(document).ready(function($) {
    // Check if there are search parameters
    var urlParams = new URLSearchParams(window.location.search);
    var hasSearchParams = urlParams.has('location') || urlParams.has('check_in') || 
                         urlParams.has('check_out') || urlParams.has('adults');
    
    <?php if ($location || $check_in || $check_out): ?>
    // If search params in PHP (from URL), trigger search
    if ($('#hostaway-search-form').length) {
        $('#hostaway-search-form').trigger('submit');
    }
    <?php else: ?>
    // No search params, load all properties
    if (typeof window.loadProperties === 'function') {
        setTimeout(function() {
            window.loadProperties();
        }, 500);
    }
    <?php endif; ?>
});
</script>

<?php get_footer(); ?>