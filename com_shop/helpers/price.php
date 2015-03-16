<?php

class price {

    protected $archive = true;

    public static function getInstance() {

        static $instance = null;

        if ($instance instanceof self)
            return $instance;

        return $instance = new self();
    }

    protected function __construct() {
        
    }

    public function format($price, $currency_sign = null) {

        $params = Factory::getComponent('shop')->getParams();
        if (!$currency_sign) {
            $currency_sign = $params->get('currency_sign');
        }

        switch (Factory::getComponent('shop')->getParams()->get('currency_place')) {

            case "before":
                return $currency_sign . number_format((double) $price, $params->get('price_decimal'), $params->get('price_separator'), $params->get('thousand_separator'));

                break;
            case "before_with_space":
                return $currency_sign . " " . number_format((double) $price, $params->get('price_decimal'), $params->get('price_separator'), $params->get('thousand_separator'));

                break;
            case "after":
                return number_format((double) $price, $params->get('price_decimal'), $params->get('price_separator'), $params->get('thousand_separator')) . $currency_sign;

                break;
            case "after_with_space":
            default:
                return number_format((double) $price, $params->get('price_decimal'), $params->get('price_separator'), $params->get('thousand_separator')) . " " . $currency_sign;

                break;
        }
    }

    protected function load_rates() {



        setlocale(LC_TIME, "en-GB");
        $now = time() + 3600; // Time in ECB (Germany) is GMT + 1 hour (3600 seconds)
        if (date("I")) {
            $now += 3600; // Adjust for daylight saving time
        }
        $weekday_now_local = gmdate('w', $now); // week day, important: week starts with sunday (= 0) !!
        $date_now_local = gmdate('Ymd', $now);
        $time_now_local = gmdate('Hi', $now);
        $time_ecb_update = '1415';


        $archivefile_name = Factory::getComponent('shop')->getAssetsPath() . '/daily.xml';
        $ecb_filename = 'http://www.ecb.int/stats/eurofxref/eurofxref-daily.xml';
        $val = '';


        if (file_exists($archivefile_name) && filesize($archivefile_name) > 0) {
            // timestamp for the Filename
            $file_datestamp = date('Ymd', filemtime($archivefile_name));

            // check if today is a weekday - no updates on weekends
            if (date('w') > 0 && date('w') < 6
                    // compare filedate and actual date
                    && $file_datestamp != $date_now_local
                    // if localtime is greater then ecb-update-time go on to update and write files
                    && $time_now_local > $time_ecb_update) {
                $curr_filename = $ecb_filename;
            } else {
                $curr_filename = $archivefile_name;
                $this->archive = false;
            }
        } else {
            $curr_filename = $ecb_filename;
        }

        if (!is_writable(Factory::getComponent('shop')->getAssetsPath())) {
            $this->archive = false;
        }
        if ($curr_filename == $ecb_filename) {
            // Fetch the file from the internet
            // $url = parse_url($curr_filename);

            $response = wp_remote_post($curr_filename);

            if (!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300) {

                $contents = $response['body'];
            } else {
                return $array();
            }
        } else {
            $contents = file_get_contents($curr_filename);
        }
        if ($contents) {
            // if archivefile does not exist
            if ($this->archive) {
                // now write new file
                file_put_contents($archivefile_name, $contents);
            }

            // $contents = str_replace ("<Cube currency='USD'", " <Cube currency='EUR' rate='1'/> <Cube currency='USD'", $contents);
        }


        $pattern = "{<Cube\s*currency='(\w*)'\s*rate='([\d\.]*)'/>}is";
        preg_match_all($pattern, $contents, $xml_rates);
        array_shift($xml_rates);

        return array_combine((array) $xml_rates[0], (array) $xml_rates[1]);
    }

    public function convert($amount, $from = null, $to = null) {

        static $rates = array();
        $amount = (double) $amount;
        if (empty($rates)) {
            $rates = $this->load_rates();

            if (empty($rates)) {
                return $amount;
            }

            $rates['EUR'] = 1;
        }

        if (empty($from) || !array_key_exists($from, $rates)) {
            $from = Factory::getComponent('shop')->getParams()->get('currency');
        }
        if (empty($to) || !array_key_exists($to, $rates)) {
            $to = Factory::getComponent('shop')->getParams()->get('currency');
        }
        if ($from == $to) {
            return $amount;
        }

        return(($amount / $rates[$from]) * $rates[$to]);
    }

}
