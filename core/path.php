<?php

class Path {

    public static function canChmod($path) {
        $perms = fileperms($path);
        if ($perms !== false) {
            if (@chmod($path, $perms ^ 0001)) {
                @chmod($path, $perms);
                return true;
            }
        }

        return false;
    }

    public static function setPermissions($path, $filemode = '0644', $foldermode = '0755') {
        // Initialise return value
        $ret = true;

        if (is_dir($path)) {
            $dh = opendir($path);

            while ($file = readdir($dh)) {
                if ($file != '.' && $file != '..') {
                    $fullpath = $path . '/' . $file;
                    if (is_dir($fullpath)) {
                        if (!Path::setPermissions($fullpath, $filemode, $foldermode)) {
                            $ret = false;
                        }
                    } else {
                        if (isset($filemode)) {
                            if (!@ chmod($fullpath, octdec($filemode))) {
                                $ret = false;
                            }
                        }
                    }
                }
            }
            closedir($dh);
            if (isset($foldermode)) {
                if (!@ chmod($path, octdec($foldermode))) {
                    $ret = false;
                }
            }
        } else {
            if (isset($filemode)) {
                $ret = @ chmod($path, octdec($filemode));
            }
        }

        return $ret;
    }

    public static function getPermissions($path) {
        $path = Path::clean($path);
        $mode = @ decoct(@ fileperms($path) & 0777);

        if (strlen($mode) < 3) {
            return '---------';
        }

        $parsed_mode = '';
        for ($i = 0; $i < 3; $i++) {
            // read
            $parsed_mode .= ($mode{$i} & 04) ? "r" : "-";
            // write
            $parsed_mode .= ($mode{$i} & 02) ? "w" : "-";
            // execute
            $parsed_mode .= ($mode{$i} & 01) ? "x" : "-";
        }

        return $parsed_mode;
    }

    public static function check($path) {
        if (strpos($path, '..') !== false) {
            // Don't translate
            throw new Exception('Use of relative paths not permitted');
            exit();
        }

        $path = Path::clean($path);
        if ((ABSPATH != '') && strpos($path, Path::clean(ABSPATH)) !== 0) {
            // Don't translate
            throw new Exception('Snooping out of bounds @ ' . $path);
            jexit();
        }

        return $path;
    }

    public static function clean($path, $ds = DIRECTORY_SEPARATOR) {
        $path = trim($path);

        if (empty($path)) {
            $path = ABSPATH;
        } else {
            // Remove double slashes and backslashes and convert all slashes and backslashes to DS
            $path = preg_replace('#[/\\\\]+#', $ds, $path);
        }

        return $path;
    }

    public static function find($paths, $file) {
        settype($paths, 'array'); //force to array
        // Start looping through the path set
        foreach ($paths as $path) {
            // Get the path to the file
            $fullname = $path . '/' . $file;

            // Is the path based on a stream?
            if (strpos($path, '://') === false) {
                // Not a stream, so do a realpath() to avoid directory
                // traversal attempts on the local file system.
                $path = realpath($path); // needed for substr() later
                $fullname = realpath($fullname);
            }

            // The substr() check added to make sure that the realpath()
            // results in a directory registered so that
            // non-registered directories are not accessible via directory
            // traversal attempts.
            if (file_exists($fullname) && substr($fullname, 0, strlen($path)) == $path) {
                return $fullname;
            }
        }

        // Could not find the file in the set of paths
        return false;
    }

    protected static function _items($path, $filter, $recurse, $full, $exclude, $excludefilter_string, $findfiles) {
        @set_time_limit(ini_get('max_execution_time'));

        // Initialise variables.
        $arr = array();

        // Read the source directory
        if (!($handle = @opendir($path))) {
            return $arr;
        }

        while (($file = readdir($handle)) !== false) {
            if ($file != '.' && $file != '..' && !in_array($file, $exclude) && (empty($excludefilter_string) || !preg_match($excludefilter_string, $file))) {
                // Compute the fullpath
                $fullpath = $path . '/' . $file;

                // Compute the isDir flag
                $isDir = is_dir($fullpath);

                if (($isDir xor $findfiles) && preg_match("/$filter/", $file)) {
                    // (fullpath is dir and folders are searched or fullpath is not dir and files are searched) and file matches the filter
                    if ($full) {
                        // Full path is requested
                        $arr[] = $fullpath;
                    } else {
                        // Filename is requested
                        $arr[] = $file;
                    }
                }
                if ($isDir && $recurse) {
                    // Search recursively
                    if (is_integer($recurse)) {
                        // Until depth 0 is reached
                        $arr = array_merge($arr, self::_items($fullpath, $filter, $recurse - 1, $full, $exclude, $excludefilter_string, $findfiles));
                    } else {
                        $arr = array_merge($arr, self::_items($fullpath, $filter, $recurse, $full, $exclude, $excludefilter_string, $findfiles));
                    }
                }
            }
        }
        closedir($handle);

        return $arr;
    }

    public static function folders($path, $filter = '.', $recurse = false, $full = false, $exclude = array('.svn', 'CVS', '.DS_Store', '__MACOSX'), $excludefilter = array('^\..*')) {
        // Check to make sure the path valid and clean
        $path = self::clean($path);

        // Is the path a folder?
        if (!is_dir($path)) {
            return array();
        }

        // Compute the excludefilter string
        if (count($excludefilter)) {
            $excludefilter_string = '/(' . implode('|', $excludefilter) . ')/';
        } else {
            $excludefilter_string = '';
        }

        // Get the folders
        $arr = self::_items($path, $filter, $recurse, $full, $exclude, $excludefilter_string, false);

        // Sort the folders
        asort($arr);
        return array_values($arr);
    }

    public static function files($path, $filter = '.', $recurse = false, $full = false, $exclude = array('.svn', 'CVS', '.DS_Store', '__MACOSX'), $excludefilter = array('^\..*', '.*~')) {
        // Check to make sure the path valid and clean
        $path = self::clean($path);

        // Is the path a folder?
        if (!is_dir($path)) {
            return array();
        }

        // Compute the excludefilter string
        if (count($excludefilter)) {
            $excludefilter_string = '/(' . implode('|', $excludefilter) . ')/';
        } else {
            $excludefilter_string = '';
        }

        // Get the files
        $arr = self::_items($path, $filter, $recurse, $full, $exclude, $excludefilter_string, true);

        // Sort the files
        asort($arr);
        return array_values($arr);
    }

}