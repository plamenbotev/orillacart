jQuery(document).ready(function ($) {

	jQuery("#billing_country,#shipping_country,select#billing_state,select#shipping_state").chosen();

	if ($('#shiptobilling input').is(':checked') || $('#shiptobilling input').size() == 0) {
		$(".shipping_field.required").removeClass("required").addClass("not_required");
	}

	$("form.login").validate();

	$("form[name=checkout]").validate({
		rules : {
			payment_method : "required"

		},
		errorPlacement : function (error, element) {},

		highlight : function (el, eclass, vclass) {
			if ($(el).attr("id") == "shipping_method") {
				$(el).closest("tr").addClass("has-error");
			} else {
				$(el).closest(".form-group").addClass("has-error");
			}
		},

		unhighlight : function (el, eclass, vclass) {
			if ($(el).attr("id") == "shipping_method") {
				$(el).closest("tr").removeClass("has-error");
			} else {
				$(el).closest(".form-group").removeClass("has-error");
			}
		}

	});

	$("select#billing_country,select#shipping_country").live('change', function () {

		var obj = this.id.substring(0, this.id.indexOf('_country'));

		$.ajax({
			type : "get",
			url : shop_helper.ajaxurl + "?action=framework-ajax-front&component=shop&con=cart&task=load_states&country=" + this.value + "&type=" + obj,
			success : function (data, text) {

				document.getElementById(obj + '_states_container').innerHTML = data;
				if (jQuery("select#" + obj + "_state", document.getElementById(obj + '_states_container')).is('select')) {
					jQuery("select#" + obj + "_state", document.getElementById(obj + '_states_container')).chosen().trigger("liszt:updated");
				}
			},

			error : function (request, status, error) {
				throw(request.responseText);

			}

		});

	});

	var updateTimer;

	function update_checkout() {

		var method = $('#shipping_method').val();

		var country = $('#billing_country').val();
		var state = $('#billing_state').val();
		var postcode = $('input#billing_zipcode').val();

		if ($('#shiptobilling input').is(':checked') || $('#shiptobilling input').size() == 0) {
			var s_country = $('#billing_country').val();
			var s_state = $('#billing_state').val();
			var s_postcode = $('input#billing_zipcode').val();
			$("#ship_to_billing").val('yes');
			$(".shipping_field.required").removeClass("required").addClass("not_required");

		} else {
			var s_country = $('#shipping_country').val();
			var s_state = $('#shipping_state').val();
			var s_postcode = $('input#shipping_zipcode').val();
			$("#ship_to_billing").val('no');
			$(".shipping_field.not_required").removeClass("not_required").addClass("required");
		}

		$('#order_methods, #order_review').block({
			message : null,

			overlayCSS : {
				background : '#fff ',
				opacity : 0.6
			}

		});

		var data = {
			action : 'framework-ajax-front',
			component : 'shop',
			con : 'cart',
			task : 'checkout',
			update_totals : 1,
			shipping_method : method,
			country : country,
			state : state,
			postcode : postcode,
			s_country : s_country,
			s_state : s_state,
			s_postcode : s_postcode,

			post_data : $('form.checkout').serialize()
		};

		$.post(shop_helper.ajaxurl, data, function (response) {

			$('#order_methods, #order_review').remove();
			$('#order_review_heading').after(response);

			$('#order_review input[name=payment_method]:checked').click();

		});

	}

	$(function () {

		$('p.password').hide();

		$('input.show_password').change(function () {
			$('p.password').slideToggle(0);
		});

		$('div#col-2').hide();
		// $('.col-1').css('width','100%');
		$('#shiptobilling input').change(function () {
			$('div#col-2').hide();
			$("#billing_fields").removeClass("col-md-6").addClass("col-md-12");
			$('.col-1').css('width', '100%');
			if (!$(this).is(':checked')) {
				$('div#col-2').slideDown();
				$("#billing_fields").removeClass("col-md-12").addClass("col-md-6");
			}
		}).change();

		if ($('input#createaccount')) {

			$('div#register').hide();
			$("#account_username.required").removeClass("required").addClass("not_required");
			$("#account_password.required").removeClass("required").addClass("not_required");
			$("#account_password-2.required").removeClass("required").addClass("not_required");

			$('input#createaccount').change(function () {
				$('div#register').hide();
				$("#account_username.required").removeClass("required").addClass("not_required");
				$("#account_password.required").removeClass("required").addClass("not_required");
				$("#account_password-2.required").removeClass("required").addClass("not_required");
				if ($(this).is(':checked')) {
					$('div#register').slideDown();
					$("#account_username.not_required").removeClass("not_required").addClass("required");
					$("#account_password.not_required").removeClass("not_required").addClass("required");
					$("#account_password-2.not_required").removeClass("not_required").addClass("srequired");
				}
			}).change();

		}

		$('.payment_methods input.input-radio').live('click', function () {
			$('div.payment_box').hide();
			if ($(this).is(':checked')) {
				$('div.payment_box.' + $(this).attr('ID')).slideDown();
			}
		});

		$('#order_review input[name=payment_method]:checked').click();

		$('form.login').hide();

		$('a.showlogin').click(function () {
			$('form.login').slideToggle(0);
			return false;
		});

		/* Update totals */
		$('#shipping_method').live('change', function () {
			clearTimeout(updateTimer);
			update_checkout();
		}).change();
		$('input#billing_state, #billing_zipcode, input#shipping_state, #shipping_zipcode').live('keydown', function () {
			clearTimeout(updateTimer);

			updateTimer = setTimeout(function () {
					update_checkout();
				}, '1000');
		});
		$('select#billing_country, select#billing_state, select#shipping_country, select#shipping_state, #shiptobilling input, .update_totals_on_change').live('change', function () {
			clearTimeout(updateTimer);
			update_checkout();
		});

	});

});
