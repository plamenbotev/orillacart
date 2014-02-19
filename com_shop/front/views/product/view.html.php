<?php

class shopViewProduct extends view {

    public function display() {

        global $wp_query, $post;

        the_post();

        $model = $this->getModel('product');
        $this->assign('row', $model->get_product_data($post->ID));


        if ($this->row->availability === true) {
            $this->assign("availability", __("in stock", "com_shop"));
        } else if ($this->row->availability === false) {
            $this->assign("availability", __("out of stock", "com_shop"));
        } else {
            $this->assign("availability", sprintf(__("%s in stock", "com_shop"), (int) $this->row->availability));
        }


        add_filter('edit_template_paths_shop', array($this, 'override_templates'), 1, 9);

        if (has_post_thumbnail()) {

            $this->assign('thumbnail_full', current(wp_get_attachment_image_src(get_post_thumbnail_id(), 'full')));
            $this->assign('thumbnail_medium', current(wp_get_attachment_image_src(get_post_thumbnail_id(), 'product_medium')));
        } else {
            $img = current($this->row->images);
            $this->assign('thumbnail_full', $img->image);
            $this->assign('thumbnail_medium', $img->medium);
        }

        if (has_term('digital', 'product_type', $post)) {
            $files = $model->get_downloadable_files();
            if (count($files) > 0) {
                $this->assign('files', $files);
            } else {
                $this->assign('files', array());
            }
        } else {
            $this->assign('files', array());
        }


        Factory::getMainframe()->addscript('jquery');

        Factory::getMainframe()->setPAgeTitle(get_the_title());

        Factory::getMainframe()->addscript('block', Factory::getApplication('shop')->getAssetsUrl() . "/js/block.js");

        Factory::getMainframe()->addstyle('lb', Factory::getApplication('shop')->getAssetsUrl() . "/slimbox.css");
        Factory::getMainframe()->addscript('json', Factory::getApplication('shop')->getAssetsUrl() . "/js/jquery.json-2.2.js");
        Factory::getMainframe()->addStyle('jquery-btatbs-css', Factory::getApplication('shop')->getAssetsUrl() . "/btabs.style.css");
        Factory::getMainframe()->addScript('jquery-btabs-js', Factory::getApplication('shop')->getAssetsUrl() . "/js/jquery.btabs.js");

        Factory::getMainframe()->addscript('lb', Factory::getApplication('shop')->getAssetsUrl() . "/js/slimbox.js", array('jquery'));

        $params = Factory::getApplication('shop')->getParams();

        Factory::getMainframe()->addCustomHeadTag('product-display', "<script type='text/javascript'>
	
 window.shop_helper.ID = " . $post->ID . ";       
	
jQuery(function() { 
      jQuery('dl.tabs').btabs();
}); 

</script>");

        Factory::getMainframe()->addCustomHeadTag('product-gallery', "<script type='text/javascript'>
	
		
	
	
	function gallery(o,url){
	       shop_helper.initGallery();
		var image = jQuery(\"#com-shop #product_medium_image\");
		image.attr('src',url);
		
		image.parent().attr('href',o.href);
			
	}
 
jQuery(function() { 
    shop_helper.initGallery();
    
  
}); 

</script>");



        parent::display("view_product_details");
    }

    public function load_child_product() {

        global $wp_query, $post;
        $post = get_post(request::getInt('pid', 0));

        $model = $this->getModel('product');
        $helper = Factory::getApplication('shop')->getHelper('product_helper');

        //we are in variation, so lets search for another variations,
        //based on the parent product id!
        if ($post->post_parent) {
            request::setVar('pid', $post->post_parent);
        }

        $pid = $model->get_variation(request::getInt('pid', 0), request::getVar('p', array()));

        if ($post->post_parent && !$pid) {
            $pid = $post->post_parent;
        } else if (!$post->post_parent && $pid === null) {
            $pid = request::getInt('pid', 0);
        }

        if ($pid && (!isset($_POST['f']) || empty($_POST['f']))) {


            $wp_query = new WP_Query();
            $wp_query->query('posts_per_page=-1&post_status=publish&post_type=product&p=' . $pid);

            $wp_query->the_post();

            $model = $this->getModel('product');
            $this->assign('row', $model->get_product_data($pid));

            if ($this->row->availability === true) {
                $this->assign("availability", __("in stock", "com_shop"));
            } else if ($this->row->availability === false) {
                $this->assign("availability", __("out of stock", "com_shop"));
            } else {
                $this->assign("availability", sprintf(__("%s in stock", "com_shop"), (int) $this->row->availability));
            }


            if (has_post_thumbnail()) {

                $this->assign('thumbnail_full', current(wp_get_attachment_image_src(get_post_thumbnail_id($pid), 'full')));
                $this->assign('thumbnail_medium', current(wp_get_attachment_image_src(get_post_thumbnail_id($pid), 'product_medium')));
            } else {
                $img = current($this->row->images);
                $this->assign('thumbnail_full', $img->image);
                $this->assign('thumbnail_medium', $img->medium);
            }

            if (has_term('digital', 'product_type', $post)) {
                $files = $model->get_downloadable_files($post->ID);

                if (count($files) > 0) {
                    $this->assign('files', $files);
                } else {
                    $this->assign('files', array());
                }
            } else {
                $this->assign('files', array());
            }


            $res = new stdClass();
            $res->id = $pid;
            $res->block = array();
            $res->block['gallery'] = '';
            $res->block['data'] = '';
            $res->block['files'] = '';

            if (!empty($this->thumbnail_medium)) {
                ob_start();
                $this->loadTemplate('gallery');
                $res->block['gallery'] = ob_get_clean();
            } else {
                unset($res->block['gallery']);
            }


            ob_start();
            $this->loadTemplate('product_data');
            $res->block['data'] = ob_get_clean();

            ob_start();
            $this->loadTemplate('files');
            $res->block['files'] = ob_get_clean();

            // ob_start();
            //  $this->loadTemplate('attributes');
            //   $res->attributes = ob_get_clean();
        } else {
            $price = $helper->get_price_with_tax(Request::getInt('pid', null), (array) $_POST['p'], (array) $_POST['f']);

            $res = new stdClass();
            $res->price = null;
            $res->price = $price->price_formated;
        }


        header("HTTP/1.0 200 OK");
        header('Content-type: text/json; charset=utf-8');
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Pragma: no-cache");

        echo json_encode($res);
        exit;
    }

    public function override_templates(array $paths) {

        foreach ($paths as $k => $v) {
            $paths[$k] = trailingslashit($v) . $this->row->product->tpl;
        }
        return $paths;
    }

}