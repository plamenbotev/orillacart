jQuery(function () {

    var selected_product = null;

    jQuery("#add_product_modal").on('click', "button#add_product_to_order", function (event) {

        jQuery.ajax({
            url: ajaxurl + '?action=ajax-call-admin&component=shop&con=orders&task=save_product_to_order&oid=' + document.getElementById('post_ID').value + '&pid=' + selected_product,
            type: "post",
            dataType: "json",
            data: jQuery('input#product_quantity,select.property').serialize(),
            success: function (data) {
                jQuery("#orillacart-order-totals div.inside").html(data.totals);
                jQuery("#orillacart-order-items div.inside").html(data.items);
                if (typeof data.status != "undefined" && data.status.length) {
                    alert(data.status);
                }
            }
        });

        event.stopPropagation();
        return false;

    });

    jQuery(".refresh-billing-states").bind("change", function (e) {
        jsShopAdminHelper.user.get_country_states(this.value, "billing_states_container", "billing");
    });

    jQuery(".refresh-shipping-states").bind("change", function (e) {
        jsShopAdminHelper.user.get_country_states(this.value, "shipping_states_container", "shipping");
    });

    //initialize the date picker on ajax added products
    jQuery("#orillacart-order-items").on("focus", "input.calendar", function (e) {

        if (!jQuery(this).hasClass("hasDatepicker")) {
            jQuery("input.calendar").datepicker({
                dateFormat: "yy-mm-dd",
                gotoCurrent: true
            });
        }

    });
    //initialize the date picker
    jQuery("input.calendar").datepicker({
        dateFormat: "yy-mm-dd",
        gotoCurrent: true
    });

    jQuery("#orillacart-order-items").on("click", ".remove-order-item", function (e) {

        var id = jQuery(this).closest("tr").attr("id").replace("item-", "");
        jQuery("#item-" + id).remove();
        jQuery("#item-downloads-" + id).remove();
        e.stopPropagation();
        return false;
    });

    //select parent product and show the attributes to select variations or options
    jQuery("input#select_parent").autocomplete({
        source: function (request, response) {
            jQuery.ajax({
                url: ajaxurl + "?action=ajax-call-admin&component=shop&con=orders&task=get_parent_list",
                dataType: "json",
                data: {
                    str: request.term
                },
                success: function (data) {

                    response(jQuery.map(data, function (value, key) {

                        return {
                            pid: key,
                            label: value,
                            value: value
                        }
                    }));
                }
            });
        },
        minLength: 2,
        select: function (event, ui) {
            if (ui.item) {
                selected_product = ui.item.pid;
                jQuery('div#add_product_modal').html('Loading...')
                        .load(ajaxurl + '?action=ajax-call-admin&component=shop&con=orders&task=add_product_to_order&oid=' + document.getElementById('post_ID').value + '&pid=' + ui.item.pid)
                        .dialog()
                        .dialog('open');

                return ui.item.label;
            }

        },
        open: function () {
            jQuery(this).removeClass("ui-corner-all").addClass("ui-corner-top");
        },
        close: function () {
            jQuery(this).removeClass("ui-corner-top").addClass("ui-corner-all");
        }
    });

    //Assign different user to the order
    jQuery("input#select_user").autocomplete({
        source: function (request, response) {
            jQuery.ajax({
                url: ajaxurl + "?action=ajax-call-admin&component=shop&con=orders&task=get_users_list",
                dataType: "json",
                data: {
                    str: request.term
                },
                success: function (data) {

                    response(jQuery.map(data, function (value, key) {

                        return {
                            pid: key,
                            label: value,
                            value: value
                        }
                    }));
                }
            });
        },
        minLength: 2,
        select: function (event, ui) {
            if (ui.item) {

                document.getElementById('customer_id').value = ui.item.pid;

                return ui.item.label;
            }

        },
        open: function () {
            jQuery(this).removeClass("ui-corner-all").addClass("ui-corner-top");
        },
        close: function () {
            jQuery(this).removeClass("ui-corner-top").addClass("ui-corner-all");
        }
    });

});
