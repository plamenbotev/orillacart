<?php

defined('_VALID_EXEC') or die('access denied');

final class route {

    static public function get($url = null, $force_ssl = false) {

        $wp_pages = array();

        parse_str($url, $vars);

        $com = $vars['component'];
        unset($vars['component']);

        if (empty($com)) {

            return ( stripos('?', $url) === false ? '?' . $url : $url );
        }

        $con = isset($vars['con']) ? $vars['con'] : null;

        $wp_pages[$com] = (array) component::get_wp_pages($com);


        $page = get_page_by_path($wp_pages[$com][0]);

        if ($page) {
            $pid = $page->ID;
        }

        if (!$pid) {
            return home_url() . ( stripos('?', $url) === false ? '?' . $url : $url );
        }

        if (!get_option('permalink_structure')) {

            if (get_option('show_on_front') == 'page' && $pid == get_option('page_on_front')) {


                if (empty($vars)) {
                    return get_permalink($pid);
                }

                $vars = (array) array_merge(array('page_id' => $pid), (array) $vars);

                return get_permalink($pid) . '?' . http_build_query($vars);
            }
            if (empty($vars)) {
                return get_permalink($pid);
            }

            return get_permalink($pid) . "&" . http_build_query($vars);
        }

        if (has_filter($com . "_build_sef")) {

            $res = apply_filters($com . "_build_sef", $vars);
            $q = $res[0];
            $vars = isset($res[1]) ? $res[1] : null;

            $q = implode('/', (array) $q);
            $q.="/";
            if (!empty($vars)) {
                $q .= (stripos('?', $q) === false ) ? "?" . http_build_query($vars, '', '&') : http_build_query($array, '', '&');
            }

            if (get_option('show_on_front') == 'page' && $pid == get_option('page_on_front')) {

                $q = trailingslashit($wp_pages[$com][0]) . $q;
            }
            return trailingslashit(get_permalink($pid)) . $q;
        }
        return get_permalink($pid) . ( stripos('?', $url) === false ? '?' . $url : $url );
    }

}