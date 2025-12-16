# ARMember Supabase Plugin

A WordPress plugin that integrates ARMember membership management with Supabase database.

## Features

- Sync WordPress users to Supabase
- Display Supabase table data via shortcodes
- Multi-database search functionality
- Table-level access control (locked/unlocked)
- DataTables integration for enhanced data display
- Automatic page generation for database tables

## Installation

1. Upload the plugin files to `/wp-content/plugins/armember-supabase-plugin/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure Supabase credentials in Settings > Supabase Sync

## Configuration

Navigate to **Supabase Sync** in the WordPress admin menu:

### Settings Tab
- **Supabase Project URL**: Your Supabase project URL
- **Supabase Service Key**: Your Supabase service role key (kept secure)
- **Paid Plans**: Comma-separated list of ARMember plan names that grant access

### Tables Tab
- Sync tables from Supabase
- Create WordPress pages for individual tables
- Toggle table lock status (locked/unlocked access)
- View table column information

## Shortcodes

### `[supabase_table]`
Display a single table with DataTables interface.

```
[supabase_table table="your_table_name"]
```

### `[supabase_multi_search]`
Display multi-database search interface.

```
[supabase_multi_search]
```

## Directory Structure

```
armember-supabase-plugin/
├── admin/
│   ├── css/
│   │   └── table-manager.css
│   ├── js/
│   │   └── admin-page.js
│   └── class-admin-page.php
├── includes/
│   ├── class-data-display.php
│   ├── class-supabase-client.php
│   └── class-sync-handler.php
├── public/
│   ├── css/
│   │   └── multi-search.css
│   └── js/
│       └── multi-search.js
├── supabase-armember-sync.php
└── README.md
```

## Requirements

- WordPress 5.0 or higher
- PHP 7.2 or higher
- ARMember plugin
- Supabase account

## License

Proprietary
