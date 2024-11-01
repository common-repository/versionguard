<?php

/**
 * Plugin Name: VersionGuard
 * Description: This plugin feeds a centralized database (SaaS) to manage version information for multiple WordPress installations.
 * Version: 1.3
 * Author: ThreatLabs
 * Author URI: https://threatlabs.eu
 * License: GPLv2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network: true
 *
 * Note: This software is distributed under the terms of the GNU General Public License (GPLv2).
 * See https://www.gnu.org/licenses/gpl-2.0.html for the full text of the license.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
require_once ABSPATH . 'wp-admin/includes/plugin.php'; //Necessary in a multisite network

define('TLWPVG_API', 'https://portal.threatlabs.eu/api/asm/hook/');

register_activation_hook(__FILE__, 'TLWPVG_activation');
add_action('TLWPVG_send_installation_info', 'TLWPVG_send_installation_info');
register_deactivation_hook(__FILE__, 'TLWPVG_deactivation');

function TLWPVG_activation()
{
    if (!wp_next_scheduled('TLWPVG_send_installation_info')) {
        wp_schedule_event(time(), 'twicedaily', 'TLWPVG_send_installation_info');
    }
}

function TLWPVG_deactivation()
{
    wp_clear_scheduled_hook('TLWPVG_send_installation_info');
}

function TLWPVG_send_installation_info()
{
    $sites = is_multisite() ? get_sites() : array(get_current_blog_id());
    $auth_token = get_option('tlwpvg_m2m_id', '') . ':' . get_option('tlwpvg_m2m_key', '');

    if ($auth_token != ':') {
        foreach ($sites as $site) {
            if (is_multisite()) {
                switch_to_blog($site->blog_id);
            }

            $data_to_send = TLWPVG_collect_data($site->blog_id);
            $response = TLWPVG_send_report_to_api($auth_token, $data_to_send);

            if (is_wp_error($response)) {
                error_log('VersionGuard error: ' . $response->get_error_message());
            } else {
                TLWPVG_save_last_sync_date();
            }

            if (is_multisite()) {
                restore_current_blog();
                sleep(20);
            }
        }
    } else {
        TLWPVG_register();
    }
}

function TLWPVG_register()
{
    $response = wp_safe_remote_post(
        TLWPVG_API . 'register/',
        array(
            'body' => '',
            'headers' => array('Content-Type' => 'application/json'),
        )
    );

    if (!is_wp_error($response)) {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if ($data && isset($data->id) && isset($data->key)) {
            update_option('tlwpvg_m2m_id', $data->id);
            update_option('tlwpvg_m2m_key', $data->key);
        }
    }
}

function TLWPVG_get_server_info()
{
    $info = [];

    $info['os']['type'] = php_uname('s');
    $info['os']['release'] = php_uname('r');
    $info['os']['version'] = php_uname('v');

    $info['hostname'] = gethostname();
    $info['architecture'] = php_uname('m');

    return $info;
}

function TLWPVG_collect_data($id)
{
    $user_counts = count_users();

    return array(
        'multisite' => is_multisite(),
        'site_id' => $id,
        'wordpress_version' => get_bloginfo('version'),
        'auto_update_core' => get_site_option('auto_update_core', false),
        'admin_count' => $user_counts['avail_roles']['administrator'],
        'user_count' => $user_counts['total_users'],
        'blog_name' => get_bloginfo('name'),
        'blog_url' => home_url(),
        'php_version' => phpversion(),
        'server' => TLWPVG_get_server_info(),
        'plugins' => TLWPVG_get_all_plugins_info(),
        'themes' => TLWPVG_get_all_themes_info()
    );
}

function TLWPVG_send_report_to_api($auth_token, $data_to_send)
{
    $data_structure = array(
        'user_agent' => 'VersionGuard',
        'version' => '1.3',
        'data' => $data_to_send,
    );


    return wp_safe_remote_post(
        TLWPVG_API,
        array(
            'body' => json_encode($data_structure),
            'headers' => array('Content-Type' => 'application/json', 'Authorization' => 'Token ' . $auth_token),
        )
    );
}

function TLWPVG_get_all_plugins_info()
{
    $plugins_info = array();

    if (function_exists('get_plugins')) {
        $plugins = get_plugins();
        $update_plugins = get_site_transient('update_plugins');
        $auto_update_settings = get_site_option('auto_update_plugins', false);

        foreach ($plugins as $plugin_path => $plugin_info) {
            $plugin_file = plugin_basename($plugin_path);
            $has_update = $update_plugins && !empty($update_plugins->response[$plugin_file]);
            $slug = $has_update ? $update_plugins->response[$plugin_file]->slug : null;
            $auto_update_enabled = $auto_update_settings && in_array($plugin_file, $auto_update_settings);

            $plugins_info[] = array_merge($plugin_info, array(
                'Path' => $plugin_path,
                'Active' => is_plugin_active($plugin_file),
                'NetworkActive' => is_plugin_active_for_network($plugin_file),
                'HasUpdate' => $has_update,
                'AutoUpdateEnabled' => $auto_update_enabled,
                'Slug' => $slug
            )
            );
        }
    }

    return $plugins_info;
}

function TLWPVG_get_all_themes_info()
{
    $themes_info = array();
    $themes = wp_get_themes();
    $update_themes = get_site_transient('update_themes');
    $auto_update_settings = get_site_option('auto_update_themes', false);

    foreach ($themes as $theme_key => $theme) {
        $is_active = $theme_key === get_option('template');
        $has_update = $update_themes && !empty($update_themes->response[$theme_key]);
        $auto_update_enabled = $auto_update_settings && in_array($theme_key, $auto_update_settings);

        $themes_info[] = array(
            'Key' => $theme_key,
            'Path' => $theme->get_template(),
            'Author' =>  $theme->get('Author'),
            'TextDomain' =>  $theme->get('TextDomain'),
            'Name' => $theme->get('Name'),
            'ThemeURI' => $theme->get('ThemeURI'),
            'Version' => $theme->get('Version'),
            'Status' => $theme->get('Status'),
            'Active' => $is_active,
            'HasUpdate' => $has_update,
            'AutoUpdateEnabled' => $auto_update_enabled,
        );
    }

    return $themes_info;
}

function TLWPVG_save_last_sync_date()
{
    $last_sync_date = current_time('mysql', true);
    update_option('tlwpvg_last_sync_date', $last_sync_date);
}

function tlwpvg_menu()
{
    if (current_user_can('manage_options') && is_main_site()) {
        add_options_page(
            'VersionGuard Settings',
            'VersionGuard',
            'manage_options',
            'tlwpvg-versionguard-settings',
            'tlwpvg_page'
        );
    }
}
add_action('admin_menu', 'tlwpvg_menu');

function tlwpvg_page()
{
    if (current_user_can('manage_options') && is_main_site()) {
        ?>
        <div class="wrap">
            <h2>VersionGuard Settings</h2>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="blogname">ID</label></th>
                        <td>
                            <input type="text" value="<?php echo esc_attr(get_option('tlwpvg_m2m_id')); ?>" class="regular-text">
                            <p class="description" id="tagline-description">Kindly share or register this ID.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="blogname">Last successful push</label></th>
                        <td><input type="text" value="<?php echo esc_attr(get_option('tlwpvg_last_sync_date')); ?>" class="regular-text" disabled></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
