<?php

class mailer {

   

    public static function getInstance() {
        static $inst = null;

        if ($inst instanceof self)
            return $inst;

        return $inst = new self();
    }

    public static function init() {
        return self::getInstance();
    }

    /** constructor */
    protected function __construct() {
        
         /**
         * Email Header + Footer
         * */
        add_action('orillacart_email_header', array($this, 'email_header'));
        add_action('orillacart_email_footer', array($this, 'email_footer'));

        /**
         * Add order meta to email templates
         * */
        add_action('orillacart_email_after_order_table', array($this, 'order_meta'), 10, 2);


        add_action('orillacart_order_status_change_email', array($this, 'status_change_mail'), 10, 3);
        add_action('orillacart_order_status_change_email', array($this, 'admin_notify'), 11, 3);

        add_action('orillacart_send_order_invoice', array($this, 'invoice_mail'));

        // Let 3rd parties unhook the above via this hook
        do_action('orillacart_email', $this);
    }

    public function get_from_name() {
        return Factory::getApplication('shop')->getParams()->get('email_from_name');
    }

    public function get_from_address() {
        return Factory::getApplication('shop')->getParams()->get('email_from_address');
    }

    public function get_content_type() {
        return 'text/html';
    }

    function send($to, $subject, $message, $headers = "Content-Type: text/html\r\n", $attachments = "") {
        add_filter('wp_mail_from', array($this, 'get_from_address'));
        add_filter('wp_mail_from_name', array($this, 'get_from_name'));
        add_filter('wp_mail_content_type', array($this, 'get_content_type'));

        ob_start();

        $res = wp_mail($to, $subject, $message, $headers, $attachments);
        if (!$res)
            Factory::getApplication('shop')->setMessage("there was error sending mail to your address.");

        ob_end_clean();

        // Unhook
        remove_filter('wp_mail_from', array($this, 'get_from_address'));
        remove_filter('wp_mail_from_name', array($this, 'get_from_name'));
        remove_filter('wp_mail_content_type', array($this, 'get_content_type'));
    }

    function invoice_mail($order_id) {
        $app = Factory::getApplication('shop');
        if (Framework::is_admin()) {
            //since we are in the admin area, the models and the views that are loaded
            //are from there but we need those from the frontend so load them
            View::addIncludePath('shop', dirname($app->getComponentPath()) . DS . "front" . DS . "views");
            Model::addIncludePath('shop', dirname($app->getComponentPath()) . DS . "front" . DS . "models");
        }
        $helper = Helper::getInstance('order', 'shop');
        $order = $helper->get_order($order_id);
        $view = View::getInstance('mail', 'shop');

        $view->assign('order', $order);

        $blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);

        $subject = apply_filters('orillacart_email_subject_invoice', sprintf(__('[%s] New Customer Order (%s) Invoice', 'com_shop'), $blogname, $order['ID']), $order);

        // Buffer
        ob_start();

        // Get mail template
        $view->invoice();

        // Get contents
        $message = ob_get_clean();
        ob_end_clean();

        //	CC, BCC, additional headers
        $headers = apply_filters('orillacart_email_headers', '', $mail);

        // Attachments
        $attachments = apply_filters('orillacart_email_attachments', '', $mail);

        // Send the mail

        if (isset($order['billing_email'])) {


            return $this->send($order['billing_email'], $subject, $message, $headers, $attachments);
        }
        return false;
    }

    /**
     * New order
     * 
     * */
    function admin_notify($old, $new, $order_id) {

        $mail = '';
        $blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);

        $params = Factory::getApplication('shop')->getParams();

        $notify_admin_mail= $params->get('notify_admin_mail');

        //if the order is completed notify the admiistrator
        if ($new == 'completed' && $params->get('notify_admin_on_new_order') && !empty($notify_admin_mail)) {


            $blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
            $subject = sprintf(__('[%s] New order (%s) has been recieved and marked as completed', 'com_shop'), $blogname, $order_id);

            $app = Factory::getApplication('shop');
            if (Framework::is_admin()) {
                View::addIncludePath('shop', dirname($app->getComponentPath()) . DS . "front" . DS . "views");
                Model::addIncludePath('shop', dirname($app->getComponentPath()) . DS . "front" . DS . "models");
            }

            $helper = Helper::getInstance('order', 'shop');

            $order = $helper->get_order($order_id);

            $view = View::getInstance('mail', 'shop');

            $view->assign('order', $order);
            $view->assign('old_status', $old);
            $view->assign('status', $new);

            ob_start();

            // Get mail template
            $view->admin_notify_mail();

            // Get contents
            $message = ob_get_clean();
            ob_end_clean();

            //	CC, BCC, additional headers
            $headers = apply_filters('orillacart_email_headers', '', $mail);

            // Attachments
            $attachments = apply_filters('orillacart_email_attachments', '', $mail);

            // Send the mail
            return $this->send($notify_admin_mail, $subject, $message, $headers, $attachments);
        }
        
        return true;
    }

    function status_change_mail($old, $new, $order_id) {

        $mail = '';
        $blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);

        switch ($old . "_to_" . $new) {

            case "pending_to_failed":
                $subject = sprintf(__('[%s] Order (%s) Has Failed', 'com_shop'), $blogname, $order_id);
                $mail = 'processing';
                break;
            case "pending_to_completed":
                $subject = sprintf(__('[%s] Order (%s) Completed', 'com_shop'), $blogname, $order_id);
                $mail = 'processing';

                break;
            case "completed_to_refunded":
                $subject = sprintf(__('[%s] Order (%s) Has Been Refunded', 'com_shop'), $blogname, $order_id);
                $mail = 'refunded';
                break;
            case "completed_to_processing":
                $subject = sprintf(__('[%s] Order (%s) Changed Status To Processing', 'com_shop'), $blogname, $order_id);
                $mail = 'processing';
                break;
            case "completed_to_shipped":
            case "processing_to_shipped":
                $subject = sprintf(__('[%s] Order (%s) Has Been Shipped', 'com_shop'), $blogname, $order_id);
                $mail = 'processing';
                break;
        }

        if (!$mail)
            return false;

        $app = Factory::getApplication('shop');
        if (Framework::is_admin()) {
            View::addIncludePath('shop', dirname($app->getComponentPath()) . DS . "front" . DS . "views");
            Model::addIncludePath('shop', dirname($app->getComponentPath()) . DS . "front" . DS . "models");
        }

        $helper = Helper::getInstance('order', 'shop');

        $order = $helper->get_order($order_id);

        $view = View::getInstance('mail', 'shop');

        $view->assign('order', $order);
        $view->assign('old_status', $old);
        $view->assign('status', $new);






        $subject = apply_filters('orillacart_email_subject_status_change', $subject, $order);
        $subject = apply_filters('orillacart_email_subject_status_change_' . $old . "_" . $new, $subject, $order);

        // Buffer
        ob_start();

        // Get mail template
        $view->{$mail}();

        // Get contents
        $message = ob_get_clean();
        ob_end_clean();

        //	CC, BCC, additional headers
        $headers = apply_filters('orillacart_email_headers', '', $mail);

        // Attachments
        $attachments = apply_filters('orillacart_email_attachments', '', $mail);

        // Send the mail

        if (isset($order['billing_email'])) {


            return $this->send($order['billing_email'], $subject, $message, $headers, $attachments);
        }
        return false;
    }

}