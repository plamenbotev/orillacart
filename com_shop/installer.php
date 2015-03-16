<?php

class shop_installer extends BObject {

    public function tables() {

        $tables = array(
            "shop_stockroom" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_stockroom` (
						`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
						`name` varchar(250) NOT NULL,
						`delivery_time` enum('day','week') NOT NULL,
						`min_del_time` int(11) NOT NULL,
						`max_del_time` int(11) NOT NULL,
						`desc` longtext NOT NULL,
						`published` set('yes','no') NOT NULL DEFAULT 'no',
						PRIMARY KEY (`id`)
					) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COMMENT='Stockroom'",
            "shop_attribute_set" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_attribute_set` (
						`attribute_set_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
						`attribute_set_name` varchar(255) NOT NULL,
						`published` enum('yes','no') NOT NULL DEFAULT 'yes',
						PRIMARY KEY (`attribute_set_id`)
					) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COMMENT='Attribute set detail'",
            "shop_attribute" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_attribute` (
						`attribute_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
						`attribute_name` varchar(250) NOT NULL,
						`attribute_required` enum('yes','no') NOT NULL DEFAULT 'no',
						`hide_attribute_price` enum('yes','no') NOT NULL DEFAULT 'no',
						`product_id` bigint(20) unsigned DEFAULT NULL,
						`ordering` int(11) NOT NULL,
						`attribute_set_id` bigint(20) unsigned DEFAULT NULL,
						`stockroom_id` bigint(20) unsigned DEFAULT NULL,
						PRIMARY KEY (`attribute_id`),
						KEY `attribute_set_id` (`attribute_set_id`),
						KEY `product_id` (`product_id`),
						KEY `stockroom_id` (`stockroom_id`),
						KEY `attribute_required` (`attribute_required`),
						FOREIGN KEY (`attribute_set_id`) REFERENCES `#_shop_attribute_set` (`attribute_set_id`) ON DELETE CASCADE ON UPDATE CASCADE,
						FOREIGN KEY (`stockroom_id`) REFERENCES `#_shop_stockroom` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
					) ENGINE=InnoDB  DEFAULT CHARSET=utf8",
            "shop_attribute_property" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_attribute_property` (
						`property_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
						`attribute_id` bigint(20) unsigned NOT NULL,
						`property_name` varchar(255) NOT NULL,
						`property_price` double NOT NULL,
						`oprand` enum('+','-','*','/') NOT NULL DEFAULT '+',
						`ordering` int(11) NOT NULL,
						PRIMARY KEY (`property_id`),
						KEY `attribute_id` (`attribute_id`),
						FOREIGN KEY (`attribute_id`) REFERENCES `#_shop_attribute` (`attribute_id`) ON DELETE CASCADE ON UPDATE CASCADE
					) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COMMENT='Products Attribute Property'",
            "shop_cart" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_cart` (
						`session_id` varchar(255) NOT NULL,
						`product_id` int(11) NOT NULL,
						`section` enum('product','property','sub_attribute') NOT NULL DEFAULT 'product',
						`qty` int(11) NOT NULL,
						`time` double NOT NULL,
						`sess_group` int(11) NOT NULL,
						`last_access` bigint(20) NOT NULL,
						KEY `session_id` (`session_id`,`sess_group`),
						KEY `session_id_3` (`session_id`),
						KEY `last_access` (`last_access`)
					) ENGINE=InnoDB DEFAULT CHARSET=utf8",
            "shop_category_xref" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_category_xref` (
						`category_parent_id` bigint(20) NOT NULL DEFAULT '0',
						`category_child_id` bigint(20) NOT NULL DEFAULT '0',
						`a11` bigint(20) unsigned NOT NULL,
						`a21` bigint(20) unsigned NOT NULL,
						`a12` bigint(20) unsigned NOT NULL,
						`a22` bigint(20) unsigned NOT NULL,
						`position` int(11) NOT NULL,
						`type` varchar(255) NOT NULL,
						PRIMARY KEY (`category_child_id`),
						UNIQUE KEY `a11_2` (`a11`,`a21`,`a12`,`a22`),
						KEY `category_xref_category_parent_id` (`category_parent_id`),
						KEY `a11` (`a11`,`a21`,`a12`,`a22`),
						KEY `category_parent_id` (`category_parent_id`,`category_child_id`,`a11`,`a21`,`a12`,`a22`,`position`),
						KEY `a11_3` (`a11`),
						KEY `a21` (`a21`),
						KEY `a12` (`a12`),
						KEY `a22` (`a22`)
					) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Category child-parent relation list'",
            "shop_country" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_country` (
						`country_id` int(11) NOT NULL AUTO_INCREMENT,
						`country_name` varchar(64) DEFAULT NULL,
						`country_3_code` char(3) DEFAULT NULL,
						`country_2_code` char(2) DEFAULT NULL,
						`in_eu` enum('yes','no') NOT NULL DEFAULT 'no',
						PRIMARY KEY (`country_id`),
						UNIQUE KEY `country_3_code` (`country_3_code`),
						UNIQUE KEY `country_2_code` (`country_2_code`),
						KEY `idx_country_name` (`country_name`),
						KEY `in_eu` (`in_eu`)
					) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COMMENT='Country records' AUTO_INCREMENT=0",
            "shop_methods" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_methods` (
						`method_id` int(11) NOT NULL AUTO_INCREMENT,
						`class` varchar(255) DEFAULT NULL,
						`name` char(80) NOT NULL,
						`method_order` int(11) NOT NULL DEFAULT '0',
						`type` enum('shipping','payment') NOT NULL,
						`countries` longtext,
                                                `params` longtext,
						PRIMARY KEY (`method_id`),
						UNIQUE KEY `method_id` (`method_id`,`type`),
						KEY `shipping_carrier_list_order` (`method_order`),
						KEY `class` (`class`),
						KEY `type` (`type`)
					) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=0",
            "shop_order_item" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_order_item` (
						`order_item_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
						`order_id` bigint(20) unsigned DEFAULT NULL,
						`product_id` int(11) DEFAULT NULL,
						`product_type` varchar(255) NOT NULL DEFAULT 'regular',
						`order_item_sku` varchar(64) DEFAULT NULL,
						`order_item_name` varchar(64) NOT NULL DEFAULT '',
						`product_quantity` int(11) DEFAULT NULL,
						`product_item_price` decimal(15,5) DEFAULT NULL,
						`vat` decimal(12,2) NOT NULL DEFAULT '0.00',
						`stockrooms` longtext,
						`access_granted` datetime DEFAULT NULL,
						`product_length` double DEFAULT NULL,
						`product_width` double DEFAULT NULL,
						`product_height` double DEFAULT NULL,
						`product_volume` double DEFAULT NULL,
						`product_diameter` double DEFAULT NULL,
						`product_weight` double DEFAULT NULL,
						PRIMARY KEY (`order_item_id`),
						KEY `order_id` (`order_id`),
						KEY `product_type` (`product_type`),
						KEY `access_granted` (`access_granted`)
					) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COMMENT='Stores all items (products) which are part of an order' AUTO_INCREMENT=0",
            "shop_order_attribute_item" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_order_attribute_item` (
						`order_att_item_id` int(11) NOT NULL AUTO_INCREMENT,
						`order_item_id` bigint(20) unsigned DEFAULT NULL,
						`order_id` bigint(20) unsigned NOT NULL,
						`section_id` int(11) NOT NULL,
						`section` enum('property','file','tax') NOT NULL,
						`parent_section_id` int(11) DEFAULT NULL,
						`section_name` varchar(250) DEFAULT NULL,
						`section_price` decimal(15,4) DEFAULT NULL,
						`section_oprand` char(1) DEFAULT NULL,
						`stockrooms` longtext,
						`downloads_remaining` int(11) DEFAULT NULL,
						`expires` datetime DEFAULT NULL,
						PRIMARY KEY (`order_att_item_id`),
						UNIQUE KEY `order_item_id_2` (`order_item_id`,`section_id`,`section`),
						KEY `order_item_id` (`order_item_id`),
						KEY `order_id` (`order_id`),
						FOREIGN KEY (`order_item_id`) REFERENCES `#_shop_order_item` (`order_item_id`) ON DELETE CASCADE ON UPDATE CASCADE
					) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COMMENT='order Attribute item'",
            "shop_products_stockroom_xref" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_products_stockroom_xref` (
						`product_id` bigint(20) unsigned NOT NULL,
						`stockroom_id` bigint(20) unsigned NOT NULL DEFAULT '0',
						`stock` int(11) NOT NULL,
						PRIMARY KEY (`product_id`,`stockroom_id`),
						KEY `product_id` (`product_id`),
						KEY `stockroom_id` (`stockroom_id`),
						FOREIGN KEY (`stockroom_id`) REFERENCES `#_shop_stockroom` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
					) ENGINE=InnoDB DEFAULT CHARSET=utf8",
            "shop_product_attribsets" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_product_attribsets` (
						`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
						`pid` bigint(20) unsigned NOT NULL,
						`sid` bigint(20) unsigned NOT NULL,
						PRIMARY KEY (`id`),
						UNIQUE KEY `pid_2` (`pid`,`sid`),
						KEY `pid` (`pid`),
						KEY `aid` (`sid`),
						FOREIGN KEY (`sid`) REFERENCES `#_shop_attribute_set` (`attribute_set_id`) ON DELETE CASCADE ON UPDATE CASCADE
					) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=0",
            "shop_property_stockroom_xref" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_property_stockroom_xref` (
						`property_id` bigint(20) unsigned NOT NULL,
						`stockroom_id` bigint(20) unsigned NOT NULL,
						`stock` int(11) NOT NULL,
						PRIMARY KEY (`property_id`,`stockroom_id`),
						KEY `stockroom_id` (`stockroom_id`),
						FOREIGN KEY (`property_id`) REFERENCES `#_shop_attribute_property` (`property_id`) ON DELETE CASCADE ON UPDATE CASCADE,
						FOREIGN KEY (`stockroom_id`) REFERENCES `#_shop_stockroom` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
					) ENGINE=InnoDB DEFAULT CHARSET=utf8",
            "shop_tax_group" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_tax_group` (
						`tax_group_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
						`tax_group_name` varchar(255) NOT NULL,
						`published` tinyint(4) NOT NULL,
						PRIMARY KEY (`tax_group_id`)
					) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COMMENT='Tax Group' AUTO_INCREMENT=0",
            "shop_shipping_rate" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_shipping_rate` (
						`shipping_rate_id` int(11) NOT NULL AUTO_INCREMENT,
						`shipping_rate_name` varchar(255) NOT NULL DEFAULT '',
						`carrier` int(11) NOT NULL,
						`shipping_rate_country` longtext,
						`shipping_rate_zip_start` varchar(20) NOT NULL,
						`shipping_rate_zip_end` varchar(20) NOT NULL,
						`shipping_rate_weight_start` decimal(10,2) NOT NULL,
						`apply_vat` enum('global','yes','no') NOT NULL DEFAULT 'global',
						`shipping_rate_weight_end` decimal(10,2) NOT NULL,
						`shipping_rate_volume_start` decimal(10,2) NOT NULL,
						`shipping_rate_volume_end` decimal(10,2) NOT NULL,
						`shipping_rate_ordertotal_start` decimal(10,3) NOT NULL DEFAULT '0.000',
						`shipping_rate_ordertotal_end` decimal(10,3) NOT NULL,
						`shipping_rate_priority` tinyint(4) NOT NULL DEFAULT '0',
						`shipping_rate_value` decimal(10,2) NOT NULL DEFAULT '0.00',
						`shipping_rate_package_fee` decimal(10,2) NOT NULL DEFAULT '0.00',
						`shipping_location_info` longtext NOT NULL,
						`shipping_rate_length_start` decimal(10,2) NOT NULL,
						`shipping_rate_length_end` decimal(10,2) NOT NULL,
						`shipping_rate_width_start` decimal(10,2) NOT NULL,
						`shipping_rate_width_end` decimal(10,2) NOT NULL,
						`shipping_rate_height_start` decimal(10,2) NOT NULL,
						`shipping_rate_height_end` decimal(10,2) NOT NULL,
						`shipping_tax_group_id` bigint(20) unsigned DEFAULT NULL,
						`shipping_rate_state` longtext,
						`qty_multiply` ENUM( 'no', 'yes' ) NOT NULL DEFAULT 'no',
						PRIMARY KEY (`shipping_rate_id`),
						KEY `carrier` (`carrier`),
						KEY `shipping_tax_group_id` (`shipping_tax_group_id`),
						FOREIGN KEY (`shipping_tax_group_id`) REFERENCES `#_shop_tax_group` (`tax_group_id`) ON DELETE CASCADE ON UPDATE CASCADE,
						FOREIGN KEY (`carrier`) REFERENCES `#_shop_methods` (`method_id`) ON DELETE CASCADE ON UPDATE CASCADE
					) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=0",
            "shop_state" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_state` (
						`state_id` int(11) NOT NULL AUTO_INCREMENT,
						`country_id` char(2) NOT NULL DEFAULT '1',
						`state_name` varchar(64) DEFAULT NULL,
						`state_3_code` char(3) DEFAULT NULL,
						`state_2_code` char(2) DEFAULT NULL,
						PRIMARY KEY (`state_id`),
						UNIQUE KEY `country_id_2` (`country_id`,`state_2_code`),
						UNIQUE KEY `country_id_3` (`country_id`,`state_3_code`),
						KEY `country_id` (`country_id`),
						FOREIGN KEY (`country_id`) REFERENCES `#_shop_country` (`country_2_code`) ON DELETE CASCADE ON UPDATE CASCADE
					) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COMMENT='States that are assigned to a country' AUTO_INCREMENT=0",
            "shop_tax_rate" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_tax_rate` (
						`tax_rate_id` int(11) NOT NULL AUTO_INCREMENT,
						`tax_state` char(2) DEFAULT NULL,
						`tax_country` char(2) DEFAULT NULL,
						`tax_name` varchar(64) DEFAULT NULL,
						`tax_rate` decimal(10,4) DEFAULT NULL,
						`tax_group_id` bigint(20) unsigned NOT NULL,
						`priority` bigint(20) unsigned NOT NULL,
						PRIMARY KEY (`tax_rate_id`),
						
						KEY `tax_state_2` (`tax_state`),
						KEY `tax_country` (`tax_country`),
						KEY `tax_group_id` (`tax_group_id`),
						KEY `priority` (`priority`),
						FOREIGN KEY (`tax_group_id`) REFERENCES `#_shop_tax_group` (`tax_group_id`) ON DELETE CASCADE ON UPDATE CASCADE,
						FOREIGN KEY (`tax_country`) REFERENCES `#_shop_country` (`country_2_code`) ON DELETE CASCADE ON UPDATE CASCADE
					) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COMMENT='Tax Rates' AUTO_INCREMENT=0",
            "shop_termmeta" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_termmeta` (
						`meta_id` bigint(20) NOT NULL AUTO_INCREMENT,
						`term_id` bigint(20) NOT NULL,
						`meta_key` varchar(255) DEFAULT NULL,
						`meta_value` longtext,
						PRIMARY KEY (`meta_id`)
					) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=0",
            "shop_variations" =>
            "CREATE TABLE IF NOT EXISTS `#_shop_variations` (
                                                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                                                `pid` bigint(20) unsigned NOT NULL,
                                                `prop` bigint(20) unsigned NOT NULL,
                                                 PRIMARY KEY (`id`),
                                                 UNIQUE KEY `pid_2` (`pid`,`prop`),
                                                 KEY `prop` (`prop`),
                                                 KEY `pid` (`pid`),
                                	         FOREIGN KEY (`prop`) REFERENCES `#_shop_attribute_property` (`property_id`) ON DELETE CASCADE ON UPDATE CASCADE
                                         ) ENGINE=InnoDB");

        return $tables;
    }

    public function activate() {

        require_once(dirname(__FILE__) . "/action_handlers.php");

        $params = Factory::getComponent('shop')->getParams();



        if (!$params->get('is_installed')) {

            $tables = $this->tables();

            $db = Factory::getDBO();

            //Create all tables

            foreach ($tables as $table) {

                $db->setQuery($table);

                if (!$db->getResource()) {

                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }
            }

            $db->setQuery($this->import_countries());

            if (!$db->getResource()) {

                trigger_error($db->getErrorString(), E_USER_ERROR);
                exit;
            }
            $db->setQuery($this->import_states());

            if (!$db->getResource()) {

                trigger_error($db->getErrorString(), E_USER_ERROR);
                exit;
            }

            //create base page that will be used

            $page_data = array(
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_author' => 1,
                'post_name' => '',
                'post_title' => __('Shop', 'com_shop'),
                'post_content' => '[framework component="shop"]',
                'comment_status' => 'closed'
            );

            $id = $this->create_single_page('shop', $page_data);

            if ($id) {

                $params->set('page_id', $id);
                $params->save();
            }

            orillacart_actions::init()->register_types();

            //add capabilities and roles
            //add roles

            add_role('customer', 'Customer', array(
                'read' => true,
                'edit_posts' => false,
                'delete_posts' => false
            ));

            global $wp_roles;

            if (class_exists('WP_Roles')) {
                if (!isset($wp_roles)) {
                    $wp_roles = new WP_Roles();
                }
            }

            add_role('shop_manager', __('Shop Manager', 'com_shop'), array(
                'level_9' => true,
                'level_8' => true,
                'level_7' => true,
                'level_6' => true,
                'level_5' => true,
                'level_4' => true,
                'level_3' => true,
                'level_2' => true,
                'level_1' => true,
                'level_0' => true,
                'read' => true,
                'read_private_pages' => true,
                'read_private_posts' => true,
                'edit_users' => true,
                'edit_posts' => true,
                'edit_pages' => true,
                'edit_published_posts' => true,
                'edit_published_pages' => true,
                'edit_private_pages' => true,
                'edit_private_posts' => true,
                'edit_others_posts' => true,
                'edit_others_pages' => true,
                'publish_posts' => true,
                'publish_pages' => true,
                'delete_posts' => true,
                'delete_pages' => true,
                'delete_private_pages' => true,
                'delete_private_posts' => true,
                'delete_published_pages' => true,
                'delete_published_posts' => true,
                'delete_others_posts' => true,
                'delete_others_pages' => true,
                'manage_categories' => true,
                'manage_links' => true,
                'moderate_comments' => true,
                'unfiltered_html' => true,
                'upload_files' => true,
                'export' => true,
                'import' => true,
                'list_users' => true
            ));




            $capabilities = array();

            $capabilities['core'] = array(
                'manage_shop'
            );

            $capability_types = array('product', 'shop_order');

            foreach ($capability_types as $capability_type) {

                $capabilities[$capability_type] = array(
                    // Post type
                    "edit_{$capability_type}",
                    "read_{$capability_type}",
                    "delete_{$capability_type}",
                    "edit_{$capability_type}s",
                    "edit_others_{$capability_type}s",
                    "publish_{$capability_type}s",
                    "read_private_{$capability_type}s",
                    "delete_{$capability_type}s",
                    "delete_private_{$capability_type}s",
                    "delete_published_{$capability_type}s",
                    "delete_others_{$capability_type}s",
                    "edit_private_{$capability_type}s",
                    "edit_published_{$capability_type}s",
                    "delete_{$capability_type}s",
                    // Terms
                    "manage_{$capability_type}_terms",
                    "edit_{$capability_type}_terms",
                    "delete_{$capability_type}_terms",
                    "assign_{$capability_type}_terms"
                );
            }

            foreach ($capabilities as $cap_group) {
                foreach ($cap_group as $cap) {
                    $wp_roles->add_cap('shop_manager', $cap);
                    $wp_roles->add_cap('administrator', $cap);
                }
            }



            //Insert taxonomies

            $product_types = array('regular', 'digital', 'virtual');

            foreach ($product_types as $type) {
                if (!$type_id = get_term_by('slug', sanitize_title($type), 'product_type')) {
                    wp_insert_term($type, 'product_type');
                }
            }

            $order_status = array('cancelled', 'failed', 'pending', 'on-hold', 'processing', 'completed', 'refunded', 'shipped');

            foreach ($order_status as $status) {
                if (!$status_id = get_term_by('slug', sanitize_title($status), 'order_status')) {
                    wp_insert_term($status, 'order_status');
                }
            }


            // Flush Rules
            flush_rewrite_rules();

            $params->set('is_installed', true);
            $params->save();

            //protect the uploads folder
            $this->protect_uploads();
        } else {

            $this->update_db();
        }
    }

    public function deactivate() {
        
    }

    public function uninstall() {

        if (!defined('WP_UNINSTALL_PLUGIN'))
            exit();

        global $wpdb, $wp_roles;
        $db = Factory::getDBO();



        // Pages
        $app = Factory::getComponent('shop');
        $params = $app->getParams();
        $page_id = $params->get('page_id');
        if (!empty($page_id))
            wp_delete_post($page_id);

        // Tables

        $tables = $this->tables();

        $tables = array_reverse($tables, true);

        foreach ($tables as $table => $def) {
            $wpdb->query("DROP TABLE IF EXISTS " . $wpdb->prefix . $table);
        }

        // Order Status
        $wpdb->query("DELETE FROM $wpdb->terms WHERE term_id IN (select term_id FROM $wpdb->term_taxonomy WHERE taxonomy IN ('product_type', 'product_cat','product_brand','product_tags','order_status'))");
        $wpdb->query("DELETE FROM $wpdb->term_taxonomy WHERE taxonomy IN IN ('product_type', 'product_cat','product_brand','product_tags','order_status')");

        // Delete options
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name = '" . $app->getName() . "_parameters'");

        remove_role("customer");
        remove_role("shop_manager");
        remove_role("product_manager");
    }

    public function update_db() {

        $params = Factory::getComponent('shop')->getParams();

        if (is_admin() && version_compare($params->get('db_version'), $params->get('db_version', true), '<')) {

            $params = Factory::getComponent('shop')->getParams();
            $db = Factory::getDBO();



            //add unified param colum for all methods 1.1.0
            if (version_compare($params->get('db_version'), '1.1.0', '<')) {
                $db->setQuery("ALTER TABLE #_shop_methods ADD COLUMN params longtext");
            }

            //create variations table 1.1.1
            if (version_compare($params->get('db_version'), '1.1.1', '<')) {
                $table = $this->tables();
                $table = $table['shop_variations'];

                $db->setQuery($table);
            }

            //import countries and states 1.1.2
            if (version_compare($params->get('db_version'), '1.1.2', '<')) {


                $db->setQuery($this->import_countries());

                if (!$db->getResource()) {

                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }
                $db->setQuery($this->import_states());

                if (!$db->getResource()) {

                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }
            }


            //add multiply by qty for shipping option
            if (version_compare($params->get('db_version'), '1.1.3', '<')) {
                $db->setQuery("ALTER TABLE `#_shop_shipping_rate` ADD `qty_multiply` ENUM( 'no', 'yes' ) NOT NULL DEFAULT 'no'");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }
            }



            //remove tax_state index to allow compound taxes and add tax name column
            if (version_compare($params->get('db_version'), '1.1.4', '<')) {
                $db->setQuery("DROP INDEX tax_state ON `#_shop_tax_rate` ");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_tax_rate` ADD `tax_name` varchar(64) DEFAULT NULL");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_tax_rate` ADD `priority` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_tax_rate` ADD INDEX (`priority`)");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_order_attribute_item` CHANGE `section` `section` ENUM( 'property', 'file', 'tax' ) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }
            }

            //allow taxes to be written in the order item attributes table
            if (version_compare($params->get('db_version'), '1.1.6', '<')) {

                $db->setQuery("ALTER TABLE `#_shop_order_attribute_item` CHANGE COLUMN `order_item_id` `order_item_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER `order_att_item_id`");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }
            }

            if (version_compare($params->get('db_version'), '1.1.7', '<')) {
                global $wp_roles;

                if (class_exists('WP_Roles')) {
                    if (!isset($wp_roles)) {
                        $wp_roles = new WP_Roles();
                    }
                }

                add_role('shop_manager', __('Shop Manager', 'com_shop'), array(
                    'level_9' => true,
                    'level_8' => true,
                    'level_7' => true,
                    'level_6' => true,
                    'level_5' => true,
                    'level_4' => true,
                    'level_3' => true,
                    'level_2' => true,
                    'level_1' => true,
                    'level_0' => true,
                    'read' => true,
                    'read_private_pages' => true,
                    'read_private_posts' => true,
                    'edit_users' => true,
                    'edit_posts' => true,
                    'edit_pages' => true,
                    'edit_published_posts' => true,
                    'edit_published_pages' => true,
                    'edit_private_pages' => true,
                    'edit_private_posts' => true,
                    'edit_others_posts' => true,
                    'edit_others_pages' => true,
                    'publish_posts' => true,
                    'publish_pages' => true,
                    'delete_posts' => true,
                    'delete_pages' => true,
                    'delete_private_pages' => true,
                    'delete_private_posts' => true,
                    'delete_published_pages' => true,
                    'delete_published_posts' => true,
                    'delete_others_posts' => true,
                    'delete_others_pages' => true,
                    'manage_categories' => true,
                    'manage_links' => true,
                    'moderate_comments' => true,
                    'unfiltered_html' => true,
                    'upload_files' => true,
                    'export' => true,
                    'import' => true,
                    'list_users' => true
                ));




                $capabilities = array();

                $capabilities['core'] = array(
                    'manage_shop'
                );

                $capability_types = array('product', 'shop_order');

                foreach ($capability_types as $capability_type) {

                    $capabilities[$capability_type] = array(
                        // Post type
                        "edit_{$capability_type}",
                        "read_{$capability_type}",
                        "delete_{$capability_type}",
                        "edit_{$capability_type}s",
                        "edit_others_{$capability_type}s",
                        "publish_{$capability_type}s",
                        "read_private_{$capability_type}s",
                        "delete_{$capability_type}s",
                        "delete_private_{$capability_type}s",
                        "delete_published_{$capability_type}s",
                        "delete_others_{$capability_type}s",
                        "edit_private_{$capability_type}s",
                        "edit_published_{$capability_type}s",
                        // Terms
                        "manage_{$capability_type}_terms",
                        "edit_{$capability_type}_terms",
                        "delete_{$capability_type}_terms",
                        "assign_{$capability_type}_terms"
                    );
                }

                foreach ($capabilities as $cap_group) {
                    foreach ($cap_group as $cap) {
                        $wp_roles->add_cap('shop_manager', $cap);
                        $wp_roles->add_cap('administrator', $cap);
                    }
                }
            }



            if (version_compare($params->get('db_version'), '1.1.8', '<')) {

                $db->setQuery("ALTER TABLE `#_shop_shipping_rate` CHANGE `shipping_rate_weight_start` `shipping_rate_weight_start` DECIMAL( 10, 4 ) NOT NULL ;");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_shipping_rate` CHANGE `shipping_rate_weight_end` `shipping_rate_weight_end` DECIMAL( 10, 4 ) NOT NULL ;");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_shipping_rate` CHANGE `shipping_rate_volume_start` `shipping_rate_volume_start` DECIMAL( 10, 4 ) NOT NULL ;");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_shipping_rate` CHANGE `shipping_rate_volume_start` `shipping_rate_volume_start` DECIMAL( 10, 4 ) NOT NULL ;");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_shipping_rate` CHANGE `shipping_rate_volume_end` `shipping_rate_volume_end` DECIMAL( 10, 4 ) NOT NULL ;");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_shipping_rate` CHANGE `shipping_rate_ordertotal_start` `shipping_rate_ordertotal_start` DECIMAL( 10, 4 ) NOT NULL ;");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_shipping_rate` CHANGE `shipping_rate_ordertotal_end` `shipping_rate_ordertotal_end` DECIMAL( 10, 4 ) NOT NULL ;");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_shipping_rate` CHANGE `shipping_rate_value` `shipping_rate_value` DECIMAL( 10, 4 ) NOT NULL ;");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_shipping_rate` CHANGE `shipping_rate_package_fee` `shipping_rate_package_fee` DECIMAL( 10, 4 ) NOT NULL ;");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_shipping_rate` CHANGE `shipping_rate_length_start` `shipping_rate_length_start` DECIMAL( 10, 4 ) NOT NULL ;");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_shipping_rate` CHANGE `shipping_rate_length_end` `shipping_rate_length_end` DECIMAL( 10, 4 ) NOT NULL ;");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_shipping_rate` CHANGE `shipping_rate_width_start` `shipping_rate_width_start` DECIMAL( 10, 4 ) NOT NULL ;");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_shipping_rate` CHANGE `shipping_rate_width_end` `shipping_rate_width_end` DECIMAL( 10, 4 ) NOT NULL ;");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_shipping_rate` CHANGE `shipping_rate_height_start` `shipping_rate_height_start` DECIMAL( 10, 4 ) NOT NULL ;");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }

                $db->setQuery("ALTER TABLE `#_shop_shipping_rate` CHANGE `shipping_rate_height_end` `shipping_rate_height_end` DECIMAL( 10, 4 ) NOT NULL ;");
                if (!$db->getResource()) {
                    trigger_error($db->getErrorString(), E_USER_ERROR);
                    exit;
                }
            }

            if (version_compare($params->get('db_version'), '1.1.9', '<')) {
                $this->protect_uploads();
            }

            //add trash capability as it was forgotten in the previous versions...
            if (version_compare($params->get('db_version'), '1.2.0', '<')) {

                global $wp_roles;

                if (class_exists('WP_Roles')) {
                    if (!isset($wp_roles)) {
                        $wp_roles = new WP_Roles();
                    }
                }

                $capabilities = array();


                $capability_types = array('product', 'shop_order');

                foreach ($capability_types as $capability_type) {

                    $capabilities[$capability_type] = array(
                        "delete_{$capability_type}s",
                    );
                }

                foreach ($capabilities as $cap_group) {
                    foreach ($cap_group as $cap) {
                        $wp_roles->add_cap('shop_manager', $cap);
                        $wp_roles->add_cap('administrator', $cap);
                    }
                }
            }

            //update the parameters after we alter the database

            $params->set('db_version', $params->get('db_version', true));
            $params->save();
            echo "<div>Database Updated</div>";
        }
    }

    protected function create_single_page($page_slug, $page_data) {

        global $wpdb;


        $slug = esc_sql(_x($page_slug, 'page_slug', 'com_shop'));
        $page_id = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_status = 'publish' AND post_status <> 'trash' LIMIT 1", $slug));

        if (!$page_id) {
            $page_data['post_name'] = $slug;
            $page_id = wp_insert_post($page_data);
        }


        return $page_id;
    }

    protected function import_countries() {

        return "INSERT IGNORE INTO `#_shop_country` (`country_id`, `country_name`, `country_3_code`, `country_2_code`, `in_eu`) VALUES
	(1, 'Afghanistan', 'AFG', 'AF', ''),
	(2, 'Albania', 'ALB', 'AL', ''),
	(3, 'Algeria', 'DZA', 'DZ', ''),
	(4, 'American Samoa', 'ASM', 'AS', ''),
	(5, 'Andorra', 'AND', 'AD', ''),
	(6, 'Angola', 'AGO', 'AO', ''),
	(7, 'Anguilla', 'AIA', 'AI', ''),
	(8, 'Antarctica', 'ATA', 'AQ', ''),
	(9, 'Antigua and Barbuda', 'ATG', 'AG', ''),
	(10, 'Argentina', 'ARG', 'AR', ''),
	(11, 'Armenia', 'ARM', 'AM', ''),
	(12, 'Aruba', 'ABW', 'AW', ''),
	(13, 'Australia', 'AUS', 'AU', ''),
	(14, 'Austria', 'AUT', 'AT', 'yes'),
	(15, 'Azerbaijan', 'AZE', 'AZ', ''),
	(16, 'Bahamas', 'BHS', 'BS', ''),
	(17, 'Bahrain', 'BHR', 'BH', ''),
	(18, 'Bangladesh', 'BGD', 'BD', ''),
	(19, 'Barbados', 'BRB', 'BB', ''),
	(20, 'Belarus', 'BLR', 'BY', ''),
	(21, 'Belgium', 'BEL', 'BE', 'yes'),
	(22, 'Belize', 'BLZ', 'BZ', ''),
	(23, 'Benin', 'BEN', 'BJ', ''),
	(24, 'Bermuda', 'BMU', 'BM', ''),
	(25, 'Bhutan', 'BTN', 'BT', ''),
	(26, 'Bolivia', 'BOL', 'BO', ''),
	(27, 'Bosnia and Herzegowina', 'BIH', 'BA', ''),
	(28, 'Botswana', 'BWA', 'BW', ''),
	(29, 'Bouvet Island', 'BVT', 'BV', ''),
	(30, 'Brazil', 'BRA', 'BR', ''),
	(31, 'British Indian Ocean Territory', 'IOT', 'IO', ''),
	(32, 'Brunei Darussalam', 'BRN', 'BN', ''),
	(33, 'Bulgaria', 'BGR', 'BG', 'yes'),
	(34, 'Burkina Faso', 'BFA', 'BF', ''),
	(35, 'Burundi', 'BDI', 'BI', ''),
	(36, 'Cambodia', 'KHM', 'KH', ''),
	(37, 'Cameroon', 'CMR', 'CM', ''),
	(38, 'Canada', 'CAN', 'CA', ''),
	(39, 'Cape Verde', 'CPV', 'CV', ''),
	(40, 'Cayman Islands', 'CYM', 'KY', ''),
	(41, 'Central African Republic', 'CAF', 'CF', ''),
	(42, 'Chad', 'TCD', 'TD', ''),
	(43, 'Chile', 'CHL', 'CL', ''),
	(44, 'China', 'CHN', 'CN', ''),
	(45, 'Christmas Island', 'CXR', 'CX', ''),
	(46, 'Cocos (Keeling) Islands', 'CCK', 'CC', ''),
	(47, 'Colombia', 'COL', 'CO', ''),
	(48, 'Comoros', 'COM', 'KM', ''),
	(49, 'Congo', 'COG', 'CG', ''),
	(50, 'Cook Islands', 'COK', 'CK', ''),
	(51, 'Costa Rica', 'CRI', 'CR', ''),
	(52, 'Cote D\'Ivoire', 'CIV', 'CI', ''),
	(53, 'Croatia', 'HRV', 'HR', 'yes'),
	(54, 'Cuba', 'CUB', 'CU', ''),
	(55, 'Cyprus', 'CYP', 'CY', 'yes'),
	(56, 'Czech Republic', 'CZE', 'CZ', 'yes'),
	(57, 'Denmark', 'DNK', 'DK', 'yes'),
	(58, 'Djibouti', 'DJI', 'DJ', ''),
	(59, 'Dominica', 'DMA', 'DM', ''),
	(60, 'Dominican Republic', 'DOM', 'DO', ''),
	(62, 'Ecuador', 'ECU', 'EC', ''),
	(63, 'Egypt', 'EGY', 'EG', ''),
	(64, 'El Salvador', 'SLV', 'SV', ''),
	(65, 'Equatorial Guinea', 'GNQ', 'GQ', ''),
	(66, 'Eritrea', 'ERI', 'ER', ''),
	(67, 'Estonia', 'EST', 'EE', 'yes'),
	(68, 'Ethiopia', 'ETH', 'ET', ''),
	(69, 'Falkland Islands (Malvinas)', 'FLK', 'FK', ''),
	(70, 'Faroe Islands', 'FRO', 'FO', ''),
	(71, 'Fiji', 'FJI', 'FJ', ''),
	(72, 'Finland', 'FIN', 'FI', 'yes'),
	(73, 'France', 'FRA', 'FR', 'yes'),
	(75, 'French Guiana', 'GUF', 'GF', ''),
	(76, 'French Polynesia', 'PYF', 'PF', ''),
	(77, 'French Southern Territories', 'ATF', 'TF', ''),
	(78, 'Gabon', 'GAB', 'GA', ''),
	(79, 'Gambia', 'GMB', 'GM', ''),
	(80, 'Georgia', 'GEO', 'GE', ''),
	(81, 'Germany', 'DEU', 'DE', 'yes'),
	(82, 'Ghana', 'GHA', 'GH', ''),
	(83, 'Gibraltar', 'GIB', 'GI', ''),
	(84, 'Greece', 'GRC', 'GR', 'yes'),
	(85, 'Greenland', 'GRL', 'GL', ''),
	(86, 'Grenada', 'GRD', 'GD', ''),
	(87, 'Guadeloupe', 'GLP', 'GP', ''),
	(88, 'Guam', 'GUM', 'GU', ''),
	(89, 'Guatemala', 'GTM', 'GT', ''),
	(90, 'Guinea', 'GIN', 'GN', ''),
	(91, 'Guinea-bissau', 'GNB', 'GW', ''),
	(92, 'Guyana', 'GUY', 'GY', ''),
	(93, 'Haiti', 'HTI', 'HT', ''),
	(94, 'Heard and Mc Donald Islands', 'HMD', 'HM', ''),
	(95, 'Honduras', 'HND', 'HN', ''),
	(96, 'Hong Kong', 'HKG', 'HK', ''),
	(97, 'Hungary', 'HUN', 'HU', 'yes'),
	(98, 'Iceland', 'ISL', 'IS', ''),
	(99, 'India', 'IND', 'IN', ''),
	(100, 'Indonesia', 'IDN', 'ID', ''),
	(101, 'Iran (Islamic Republic of)', 'IRN', 'IR', ''),
	(102, 'Iraq', 'IRQ', 'IQ', ''),
	(103, 'Ireland', 'IRL', 'IE', 'yes'),
	(104, 'Israel', 'ISR', 'IL', ''),
	(105, 'Italy', 'ITA', 'IT', 'yes'),
	(106, 'Jamaica', 'JAM', 'JM', ''),
	(107, 'Japan', 'JPN', 'JP', ''),
	(108, 'Jordan', 'JOR', 'JO', ''),
	(109, 'Kazakhstan', 'KAZ', 'KZ', ''),
	(110, 'Kenya', 'KEN', 'KE', ''),
	(111, 'Kiribati', 'KIR', 'KI', ''),
	(112, 'Korea, Democratic People\'s Republic of', 'PRK', 'KP', ''),
	(113, 'Korea, Republic of', 'KOR', 'KR', ''),
	(114, 'Kuwait', 'KWT', 'KW', ''),
	(115, 'Kyrgyzstan', 'KGZ', 'KG', ''),
	(116, 'Lao People\'s Democratic Republic', 'LAO', 'LA', ''),
	(117, 'Latvia', 'LVA', 'LV', 'yes'),
	(118, 'Lebanon', 'LBN', 'LB', ''),
	(119, 'Lesotho', 'LSO', 'LS', ''),
	(120, 'Liberia', 'LBR', 'LR', ''),
	(121, 'Libyan Arab Jamahiriya', 'LBY', 'LY', ''),
	(122, 'Liechtenstein', 'LIE', 'LI', ''),
	(123, 'Lithuania', 'LTU', 'LT', 'yes'),
	(124, 'Luxembourg', 'LUX', 'LU', 'yes'),
	(125, 'Macau', 'MAC', 'MO', ''),
	(126, 'Macedonia, The Former Yugoslav Republic of', 'MKD', 'MK', ''),
	(127, 'Madagascar', 'MDG', 'MG', ''),
	(128, 'Malawi', 'MWI', 'MW', ''),
	(129, 'Malaysia', 'MYS', 'MY', ''),
	(130, 'Maldives', 'MDV', 'MV', ''),
	(131, 'Mali', 'MLI', 'ML', ''),
	(132, 'Malta', 'MLT', 'MT', 'yes'),
	(133, 'Marshall Islands', 'MHL', 'MH', ''),
	(134, 'Martinique', 'MTQ', 'MQ', ''),
	(135, 'Mauritania', 'MRT', 'MR', ''),
	(136, 'Mauritius', 'MUS', 'MU', ''),
	(137, 'Mayotte', 'MYT', 'YT', ''),
	(138, 'Mexico', 'MEX', 'MX', ''),
	(139, 'Micronesia, Federated States of', 'FSM', 'FM', ''),
	(140, 'Moldova, Republic of', 'MDA', 'MD', ''),
	(141, 'Monaco', 'MCO', 'MC', ''),
	(142, 'Mongolia', 'MNG', 'MN', ''),
	(143, 'Montserrat', 'MSR', 'MS', ''),
	(144, 'Morocco', 'MAR', 'MA', ''),
	(145, 'Mozambique', 'MOZ', 'MZ', ''),
	(146, 'Myanmar', 'MMR', 'MM', ''),
	(147, 'Namibia', 'NAM', 'NA', ''),
	(148, 'Nauru', 'NRU', 'NR', ''),
	(149, 'Nepal', 'NPL', 'NP', ''),
	(150, 'Netherlands', 'NLD', 'NL', 'yes'),
	(151, 'Netherlands Antilles', 'ANT', 'AN', ''),
	(152, 'New Caledonia', 'NCL', 'NC', ''),
	(153, 'New Zealand', 'NZL', 'NZ', ''),
	(154, 'Nicaragua', 'NIC', 'NI', ''),
	(155, 'Niger', 'NER', 'NE', ''),
	(156, 'Nigeria', 'NGA', 'NG', ''),
	(157, 'Niue', 'NIU', 'NU', ''),
	(158, 'Norfolk Island', 'NFK', 'NF', ''),
	(159, 'Northern Mariana Islands', 'MNP', 'MP', ''),
	(160, 'Norway', 'NOR', 'NO', ''),
	(161, 'Oman', 'OMN', 'OM', ''),
	(162, 'Pakistan', 'PAK', 'PK', ''),
	(163, 'Palau', 'PLW', 'PW', ''),
	(164, 'Panama', 'PAN', 'PA', ''),
	(165, 'Papua New Guinea', 'PNG', 'PG', ''),
	(166, 'Paraguay', 'PRY', 'PY', ''),
	(167, 'Peru', 'PER', 'PE', ''),
	(168, 'Philippines', 'PHL', 'PH', ''),
	(169, 'Pitcairn', 'PCN', 'PN', ''),
	(170, 'Poland', 'POL', 'PL', 'yes'),
	(171, 'Portugal', 'PRT', 'PT', 'yes'),
	(172, 'Puerto Rico', 'PRI', 'PR', ''),
	(173, 'Qatar', 'QAT', 'QA', ''),
	(174, 'Reunion', 'REU', 'RE', ''),
	(175, 'Romania', 'ROM', 'RO', 'yes'),
	(176, 'Russian Federation', 'RUS', 'RU', ''),
	(177, 'Rwanda', 'RWA', 'RW', ''),
	(178, 'Saint Kitts and Nevis', 'KNA', 'KN', ''),
	(179, 'Saint Lucia', 'LCA', 'LC', ''),
	(180, 'Saint Vincent and the Grenadines', 'VCT', 'VC', ''),
	(181, 'Samoa', 'WSM', 'WS', ''),
	(182, 'San Marino', 'SMR', 'SM', ''),
	(183, 'Sao Tome and Principe', 'STP', 'ST', ''),
	(184, 'Saudi Arabia', 'SAU', 'SA', ''),
	(185, 'Senegal', 'SEN', 'SN', ''),
	(186, 'Seychelles', 'SYC', 'SC', ''),
	(187, 'Sierra Leone', 'SLE', 'SL', ''),
	(188, 'Singapore', 'SGP', 'SG', ''),
	(189, 'Slovakia (Slovak Republic)', 'SVK', 'SK', 'yes'),
	(190, 'Slovenia', 'SVN', 'SI', 'yes'),
	(191, 'Solomon Islands', 'SLB', 'SB', ''),
	(192, 'Somalia', 'SOM', 'SO', ''),
	(193, 'South Africa', 'ZAF', 'ZA', ''),
	(194, 'South Georgia and the South Sandwich Islands', 'SGS', 'GS', ''),
	(195, 'Spain', 'ESP', 'ES', 'yes'),
	(196, 'Sri Lanka', 'LKA', 'LK', ''),
	(197, 'St. Helena', 'SHN', 'SH', ''),
	(198, 'St. Pierre and Miquelon', 'SPM', 'PM', ''),
	(199, 'Sudan', 'SDN', 'SD', ''),
	(200, 'Suriname', 'SUR', 'SR', ''),
	(201, 'Svalbard and Jan Mayen Islands', 'SJM', 'SJ', ''),
	(202, 'Swaziland', 'SWZ', 'SZ', ''),
	(203, 'Sweden', 'SWE', 'SE', 'yes'),
	(204, 'Switzerland', 'CHE', 'CH', ''),
	(205, 'Syrian Arab Republic', 'SYR', 'SY', ''),
	(206, 'Taiwan', 'TWN', 'TW', ''),
	(207, 'Tajikistan', 'TJK', 'TJ', ''),
	(208, 'Tanzania, United Republic of', 'TZA', 'TZ', ''),
	(209, 'Thailand', 'THA', 'TH', ''),
	(210, 'Togo', 'TGO', 'TG', ''),
	(211, 'Tokelau', 'TKL', 'TK', ''),
	(212, 'Tonga', 'TON', 'TO', ''),
	(213, 'Trinidad and Tobago', 'TTO', 'TT', ''),
	(214, 'Tunisia', 'TUN', 'TN', ''),
	(215, 'Turkey', 'TUR', 'TR', ''),
	(216, 'Turkmenistan', 'TKM', 'TM', ''),
	(217, 'Turks and Caicos Islands', 'TCA', 'TC', ''),
	(218, 'Tuvalu', 'TUV', 'TV', ''),
	(219, 'Uganda', 'UGA', 'UG', ''),
	(220, 'Ukraine', 'UKR', 'UA', ''),
	(221, 'United Arab Emirates', 'ARE', 'AE', ''),
	(222, 'United Kingdom', 'GBR', 'GB', 'yes'),
	(223, 'United States', 'USA', 'US', ''),
	(224, 'United States Minor Outlying Islands', 'UMI', 'UM', ''),
	(225, 'Uruguay', 'URY', 'UY', ''),
	(226, 'Uzbekistan', 'UZB', 'UZ', ''),
	(227, 'Vanuatu', 'VUT', 'VU', ''),
	(228, 'Vatican City State (Holy See)', 'VAT', 'VA', ''),
	(229, 'Venezuela', 'VEN', 'VE', ''),
	(230, 'Viet Nam', 'VNM', 'VN', ''),
	(231, 'Virgin Islands (British)', 'VGB', 'VG', ''),
	(232, 'Virgin Islands (U.S.)', 'VIR', 'VI', ''),
	(233, 'Wallis and Futuna Islands', 'WLF', 'WF', ''),
	(234, 'Western Sahara', 'ESH', 'EH', ''),
	(235, 'Yemen', 'YEM', 'YE', ''),
	(237, 'The Democratic Republic of Congo', 'DRC', 'DC', ''),
	(238, 'Zambia', 'ZMB', 'ZM', ''),
	(239, 'Zimbabwe', 'ZWE', 'ZW', ''),
	(241, 'Jersey', 'XJE', 'XJ', ''),
	(242, 'St. Barthelemy', 'XSB', 'XB', ''),
	(245, 'Aland Islands', 'ALA', 'AX', ''),
	(246, 'Guernsey', 'GGY', 'GG', ''),
	(247, 'Saint Martin (French part)', 'MAF', 'MF', ''),
	(248, 'Timor-Leste', 'TLS', 'TL', ''),
	(249, 'Serbia', 'SRB', 'RS', ''),
	(250, 'Isle of Man', 'IMN', 'IM', ''),
	(251, 'Montenegro', 'MNE', 'ME', ''),
	(252, 'Palestinian Territory, Occupied', 'PSE', 'PS', '')";
    }

    protected function import_states() {
        return "INSERT IGNORE INTO `#_shop_state` (`state_id`, `country_id`, `state_name`, `state_3_code`, `state_2_code`) VALUES
	(1, 'US', 'Alabama', 'ALA', 'AL'),
	(2, 'US', 'Alaska', 'ALK', 'AK'),
	(3, 'US', 'Arizona', 'ARZ', 'AZ'),
	(4, 'US', 'Arkansas', 'ARK', 'AR'),
	(5, 'US', 'California', 'CAL', 'CA'),
	(6, 'US', 'Colorado', 'COL', 'CO'),
	(7, 'US', 'Connecticut', 'CCT', 'CT'),
	(8, 'US', 'Delaware', 'DEL', 'DE'),
	(9, 'US', 'District Of Columbia', 'DOC', 'DC'),
	(10, 'US', 'Florida', 'FLO', 'FL'),
	(11, 'US', 'Georgia', 'GEA', 'GA'),
	(12, 'US', 'Hawaii', 'HWI', 'HI'),
	(13, 'US', 'Idaho', 'IDA', 'ID'),
	(14, 'US', 'Illinois', 'ILL', 'IL'),
	(15, 'US', 'Indiana', 'IND', 'IN'),
	(16, 'US', 'Iowa', 'IOA', 'IA'),
	(17, 'US', 'Kansas', 'KAS', 'KS'),
	(18, 'US', 'Kentucky', 'KTY', 'KY'),
	(19, 'US', 'Louisiana', 'LOA', 'LA'),
	(20, 'US', 'Maine', 'MAI', 'ME'),
	(21, 'US', 'Maryland', 'MLD', 'MD'),
	(22, 'US', 'Massachusetts', 'MSA', 'MA'),
	(23, 'US', 'Michigan', 'MIC', 'MI'),
	(24, 'US', 'Minnesota', 'MIN', 'MN'),
	(25, 'US', 'Mississippi', 'MIS', 'MS'),
	(26, 'US', 'Missouri', 'MIO', 'MO'),
	(27, 'US', 'Montana', 'MOT', 'MT'),
	(28, 'US', 'Nebraska', 'NEB', 'NE'),
	(29, 'US', 'Nevada', 'NEV', 'NV'),
	(30, 'US', 'New Hampshire', 'NEH', 'NH'),
	(31, 'US', 'New Jersey', 'NEJ', 'NJ'),
	(32, 'US', 'New Mexico', 'NEM', 'NM'),
	(33, 'US', 'New York', 'NEY', 'NY'),
	(34, 'US', 'North Carolina', 'NOC', 'NC'),
	(35, 'US', 'North Dakota', 'NOD', 'ND'),
	(36, 'US', 'Ohio', 'OHI', 'OH'),
	(37, 'US', 'Oklahoma', 'OKL', 'OK'),
	(38, 'US', 'Oregon', 'ORN', 'OR'),
	(39, 'US', 'Pennsylvania', 'PEA', 'PA'),
	(40, 'US', 'Rhode Island', 'RHI', 'RI'),
	(41, 'US', 'South Carolina', 'SOC', 'SC'),
	(42, 'US', 'South Dakota', 'SOD', 'SD'),
	(43, 'US', 'Tennessee', 'TEN', 'TN'),
	(44, 'US', 'Texas', 'TXS', 'TX'),
	(45, 'US', 'Utah', 'UTA', 'UT'),
	(46, 'US', 'Vermont', 'VMT', 'VT'),
	(47, 'US', 'Virginia', 'VIA', 'VA'),
	(48, 'US', 'Washington', 'WAS', 'WA'),
	(49, 'US', 'West Virginia', 'WEV', 'WV'),
	(50, 'US', 'Wisconsin', 'WIS', 'WI'),
	(51, 'US', 'Wyoming', 'WYO', 'WY'),
	(52, 'CA', 'Alberta', 'ALB', 'AB'),
	(53, 'CA', 'British Columbia', 'BRC', 'BC'),
	(54, 'CA', 'Manitoba', 'MAB', 'MB'),
	(55, 'CA', 'New Brunswick', 'NEB', 'NB'),
	(56, 'CA', 'Newfoundland and Labrador', 'NFL', 'NL'),
	(57, 'CA', 'Northwest Territories', 'NWT', 'NT'),
	(58, 'CA', 'Nova Scotia', 'NOS', 'NS'),
	(59, 'CA', 'Nunavut', 'NUT', 'NU'),
	(60, 'CA', 'Ontario', 'ONT', 'ON'),
	(61, 'CA', 'Prince Edward Island', 'PEI', 'PE'),
	(62, 'CA', 'Quebec', 'QEC', 'QC'),
	(63, 'CA', 'Saskatchewan', 'SAK', 'SK'),
	(64, 'CA', 'Yukon', 'YUT', 'YT'),
	(65, 'GB', 'England', 'ENG', 'EN'),
	(66, 'GB', 'Northern Ireland', 'NOI', 'NI'),
	(67, 'GB', 'Scotland', 'SCO', 'SD'),
	(68, 'GB', 'Wales', 'WLS', 'WS'),
	(69, 'AU', 'Australian Capital Territory', 'ACT', 'AT'),
	(70, 'AU', 'New South Wales', 'NSW', 'NW'),
	(71, 'AU', 'Northern Territory', 'NOT', 'NT'),
	(72, 'AU', 'Queensland', 'QLD', 'QL'),
	(73, 'AU', 'South Australia', 'SOA', 'SA'),
	(74, 'AU', 'Tasmania', 'TAS', 'TA'),
	(75, 'AU', 'Victoria', 'VIC', 'VI'),
	(76, 'AU', 'Western Australia', 'WEA', 'WA'),
	(77, 'MX', 'Aguascalientes', 'AGS', 'AG'),
	(78, 'MX', 'Baja California Norte', 'BCN', 'BN'),
	(79, 'MX', 'Baja California Sur', 'BCS', 'BS'),
	(80, 'MX', 'Campeche', 'CAM', 'CA'),
	(81, 'MX', 'Chiapas', 'CHI', 'CS'),
	(82, 'MX', 'Chihuahua', 'CHA', 'CH'),
	(83, 'MX', 'Coahuila', 'COA', 'CO'),
	(84, 'MX', 'Colima', 'COL', 'CM'),
	(85, 'MX', 'Distrito Federal', 'DFM', 'DF'),
	(86, 'MX', 'Durango', 'DGO', 'DO'),
	(87, 'MX', 'Guanajuato', 'GTO', 'GO'),
	(88, 'MX', 'Guerrero', 'GRO', 'GU'),
	(89, 'MX', 'Hidalgo', 'HGO', 'HI'),
	(90, 'MX', 'Jalisco', 'JAL', 'JA'),
	(91, 'MX', 'México (Estado de)', 'EDM', 'EM'),
	(92, 'MX', 'Michoacán', 'MCN', 'MI'),
	(93, 'MX', 'Morelos', 'MOR', 'MO'),
	(94, 'MX', 'Nayarit', 'NAY', 'NY'),
	(95, 'MX', 'Nuevo León', 'NUL', 'NL'),
	(96, 'MX', 'Oaxaca', 'OAX', 'OA'),
	(97, 'MX', 'Puebla', 'PUE', 'PU'),
	(98, 'MX', 'Querétaro', 'QRO', 'QU'),
	(99, 'MX', 'Quintana Roo', 'QUR', 'QR'),
	(100, 'MX', 'San Luis Potosí', 'SLP', 'SP'),
	(101, 'MX', 'Sinaloa', 'SIN', 'SI'),
	(102, 'MX', 'Sonora', 'SON', 'SO'),
	(103, 'MX', 'Tabasco', 'TAB', 'TA'),
	(104, 'MX', 'Tamaulipas', 'TAM', 'TM'),
	(105, 'MX', 'Tlaxcala', 'TLX', 'TX'),
	(106, 'MX', 'Veracruz', 'VER', 'VZ'),
	(107, 'MX', 'Yucatán', 'YUC', 'YU'),
	(108, 'MX', 'Zacatecas', 'ZAC', 'ZA'),
	(109, 'BR', 'Acre', 'ACR', 'AC'),
	(110, 'BR', 'Alagoas', 'ALG', 'AL'),
	(111, 'BR', 'Amapá', 'AMP', 'AP'),
	(112, 'BR', 'Amazonas', 'AMZ', 'AM'),
	(113, 'BR', 'Bahía', 'BAH', 'BA'),
	(114, 'BR', 'Ceará', 'CEA', 'CE'),
	(115, 'BR', 'Distrito Federal', 'DFB', 'DF'),
	(116, 'BR', 'Espirito Santo', 'ESS', 'ES'),
	(117, 'BR', 'Goiás', 'GOI', 'GO'),
	(118, 'BR', 'Maranhão', 'MAR', 'MA'),
	(119, 'BR', 'Mato Grosso', 'MAT', 'MT'),
	(120, 'BR', 'Mato Grosso do Sul', 'MGS', 'MS'),
	(121, 'BR', 'Minas Geraís', 'MIG', 'MG'),
	(122, 'BR', 'Paraná', 'PAR', 'PR'),
	(123, 'BR', 'Paraíba', 'PRB', 'PB'),
	(124, 'BR', 'Pará', 'PAB', 'PA'),
	(125, 'BR', 'Pernambuco', 'PER', 'PE'),
	(126, 'BR', 'Piauí', 'PIA', 'PI'),
	(127, 'BR', 'Rio Grande do Norte', 'RGN', 'RN'),
	(128, 'BR', 'Rio Grande do Sul', 'RGS', 'RS'),
	(129, 'BR', 'Rio de Janeiro', 'RDJ', 'RJ'),
	(130, 'BR', 'Rondônia', 'RON', 'RO'),
	(131, 'BR', 'Roraima', 'ROR', 'RR'),
	(132, 'BR', 'Santa Catarina', 'SAC', 'SC'),
	(133, 'BR', 'Sergipe', 'SER', 'SE'),
	(134, 'BR', 'São Paulo', 'SAP', 'SP'),
	(135, 'BR', 'Tocantins', 'TOC', 'TO'),
	(136, 'CN', 'Anhui', 'ANH', '34'),
	(137, 'CN', 'Beijing', 'BEI', '11'),
	(138, 'CN', 'Chongqing', 'CHO', '50'),
	(139, 'CN', 'Fujian', 'FUJ', '35'),
	(140, 'CN', 'Gansu', 'GAN', '62'),
	(141, 'CN', 'Guangdong', 'GUA', '44'),
	(142, 'CN', 'Guangxi Zhuang', 'GUZ', '45'),
	(143, 'CN', 'Guizhou', 'GUI', '52'),
	(144, 'CN', 'Hainan', 'HAI', '46'),
	(145, 'CN', 'Hebei', 'HEB', '13'),
	(146, 'CN', 'Heilongjiang', 'HEI', '23'),
	(147, 'CN', 'Henan', 'HEN', '41'),
	(148, 'CN', 'Hubei', 'HUB', '42'),
	(149, 'CN', 'Hunan', 'HUN', '43'),
	(150, 'CN', 'Jiangsu', 'JIA', '32'),
	(151, 'CN', 'Jiangxi', 'JIX', '36'),
	(152, 'CN', 'Jilin', 'JIL', '22'),
	(153, 'CN', 'Liaoning', 'LIA', '21'),
	(154, 'CN', 'Nei Mongol', 'NML', '15'),
	(155, 'CN', 'Ningxia Hui', 'NIH', '64'),
	(156, 'CN', 'Qinghai', 'QIN', '63'),
	(157, 'CN', 'Shandong', 'SNG', '37'),
	(158, 'CN', 'Shanghai', 'SHH', '31'),
	(159, 'CN', 'Shaanxi', 'SHX', '61'),
	(160, 'CN', 'Sichuan', 'SIC', '51'),
	(161, 'CN', 'Tianjin', 'TIA', '12'),
	(162, 'CN', 'Xinjiang Uygur', 'XIU', '65'),
	(163, 'CN', 'Xizang', 'XIZ', '54'),
	(164, 'CN', 'Yunnan', 'YUN', '53'),
	(165, 'CN', 'Zhejiang', 'ZHE', '33'),
	(166, 'IL', 'Gaza Strip', 'GZS', 'GZ'),
	(167, 'IL', 'West Bank', 'WBK', 'WB'),
	(168, 'IL', 'Other', 'OTH', 'OT'),
	(169, 'AN', 'St. Maarten', 'STM', 'SM'),
	(170, 'AN', 'Bonaire', 'BNR', 'BN'),
	(171, 'AN', 'Curacao', 'CUR', 'CR'),
	(172, 'RO', 'Alba', 'ABA', 'AB'),
	(173, 'RO', 'Arad', 'ARD', 'AR'),
	(174, 'RO', 'Arges', 'ARG', 'AG'),
	(175, 'RO', 'Bacau', 'BAC', 'BC'),
	(176, 'RO', 'Bihor', 'BIH', 'BH'),
	(177, 'RO', 'Bistrita-Nasaud', 'BIS', 'BN'),
	(178, 'RO', 'Botosani', 'BOT', 'BT'),
	(179, 'RO', 'Braila', 'BRL', 'BR'),
	(180, 'RO', 'Brasov', 'BRA', 'BV'),
	(181, 'RO', 'Bucuresti', 'BUC', 'B'),
	(182, 'RO', 'Buzau', 'BUZ', 'BZ'),
	(183, 'RO', 'Calarasi', 'CAL', 'CL'),
	(184, 'RO', 'Caras Severin', 'CRS', 'CS'),
	(185, 'RO', 'Cluj', 'CLJ', 'CJ'),
	(186, 'RO', 'Constanta', 'CST', 'CT'),
	(187, 'RO', 'Covasna', 'COV', 'CV'),
	(188, 'RO', 'Dambovita', 'DAM', 'DB'),
	(189, 'RO', 'Dolj', 'DLJ', 'DJ'),
	(190, 'RO', 'Galati', 'GAL', 'GL'),
	(191, 'RO', 'Giurgiu', 'GIU', 'GR'),
	(192, 'RO', 'Gorj', 'GOR', 'GJ'),
	(193, 'RO', 'Hargita', 'HRG', 'HR'),
	(194, 'RO', 'Hunedoara', 'HUN', 'HD'),
	(195, 'RO', 'Ialomita', 'IAL', 'IL'),
	(196, 'RO', 'Iasi', 'IAS', 'IS'),
	(197, 'RO', 'Ilfov', 'ILF', 'IF'),
	(198, 'RO', 'Maramures', 'MAR', 'MM'),
	(199, 'RO', 'Mehedinti', 'MEH', 'MH'),
	(200, 'RO', 'Mures', 'MUR', 'MS'),
	(201, 'RO', 'Neamt', 'NEM', 'NT'),
	(202, 'RO', 'Olt', 'OLT', 'OT'),
	(203, 'RO', 'Prahova', 'PRA', 'PH'),
	(204, 'RO', 'Salaj', 'SAL', 'SJ'),
	(205, 'RO', 'Satu Mare', 'SAT', 'SM'),
	(206, 'RO', 'Sibiu', 'SIB', 'SB'),
	(207, 'RO', 'Suceava', 'SUC', 'SV'),
	(208, 'RO', 'Teleorman', 'TEL', 'TR'),
	(209, 'RO', 'Timis', 'TIM', 'TM'),
	(210, 'RO', 'Tulcea', 'TUL', 'TL'),
	(211, 'RO', 'Valcea', 'VAL', 'VL'),
	(212, 'RO', 'Vaslui', 'VAS', 'VS'),
	(213, 'RO', 'Vrancea', 'VRA', 'VN'),
	(214, 'IT', 'Agrigento', 'AGR', 'AG'),
	(215, 'IT', 'Alessandria', 'ALE', 'AL'),
	(216, 'IT', 'Ancona', 'ANC', 'AN'),
	(217, 'IT', 'Aosta', 'AOS', 'AO'),
	(218, 'IT', 'Arezzo', 'ARE', 'AR'),
	(219, 'IT', 'Ascoli Piceno', 'API', 'AP'),
	(220, 'IT', 'Asti', 'AST', 'AT'),
	(221, 'IT', 'Avellino', 'AVE', 'AV'),
	(222, 'IT', 'Bari', 'BAR', 'BA'),
	(223, 'IT', 'Belluno', 'BEL', 'BL'),
	(224, 'IT', 'Benevento', 'BEN', 'BN'),
	(225, 'IT', 'Bergamo', 'BEG', 'BG'),
	(226, 'IT', 'Biella', 'BIE', 'BI'),
	(227, 'IT', 'Bologna', 'BOL', 'BO'),
	(228, 'IT', 'Bolzano', 'BOZ', 'BZ'),
	(229, 'IT', 'Brescia', 'BRE', 'BS'),
	(230, 'IT', 'Brindisi', 'BRI', 'BR'),
	(231, 'IT', 'Cagliari', 'CAG', 'CA'),
	(232, 'IT', 'Caltanissetta', 'CAL', 'CL'),
	(233, 'IT', 'Campobasso', 'CBO', 'CB'),
	(234, 'IT', 'Carbonia-Iglesias', 'CAR', 'CI'),
	(235, 'IT', 'Caserta', 'CAS', 'CE'),
	(236, 'IT', 'Catania', 'CAT', 'CT'),
	(237, 'IT', 'Catanzaro', 'CTZ', 'CZ'),
	(238, 'IT', 'Chieti', 'CHI', 'CH'),
	(239, 'IT', 'Como', 'COM', 'CO'),
	(240, 'IT', 'Cosenza', 'COS', 'CS'),
	(241, 'IT', 'Cremona', 'CRE', 'CR'),
	(242, 'IT', 'Crotone', 'CRO', 'KR'),
	(243, 'IT', 'Cuneo', 'CUN', 'CN'),
	(244, 'IT', 'Enna', 'ENN', 'EN'),
	(245, 'IT', 'Ferrara', 'FER', 'FE'),
	(246, 'IT', 'Firenze', 'FIR', 'FI'),
	(247, 'IT', 'Foggia', 'FOG', 'FG'),
	(248, 'IT', 'Forli-Cesena', 'FOC', 'FC'),
	(249, 'IT', 'Frosinone', 'FRO', 'FR'),
	(250, 'IT', 'Genova', 'GEN', 'GE'),
	(251, 'IT', 'Gorizia', 'GOR', 'GO'),
	(252, 'IT', 'Grosseto', 'GRO', 'GR'),
	(253, 'IT', 'Imperia', 'IMP', 'IM'),
	(254, 'IT', 'Isernia', 'ISE', 'IS'),
	(255, 'IT', 'L\'Aquila', 'AQU', 'AQ'),
	(256, 'IT', 'La Spezia', 'LAS', 'SP'),
	(257, 'IT', 'Latina', 'LAT', 'LT'),
	(258, 'IT', 'Lecce', 'LEC', 'LE'),
	(259, 'IT', 'Lecco', 'LCC', 'LC'),
	(260, 'IT', 'Livorno', 'LIV', 'LI'),
	(261, 'IT', 'Lodi', 'LOD', 'LO'),
	(262, 'IT', 'Lucca', 'LUC', 'LU'),
	(263, 'IT', 'Macerata', 'MAC', 'MC'),
	(264, 'IT', 'Mantova', 'MAN', 'MN'),
	(265, 'IT', 'Massa-Carrara', 'MAS', 'MS'),
	(266, 'IT', 'Matera', 'MAA', 'MT'),
	(267, 'IT', 'Medio Campidano', 'MED', 'VS'),
	(268, 'IT', 'Messina', 'MES', 'ME'),
	(269, 'IT', 'Milano', 'MIL', 'MI'),
	(270, 'IT', 'Modena', 'MOD', 'MO'),
	(271, 'IT', 'Napoli', 'NAP', 'NA'),
	(272, 'IT', 'Novara', 'NOV', 'NO'),
	(273, 'IT', 'Nuoro', 'NUR', 'NU'),
	(274, 'IT', 'Ogliastra', 'OGL', 'OG'),
	(275, 'IT', 'Olbia-Tempio', 'OLB', 'OT'),
	(276, 'IT', 'Oristano', 'ORI', 'OR'),
	(277, 'IT', 'Padova', 'PDA', 'PD'),
	(278, 'IT', 'Palermo', 'PAL', 'PA'),
	(279, 'IT', 'Parma', 'PAA', 'PR'),
	(280, 'IT', 'Pavia', 'PAV', 'PV'),
	(281, 'IT', 'Perugia', 'PER', 'PG'),
	(282, 'IT', 'Pesaro e Urbino', 'PES', 'PU'),
	(283, 'IT', 'Pescara', 'PSC', 'PE'),
	(284, 'IT', 'Piacenza', 'PIA', 'PC'),
	(285, 'IT', 'Pisa', 'PIS', 'PI'),
	(286, 'IT', 'Pistoia', 'PIT', 'PT'),
	(287, 'IT', 'Pordenone', 'POR', 'PN'),
	(288, 'IT', 'Potenza', 'PTZ', 'PZ'),
	(289, 'IT', 'Prato', 'PRA', 'PO'),
	(290, 'IT', 'Ragusa', 'RAG', 'RG'),
	(291, 'IT', 'Ravenna', 'RAV', 'RA'),
	(292, 'IT', 'Reggio Calabria', 'REG', 'RC'),
	(293, 'IT', 'Reggio Emilia', 'REE', 'RE'),
	(294, 'IT', 'Rieti', 'RIE', 'RI'),
	(295, 'IT', 'Rimini', 'RIM', 'RN'),
	(296, 'IT', 'Roma', 'ROM', 'RM'),
	(297, 'IT', 'Rovigo', 'ROV', 'RO'),
	(298, 'IT', 'Salerno', 'SAL', 'SA'),
	(299, 'IT', 'Sassari', 'SAS', 'SS'),
	(300, 'IT', 'Savona', 'SAV', 'SV'),
	(301, 'IT', 'Siena', 'SIE', 'SI'),
	(302, 'IT', 'Siracusa', 'SIR', 'SR'),
	(303, 'IT', 'Sondrio', 'SOO', 'SO'),
	(304, 'IT', 'Taranto', 'TAR', 'TA'),
	(305, 'IT', 'Teramo', 'TER', 'TE'),
	(306, 'IT', 'Terni', 'TRN', 'TR'),
	(307, 'IT', 'Torino', 'TOR', 'TO'),
	(308, 'IT', 'Trapani', 'TRA', 'TP'),
	(309, 'IT', 'Trento', 'TRE', 'TN'),
	(310, 'IT', 'Treviso', 'TRV', 'TV'),
	(311, 'IT', 'Trieste', 'TRI', 'TS'),
	(312, 'IT', 'Udine', 'UDI', 'UD'),
	(313, 'IT', 'Varese', 'VAR', 'VA'),
	(314, 'IT', 'Venezia', 'VEN', 'VE'),
	(315, 'IT', 'Verbano Cusio Ossola', 'VCO', 'VB'),
	(316, 'IT', 'Vercelli', 'VER', 'VC'),
	(317, 'IT', 'Verona', 'VRN', 'VR'),
	(318, 'IT', 'Vibo Valenzia', 'VIV', 'VV'),
	(319, 'IT', 'Vicenza', 'VII', 'VI'),
	(320, 'IT', 'Viterbo', 'VIT', 'VT'),
	(321, 'ES', 'A Coruña', 'ACO', '15'),
	(322, 'ES', 'Alava', 'ALA', '01'),
	(323, 'ES', 'Albacete', 'ALB', '02'),
	(324, 'ES', 'Alicante', 'ALI', '03'),
	(325, 'ES', 'Almeria', 'ALM', '04'),
	(326, 'ES', 'Asturias', 'AST', '33'),
	(327, 'ES', 'Avila', 'AVI', '05'),
	(328, 'ES', 'Badajoz', 'BAD', '06'),
	(329, 'ES', 'Baleares', 'BAL', '07'),
	(330, 'ES', 'Barcelona', 'BAR', '08'),
	(331, 'ES', 'Burgos', 'BUR', '09'),
	(332, 'ES', 'Caceres', 'CAC', '10'),
	(333, 'ES', 'Cadiz', 'CAD', '11'),
	(334, 'ES', 'Cantabria', 'CAN', '39'),
	(335, 'ES', 'Castellon', 'CAS', '12'),
	(336, 'ES', 'Ceuta', 'CEU', '51'),
	(337, 'ES', 'Ciudad Real', 'CIU', '13'),
	(338, 'ES', 'Cordoba', 'COR', '14'),
	(339, 'ES', 'Cuenca', 'CUE', '16'),
	(340, 'ES', 'Girona', 'GIR', '17'),
	(341, 'ES', 'Granada', 'GRA', '18'),
	(342, 'ES', 'Guadalajara', 'GUA', '19'),
	(343, 'ES', 'Guipuzcoa', 'GUI', '20'),
	(344, 'ES', 'Huelva', 'HUL', '21'),
	(345, 'ES', 'Huesca', 'HUS', '22'),
	(346, 'ES', 'Jaen', 'JAE', '23'),
	(347, 'ES', 'La Rioja', 'LRI', '26'),
	(348, 'ES', 'Las Palmas', 'LPA', '35'),
	(349, 'ES', 'Leon', 'LEO', '24'),
	(350, 'ES', 'Lleida', 'LLE', '25'),
	(351, 'ES', 'Lugo', 'LUG', '27'),
	(352, 'ES', 'Madrid', 'MAD', '28'),
	(353, 'ES', 'Malaga', 'MAL', '29'),
	(354, 'ES', 'Melilla', 'MEL', '52'),
	(355, 'ES', 'Murcia', 'MUR', '30'),
	(356, 'ES', 'Navarra', 'NAV', '31'),
	(357, 'ES', 'Ourense', 'OUR', '32'),
	(358, 'ES', 'Palencia', 'PAL', '34'),
	(359, 'ES', 'Pontevedra', 'PON', '36'),
	(360, 'ES', 'Salamanca', 'SAL', '37'),
	(361, 'ES', 'Santa Cruz de Tenerife', 'SCT', '38'),
	(362, 'ES', 'Segovia', 'SEG', '40'),
	(363, 'ES', 'Sevilla', 'SEV', '41'),
	(364, 'ES', 'Soria', 'SOR', '42'),
	(365, 'ES', 'Tarragona', 'TAR', '43'),
	(366, 'ES', 'Teruel', 'TER', '44'),
	(367, 'ES', 'Toledo', 'TOL', '45'),
	(368, 'ES', 'Valencia', 'VAL', '46'),
	(369, 'ES', 'Valladolid', 'VLL', '47'),
	(370, 'ES', 'Vizcaya', 'VIZ', '48'),
	(371, 'ES', 'Zamora', 'ZAM', '49'),
	(372, 'ES', 'Zaragoza', 'ZAR', '50'),
	(373, 'AM', 'Aragatsotn', 'ARG', 'AG'),
	(374, 'AM', 'Ararat', 'ARR', 'AR'),
	(375, 'AM', 'Armavir', 'ARM', 'AV'),
	(376, 'AM', 'Gegharkunik', 'GEG', 'GR'),
	(377, 'AM', 'Kotayk', 'KOT', 'KT'),
	(378, 'AM', 'Lori', 'LOR', 'LO'),
	(379, 'AM', 'Shirak', 'SHI', 'SH'),
	(380, 'AM', 'Syunik', 'SYU', 'SU'),
	(381, 'AM', 'Tavush', 'TAV', 'TV'),
	(382, 'AM', 'Vayots-Dzor', 'VAD', 'VD'),
	(383, 'AM', 'Yerevan', 'YER', 'ER'),
	(384, 'IN', 'Andaman & Nicobar Islands', 'ANI', 'AI'),
	(385, 'IN', 'Andhra Pradesh', 'AND', 'AN'),
	(386, 'IN', 'Arunachal Pradesh', 'ARU', 'AR'),
	(387, 'IN', 'Assam', 'ASS', 'AS'),
	(388, 'IN', 'Bihar', 'BIH', 'BI'),
	(389, 'IN', 'Chandigarh', 'CHA', 'CA'),
	(390, 'IN', 'Chhatisgarh', 'CHH', 'CH'),
	(391, 'IN', 'Dadra & Nagar Haveli', 'DAD', 'DD'),
	(392, 'IN', 'Daman & Diu', 'DAM', 'DA'),
	(393, 'IN', 'Delhi', 'DEL', 'DE'),
	(394, 'IN', 'Goa', 'GOA', 'GO'),
	(395, 'IN', 'Gujarat', 'GUJ', 'GU'),
	(396, 'IN', 'Haryana', 'HAR', 'HA'),
	(397, 'IN', 'Himachal Pradesh', 'HIM', 'HI'),
	(398, 'IN', 'Jammu & Kashmir', 'JAM', 'JA'),
	(399, 'IN', 'Jharkhand', 'JHA', 'JH'),
	(400, 'IN', 'Karnataka', 'KAR', 'KA'),
	(401, 'IN', 'Kerala', 'KER', 'KE'),
	(402, 'IN', 'Lakshadweep', 'LAK', 'LA'),
	(403, 'IN', 'Madhya Pradesh', 'MAD', 'MD'),
	(404, 'IN', 'Maharashtra', 'MAH', 'MH'),
	(405, 'IN', 'Manipur', 'MAN', 'MN'),
	(406, 'IN', 'Meghalaya', 'MEG', 'ME'),
	(407, 'IN', 'Mizoram', 'MIZ', 'MI'),
	(408, 'IN', 'Nagaland', 'NAG', 'NA'),
	(409, 'IN', 'Orissa', 'ORI', 'OR'),
	(410, 'IN', 'Pondicherry', 'PON', 'PO'),
	(411, 'IN', 'Punjab', 'PUN', 'PU'),
	(412, 'IN', 'Rajasthan', 'RAJ', 'RA'),
	(413, 'IN', 'Sikkim', 'SIK', 'SI'),
	(414, 'IN', 'Tamil Nadu', 'TAM', 'TA'),
	(415, 'IN', 'Tripura', 'TRI', 'TR'),
	(416, 'IN', 'Uttaranchal', 'UAR', 'UA'),
	(417, 'IN', 'Uttar Pradesh', 'UTT', 'UT'),
	(418, 'IN', 'West Bengal', 'WES', 'WE'),
	(419, 'IR', 'Ahmadi va Kohkiluyeh', 'BOK', 'BO'),
	(420, 'IR', 'Ardabil', 'ARD', 'AR'),
	(421, 'IR', 'Azarbayjan-e Gharbi', 'AZG', 'AG'),
	(422, 'IR', 'Azarbayjan-e Sharqi', 'AZS', 'AS'),
	(423, 'IR', 'Bushehr', 'BUS', 'BU'),
	(424, 'IR', 'Chaharmahal va Bakhtiari', 'CMB', 'CM'),
	(425, 'IR', 'Esfahan', 'ESF', 'ES'),
	(426, 'IR', 'Fars', 'FAR', 'FA'),
	(427, 'IR', 'Gilan', 'GIL', 'GI'),
	(428, 'IR', 'Gorgan', 'GOR', 'GO'),
	(429, 'IR', 'Hamadan', 'HAM', 'HA'),
	(430, 'IR', 'Hormozgan', 'HOR', 'HO'),
	(431, 'IR', 'Ilam', 'ILA', 'IL'),
	(432, 'IR', 'Kerman', 'KER', 'KE'),
	(433, 'IR', 'Kermanshah', 'BAK', 'BA'),
	(434, 'IR', 'Khorasan-e Junoubi', 'KHJ', 'KJ'),
	(435, 'IR', 'Khorasan-e Razavi', 'KHR', 'KR'),
	(436, 'IR', 'Khorasan-e Shomali', 'KHS', 'KS'),
	(437, 'IR', 'Khuzestan', 'KHU', 'KH'),
	(438, 'IR', 'Kordestan', 'KOR', 'KO'),
	(439, 'IR', 'Lorestan', 'LOR', 'LO'),
	(440, 'IR', 'Markazi', 'MAR', 'MR'),
	(441, 'IR', 'Mazandaran', 'MAZ', 'MZ'),
	(442, 'IR', 'Qazvin', 'QAS', 'QA'),
	(443, 'IR', 'Qom', 'QOM', 'QO'),
	(444, 'IR', 'Semnan', 'SEM', 'SE'),
	(445, 'IR', 'Sistan va Baluchestan', 'SBA', 'SB'),
	(446, 'IR', 'Tehran', 'TEH', 'TE'),
	(447, 'IR', 'Yazd', 'YAZ', 'YA'),
	(448, 'IR', 'Zanjan', 'ZAN', 'ZA'),
	(449, 'PL', 'Dolnośląskie', 'DOL', 'DO'),
	(450, 'PL', 'Kujawsko-Pomorskie', 'KUJ', 'KU'),
	(451, 'PL', 'Lubelskie', 'LUB', 'LU'),
	(452, 'PL', 'Lubuskie', 'LBU', 'LB'),
	(453, 'PL', 'Łódzkie', 'LOD', 'LO'),
	(454, 'PL', 'Małopolskie', 'MAL', 'MP'),
	(455, 'PL', 'Mazowieckie', 'MAZ', 'MZ'),
	(456, 'PL', 'Opolskie', 'OPO', 'OP'),
	(457, 'PL', 'Podkarpackie', 'PDK', 'PK'),
	(458, 'PL', 'Podlaskie', 'PDL', 'PL'),
	(459, 'PL', 'Pomorskie', 'POM', 'PO'),
	(460, 'PL', 'Śląskie', 'SLA', 'SL'),
	(461, 'PL', 'Świętokrzyskie', 'SWI', 'SW'),
	(462, 'PL', 'Warmińsko-Mazurskie', 'WAR', 'WA'),
	(463, 'PL', 'Wielkopolskie', 'WIE', 'WI'),
	(464, 'PL', 'Zachodniopomorskie', 'ZAC', 'ZA')";
    }

    protected function protect_uploads() {

        $upload_dir = wp_upload_dir();

        $files = array(
            array(
                'base' => $upload_dir['basedir'] . '/com_shop_uploads',
                'file' => '.htaccess',
                'content' => 'deny from all'
            ),
            array(
                'base' => $upload_dir['basedir'] . '/com_shop_uploads',
                'file' => 'index.html',
                'content' => ''
            ),
        );

        foreach ($files as $file) {
            if (wp_mkdir_p($file['base']) && !file_exists(trailingslashit($file['base']) . $file['file'])) {
                if ($file_handle = @fopen(trailingslashit($file['base']) . $file['file'], 'w')) {
                    fwrite($file_handle, $file['content']);
                    fclose($file_handle);
                }
            }
        }
    }

}
