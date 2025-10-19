# Hostaway Property Sync Plugin

A comprehensive WordPress plugin that syncs properties from Hostaway API with search, filtering, booking functionality, and WooCommerce integration.

## Features

- **Property Sync**: Automatically sync properties from Hostaway API
- **Search Functionality**: Location, check-in/out dates, guest count search
- **Property Filtering**: Filter by amenities with admin-configurable options
- **Interactive Maps**: Google Maps integration with property pins
- **Dynamic Pricing**: Real-time pricing based on selected dates
- **Booking System**: Direct booking or WooCommerce integration
- **Payment Processing**: Stripe integration via WooCommerce
- **Responsive Design**: Mobile-friendly interface
- **Admin Dashboard**: Complete property and amenity management

## Installation

1. Upload the plugin files to `/wp-content/plugins/hostaway-property-sync/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to 'Hostaway Sync' in the admin menu
4. Configure your Hostaway Account ID and API Key
5. Add your Google Maps API Key
6. Click 'Sync Now' to import properties

## Configuration

### Required Settings

1. **Hostaway Account ID**: Your Hostaway account identifier
2. **Hostaway API Key**: Your API key from Hostaway dashboard
3. **Google Maps API Key**: For map functionality

### Optional Settings

- **WooCommerce Integration**: Install WooCommerce and Stripe for payment processing
- **Amenity Management**: Select which amenities appear in filters

## Usage

### Shortcodes

#### Search Form
```
[hostaway_search]
```
Displays a search form with location, dates, and guest selection.

#### Properties Listing
```
[hostaway_properties]
```
Shows properties grid with filters and map toggle.

#### Debug Information (Admin Only)
```
[hostaway_debug]
```
Shows sync status and configuration info.

### URL Structure

- `/properties/` - Properties listing page
- `/properties/property-slug/` - Individual property page
- `/search/` - Search page

### Admin Features

1. **Sync Properties**: Manual sync from Hostaway API
2. **Manage Properties**: View, search, and delete properties
3. **Amenity Management**: Control which amenities appear in filters
4. **Settings**: Configure API keys and options

## WooCommerce Integration

When WooCommerce is installed:

1. Bookings create WooCommerce orders
2. Payment processing via Stripe
3. Order completion syncs to Hostaway
4. Email notifications for bookings

## API Endpoints Used

- `GET /v1/listings` - Fetch properties
- `GET /v1/listings/{id}/calendar/priceDetails` - Get pricing
- `POST /v1/reservations` - Create bookings

## Requirements

- WordPress 5.0+
- PHP 7.4+
- MySQL 5.6+
- Google Maps API Key
- Hostaway Account

## Optional Requirements

- WooCommerce 5.0+ (for payment processing)
- Stripe Payment Gateway

## Troubleshooting

### Common Issues

1. **Properties not syncing**: Check API credentials
2. **Maps not loading**: Verify Google Maps API key
3. **Booking failures**: Ensure API permissions
4. **Payment issues**: Check WooCommerce/Stripe setup

### Debug Mode

Use the `[hostaway_debug]` shortcode to check:
- Total properties synced
- API configuration status
- Plugin version info

## Support

For issues and feature requests, please check the plugin documentation or contact support.

## Changelog

### Version 1.0.1
- Initial release
- Property sync functionality
- Search and filtering
- Booking system
- WooCommerce integration
- Google Maps integration
