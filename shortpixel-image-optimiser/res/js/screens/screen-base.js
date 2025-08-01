'use strict';

class ShortPixelScreenBase
{
	isCustom = true;
	isMedia = true;
	processor;
	strings = [];
	settings;

	// ImageModel Constants
	imageConstants = {
		 COMPRESSION_LOSSLESS: 0,
		 COMPRESSION_LOSSY: 1,
		 COMPRESSION_GLOSSY: 2,
		 ACTION_SMARTCROP: 100,
		 ACTION_SMARTCROPLESS: 101,
	};


	constructor(MainScreen, processor)
	{
		 this.processor = processor;
		 this.strings = spio_screenStrings;
	}

	// Function for subclasses to add more init. Seperated because of screens that need to call Process functions when starting.
	Init()
	{
	}

//	var message = {status: false, http_status: response.status, http_text: text, status_text: response.statusText };
	HandleError(data)
	{
		if (this.processor.debugIsActive == 'false')
			return; // stay silent when debug is not active.

		var text = String(data.http_text);
		var title = this.strings.fatalError;
		var notice = this.GetErrorNotice(title, text);

 		var el = this.GetErrorPosition();
		if (el === null)
		{
				console.error('Cannot display error - no position found!');
				return;
		}
		el.prepend(notice);
	}

	// No actions at the base.
	HandleItemError(result)
	{

	}

	// Add notices that are coming for ajax responses to the place required.
	AppendNotices(notices,element)
	{
			if (null === element)
			{
						console.error('Element is null, cannot display notices');
						return;
			}

			for (let i = 0; i < notices.length; i++)
			{
				let notice = notices[i];

				// Parse Dom
				const parser = new DOMParser();
				var dom = parser.parseFromString(notice, 'text/html');
				var noticeDom = dom.body.firstChild;
				if (noticeDom.classList.contains('is-dismissible'))
				{
					 //  Add close Event
					let button = noticeDom.querySelector('button.notice-dismiss'); 
					button.addEventListener('click', this.EventCloseErrorNotice.bind(this)); // Might be renamed

				}
			//	element.insertAdjacentHTML('afterend', notices[i]);
			element.after(noticeDom); // Add to top
			}
	}

	HandleErrorStop()
	{
		if (this.processor.debugIsActive == 'false')
			return; // stay silent when debug is not active.

		  var title = this.strings.fatalErrorStop;
			var text = this.strings.fatalErrorStopText;

			var notice = this.GetErrorNotice(title, text);
			var el = this.GetErrorPosition();
			if (el === null)
			{
				console.error('Cannot display error - no position found!');
				return;
			}
		el.prepend(notice);
	}


	GetErrorNotice(title, text)
	{
		  var notice = document.createElement('div');

			var button  = document.createElement('button'); // '<button type="button" class="notice-dismiss"></button>';
			button.classList.add('notice-dismiss');
			button.type = 'button';
			button.addEventListener('click', this.EventCloseErrorNotice);

			notice.classList.add('notice', 'notice-error', 'is-dismissible');

			notice.innerHTML += '<p><strong>' + title + '</strong></p>';
			notice.innerHTML += '<div class="error-details">' + text + '</div>';

			notice.append(button);

			return notice;
	}

	EventCloseErrorNotice(event)
	{
			event.target.parentElement.remove();
	}

	// Search for where to insert the notice before ( ala WP system )
	GetErrorPosition()
	{
		var el = document.querySelector('.is-shortpixel-settings-page');
		if (el !== null) // we are on settings page .
		{
			 return el;
		}

		var el = document.querySelector('.wrap');
		if (el !== null)
			return el;


		var el = document.querySelector('#wpbody-content');
		if (el !== null)
			return el;


		return null;
	}

	HandleImage(result, type)
	{
			return true;
	}

	UpdateStats()
	{

	}


	RenderItemView(e)
	{

	}

	// @todo Find a better home for this. Global screen class?
	ParseNumber(str)
	{
		 str = str.replace(',','', str).replace('.','',str);
		 return parseInt(str);
	}


	// ** FADE OUT FUNCTION **
  FadeOut(el) {
			el.style.opacity = 0;
			el.style.display = 'none';

	};

	// ** FADE IN FUNCTION **
	 FadeIn(el, display) {
			el.style.opacity = 1;
			el.style.display = "block";

	};

	Show(el)
	{
		 el.style.display = 'block';
	}

	Hide(el)
	{
		el.style.display = 'none';
	}



} // class
