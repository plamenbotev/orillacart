<?php

add_filter('shop_build_sef', 'shopBuildSef');

function shopBuildSef($seg) {

    $q = array();

    if (isset($seg['con'])) {
        switch ($seg['con']) {
            case "account":
                $q[] = 'account';
                unset($seg['con']);

                break;
            case "cart":

                unset($seg['con']);

                if (isset($seg['task']) && $seg['task'] == 'checkout') {
                    $q[] = 'checkout';
                    unset($seg['task']);
                } else {
                    $q[] = "cart";

                    if (isset($seg['task']) && $seg['task'] == 'remove') {


                        $q[] = 'remove';
                        $q[] = $seg['group'];
                        unset($seg['task'], $seg['group']);
                    }

                    if (isset($seg['task']) && $seg['task'] == 'add_to_cart') {


                        $q[] = 'add';
                        $q[] = $seg['id'];
                        unset($seg['task'], $seg['id']);
                    }
                }

                break;
        }
    }
    return array($q, $seg);
}
