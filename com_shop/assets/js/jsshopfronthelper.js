;
(function (window, $) {

    window.shop_helper = new function () {

        this.ajaxurl = ''; //url for all ajax requests
        this.ID = null; //current product id
        this.req = null; //current ajax request reference
        this.recalc_params = jQuery.Callbacks();
        this.after_recalc_price = jQuery.Callbacks();
        /*
         *	load variation or recalculatethe price, based on the product type and if variation is available
         *	for the selected attribute properties
         */
        this.recalc_price = function (pid) {
            /*
             q = (typeof q === "undefined") ? jQuery('#submit-form-container input[name="qty"]').val() : q;
             
             if(q < 1) q = 1;
             */
            if (!pid)
                return false;

            jQuery('#submit-form-container, .product-attributes').block({
                message: null,
                overlayCSS: {
                    background: '#fff ',
                    opacity: 0.6
                }

            });

            var data = {
                p: [],
                f: [],
                // qty:1
            }

            this.recalc_params.fire(data);

            jQuery("#com-shop #product_attributes select.property").each(function (i) {

                switch (this.nodeName.toLowerCase()) {

                    case "select":

                        if (this.value)
                            data.p.push(this.value);
                        break;

                    case "input":

                        if (this.type.toLowerCase() != 'radio')
                            break;
                        if (!this.checked)
                            break;
                        data.p.push(this.value);

                        break;
                }
            });

            jQuery("#com-shop input[name=files\\[\\]]").each(function (i) {

                if (jQuery(this).attr('checked')) {
                    data.f.push(jQuery(this).val());
                }
            });



            var ajaxurl = this.ajaxurl;

            if (this.req) {
                this.req.abort();
            }

            var $this = this;

            this.req = jQuery.ajax({
                type: "post",
                url: ajaxurl + "?action=ajax-call-front&component=shop&con=product&task=get_price&pid=" + pid,
                data: data,
                success: function (res, text) {

                    if (typeof res['id'] != "undefined") {

                        $this.ID = res['id'];

                        for (var key in res.block) {
                            jQuery("#product_" + key).html(res.block[key]);
                        }

                        $this.after_recalc_price.fire(this);

                        $this.initGallery();
                    } else {
                        jQuery("#com-shop #price_container").html(res.price);

                        $this.after_recalc_price.fire(this);
                    }
                    jQuery('#submit-form-container, .product-attributes').unblock();
                },
                error: function (request, status, error) {
                    jQuery('#submit-form-container, .product-attributes').unblock();
                }
            });
        }

        /*
         *	Init the product gallery
         */
        this.initGallery = function () {
            var total = jQuery("#com-shop #gallery a, [rel^='lightbox']").length;
            jQuery("#com-shop #gallery a, [rel^='lightbox']").slimbox(0, '', function (e, i) {

                if (i == 0 && total > 1)
                    return false;

                return true;
            });
        }

        //private registry helper

        function registry(options) {

            this.data = options;

            this.get = function (k, def) {
                if (typeof this.data != "undefined" && typeof this.data[k] != "undefined" && this.data[k] != null) {
                    return this.data[k];
                } else {
                    if (typeof def != "undefined" && def != null)
                        return def;
                    return "";

                }
            }
        }
    }
})(window, jQuery)
