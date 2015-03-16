<?php

class toolbar {

    private $name = '';
    private $bar = array();
    private $buttons = array();
    private $buttonPath = array();
    private $title = '';
    private $title_image = 'default';
    private $icon = null;

    public function getName() {
        return $this->name;
    }

    protected function __construct($name = 'toolbar', $title = '', $title_image = 'default', $icon = null) {
        $this->name = $name;
        $this->title = $title;
        $this->title_image = $title_image;
        $this->icon = $icon;
        // Set base path to find buttons
        $this->buttonPath[] = realpath(dirname(__FILE__) . DS . 'button');
    }

    public static function getInstance($name = 'toolbar', $title = '', $title_image = 'default', $icon = null) {
        static $instances = array();;
       

        if (!isset($instances[$name])) {
            $instances[$name] = new self($name, $title, $title_image, $icon);
        }

        return $instances[$name];
    }

    public function appendButton() {
        // Push button onto the end of the toolbar array
        $btn = func_get_args();
        array_push($this->bar, $btn);
        return true;
    }

    public function prependButton() {
        // Insert button into the front of the toolbar array
        $btn = func_get_args();
        array_unshift($this->bar, $btn);
        return true;
    }

    public function render() {
        $html = array();
        // Start toolbar div
        $html[] = '<div class="icon32 toolbar-header toolbar-header-' . $this->title_image . '">
            
                    ' . ($this->icon ? '<i class="icon-' . $this->icon . '"></i>' : '') . '
                    <br />
                    
                    </div>';


        $html[] = '<h2 id="' . $this->name . '">' . $this->title;
        $html[] = '</h2>';
        $html[] = '<div class="btn-' . $this->name . '">';

        foreach ($this->bar as $button) {
            $html[] = $this->renderButton($button);
        }

        // End toolbar div

        $html[] = '</div><div class="clr"></div>';

        return implode("\n", $html);
    }

    protected function renderButton($node) {
        // Get the button type
        $type = $node[0];

        $button = $this->loadButtonType($type);

        /**
         * Error Occurred
         */
        if ($button === false) {
            throw new Exception('Button not defined for type = ' . $type);
        }
		unset($node[0]);
        return $button->render($node);
    }

    function loadButtonType($type, $new = false) {
        $false = false;

        $signature = md5($type);
        if (isset($this->buttons[$signature]) && $new === false) {
            return $this->buttons[$signature];
        }

        if (!class_exists('button')) {
            throw new Exception('Could not load button base class.');
            return $false;
        }

        $buttonClass = 'button' . ucfirst($type);
        if (!class_exists($buttonClass)) {
            if (isset($this->buttonPath)) {
                $dirs = $this->buttonPath;
            } else {
                $dirs = array();
            }
			$filter = FilterInput::getInstance();
            $file = $filter->clean(str_replace('_', DS, strtolower($type)) . '.php', 'path');


            $full_path = null;

            foreach ((array) $dirs as $path) {

                // get the path to the file
                $fullname = $path . DS . $file;

                //  realpath() to avoid directory
                // traversal attempts on the local file system.
                $path = realpath($path); // needed for substr() later

                $fullname = realpath($fullname);


                // the substr() check added to make sure that the realpath()
                // results in a directory registered so that
                // non-registered directores are not accessible via directory
                // traversal attempts.

                if (file_exists($fullname) && substr($fullname, 0, strlen($path)) == $path) {
                    $full_path = $fullname;
                    break;
                }
            }


            if ($full_path) {

                include_once $full_path;
            } else {
                throw new Exception("Could not load module $buttonClass ($buttonFile).");
            }
        }

        if (!class_exists($buttonClass)) {
            throw new Exception("Module file $buttonFile does not contain class $buttonClass.");
        }
        $this->buttons[$signature] = new $buttonClass($this);

        return $this->buttons[$signature];
    }

    function addButtonPath($path) {
        if (is_array($path)) {
            $this->buttonPath = (array) array_merge((array) $this->buttonPath, (array) $path);
        } else {
            array_push($this->buttonPath, $path);
        }
    }

}
