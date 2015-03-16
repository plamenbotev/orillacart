<?php

final class lib {

    private function __construct() {
        
    }

    static public function import($path) {

        $libDir = realpath(dirname(__FILE__) . "/../libs");


        $libPath = str_replace('.', DIRECTORY_SEPARATOR, $path);
        $libPath .='.php';

        if (is_dir($libDir . DIRECTORY_SEPARATOR . dirname($libPath)) && file_exists($libDir . DIRECTORY_SEPARATOR . $libPath))
            require_once($libDir . DIRECTORY_SEPARATOR . $libPath);
        else
            throw new Exception("LIB: " . $path . " not found!");
    }

}
