/* Thumbnail picker for the product category add/edit forms. */
jQuery(function ($) {
    var frame = null;

    function reset(box) {
        box.find('.shop-cat-thumbnail-id').val('');
        box.find('.shop-cat-thumbnail-img').attr('src', '').hide();
    }

    $(document).on('click', '.shop-cat-thumbnail-set', function (e) {
        e.preventDefault();
        var box = $(this).closest('.shop-cat-thumbnail');

        frame = wp.media({
            title: $(this).text(),
            library: {type: 'image'},
            multiple: false
        });
        frame.on('select', function () {
            var image = frame.state().get('selection').first().toJSON();
            var src = image.sizes && image.sizes.thumbnail ? image.sizes.thumbnail.url : image.url;
            box.find('.shop-cat-thumbnail-id').val(image.id);
            box.find('.shop-cat-thumbnail-img').attr('src', src).show();
        });
        frame.open();
    });

    $(document).on('click', '.shop-cat-thumbnail-remove', function (e) {
        e.preventDefault();
        reset($(this).closest('.shop-cat-thumbnail'));
    });

    // the add form is submitted by ajax and kept on screen, so clear the picker after a term is added
    $(document).ajaxComplete(function (event, xhr, settings) {
        if (settings.data && typeof settings.data === 'string' && settings.data.indexOf('action=add-tag') !== -1) {
            $('#addtag .shop-cat-thumbnail').each(function () {
                reset($(this));
            });
        }
    });
});
