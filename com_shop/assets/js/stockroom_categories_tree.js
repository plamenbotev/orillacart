jQuery(function() {



    jQuery("#tree")


            .bind("open_node.jstree", function(e, data) {



        jQuery("ul  li[rel='default']:not(:has('input'))", data.rslt.obj).each(function(i) {

            var box = "<input type='checkbox' name='filter[cats][]' ";

            if (jQuery(this).hasClass('checked'))
                box += " checked='checked' ";

            box += " value='" + jQuery(this).attr('id').replace('node_', '') + "' onclick='' />";
            //alert(box);
            this.innerHTML += box;

        });





    }
    )




            .bind("loaded.jstree", function(event, data) {



    }
    )
            .jstree({
        "plugins": ["themes", "json_data", "types", "cookies"],
        "json_data": {
            "ajax": {
                "url": ajaxurl + "?component=shop&con=shoptree",
                "data": function(n) {


                    return {
                        "action": "framework-ajax-admin",
                        "operation": "get_children",
                        "category_child_id": n.attr ? n.attr("id").replace("node_", "") : -1,
                    };
                }
            }
        },
        "search": {
            "ajax": {
                "url": ajaxurl + "?component=shop&con=shoptree",
                "data": function(str) {
                    return {
                        "action": "framework-ajax-admin",
                        "operation": "search",
                        "search_str": str
                    };
                }
            }
        },
        "types": {
            "max_depth": -2,
            "max_children": -2,
            "valid_children": ["tree"],
            "types": {
                "default": {
                    "valid_children": ["default"],
                    "icon": {
                        "image": shop_tree_folderImage
                    }
                },
                "tree": {
                    "valid_children": ["default"],
                    "icon": {
                        "image": shop_tree_root_image
                    },
                    "start_drag": false,
                    "move_node": false,
                    "delete_node": false,
                    "remove": false
                }
            }
        },
        "ui": {
        },
        "core": {
            "animation": 280

        }
    })







});