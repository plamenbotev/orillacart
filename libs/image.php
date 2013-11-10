<?php

defined('_VALID_EXEC') or die('access denied');

final class image {

    const notImage = 1;

    private $image;
    private $image_type;

    public function load($filename) {

        $image_info = getimagesize($filename);
        $this->image_type = $image_info[2];
        if ($this->image_type == IMAGETYPE_JPEG) {
            $this->image = imagecreatefromjpeg($filename);
        } elseif ($this->image_type == IMAGETYPE_GIF) {
            $this->image = imagecreatefromgif($filename);
        } elseif ($this->image_type == IMAGETYPE_PNG) {
            $this->image = imagecreatefrompng($filename);
        }
        else
            return self::notImage;
    }

    public function save($filename, $image_type = IMAGETYPE_JPEG, $compression = 75, $permissions = null) {
        if ($image_type == IMAGETYPE_JPEG) {
            imagejpeg($this->image, $filename, $compression);
        } elseif ($image_type == IMAGETYPE_GIF) {
            imagegif($this->image, $filename);
        } elseif ($image_type == IMAGETYPE_PNG) {
            imagepng($this->image, $filename);
        }
        if ($permissions != null) {
            chmod($filename, $permissions);
        }
    }

    public function output($image_type = IMAGETYPE_JPEG) {
        if ($image_type == IMAGETYPE_JPEG) {
            imagejpeg($this->image);
        } elseif ($image_type == IMAGETYPE_GIF) {
            imagegif($this->image);
        } elseif ($image_type == IMAGETYPE_PNG) {
            imagepng($this->image);
        }
    }

    public function getWidth() {
        return imagesx($this->image);
    }

    public function getHeight() {
        return imagesy($this->image);
    }

    public function resizeToHeight($height) {

        if ($this->getHeight() <= $height)
            return false;

        $ratio = $height / $this->getHeight();
        $width = $this->getWidth() * $ratio;
        $this->resize($width, $height);
    }

    public function make_square($a, $b = 0) {

        $a = abs($a);
        $b = abs($b);
        $c = null;

        if ($a && $b) {

            $image = imagecreatetruecolor($a, $b);
        } else {

            $a = max($a, $b);
            $b = $a;
            $image = imagecreatetruecolor($a, $a);
        }


        imagealphablending($image, false);
        $col = imagecolorallocatealpha($image, 255, 255, 255, 127);
        //imagecolortransparent($image, $col);

        imagefilledrectangle($image, 0, 0, $a, $a, $col);
        imagesavealpha($image, true);
        imagealphablending($image, true);
        // ^^ Alpha blanding is back on.


        if ($this->getWidth() >= $this->getHeight()) {

            if ($this->getWidth() > $a)
                $this->resizeToWidth($a);
        } else {

            if ($this->getHeight() > $b)
                $this->resizeToHeight($b);
        }



        imagecopymerge($image, $this->image, ($a - $this->getWidth()) / 2, // Center the image horizontally
                ($b - $this->getHeight()) / 2, // Center the image vertically
                0, 0, $this->getWidth(), $this->getHeight(), 100);

        imagedestroy($this->image);

        $this->image = $image;
    }

    public function resizeToWidth($width) {

        if ($this->getWidth() <= $width)
            return false;
        $ratio = $width / $this->getWidth();
        $height = $this->getheight() * $ratio;
        $this->resize($width, $height);
    }

    public function crop($thumb_width, $thumb_height) {


        if (!$thumb_width) {

            $thumb_width = $thumb_height;
        } else if (!$thumb_height) {

            $thumb_height = $thumb_width;
        }


        $width = $this->getWidth();
        $height = $this->getHeight();

        $original_aspect = $width / $height;
        $thumb_aspect = $thumb_width / $thumb_height;

        if ($original_aspect >= $thumb_aspect) {
            // If image is wider than thumbnail (in aspect ratio sense)
            $new_height = $thumb_height;
            $new_width = $width / ($height / $thumb_height);
        } else {
            // If the thumbnail is wider than the image
            $new_width = $thumb_width;
            $new_height = $height / ($width / $thumb_width);
        }

        $thumb = imagecreatetruecolor($thumb_width, $thumb_height);

// Resize and crop
        imagecopyresampled($thumb, $this->image, 0 - ($new_width - $thumb_width) / 2, // Center the image horizontally
                0 - ($new_height - $thumb_height) / 2, // Center the image vertically
                0, 0, $new_width, $new_height, $width, $height);

        imagedestroy($this->image);
        $this->image = $thumb;
    }

    public function scale($scale) {
        $width = $this->getWidth() * $scale / 100;
        $height = $this->getheight() * $scale / 100;
        $this->resize($width, $height);
    }

    public function resize($width, $height) {
        if (strtolower($height) == 'auto') {

            return $this->resizeToWidth($width);
        } else if (strtolower($width) == 'auto') {

            return $this->resizeToHeight($height);
        }

        $new_image = imagecreatetruecolor($width, $height);
        imagecopyresampled($new_image, $this->image, 0, 0, 0, 0, $width, $height, $this->getWidth(), $this->getHeight());
        imagedestroy($this->image);
        $this->image = $new_image;
    }

    public function __destruct() {

        imagedestroy($this->image);
    }

}