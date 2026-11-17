# Menu Popup Plugin - New Reservation System Documentation

## Overview

The NEW reservation system in the Menu Popup Plugin provides a comprehensive restaurant table reservation management platform with advanced features for handling bookings, table assignments, and customer management.

## Key Features

### 📋 Core Functionality

- **Online Reservation Forms**: Customer-facing booking system with validation
- **Automated Table Assignment**: Smart table matching based on party size and preferences
- **Admin Dashboard**: Complete reservation management interface
- **Real-time Updates**: AJAX-powered admin dashboard with live notifications
- **Reporting & Analytics**: Daily summaries, revenue tracking, and customer insights

### 🎯 Advanced Features

- **Status Management**: Track reservations through multiple stages (Pending → Confirmed → Completed/Cancelled)
- **Flexible Scheduling**: Time slot management with facility for custom operating hours
- **Table Availability Engine**: Intelligent conflict resolution and space optimization
- **Customer Communication**: Automated follow-up and status notifications
- **Multi-branch Support**: Handle reservations across multiple restaurant locations

---

## System Architecture

### Database Structure

#### Custom Post Types

```
- menu_reservation (main reservation posts)
- restaurant_table (table management)
- menu_popup_branch (restaurant branches)
```

#### Reservation Meta Fields

```
_customer_name          : string - Customer full name
_customer_phone         : string - Contact phone number
_customer_email         : string - Optional email address
_party_size            : int - Number of guests (1-20)
_reservation_date      : date - YYYY-MM-DD format
_reservation_time      : time - HH:MM format
_branch_id             : int - Branch post ID
_special_requests      : text - Customer notes/preferences
_status                : enum - [pending, confirmed, completed, cancelled, no-show]
_assigned_table        : int - Auto-assigned table ID
_duration             : int - Reservation duration in minutes (default: 90)
```

### Admin Classes Structure

```
├── class-menu-popup-admin-reservations.php     # Main reservation admin
├── admin/class-menu-popup-admin-menu-items.php  # Menu management
├── admin/class-menu-popup-admin-dashboard.php   # Main dashboard
├── includes/class-menu-popup-availability.php   # Table availability engine
└── frontend/class-menu-popup-frontend-fixed.php  # Frontend reservation form
```

---

## Workflow Logic

### 1. Customer Reservation Process

#### Frontend Form Workflow

1. **Form Validation**

   ```javascript
   // Required fields with client-side validation
   - customer_name: min 2 characters
   - customer_phone: valid format (+country code optional)
   - party_size: 1-20 range
   - reservation_date: future date only
   - reservation_time: from predefined slots
   - branch_selection: from active branches
   ```

2. **AJAX Submission**

   ```php
   // Security validation
   - WordPress nonce verification
   - Sanitization of all inputs
   - Server-side business logic validation
   ```

3. **Success Response**
   - Database record creation
   - Customer confirmation message
   - Optional email notification hook

#### Time Slot Generation

```php
// Dynamic time slot creation based on branch settings
- Default slots: 17:00-22:00 in 30-minute intervals
- Custom operating hours support per branch
- Base duration: 90 minutes per reservation
```

### 2. Automated Table Assignment System

#### Smart Matching Algorithm

```php
// Find available tables based on:
1. Party size capacity (>= requested size)
2. Time slot availability (no conflicts)
3. Branch location filter
4. Preferred area (optional)
5. Special assignments (romantic, kids-friendly, etc.)
```

#### Assignment Priority Order

```php
1. Perfect capacity matches (party ≤ capacity ≤ 1.2 × party)
2. Available tables (no assignment conflicts)
3. Better capacity utilization (avoid oversized tables)
4. First available slot in preferred time range
```

#### Availability Engine Features

- **Conflict Detection**: Prevents double-booking of tables
- **Duration Handling**: 90-minute default with custom override
- **Status-based Filtering**: Only consider confirmed/pending reservations
- **Real-time Checking**: AJAX validation for instant feedback

### 3. Admin Management System

#### Reservation Dashboard Features

```php
// Statistics Panel
- Total reservations count
- Pending/confirmed/completed status breakdown
- Revenue calculations from completed orders
- Daily/monthly performance tracking
```

#### Advanced Filtering

```php
// Search capabilities:
- Customer name/phone partial matching
- Date range filtering
- Status-based filtering
- Branch-specific view
- Party size ranges
```

#### Bulk Operations

```javascript
// AJAX-powered admin actions:
- Status updates (single/bulk)
- Reservation cancellation
- Customer notification triggers
- Table re-assignment
```

#### Real-time Dashboard Updates

