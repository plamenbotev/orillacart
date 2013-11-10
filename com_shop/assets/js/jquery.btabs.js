(function($){
	
$.fn.btabs=function(){


 return $(this).each(function() {

 
	var closeAllBut = function(but){
 
 
	$(dlref).children('dt').each(function(i){

		if(i != but){
			
			$(this).removeClass('open').addClass('closed');
		$(divs[i]).css('display','none');
	
		}
		else{
 
			$(this).removeClass('closed').addClass('open');
$(divs[i]).css('display','block');
			}
 
		});
 
 
 
	}
 
	
	var dlref = this;
	var divs = new Array();
 
	$(this).children('dd').each(function(i){

		
		divs[i] = document.createElement('div');
		divs[i].innerHTML = $(this).html();
		$(divs[i]).css('display','none');
		$(divs[i]).addClass('current');
		$(dlref).after(divs[i]);
		$(this).remove();
 
 
	});

	closeAllBut(0);

  
	$(this).children('dt').each(function(i){

	$(this).click(function(){  closeAllBut(i);  } );
 
	});
	
 });
 
 }
 })(jQuery);
 