
jQuery(function () {

    // Uploading files
    var file_path_field;

    window.send_to_editor_default = window.send_to_editor;

    jQuery(document).on("click", ".upload_file_button", function () {

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

});