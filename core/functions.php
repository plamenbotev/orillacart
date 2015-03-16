<?php

if (!function_exists('readfile_chunked')) {

    function readfile_chunked($file, $retbytes = TRUE) {
        $chunksize = 1 * (1024 * 1024);
        $buffer = '';
        $cnt = 0;
        $handle = fopen($file, 'r');

        if ($handle === FALSE)
            return FALSE;

        while (!feof($handle)) :
            $buffer = fread($handle, $chunksize);
            echo $buffer;
            ob_flush();
            flush();

            if ($retbytes)
                $cnt += strlen($buffer);
        endwhile;

        $status = fclose($handle);

        if ($retbytes AND $status)
            return $cnt;

        return $status;
    }

}