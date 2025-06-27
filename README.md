# PIM Sync – Integration of Compo PIM with CS-Cart

## Description

An add-on for automatic synchronization of product catalog from the Compo PIM system into CS-Cart.

## Features

-   ✅ Full synchronization of categories and products
-   ✅ Incremental synchronization of changed products
-   ✅ Automatic image upload
-   ✅ Logging of all operations
-   ✅ Admin panel interface for management
-   ✅ Automatic scheduled synchronization via cron

## Installation

### 1. Installation via admin panel

1. Go to the CS-Cart admin panel
2. Navigate to **Add-ons → Manage add-ons**
3. Click **+** to upload a new add-on
4. Upload the archive with the add-on or specify the path to the `app/addons/pim_sync` folder
5. Find the "Compo PIM Synchronization" module in the list
6. Click **Install**

### 2. Activation and configuration

1. After installation, go to the add-on settings
2. Enter the API connection parameters:
    - **API URL**: `https://YOUR_API_URL`
    - **API Login**: login
    - **API Password**: password
    - **Catalog UID**: `1111111-2222-3333-4444-55555555555`
3. Enable automatic synchronization if needed
4. Save the settings

### 3. First run

1. Go to **Catalog → PIM Synchronization**
2. Click **Test Connection** to check API availability
3. Perform a **Full Synchronization** for initial data load

## Usage

### Manual synchronization

The admin panel provides the following actions:

-   **Full Synchronization** – loads all categories and products
-   **Sync Changes** – updates only changed products for the specified period
-   **Test Connection** – checks API accessibility

### Automatic synchronization

To enable automatic synchronization, add to `crontab`:

```bash
# Incremental synchronization every 30 minutes
0,30 * * * * php /path/to/cscart/app/addons/pim_sync/cron/sync.php

# Full synchronization once a week (Sunday at 3:00 AM)
0 3 * * 0 php /path/to/cscart/app/addons/pim_sync/cron/sync.php --full
```
