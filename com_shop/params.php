<?php

defined('_VALID_EXEC') or die('access denied');

class shopParams extends parameters {

    public $email_from_name = '';
    public $email_from_address = '';
    public $price_decimal = 2;
    public $price_separator = ',';
    public $thousand_separator = ' ';
    public $currency_sign = "$";
    public $currency_place = "after_with_space";
    public $shop_country = null;
    public $shop_state = null;
    public $shop_tax_group = null;
    public $retail_countries = array();
    public $shop_zip = "";
//Global settings

    public $objects_per_page = 12;
    public $display_cetegories = true;
    public $products_per_row = 4;
    public $list_type = 'grid';
    public $catalogOnly = false;
    public $hide_the_price = false;
    public $vat = true;
    public $vatType = 1;
    public $userReg = 0;
    public $checkStock = true;
    public $currency = 'USD';
//Security settings
    public $https = false;
    public $download_method = 'readfile';
//Site

    public $productSort = 'id';
    public $productsPerRow = 1;
    public $thumbX = 90;
    public $thumbY = 90;
    public $miniX = 50;
    public $miniY = 50;
    public $mediumX = 350;
    public $mediumY = 350;
    public $catX = 90;
    public $catY = 90;
//Shipping

    public $shipping = true;
    public $default_weight_unit = 'kg';
    public $default_volume_unit = 'cm';
//Downloads

    public $db_version = "1.2.0";
    public $is_installed = false;
    public $page_id = null;
    public $front_page_cat = 0;
    public $exclude_frontpage_cat = 0;
    public $list_variations = 0;
    public $notify_admin_mail = "";
    public $notify_admin_on_new_order = 0;

    public function bind(&$from) {

        //make all unchecked checkboxes as false
        if (!isset($from['catalogOnly'])) {
            $from['catalogOnly'] = false;
        }
        if (!isset($from['hide_the_price'])) {
            $from['hide_the_price'] = false;
        }

        if (!isset($from['vat'])) {
            $from['vat'] = false;
        }
        if (!isset($from['notify_admin_on_new_order'])) {
            $from['notify_admin_on_new_order'] = false;
        }

        if (!isset($from['checkStock'])) {
            $from['checkStock'] = false;
        }

        if (!isset($from['shipping'])) {
            $from['shipping'] = false;
        }

        if (!isset($from['display_cetegories'])) {
            $from['display_cetegories'] = false;
        }

        if (!isset($from['exclude_frontpage_cat'])) {
            $from['exclude_frontpage_cat'] = false;
        }
        if (!isset($from['list_variations'])) {
            $from['list_variations'] = 0;
        }

        return parent::bind($from);
    }

    public function check() {


        return true;
    }

}
