<?php defined('_VALID_EXEC') or die('access denied'); ?>

<div id="container" >
    <div id="mmenu" >
        <button class="btn btn-small"  id='add_default'>
            <i class="icon-new ">
            </i>
            <?php _e('Add new', 'com_shop'); ?>
        </button>
        <button class="btn btn-small"  id='rename'>
            <i class="icon-pencil">
            </i>
            <?php _e('Rename', 'com_shop'); ?>
        </button>
        <button class="btn btn-small"  id='remove'>
            <i class="icon-remove ">
            </i>
            <?php _e('Remove', 'com_shop'); ?>
        </button>
    </div>



    <!-- the tree container (notice NOT an UL node) -->
    <div id='treecontainer'>
        <div id="tree" class="tree"></div>
    </div>
    <!-- JavaScript neccessary for the tree -->
    <script type="text/javascript">

        shopTreeHelper = ({
            loadEdit: function (id) {

                if (!parseInt(id))
                    return false;
                jQuery.post(
                        ajaxurl + "?component=shop&con=shoptree",
                        {
                            "action": "ajax-call-admin",
                            "operation": "node_editor",
                            "id": id


                        },
                function (r) {



                    if (r.status) {

                        if (r.error)
                            throw r.errormsg;
                        else {


                            jQuery("#category_id").val(r.row.term_id);
                            jQuery("#category_show_id").html(r.row.term_id);
                            jQuery("#category_name").val(r.row.name);
                            jQuery("#category_description").val(r.row.description);
                           // if (r.row.image_id) {

                                jQuery('#product_cat_thumbnail_id').val(r.row.image_id);
                                jQuery('img#product_cat_thumbnail').attr('src', r.row.image_src);
                                jQuery('#products_per_row').val(r.row.products_per_row);
                            //}

                            if (r.row.list_template) {
                                jQuery("#list_template").val(r.row.list_template);
                            }

                            if (r.row.view_style) {
                                jQuery("#view_style").val(r.row.view_style);
                            }


                        }



                    }


                    else {
                        throw " <?php _e('communication error!', 'com_shop'); ?> ";
                    }
                }
                );
                return false;
            }


        });
        jQuery(function () {



            // Settings up the tree - using jQuery(selector).jstree(options);
            // All those configuration options are documented in the _docs folder
            jQuery("#tree")

                    .bind("select_node.jstree", function (e, data) {
                        if (data.rslt.obj.attr('rel') == "default") {
                            shopTreeHelper.loadEdit(data.rslt.obj.attr('id').replace("node_", ""));
                        }



                    })


                    .bind("loaded.jstree", function (event, data) {



                    }
                    )
                    .jstree({
                        // the list of plugins to include
                        "plugins": ["themes", "json_data", "ui", "crrm", "cookies", "dnd", "search", "types", "hotkeys", "contextmenu"],
                        // Plugin configuration
                        "contextmenu": {
                            items: {
                                "ccp": {
                                    "separator_before": true,
                                    "icon": false,
                                    "separator_after": false,
                                    "label": "Edit",
                                    "action": false,
                                    "submenu": {
                                        "cut": {
                                            "separator_before": false,
                                            "separator_after": false,
                                            "label": "Cut",
                                            "action": function (obj) {
                                                this.cut(obj);
                                            }
                                        },
                                        "copy": false,
                                        "paste": {
                                            "separator_before": false,
                                            "icon": false,
                                            "separator_after": false,
                                            "label": "Paste",
                                            "action": function (obj) {
                                                this.paste(obj);
                                            }
                                        }
                                    }
                                }
                            }
                        },
                        "dnd": {
                            "copy_modifier": false,
                            "drag_check": function (data) {
                                return {
                                    after: true,
                                    before: true,
                                    inside: true
                                };
                            }
                        },
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
                                        "category_child_id": n.attr ? n.attr("id").replace("node_", "") : -1



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
                                        "image": "<?php echo $this->folderImage; ?>"
                                    }
                                },
                                // The `drive` nodes
                                "tree": {
                                    // can have files and folders inside, but NOT other `drive` nodes
                                    "valid_children": ["default"],
                                    "icon": {
                                        "image": "<?php echo $this->rootImage; ?>"
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
                    .bind("create.jstree", function (e, data) {


                        jQuery.post(
                                ajaxurl + "?component=shop&con=shoptree",
                                {
                                    "action": "ajax-call-admin",
                                    "operation": "create_node",
                                    "category_parent_id": data.rslt.parent.attr("id").replace("node_", ""),
                                    "position": data.rslt.position,
                                    "title": data.rslt.name,
                                    "type": data.rslt.obj.attr("rel")
                                },
                        function (r) {

                            if (r.status) {

                                jQuery(data.rslt.obj).attr("id", "node_" + r.id);
                            }
                            else {

                                if (typeof r['msg'] != 'undefined') {
                                    alert(r.msg);
                                }
                                jQuery.jstree.rollback(data.rlbk);
                            }
                        }
                        );
                    })
                    .bind("remove.jstree", function (e, data) {
                        data.rslt.obj.each(function () {
                            jQuery.ajax({
                                async: false,
                                type: 'POST',
                                url: ajaxurl + "?component=shop&con=shoptree",
                                data: {
                                    "action": "ajax-call-admin",
                                    "operation": "remove_node",
                                    "category_child_id": this.id.replace("node_", "")
                                },
                                success: function (r) {




                                    //  $('#tree').jstree('refresh', $('#node_' + id)); 
                                    //  alert(data.rslt.parent.attr("id").replace("node_",""));
                                    var p = data.rslt.parent;
                                    if (p != -1) {
                                        var id = data.rslt.parent.attr("id").replace("node_", "");
                                        jQuery('#tree').jstree('refresh', jQuery('#node_' + id));
                                        jQuery('#node_' + id).removeClass("jstree-closed").removeClass('jstree-leaf').addClass("jstree-open");
                                    } else {
                                        data.inst.refresh();
                                    }
                                }
                            });
                        });
                    })
                    .bind("rename.jstree", function (e, data) {
                        jQuery.post(
                                ajaxurl + "?component=shop&con=shoptree",
                                {
                                    "action": "ajax-call-admin",
                                    "operation": "rename_node",
                                    "category_child_id": data.rslt.obj.attr("id").replace("node_", ""),
                                    "title": data.rslt.new_name
                                },
                        function (r) {
                            if (!r.status) {
                                jQuery.jstree.rollback(data.rlbk);
                            }
                        }
                        );
                    })
                    .bind("move_node.jstree", function (e, data) {
                        data.rslt.o.each(function (i) {
                            jQuery.ajax({
                                async: false,
                                type: 'POST',
                                url: ajaxurl + "?component=shop&con=shoptree",
                                data: {
                                    "action": "ajax-call-admin",
                                    "operation": "move_node",
                                    "id": jQuery(this).attr("id").replace("node_", ""),
                                    "ref": data.rslt.np.attr("id").replace("node_", ""),
                                    "position": data.rslt.cp + i,
                                    "title": data.rslt.name,
                                    "copy": data.rslt.cy ? 1 : 0
                                },
                                success: function (r) {
                                    if (!r.status) {
                                        jQuery.jstree.rollback(data.rlbk);
                                    }
                                    else {
                                        jQuery(data.rslt.oc).attr("id", "node_" + r.id);
                                        if (data.rslt.cy && jQuery(data.rslt.oc).children("UL").length) {
                                            data.inst.refresh(data.inst._get_parent(data.rslt.oc));
                                        }
                                    }
                                    jQuery("#analyze").click();
                                }
                            });
                        });
                    })
                    .bind("rename.jstree", function (e, data) {
                        shopTreeHelper.loadEdit(data.rslt.obj.attr('id').replace("node_", ""));
                    });
        });</script>
    <script type="text/javascript">
        jQuery(function () {





            jQuery("#mmenu button").click(function () {
                switch (this.id) {
                    case "add_default":
                    case "add_folder":
                        jQuery("#tree").jstree("create", null, "last", {"attr": {"rel": this.id.toString().replace("add_", "")}});
                        break;
                    case "search":
                        jQuery("#tree").jstree("search", document.getElementById("text").value);
                        break;
                    case "text":
                        break;
                    default:
                        jQuery("#tree").jstree(this.id);
                        break;
                }
            });
        });
    </script>
</div>