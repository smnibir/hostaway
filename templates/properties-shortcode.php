<?php
/**
 * Properties Shortcode Template
 */

global $wpdb;
$amenities_table = $wpdb->prefix . 'hostaway_amenities';
$amenities = $wpdb->get_results("SELECT * FROM {$amenities_table} WHERE is_active = 1 ORDER BY amenity_name ASC");
?>

<div class="hostaway-properties-wrapper">
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