```php
// Auto-refresh capabilities:
- Configurable update intervals (30-300 seconds)
- Live notification badges on new bookings
- Statistics recalculation
- Reservation status changes
```

---

## Business Logic Rules

### Reservation Status Flow

```
PENDING → CONFIRMED → COMPLETED
     ↓         ↓         ↓
CANCELLED    NO-SHOW    CLOSED
```

### Validation Rules

```php
// Business constraints:
- Minimum advance booking: 1 hour
- Maximum advance booking: 30 days
- Party size limits: 1-20 guests
- Operating hours: 11:00-23:00 (configurable per branch)
```

### Place Assignment Logic

```php
// Smart table selection:
1. Exact capacity match (preferred)
2. Over-capacity (acceptable for special requests)
3. Time slot availability (mandatory)
4. Preference matching (romantic/kids/etc.)
5. Area optimization (balance dining spaces)
```

---

## AJAX Endpoints

### Frontend Endpoints

```php
wp_ajax_nopriv_submit_reservation
wp_ajax_nopriv_get_available_tables_for_reservation

// Validation Features:
- Real-time branch availability
- Dynamic time slot updates
- Instant conflict detection
```

### Admin Endpoints

```php
wp_ajax_update_reservation_status
wp_ajax_delete_reservation
wp_ajax_save_reservation
wp_ajax_get_reservation_details
wp_ajax_get_reservations_active

// Dashboard Features:
- Live status updates
- Bulk operations support
- Export functionality
- Real-time statistics
```

---

## User Interface Components

### Frontend Reservation Form

```html
<!-- Dynamic form with validation -->
- Real-time field validation - Ajax branch/table availability - Responsive
mobile design - Accessibility compliant - RTL language support
```

### Admin Dashboard Layout

```html
<!-- Statistics cards -->
- Key metrics at-a-glance - Color-coded status indicators - Quick action buttons
- Real-time data updates

<!-- Advanced table interface -->
- Sortable/filterable columns - Inline editing capabilities - Modal detail views
- Export functionality
```

---

## Error Handling & Validation

### Client-side Validation

```javascript
// Immediate feedback:
- Field format validation
- Required field indicators
- Dynamic error messages
- Progress indicators for multi-step forms
```

### Server-side Validation

```php
// Security measures:
- Nonce verification
- Input sanitization
- Business rule enforcement
- Conflict resolution
- Data consistency checks
```

### Error Messages

```php
// User-friendly responses:
- Clear problem identification
- Suggested corrective actions
- Alternative options when available
- Contact information for support
```

---

## Performance Optimization

### Database Optimizations

```php
// Query performance:
- Indexed meta keys for reservation searches
- Efficient date range queries
- Cached availability calculations
- Batch update operations
```

### AJAX Optimizations

```javascript
// Frontend speed:
- Debounced search inputs (500ms delay)
- Paginated result loading
- Progressive enhancement
- Background update polling
```

---

## Integration Points

### WordPress Core Integration

```php
// Native WordPress features:
- Custom post types for data structure
- Meta fields for extensible storage
- User permission system
- Taxonomy support for organization
```

### Third-party Compatibility

```php
// Plugin ecosystem:
- Multilingual plugin support (WPML/Polylang)
- Calendar integrations
- Email notification plugins
- SMS gateways
```

---

## Security Considerations

### Data Protection

```php
// Security measures:
- WordPress nonce system
- SQL injection prevention
- XSS protection via sanitization
- CSRF token validation
- File upload restrictions
```

### Access Control

```php
// Permission levels:
- Admin-only reservation management
- Customer data privacy
- Audit trail maintenance
- Backup and recovery procedures
```

---

## Future Enhancement Hooks

### Extension Points

```php
// Plugin extendability:
- Custom reservation fields hook
- Table assignment algorithm filters
- Notification system integrations
- Payment gateway connections
- Third-party API integrations
```

### Scalability Features

```php
// Performance optimizations:
- Database query caching
- CDN support for assets
- Background processing for heavy operations
- Multi-server deployment support
```

---

## Troubleshooting Guide

### Common Issues

```
- Time slot conflicts → Check availability engine
- Form submission errors → Verify AJAX endpoints
- Table assignment failures → Review capacity constraints
- Real-time updates failing → Check heartbeat settings
```

### Debug Tools

```php
// Built-in diagnostics:
- Reservation status verification
- Table availability checking
- Form validation testing
- Performance monitoring logs
```

---

_This documentation outlines the complete workflow and logic for the NEW reservation system. All features are designed to provide a professional-grade restaurant reservation platform with enterprise-level functionality._
