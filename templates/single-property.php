<?php
/**
 * Template for Single Property Page
 */

get_header();

global $wpdb;
$table_name = $wpdb->prefix . 'hostaway_properties';
$property_slug = get_query_var('property_slug');

// Get property by slug
$property = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$table_name} WHERE slug = %s LIMIT 1",
    $property_slug
));

if (!$property) {
    echo '<div class="container"><h1>Property not found</h1></div>';
    get_footer();
    exit;
}

$images = json_decode($property->images, true) ?: array();
$amenities = json_decode($property->amenities, true) ?: array();
?>

<div class="single-property-wrapper">
    
    <!-- Property Gallery -->
    <div class="property-gallery">
        <div class="gallery-main">
            <img src="<?php echo esc_url($images[0] ?? 'https://via.placeholder.com/800x600'); ?>" alt="<?php echo esc_attr($property->title); ?>">
        </div>
        <div class="gallery-thumbs">
            <?php 
            $thumb_images = array_slice($images, 1, 2);
            foreach ($thumb_images as $img): 
            ?>
                <div class="gallery-thumb">
                    <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($property->title); ?>">
                </div>
            <?php endforeach; ?>
            
            <?php if (count($images) > 3): ?>
                <div class="gallery-thumb" style="position: relative;">
                    <img src="<?php echo esc_url($images[3]); ?>" alt="<?php echo esc_attr($property->title); ?>">
                    <div class="see-all-photos">See all <?php echo count($images); ?> photos</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Property Content -->
    <div class="property-content">
        
        <!-- Main Content -->
        <div class="property-main">
            <h1><?php echo esc_html($property->title); ?></h1>
            
            <div class="property-location">
                <?php echo esc_html($property->address); ?>, <?php echo esc_html($property->city); ?>, <?php echo esc_html($property->country); ?>
            </div>
            
            <div class="property-features" style="margin: 20px 0; padding: 20px; background: #f9f9f9; border-radius: 8px;">
                <div class="feature-item" style="margin-right: 20px;">
                    <strong>🛏️ <?php echo $property->bedrooms; ?> Bedrooms</strong>
                </div>
                <div class="feature-item" style="margin-right: 20px;">
                    <strong>🚿 <?php echo $property->bathrooms; ?> Bathrooms</strong>
                </div>
                <div class="feature-item">
                    <strong>👥 Up to <?php echo $property->guests; ?> Guests</strong>
                </div>
            </div>
            
            <hr style="margin: 30px 0;">
            
            <h2>About This Property</h2>
            <div class="property-description">
                <?php echo wpautop($property->description); ?>
            </div>
            
            <hr style="margin: 30px 0;">
            
            <?php if (!empty($amenities)): ?>
            <h2>Amenities</h2>
            <div class="amenities-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px; margin-top: 20px;">
                <?php foreach ($amenities as $amenity): ?>
                    <div class="amenity-item" style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 20px;">✓</span>
                        <span><?php echo esc_html($amenity); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <hr style="margin: 30px 0;">
            
            <h2>Location</h2>
            <div id="property-map" style="height: 400px; width: 100%; border-radius: 8px; margin-top: 20px;"></div>
            
            <script>
            function initPropertyMap() {
                if (typeof google === 'undefined' || !google.maps) return;
                
                var position = {
                    lat: <?php echo floatval($property->latitude); ?>,
                    lng: <?php echo floatval($property->longitude); ?>
                };
                
                var map = new google.maps.Map(document.getElementById('property-map'), {
                    center: position,
                    zoom: 15
                });
                
                new google.maps.Marker({
                    position: position,
                    map: map,
                    title: '<?php echo esc_js($property->title); ?>'
                });
            }
            
            if (typeof google !== 'undefined' && google.maps) {
                initPropertyMap();
            }
            </script>
        </div>
        
        <!-- Sidebar Booking Card -->
        <div class="property-sidebar">
            <div class="booking-card">
                <div class="booking-price">
                    $<?php echo number_format($property->base_price, 0); ?> <span style="font-size: 16px; font-weight: normal;">per night</span>
                </div>
                
                <form id="booking-form" class="booking-form">
                    <input type="hidden" id="booking-listing-id" value="<?php echo esc_attr($property->listing_id); ?>">
                    
                    <div class="form-group">
                        <label>Check-in</label>
                        <input type="text" id="booking-check-in" name="check_in" required readonly placeholder="Select date">
                    </div>
                    
                    <div class="form-group">
                        <label>Check-out</label>
                        <input type="text" id="booking-check-out" name="check_out" required readonly placeholder="Select date">
                    </div>
                    
                    <div class="form-group">
                        <label>Number of Guests</label>
                        <input type="number" name="guests" id="booking-guests" min="1" max="<?php echo $property->guests; ?>" value="1" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="guest_name" id="guest-name" required placeholder="Your full name">
                    </div>
                    
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="guest_email" id="guest-email" required placeholder="your@email.com">
                    </div>
                    
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" name="guest_phone" id="guest-phone" required placeholder="+1234567890">
                    </div>
                    
                    <div class="price-breakdown">
                        <div class="price-row">
                            <span>Base Price</span>
                            <span id="base-price-display">$<?php echo number_format($property->base_price, 0); ?></span>
                        </div>
                        <div class="price-row">
                            <span>Nights</span>
                            <span id="nights-count">-</span>
                        </div>
                        <div class="price-row total">
                            <span>Total</span>
                            <span id="total-price">$<?php echo number_format($property->base_price, 0); ?></span>
                        </div>
                    </div>
                    
                    <button type="submit" class="book-now-btn">Book Now</button>
                    <div id="booking-message" style="margin-top: 15px;"></div>
                </form>
                
                <p style="text-align: center; font-size: 12px; color: #666; margin-top: 15px;">
                    Booking will be confirmed via email
                </p>
            </div>
        </div>
        
    </div>
    
</div>

<?php get_footer(); ?>