# Admin Chat Management

A lightweight WordPress plugin designed to manage administrative and customer chat functionalities directly within the WordPress admin dashboard and front-end interface.

## Description

**Admin Chat Management** provides an integrated interface for site administrators to manage and respond to chat interactions. It includes dedicated admin-side controls alongside front-end chat assets for seamless communication.

## Features

* **Admin Chat Dashboard:** Specialized styles (`admin.css`) and scripts (`admin.js`) for managing conversations within the WordPress backend[cite: 1].
* **Front-End Chat Interface:** Lightweight styling (`chat.css`) and script execution (`chat.js`) for visitor or user interaction[cite: 1].
* **Clean File Structure:** Lightweight, modular organization with minimal overhead[cite: 1].

## File Structure

```text
admin-chat-management/
├── admin-chat-management.php   # Main plugin file & entry point
└── assets/
    ├── admin.css               # Backend admin dashboard styles
    ├── admin.js                # Backend admin dashboard scripts
    ├── chat.css                # Front-end chat widget styles
    └── chat.js                 # Front-end chat widget scripts
```[cite: 1]

## Installation

1. Download or zip the `admin-chat-management` folder.
2. Log in to your WordPress Admin Dashboard.
3. Navigate to **Plugins > Add New > Upload Plugin**.
4. Choose the `admin-chat-management.zip` file and click **Install Now**.
5. Click **Activate Plugin**.

Alternatively, upload the `admin-chat-management` folder directly to your server's `/wp-content/plugins/` directory via FTP, then activate it from the WordPress Admin Dashboard[cite: 1].

## Usage

Once activated, the plugin initializes through `admin-chat-management.php`[cite: 1]:
* Admin options and chat management tools are accessible from the WordPress admin menu[cite: 1].
* Front-end scripts (`chat.js`) and styles (`chat.css`) automatically load on supported pages to render the chat interface for visitors[cite: 1].

## Requirements

* **WordPress:** 5.0 or higher
* **PHP:** 7.4 or higher

## License

This plugin is open-source software licensed under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
