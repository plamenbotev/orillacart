
jQuery(function () {

    // Uploading files
    var file_path_field;

    window.send_to_editor_default = window.send_to_editor;

    jQuery(".upload_file_button").live("click", function () {

        file_path_field = jQuery(this).parent().find(".file_path");

        formfield = jQuery(file_path_field).attr("name");

        window.send_to_editor = window.send_to_download_url;

        tb_show("", "media-upload.php?post_id=" + jsShopAdminHelper.data.pid + "&amp;type=downloadable_product&amp;from=wc01&amp;TB_iframe=true");
        return false;
    });

    window.send_to_download_url = function (html) {

        file_url = jQuery(html).attr("href");
        if (file_url) {
            jQuery(file_path_field).val(file_url);
        }
        tb_remove();
        window.send_to_editor = window.send_to_editor_default;

    }

    jQuery("dl.tabs").btabs();

    jQuery("label.hasTip").tipsy({gravity: "s", html: true});

    jQuery("input.calendar").datepicker({
        dateFormat: 'yy-mm-dd',
        gotoCurrent: true
    });

    jQuery("a.modal").click(function () {
        var url = this.href;
        if (jQuery("#dialog-modal").length == 0) {
            dialog = jQuery('<div id="dialog-modal" style="display:hidden"></div>').appendTo('body');
        }

        dialog.load(
                url,
                {},
                function (responseText, textStatus, XMLHttpRequest) {

                    dialog.dialog(
                            {
                                modal: true
                            }

                    );
                }
        );


        return false;



    });






    jQuery("#tree").bind("open_node.jstree", function (e, data) {



        jQuery("ul  li[rel='default']:not(:has('input'))", data.rslt.obj).each(function (i) {

            var box = "<input type='checkbox' name='' ";

            if (jQuery(this).hasClass('checked'))
                box += " checked='checked' ";

            box += " value='" + jQuery(this).attr('id').replace('node_', '') + "' onclick='jsShopAdminHelper.products.addProductToCat(this);' />";
            //alert(box);
            this.innerHTML += box;

        });





    }
    )




            .bind("loaded.jstree", function (event, data) {



            }
            )
            .jstree({
                // the list of plugins to include
                "plugins": ["themes", "json_data", "types", "cookies"],
                // Plugin configuration

                // I usually configure the plugin that handles the data first - in this case JSON as it is most common
                "json_data": {
                    // I chose an ajax enabled tree - again - as this is most common, and maybe a bit more complex
                    // All the options are the same as jQuery's except for `data` which CAN (not should) be a function
                    "ajax": {
                        // the URL to fetch the data
                        "url": ajaxurl + "?component=shop&con=shoptree",
                        // this function is executed in the instance's scope (this refers to the tree instance)
                        // the parameter is the node being loaded (may be -1, 0, or undefined when loading the root nodes)
                        "data": function (n) {



                            // the result is fed to the AJAX request `data` option
                            return {
                                "action": "ajax-call-admin",
                                "operation": "get_children",
                                "category_child_id": n.attr ? n.attr("id").replace("node_", "") : -1,
                                "pid": jsShopAdminHelper.data.pid


                            };
                        }
                    }
                },
                // Configuring the search plugin
                "search": {
                    // As this has been a common question - async search
                    // Same as above - the `ajax` config option is actually jQuery's object (only `data` can be a function)
                    "ajax": {
                        "url": ajaxurl + "?component=shop&con=shoptree",
                        // You get the search string as a parameter
                        "data": function (str) {
                            return {
                                "action": "ajax-call-admin",
                                "operation": "search",
                                "search_str": str
                            };
                        }
                    }
                },
                // Using types - most of the time this is an overkill
                // Still meny people use them - here is how
                "types": {
                    // I set both options to -2, as I do not need depth and children count checking
                    // Those two checks may slow jstree a lot, so use only when needed
                    "max_depth": -2,
                    "max_children": -2,
                    // I want only `drive` nodes to be root nodes 
                    // This will prevent moving or creating any other type as a root node
                    "valid_children": ["tree"],
                    "types": {
                        // The default type

                        "default": {
                            // I want this type to have no children (so only leaf nodes)
                            // In my case - those are files
                            "valid_children": ["default"],
                            // If we specify an icon for the default type it WILL OVERRIDE the theme icons
                            "icon": {
                                "image": jsShopAdminHelper.data.folderImage
                            }
                        },
                        // The `drive` nodes 
                        "tree": {
                            // can have files and folders inside, but NOT other `drive` nodes
                            "valid_children": ["default"],
                            "icon": {
                                "image": jsShopAdminHelper.data.rootImage
                            },
                            // those options prevent the functions with the same name to be used on the `drive` type nodes
                            // internally the `before` event is used
                            "start_drag": false,
                            "move_node": false,
                            "delete_node": false,
                            "remove": false
                        }
                    }
                },
                // For UI & core - the nodes to initially select and open will be overwritten by the cookie plugin

                // the UI plugin - it handles selecting/deselecting/hovering nodes
                "ui": {
                    // this makes the node with ID node_4 selected onload
                    //	"initially_select" : [ "node_4" ]
                },
                // the core plugin - not many options here
                "core": {
                    "animation": 280
                            // just open those two nodes up
                            // as this is an AJAX enabled tree, both will be downloaded from the server
                            //"initially_open" : [ "node_2" , "node_3" ] 
                }
            })

});