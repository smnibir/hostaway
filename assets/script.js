jQuery(document).ready(function($) {
    
    // Initialize date pickers
    if (typeof flatpickr !== 'undefined') {
        const checkInPicker = flatpickr('#search-check-in', {
            minDate: 'today',
            dateFormat: 'Y-m-d',
            onChange: function(selectedDates, dateStr) {
                checkOutPicker.set('minDate', dateStr);
                const checkOutDate = $('#search-check-out').val();
                if (checkOutDate && checkOutDate <= dateStr) {
                    $('#search-check-out').val('');
                }
            }
        });
        
        const checkOutPicker = flatpickr('#search-check-out', {
            minDate: 'today',
            dateFormat: 'Y-m-d'
        });
        
        // Booking page date pickers
        if ($('#booking-check-in').length) {
            const bookingCheckInPicker = flatpickr('#booking-check-in', {
                minDate: 'today',
                dateFormat: 'Y-m-d',
                onChange: function(selectedDates, dateStr) {
                    bookingCheckOutPicker.set('minDate', dateStr);
                    const checkOutDate = $('#booking-check-out').val();
                    if (checkOutDate && checkOutDate <= dateStr) {
                        $('#booking-check-out').val('');
                    }
                    updateBookingPrice();
                }
            });
            
            const bookingCheckOutPicker = flatpickr('#booking-check-out', {
                minDate: 'today',
                dateFormat: 'Y-m-d',
                onChange: function() {
                    updateBookingPrice();
                }
            });
        }
    }
    
    // Guest dropdown functionality
    $('#guests-display').on('click', function(e) {
        e.stopPropagation();
        $('.guests-popup').toggle();
    });
    
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.guests-dropdown').length) {
            $('.guests-popup').hide();
        }
    });
    
    // Guest counter
    $('.counter button').on('click', function(e) {
        e.preventDefault();
        var target = $(this).data('target');
        var input = $('#' + target);
        var currentVal = parseInt(input.val());
        var min = parseInt(input.attr('min'));
        
        if ($(this).hasClass('plus')) {
            input.val(currentVal + 1);
        } else if ($(this).hasClass('minus') && currentVal > min) {
            input.val(currentVal - 1);
        }
        
        updateGuestsDisplay();
    });
    
    function updateGuestsDisplay() {
        var adults = parseInt($('#adults').val());
        var children = parseInt($('#children').val());
        var infants = parseInt($('#infants').val());
        
        var text = [];
        if (adults > 0) text.push(adults + ' Adult' + (adults > 1 ? 's' : ''));
        if (children > 0) text.push(children + ' Child' + (children > 1 ? 'ren' : ''));
        if (infants > 0) text.push(infants + ' Infant' + (infants > 1 ? 's' : ''));
        
        $('#guests-display').val(text.join(', '));
    }
    
    // Search form submission
    $('#hostaway-search-form').on('submit', function(e) {
        e.preventDefault();
        
        var formData = {
            location: $('#search-location').val(),
            check_in: $('#search-check-in').val(),
            check_out: $('#search-check-out').val(),
            adults: $('#adults').val(),
            children: $('#children').val(),
            infants: $('#infants').val()
        };
        
        var queryString = $.param(formData);
        window.location.href = '/properties?' + queryString;
    });
    
    // Load properties on properties page
    if ($('#properties-grid').length) {
        loadProperties();
    }
    
    // Make loadProperties globally accessible
    window.loadProperties = loadProperties;
    
    function loadProperties() {
        var urlParams = new URLSearchParams(window.location.search);
        var searchData = {
            action: 'hostaway_search',
            location: urlParams.get('location') || '',
            check_in: urlParams.get('check_in') || '',
            check_out: urlParams.get('check_out') || '',
            adults: urlParams.get('adults') || 1,
            children: urlParams.get('children') || 0,
            infants: urlParams.get('infants') || 0,
            amenities: []
        };
        
        // Get selected amenities
        $('input[name="amenity_filter"]:checked').each(function() {
            searchData.amenities.push($(this).val());
        });
        
        $('#properties-grid').html('<div class="loading">Loading properties...</div>');
        
        $.ajax({
            url: hostawayData.ajaxurl,
            method: 'POST',
            data: searchData,
            success: function(response) {
                if (response.success) {
                    displayProperties(response.data.properties, searchData.check_in, searchData.check_out);
                } else {
                    $('#properties-grid').html('<div class="loading">No properties found.</div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                console.error('Response:', xhr.responseText);
                $('#properties-grid').html('<div class="loading">Error loading properties. Please check console for details.</div>');
            }
        });
    }
    
    function displayProperties(properties, checkIn, checkOut) {
        var grid = $('#properties-grid');
        grid.empty();
        
        if (!properties || properties.length === 0) {
            grid.html('<div class="loading">No properties found matching your criteria.</div>');
            return;
        }
        
        properties.forEach(function(property) {
            var images = property.images || [];
            var firstImage = images.length > 0 ? images[0] : 'https://via.placeholder.com/400x300';
            
            var card = $('<div class="property-card"></div>');
            card.attr('data-listing-id', property.listing_id);
            card.attr('data-slug', property.slug);
            card.attr('data-lat', property.latitude);
            card.attr('data-lng', property.longitude);
            
            var imagesHtml = '<div class="property-images">';
            imagesHtml += '<div class="property-slider">';
            images.forEach(function(img) {
                imagesHtml += '<img src="' + img + '" alt="' + property.title + '">';
            });
            imagesHtml += '</div>';
            
            if (images.length > 1) {
                imagesHtml += '<button class="slider-nav prev">‹</button>';
                imagesHtml += '<button class="slider-nav next">›</button>';
            }
            
            imagesHtml += '<div class="property-price">';
            imagesHtml += '<div class="amount">$' + Math.round(property.base_price) + '</div>';
            imagesHtml += '<div class="period">per night</div>';
            imagesHtml += '</div>';
            imagesHtml += '</div>';
            
            var infoHtml = '<div class="property-info">';
            infoHtml += '<h3 class="property-title">' + property.title + '</h3>';
            infoHtml += '<div class="property-location">' + property.city + ', ' + property.country + '</div>';
            infoHtml += '<div class="property-features">';
            infoHtml += '<div class="feature-item">🛏️ ' + property.bedrooms + ' Beds</div>';
            infoHtml += '<div class="feature-item">🚿 ' + property.bathrooms + ' Baths</div>';
            infoHtml += '<div class="feature-item">👥 ' + property.guests + ' Guests</div>';
            infoHtml += '</div>';
            infoHtml += '</div>';
            
            card.html(imagesHtml + infoHtml);
            grid.append(card);
            
            // Get dynamic pricing if dates are selected
            if (checkIn && checkOut) {
                getPriceDetails(property.listing_id, checkIn, checkOut, card);
            }
        });
        
        // Property card click - use slug for URL
        $('.property-card').on('click', function(e) {
            if (!$(e.target).hasClass('slider-nav')) {
                var slug = $(this).data('slug');
                window.location.href = '/properties/' + slug;
            }
        });
        
        // Image slider
        $('.slider-nav').on('click', function(e) {
            e.stopPropagation();
            var slider = $(this).siblings('.property-slider');
            var images = slider.find('img');
            var currentIndex = Math.round(slider.scrollLeft() / slider.width());
            
            if ($(this).hasClass('next')) {
                currentIndex = (currentIndex + 1) % images.length;
            } else {
                currentIndex = (currentIndex - 1 + images.length) % images.length;
            }
            
            slider.css('transform', 'translateX(-' + (currentIndex * 100) + '%)');
        });
        
        // Initialize map if visible
        if ($('#map-container').is(':visible')) {
            initMap(properties);
        }
    }
    
    function getPriceDetails(listingId, checkIn, checkOut, card) {
        $.ajax({
            url: hostawayData.ajaxurl,
            method: 'POST',
            data: {
                action: 'get_price_details',
                listing_id: listingId,
                check_in: checkIn,
                check_out: checkOut
            },
            success: function(response) {
                if (response.success) {
                    card.find('.property-price .amount').text('$' + Math.round(response.data.total_price));
                    card.find('.property-price .period').text('total');
                }
            }
        });
    }
    
    // Filter toggle
    $('#filter-toggle').on('click', function() {
        $('.filter-popup').fadeIn();
    });
    
    $('.filter-popup').on('click', function(e) {
        if ($(e.target).hasClass('filter-popup')) {
            $(this).fadeOut();
        }
    });
    
    // Apply filters
    $('#apply-filters').on('click', function() {
        $('.filter-popup').fadeOut();
        loadProperties();
    });
    
    // Map toggle
    $('#map-toggle').on('click', function() {
        var mapContainer = $('#map-container');
        var grid = $('#properties-grid');
        
        if (mapContainer.is(':visible')) {
            mapContainer.hide();
            grid.removeClass('with-map');
            $(this).text('Show Map');
        } else {
            mapContainer.show();
            grid.addClass('with-map');
            $(this).text('Hide Map');
            
            // Initialize map if not already done
            if (!window.hostawayMap) {
                var properties = [];
                $('.property-card').each(function() {
                    properties.push({
                        listing_id: $(this).data('listing-id'),
                        latitude: parseFloat($(this).data('lat')),
                        longitude: parseFloat($(this).data('lng')),
                        title: $(this).find('.property-title').text(),
                        price: $(this).find('.property-price .amount').text(),
                        image: $(this).find('.property-images img').first().attr('src')
                    });
                });
                initMap(properties);
            }
        }
    });
    
    // Reset filters
    $('#reset-filters').on('click', function() {
        $('input[name="amenity_filter"]').prop('checked', false);
        window.location.href = '/properties';
    });
    
    // Initialize Google Map
    function initMap(properties) {
        if (typeof google === 'undefined' || !google.maps) {
            console.error('Google Maps API not loaded');
            return;
        }
        
        var mapElement = document.getElementById('properties-map');
        if (!mapElement) return;
        
        var bounds = new google.maps.LatLngBounds();
        var center = { lat: 0, lng: 0 };
        
        if (properties.length > 0 && properties[0].latitude && properties[0].longitude) {
            center = { lat: parseFloat(properties[0].latitude), lng: parseFloat(properties[0].longitude) };
        }
        
        var map = new google.maps.Map(mapElement, {
            center: center,
            zoom: 12,
            styles: [
                {
                    featureType: 'poi',
                    elementType: 'labels',
                    stylers: [{ visibility: 'off' }]
                }
            ]
        });
        
        window.hostawayMap = map;
        
        properties.forEach(function(property) {
            if (!property.latitude || !property.longitude) return;
            
            var position = {
                lat: parseFloat(property.latitude),
                lng: parseFloat(property.longitude)
            };
            
            var marker = new google.maps.Marker({
                position: position,
                map: map,
                title: property.title
            });
            
            bounds.extend(position);
            
            var infoContent = '<div class="map-popup">';
            if (property.image) {
                infoContent += '<img src="' + property.image + '" alt="' + property.title + '">';
            }
            infoContent += '<h4>' + property.title + '</h4>';
            infoContent += '<div class="price">' + property.price + '</div>';
            infoContent += '</div>';
            
            var infowindow = new google.maps.InfoWindow({
                content: infoContent
            });
            
            marker.addListener('mouseover', function() {
                infowindow.open(map, marker);
            });
            
            marker.addListener('mouseout', function() {
                infowindow.close();
            });
            
            marker.addListener('click', function() {
                var slug = property.title.toLowerCase().replace(/[^a-z0-9]+/g, '-');
                window.location.href = '/properties/' + slug;
            });
        });
        
        if (properties.length > 1) {
            map.fitBounds(bounds);
        }
    }
    
    // Single property page - gallery
    $('.gallery-thumb').on('click', function() {
        var imgSrc = $(this).find('img').attr('src');
        $('.gallery-main img').attr('src', imgSrc);
    });
    
    // Single property booking price update
    function updateBookingPrice() {
        var listingId = $('#booking-listing-id').val();
        var checkIn = $('#booking-check-in').val();
        var checkOut = $('#booking-check-out').val();
        
        if (!listingId || !checkIn || !checkOut) return;
        
        // Calculate number of nights
        var checkInDate = new Date(checkIn);
        var checkOutDate = new Date(checkOut);
        var timeDiff = checkOutDate - checkInDate;
        var nights = Math.ceil(timeDiff / (1000 * 3600 * 24));
        
        if (nights > 0) {
            $('#nights-count').text(nights + ' night' + (nights > 1 ? 's' : ''));
        }
        
        $.ajax({
            url: hostawayData.ajaxurl,
            method: 'POST',
            data: {
                action: 'get_price_details',
                listing_id: listingId,
                check_in: checkIn,
                check_out: checkOut
            },
            success: function(response) {
                if (response.success) {
                    var totalPrice = response.data.total_price;
                    $('#total-price').text('$' + Math.round(totalPrice));
                    $('.booking-price').html('$' + Math.round(totalPrice) + ' <span style="font-size: 16px; font-weight: normal;">total</span>');
                }
            },
            error: function() {
                console.error('Failed to get price details');
            }
        });
    }
    
    // Booking form submission
    $('#booking-form').on('submit', function(e) {
        e.preventDefault();
        
        var formData = {
            action: 'create_booking',
            nonce: hostawayData.nonce,
            listing_id: $('#booking-listing-id').val(),
            check_in: $('#booking-check-in').val(),
            check_out: $('#booking-check-out').val(),
            guests: $('#booking-guests').val(),
            guest_name: $('#guest-name').val(),
            guest_email: $('#guest-email').val(),
            guest_phone: $('#guest-phone').val()
        };
        
        var submitBtn = $(this).find('.book-now-btn');
        var messageDiv = $('#booking-message');
        
        submitBtn.prop('disabled', true).text('Processing...');
        messageDiv.html('');
        
        $.ajax({
            url: hostawayData.ajaxurl,
            method: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    if (response.data.payment_url) {
                        // WooCommerce order created, redirect to payment
                        messageDiv.html('<p style="color: green; font-weight: bold;">✓ Booking created successfully!</p>');
                        messageDiv.append('<p>Redirecting to payment...</p>');
                        setTimeout(function() {
                            window.location.href = response.data.payment_url;
                        }, 2000);
                    } else {
                        // Direct Hostaway booking
                        messageDiv.html('<p style="color: green; font-weight: bold;">✓ Booking created successfully! Reservation ID: ' + response.data.reservation_id + '</p>');
                        messageDiv.append('<p>You will receive a confirmation email shortly.</p>');
                        
                        // Reset form after 5 seconds
                        setTimeout(function() {
                            $('#booking-form')[0].reset();
                            messageDiv.html('');
                            submitBtn.prop('disabled', false).text('Book Now');
                        }, 5000);
                    }
                } else {
                    messageDiv.html('<p style="color: red;">✗ Error: ' + response.data.message + '</p>');
                    submitBtn.prop('disabled', false).text('Book Now');
                }
            },
            error: function(xhr, status, error) {
                console.error('Booking error:', error);
                messageDiv.html('<p style="color: red;">✗ Failed to create booking. Please try again.</p>');
                submitBtn.prop('disabled', false).text('Book Now');
            }
        });
    });
    
});