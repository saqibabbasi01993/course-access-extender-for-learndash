=== Course Access Extender for LearnDash ===
Contributors:      skfreelancers, saqibabbasi
Tags:              learndash, course access, lms, permissions, woocommerce
Requires at least: 6.0
Tested up to:      7.1
Requires PHP:      7.4
Stable tag:        1.1.4
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Extend and control course access in LearnDash LMS when WooCommerce extension products are purchased.

== Description ==

**Course Access Extender for LearnDash** solves a common gap in the LearnDash + WooCommerce integration and adds a complete student management dashboard.

> **Disclaimer:** This plugin is an independent third-party tool and is not affiliated with, endorsed by, or sponsored by LearnDash or its parent company. "LearnDash" is a trademark of its respective owner. This plugin is simply designed to be compatible with the LearnDash LMS plugin.

= Core Fix =

If a student buys a Course Extension product **before** their current access has expired, LearnDash does not extend the access correctly — it either resets or ignores it. This plugin intercepts the order and adds the extension days on top of the existing expiry.

**Example:** Student has 30 days left → buys 90-day extension → gets 30 + 90 = 120 more days. ✅

= Students Dashboard =

A new **Students** tab under WooCommerce → Course Access Extender lets you:

* View all enrolled students with their course, expiry date, days remaining, and status (Active / Expired).
* Filter by course, status (active/expired), expiry window, enrollment date range, or search by name/email.
* Sort by student name or expiry date.
* **Adjust any student's expiry date** directly from the table with a date picker.
* **View a student's full purchase history** in a modal — showing all course-related orders with dates, products, quantities, totals, and order status.

= Activity Log =

A full audit trail of every access grant, extension, admin adjustment, and profile change with filters for course, action type, keyword search, and date range.

= Requirements =

* WordPress 6.0+
* WooCommerce 7.0+
* LearnDash LMS 4.0+
* PHP 7.4+

== Installation ==

1. Upload the `course-access-extender-for-learndash` folder to `/wp-content/plugins/`.
2. Activate through the **Plugins** screen.
3. Go to **WooCommerce → Course Access Extender → Settings** and configure the extension duration.
4. Edit your Course Extension WooCommerce product → open the **Course Extension** tab → enable it and select the linked LearnDash course.

== Frequently Asked Questions ==

= Is this plugin affiliated with LearnDash? =

No. This is an independent plugin built to work with the LearnDash LMS plugin. It is not affiliated with, endorsed by, or sponsored by LearnDash or its parent company.

= What happens if I deactivate the plugin? =

Your data is preserved. The plugin does not delete any data on deactivation. To remove all data, enable "Delete all data on uninstall" in Settings before deleting the plugin.

== Changelog ==

= 1.1.4 =
* Fixed: Purchasing a Course Extension after access had already expired granted double the days (e.g. 180 instead of 90) when the product was also linked to the course in LearnDash's WooCommerce settings. The student's pre-purchase expiry is now snapshotted before LearnDash re-enrolls them.
* Fixed: Checkout extensions now use a true UTC timestamp for "now", so fresh grants are no longer shifted by the site's timezone offset.

= 1.1.2 =
* Renamed: Plugin renamed to "Course Access Extender for LearnDash" for WordPress.org trademark compliance.
* Updated: All internal prefixes changed from ldce_ to cael_ throughout.
* Updated: Text domain updated to course-access-extender-for-learndash.
* Fixed: All Plugin Check warnings resolved for WordPress.org submission.
* Fixed: Logger queries use esc_sql() with documented phpcs suppressions.
* Fixed: uninstall.php global variables correctly prefixed.
* Fixed: load_plugin_textdomain() removed (auto-loaded since WP 4.6).
* Added: index.php silence files in all subdirectories.
* Updated: Tested up to WordPress 7.1.

= 1.1.0 =
* Added Students tab with enrollment list, expiry management, and purchase history modal.
* Added AJAX expiry adjustment with admin log entry.
* Added per-product access days field (30, 40, 90-day products etc.).
* Added Activity Log filters: course, action type, keyword search, date range.
* Added Students tab filters: expiry-within, enrollment date range.
* Added "Enrolled Since" column to Students tab.
* Added data-preservation default on uninstall (opt-in to delete via Settings).

= 1.0.0 =
* Initial release.
