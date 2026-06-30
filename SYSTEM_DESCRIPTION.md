# DICT SARO Monitoring System

## Overview
The SARO (Special Allotment Release Order) Monitoring System operates as a robust, responsive web application built on a modern technology stack. It requires no local software installation and can be accessed securely from any location with an active internet connection.

The system's architecture relies on the following core technologies:

**Backend Framework**: The core server-side logic, routing, and data management are powered by custom Object-Oriented PHP, providing a secure, scalable, and efficient foundation for handling budget and procurement data.

**Database Management**: Data storage and retrieval are managed through MySQL, utilizing PHP Data Objects (PDO) to ensure secure, reliable, and injection-free transactions for all system records.

**Frontend Design**: The user interface is styled using Tailwind CSS, ensuring a clean, highly responsive, and modern layout that adapts dynamically to provide an optimal viewing experience across desktop computers, laptops, tablets, and smartphones.

**Utility & Automation**: Python scripts are integrated into the architecture to efficiently manage backend data synchronization, batch processing, and automated system maintenance tasks.

## System Requirements
To ensure a smooth and uninterrupted experience, users must meet the following minimum requirements:

- **Internet Connection**: A stable broadband, DSL, or mobile data connection. (7 Mbps minimum)
- **Hardware**: Any functional desktop, laptop, tablet, or smartphone capable of running modern web browsers.
- **Supported Web Browsers**: The latest official versions of Google Chrome, Mozilla Firefox, Microsoft Edge, or Apple Safari.
- **Browser Settings**: JavaScript and Cookies must be enabled in the browser settings for the platform's features to function correctly.

## Getting Started

To operate, the system requires an account. The platform utilizes official administrator provisioning, with strict verification protocols in place to ensure data integrity and security across the department.

### Account Creation and Provisioning

- **Standard User Accounts**: Personnel handling financial or procurement data must have an account to access the system. To unlock data-entry privileges, these accounts are manually created and provisioned by a System Administrator to confirm the user is authorized personnel.
- **Administrator Accounts**: To protect sensitive budget data, prevent unauthorized modifications, and maintain the integrity of procurement records, only existing IT Administrators have the authority to create and provision top-level accounts.

### System Access

Navigate to the official SARO Monitoring System web portal using any supported web browser. The secure URL will be distributed internally by the IT Office.

Upon reaching the login portal, enter your registered credentials (email and password) to access your dashboard.

### Forgot Password

If you have forgotten your password, click the **Forgot Password** link on the login page and enter your registered email address. The system will send an email containing a password reset link. 

Click the link in the email to be directed to the password reset page. Enter and confirm your new password. Once completed, you may log in to the system using your new credentials. (Note: Depending on system settings, some password reset requests may require manual approval from an Administrator).

## Directory Structure
- `/admin/` - Contains administrative features (user management, system logs).
- `/saro/` - Contains the core functionality of the SARO tracking system (dashboard, data entry, reports).
- `/includes/` - Shared backend PHP scripts (e.g., database connection, auth logic).
- `/assets/`, `/dist/`, `/src/` - Frontend assets, compiled CSS/JS, and source files.
- `/class/` - Object-oriented PHP classes.

## Key Pages and Functionalities

### Public/Auth Pages
- **`index.php`**: The landing page of the application, introducing the system.
- **`login.php`**: User authentication portal.
- **`forgot_password.php` / `reset_password.php`**: Account recovery flows.
- **`setup.php`**: Initial system setup or database initialization.
- **`logout.php`**: Terminates the user session.

### SARO Module (`/saro/`)
This module is intended for users handling financial and procurement data.
- **`dashboard.php`**: The main overview presenting key metrics, analytics, and summaries of SARO statuses.
- **`data_entry.php`**: Interface for inputting new SARO records, travel expenses, and other budget-related data.
- **`view_saro.php`**: Detailed view of specific SARO entries, allowing users to see itemized budgets, travel lists, and expenses.
- **`obligated_saro.php`**: Tracks SAROs that have been obligated or committed to specific contracts/expenses.
- **`cancelled_saro.php`**: Records of SAROs that have been cancelled or revoked.
- **`lapsed_saro.php`**: Monitors SAROs that have reached their expiration or validity period without being fully utilized.
- **`procurement_stat.php` & `view_procure_act.php`**: Tracks the status of procurement activities tied to the budget releases.
- **`export_records.php`**: Generates reports (e.g., CSV, Excel) of SARO data for offline review and auditing.
- **`audit_logs.php`**: Keeps a trail of specific data modifications within the SARO records.
- **`settings.php`**: User-specific or module-specific configurations.

### Admin Module (`/admin/`)
This module is restricted to system administrators for managing access and monitoring system-wide activity.
- **`dashboard.php`**: An overview of system usage, user counts, and pending administrative requests.
- **`users.php`**: User management page to add, edit, or deactivate accounts and set access permissions.
- **`password_requests.php`**: Handles and approves password reset requests from users.
- **`activity_logs.php`**: A comprehensive log of all user activities (logins, logouts, data changes) for security auditing.
- **`export_records.php`**: Allows administrators to export system logs and user data.

## Python Utility Scripts
The root directory includes several Python automation scripts (e.g., `fix_icons.py`, `fix_permissions.py`, `sync_travel.py`, `modify_cancelled_saro.py`). These appear to be used for batch data processing, database migrations, syncing travel data, and applying automated patches to the PHP source code during development.
