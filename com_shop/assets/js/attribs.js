var total_atts = parseInt(jQuery("#total_table").val()) | 0;
var total_props = parseInt(jQuery("#total_g").val()) | 0;

function new_attribute() {

    var ord = jQuery('div#attributes div.attributeContainer').size();
    var div = document.createElement('div');
    div.setAttribute('class', 'attributeContainer');
    div.setAttribute('id', 'att-' + total_atts);

    var html =
            "<div class='boxHeader'>\
				<input type='hidden' name='attribute_id[" + total_atts + "][id]' value='0' />\
				<strong>Title</strong>\
					<input type='text' value='' name='title[" + total_atts + "][name]' />\
					<input type='hidden' class='aordering' name='title[" + total_atts + "][ordering]' size='3' value='" + ord + "' >\
					<button class='btn btn-small' onclick=\"addproperty(jQuery('.attributeProps',this.parentNode.parentNode)[0]," + total_atts + "); return false;\"> Enter sub attribute</button>\
					<button class='btn btn-danger btn-small' onclick=\"jQuery(this).closest('.attributeContainer').remove(); return false\">\
					<span class='icon-trash'></span>\
					</button>\
					<div title='Click to change' class='handlediv'><br></div>\
					</div>\
				<div class='boxBody'>\
				<div>\
					Required Attribute:<input type='checkbox' value='1' name='title[" + total_atts + "][required]' />\
					&nbsp;&nbsp;<span>Hide Attribute Price</span><input type='checkbox' name='title[" + total_atts + "][hide_attribute_price]' />\
					</div>\
				<div class='attributeProps'>\
				</div>\
			</div>";

    div.innerHTML = html;
    total_atts++;
    document.getElementById('attributes').appendChild(div);
    jQuery("div#attributes").sortable({
        "axis": "y",
        "items": "div.attributeContainer",
        "containment": "parent",
        "cursor": "move",
        "cancel": ':input,button,a'
    });

}

function addproperty(parent, att) {

    var ord = jQuery('.subPropertyContainer', parent).size();
    var div = document.createElement('div');
    div.setAttribute('class', 'subPropertyContainer');
    var html =
            "<div class='boxHeader'>\
				<input type='hidden' name='property_id[" + att + "][value][]' value='0' />\
				<strong>Title:</strong><input type='text' size='10' name='property[" + att + "][value][]' />\
				<input type='hidden' class='pordering' name='propordering[" + att + "][value][]' size='3' value='" + ord + "' />\
				<button class='btn btn-danger btn-small' onclick='jQuery(this).closest(\".subPropertyContainer\").remove(); return false;'>\
				<span class='icon-trash'></span>\
				</button>\
				<div title='Click to change' class='handlediv'><br></div>\
				</div>\
				<div class='boxBody'>\
				Price:\
				<input type='text' size='2' name='oprand[" + att + "][value][]' value='+' maxlength='1' onchange='oprand_check(this);' />\
				<input type='text' size='2' name='att_price[" + att + "][value][]'  />\
				</div>";
    div.innerHTML = html;
    parent.appendChild(div);

    total_props++;
    jQuery(parent).sortable({
        "axis": "y",
        "items": "div.subPropertyContainer",
        "containment": "parent",
        "cursor": "move",
        "cancel": ':input,button,a'
    });

    //jQuery("div.attributeProps").sortable('refresh');

}

function oprand_check(s) {
    var oprand = s.value;
    if (oprand != '+' && oprand != '-') {
        alert("WRONG OPRAND");

        s.value = "+";
    }
}

jQuery(function () {
    wireReorderList();
});

function wireReorderList() {

    jQuery("div#attributes").sortable({
        "axis": "y",
        "items": "div.attributeContainer",
        "containment": "parent",
        "cursor": "move",
        "cancel": ':input,button,a'
    });

    jQuery("div.attributeProps").sortable({
        "axis": "y",
        "items": "div.subPropertyContainer",
        "containment": "parent",
        "cursor": "move",
        "cancel": ':input,button,a'
    });

    jQuery("div#attributes div.boxHeader div.handlediv").live("click", function (event) {

        jQuery(">div.boxBody", event.target.parentNode.parentNode).toggle("boxClosed");

    });

    jQuery("div#attributes").live("sortstop", function (event, ui) {

        var dom = ui.item.parent()[0];

        if (dom.id == "attributes")
            selector = ".aordering";
        else if (jQuery(dom).hasClass("attributeProps"))
            selector = ".pordering";

        jQuery(selector, dom).each(function (i, el) {
            jQuery(el).val(i);
        });

    });

}
