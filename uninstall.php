<?php

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('tlwpvg_m2m_id');
delete_option('tlwpvg_m2m_key');
delete_option('tlwpvg_last_sync_date');