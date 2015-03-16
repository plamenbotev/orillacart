<?php

class shopViewMail extends view {

    public function processing() {

        $order = $this->order;
        $price = Factory::getComponent('shop')->getHelper('price');
        $model = Model::getInstance('order', 'shop');

        $class = $order['payment_method'];
        if (!class_exists($class) || !is_subclass_of($class, 'payment_method')) {
            $this->assign('on_receipt_content', '');
        } else {
            $gateway = new $class();

            if (method_exists($gateway, 'on_receipt')) {
                $this->assign('on_receipt_content', (string) $gateway->on_receipt());
            } else {
                $this->assign('on_receipt_content', '');
            }
        }

        $this->assign("files", $model->get_order_files((int) $order['ID']));

        $this->assign('price', $price);

        $this->assign('items', $model->get_order_items($order['ID']));

        $this->assign('order', $order);

        $this->loadTemplate("proccessing_mail");
    }

    public function invoice() {
        $order = $this->order;

        $price = Factory::getComponent('shop')->getHelper('price');
        $model = Model::getInstance('order', 'shop');


        $class = $order['payment_method'];
        if (!class_exists($class) || !is_subclass_of($class, 'payment_method')) {
            $this->assign('on_receipt_content', '');
        } else {
            $gateway = new $class();

            if (method_exists($gateway, 'on_receipt')) {
                $this->assign('on_receipt_content', (string) $gateway->on_receipt());
            } else {
                $this->assign('on_receipt_content', '');
            }
        }

        $this->assign("files", $model->get_order_files((int) $order['ID']));

        $this->assign('price', $price);

        $this->assign('items', $model->get_order_items($order['ID']));

        $this->assign('order', $order);

        $this->loadTemplate("invoice_mail");
    }

    public function admin_notify_mail() {
        $order = $this->order;

        $price = Factory::getComponent('shop')->getHelper('price');
        $model = Model::getInstance('order', 'shop');


        $class = $order['payment_method'];
        if (!class_exists($class) || !is_subclass_of($class, 'payment_method')) {
            $this->assign('on_receipt_content', '');
        } else {
            $gateway = new $class();

            if (method_exists($gateway, 'on_receipt')) {
                $this->assign('on_receipt_content', (string) $gateway->on_receipt());
            } else {
                $this->assign('on_receipt_content', '');
            }
        }

        $this->assign("files", $model->get_order_files((int) $order['ID']));

        $this->assign('price', $price);

        $this->assign('items', $model->get_order_items($order['ID']));

        $this->assign('order', $order);

        $this->loadTemplate("admin_notify_mail");
    }

    public function refunded() {
        $order = $this->order;

        $price = Factory::getComponent('shop')->getHelper('price');
        $model = Model::getInstance('order', 'shop');


        $class = $order['payment_method'];
        if (!class_exists($class) || !is_subclass_of($class, 'payment_method')) {
            $this->assign('on_receipt_content', '');
        } else {
            $gateway = new $class();

            if (method_exists($gateway, 'on_receipt')) {
                $this->assign('on_receipt_content', (string) $gateway->on_receipt());
            } else {
                $this->assign('on_receipt_content', '');
            }
        }

        $this->assign("files", $model->get_order_files((int) $order['ID']));

        $this->assign('price', $price);


        $this->assign('items', $model->get_order_items($order['ID']));

        $this->assign('order', $order);

        $this->loadTemplate("refunded_mail");
    }

}
