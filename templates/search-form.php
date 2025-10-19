<?php
/**
 * Search Form Template for Shortcode
 */

global $wpdb;
$cities_table = $wpdb->prefix . 'hostaway_properties';
$cities = $wpdb->get_col("SELECT DISTINCT city FROM {$cities_table} WHERE city != '' ORDER BY city ASC");
?>

<div class="hostaway-search-form">
    <form id="hostaway-search-form">
        <div class="search-row">
            <div class="search-field">
                <label>Location</label>
                <select name="location" id="search-location">
                    <option value="">All Locations</option>
                    <?php foreach ($cities as $city): ?>
                        <option value="<?php echo esc_attr($city); ?>">
                            <?php echo esc_html($city); ?>
                        </option>
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
