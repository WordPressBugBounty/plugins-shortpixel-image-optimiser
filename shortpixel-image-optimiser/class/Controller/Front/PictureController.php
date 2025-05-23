<?php
namespace ShortPixel\Controller\Front;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;
use ShortPixel\Helper\UtilHelper as UtilHelper;
use ShortPixel\Model\FrontImage as FrontImage;

use ShortPixel\ShortPixelImgToPictureWebp as ShortPixelImgToPictureWebp;

/** Handle everything that SP is doing front-wise */
class PictureController extends \ShortPixel\Controller\Front\PageConverter
{
  // DeliverWebp option settings for front-end delivery of webp
  const WEBP_GLOBAL = 1;
  const WEBP_WP = 2;
  const WEBP_NOCHANGE = 3;

  public function __construct()
  {
			add_action('init', [$this, 'initWebpHooks']);
//			$this->initWebpHooks();
  }

	public function initWebpHooks()
  {
    $webp_option = \wpSPIO()->settings()->deliverWebp;
    if (false === $this->shouldConvert())
    {
       return false;
    }


		if ($webp_option ) {  // @tood Replace this function with the one in ENV.
        if(UtilHelper::shortPixelIsPluginActive('shortpixel-adaptive-images/short-pixel-ai.php')) {
            Notices::addWarning(__('Please deactivate the ShortPixel Image Optimizer\'s
                <a href="options-general.php?page=wp-shortpixel-settings&part=webp">Serve WebP/AVIF images from locally hosted files (without using a CDN)</a>
                option when the ShortPixel Adaptive Images plugin is active.','shortpixel-image-optimiser'), true);
        }
        elseif( $webp_option == self::WEBP_GLOBAL ){
            //add_action( 'wp_head', array($this, 'addPictureJs') ); // adds polyfill JS to the header || Removed. Browsers without picture support?
					 // add_action( 'init',  array($this, 'startOutputBuffer'), 1 ); // start output buffer to capture content
						$this->startOutputBuffer('convertImgToPictureAddWebp');

        } else {

            add_filter( 'the_content', array($this, 'convertImgToPictureAddWebp'), 10000 ); // priority big, so it will be executed last
            add_filter( 'the_excerpt', array($this, 'convertImgToPictureAddWebp'), 10000 );
            add_filter( 'post_thumbnail_html', array($this,'convertImgToPictureAddWebp') );
        }
    }
  }


  /* Picture generation, hooked on the_content filter
  * @param $content String The content to check and convert
  * @return String Converted content
  */
  public function convertImgToPictureAddWebp($content) {

			if (false === $this->checkPreProcess())
			{
				 return $content;
			}
      if(function_exists('amp_is_request') && amp_is_request()) {
          //for AMP pages the <picture> tag is not allowed
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
          return $content . (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!-- SPDBG is AMP -->' : '');
      }

      $content = $this->convert($content);
      return $content;
  }



  protected function convert($content)
  {
      // Don't do anything with the RSS feed.
      if (is_feed() || is_admin()) {
          //Log::addInfo('SPDBG convert is_feed or is_admin');
          return $content; // . (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!--  -->' : '');
      }

      $new_content = $this->testPictures($content);

      if ($new_content !== false)
      {
        $content = $new_content;
      }
      else
      {
        Log::addDebug('Test Pictures returned empty.');
      }

      if (! class_exists('DOMDocument'))
      {
        Log::addWarn('Webp Active, but DomDocument class not found ( missing xmldom library )');
        return $content;
      }


      $pattern = '/<img[^>]*>/i';
      $content = preg_replace_callback($pattern, array($this, 'convertImage'), $content);

      // [BS] No callback because we need preg_match_all
      $content = $this->testInlineStyle($content);

      return $content;
  }


  /* Find image tags within picture definitions and make sure they are converted only by block, */
  private function testPictures($content)
  {
    // [BS] Escape when DOM Module not installed
    //if (! class_exists('DOMDocument'))
    //  return false;
  //$pattern =''
  //$pattern ='/(?<=(<picture>))(.*)(?=(<\/picture>))/mi';
  $pattern = '/<picture.*?>.*?(<img.*?>).*?<\/picture>/is';
  $count = preg_match_all($pattern, $content, $matches);

  if ($matches === false)
    return false;

  if ( is_array($matches) && count($matches) > 0)
  {
    foreach($matches[1] as $match)
    {
         $imgtag = $match;

         if (strpos($imgtag, 'class=') !== false) // test for class, if there, insert ours in there.
         {
          $pos = strpos($imgtag, 'class=');
          $pos = $pos + 7;

          $newimg = substr($imgtag, 0, $pos) . 'sp-no-webp ' . substr($imgtag, $pos);

         }
         else {
            $pos = 4;
            $newimg = substr($imgtag, 0, $pos) . ' class="sp-no-webp" ' . substr($imgtag, $pos);
         }

         $content = str_replace($imgtag, $newimg, $content);

    }
  }

  return $content;
  }

  /* This might be a future solution for regex callbacks.
  public static function processImageNode($node, $type)
  {
    $srcsets = $node->getElementsByTagName('srcset');
    $srcs = $node->getElementsByTagName('src');
    $imgs = $node->getElementsByTagName('img');
  } */

  /** Callback function with received an <img> tag match
  * @param $match Image declaration block
  * @return String Replacement image declaration block
  */
  protected function convertImage($match)
  {
      $fs = \wpSPIO()->filesystem();

      $raw_image = $match[0];

      // Raw Image HTML
      $image = new FrontImage($raw_image);

      if (false === $image->isParseable())
      {
         return $raw_image;
      }

      $srcsetWebP = array();
      $srcsetAvif = array();
      // Count real instances of either of them, without fillers.
      $webpCount = $avifCount = 0;

      $imagePaths = array();

      $definitions = $image->getImageData();
      $imageBase = $image->getImageBase();

      foreach ($definitions as $definition) {

              // Split the URL from the size definition ( eg 800w )
              $parts = preg_split('/\s+/', trim($definition));
              $image_url = $parts[0];

              // The space if not set is required, otherwise it will not work.
              $image_condition = isset($parts[1]) ? ' ' . $parts[1] : ' ';

              // A source that starts with data:, will not need processing.
              if (strpos($image_url, 'data:') === 0)
              {
                continue;
              }

              $fsFile = $fs->getFile($image_url);
              $extension = $fsFile->getExtension(); // trigger setFileinfo, which will resolve URL -> Path
              $mime = $fsFile->getMime();

              // Can happen when file is virtual, or other cases. Just assume this type.
              if ($mime === false)
              {
                 $mime = 'image/' .  $extension;
              }

              $fileWebp = $fs->getFile($imageBase . $fsFile->getFileBase() . '.webp');
              $fileWebpCompat = $fs->getFile($imageBase . $fsFile->getFileName() . '.webp');

              // The URL of the image without the filename
              $image_url_base = str_replace($fsFile->getFileName(), '', $image_url);

              $files = array($fileWebp, $fileWebpCompat);

              $fileAvif = $fs->getFile($imageBase . $fsFile->getFileBase() . '.avif');

              $lastwebp = false;

              foreach($files as $index => $thisfile)
              {
                if (! $thisfile->exists())
                {
                  // FILTER: boolean, object, string, filedir

								 // Return fileObj if you want to live.
                  $thisfile = $fileWebp_exists = apply_filters('shortpixel/front/webp_notfound', false, $thisfile, $image_url, $imageBase);
                }

                if ($thisfile !== false)
                {
                    // base url + found filename + optional condition ( in case of sourceset, as in 1400w or similar)
                    $webpCount++;
                     $lastwebp = $image_url_base . $thisfile->getFileName() . $image_condition;
                     $srcsetWebP[] = $lastwebp;
                     break;
                }
                elseif ($index+1 !== count($files)) // Don't write the else on the first file, because then the srcset will be written twice ( if file exists on the first fails)
                {
                  continue;
                }
                else {
                    $lastwebp = $definition;
                    $srcsetWebP[] = $lastwebp;
                }
              }

              if (false === $fileAvif->exists())
              {
                $fileAvif = apply_filters('shortpixel/front/webp_notfound', false, $fileAvif, $image_url, $imageBase);
              }

              if ($fileAvif !== false)
              {
                 $srcsetAvif[] = $image_url_base . $fileAvif->getFileName() . $image_condition;
                 $avifCount++;
              }
              else { //fallback to jpg
                if (false !== $lastwebp) // fallback to webp if there is a variant in this run. or jpg if none
                {
                   $srcsetAvif[] = $lastwebp;
                }
                else {
                  $srcsetAvif[] = $definition;
                }
              }
      }

      if ($webpCount == 0 && $avifCount == 0) {
          return $raw_image;
      }

      $args = array();

      if ($webpCount > 0)
        $args['webp'] = $srcsetWebP;

      if ($avifCount > 0)
        $args['avif']  = $srcsetAvif;

      $output = $image->parseReplacement($args);

      return $output;

  }

  protected function testInlineStyle($content)
  {
    //preg_match_all('/background.*[^:](url\(.*\))[;]/isU', $content, $matches);
    // Pattern : Find the URL() from CSS, save any extra background information ( position, repeat etc ) in second group and terminal at ; or " (hopefully end of line)
    preg_match_all('/url\(.*\)(.*)(?:;|\"|\')/isU', $content, $matches);

    if (count($matches) == 0)
      return $content;

    $content = $this->convertInlineStyle($matches, $content);
    return $content;
  }


  /** Function to convert inline CSS backgrounds to webp
  * @param $match Regex match for inline style
  * @return String Replaced (or not) content for webp.
  * @author Bas Schuiling
  */
  protected function convertInlineStyle($matches, $content)
  {

    $fs = \wpSPIO()->filesystem();
    $allowed_exts = array('jpg', 'jpeg', 'gif', 'png');
    $converted = array();

    for($i = 0; $i < count($matches[0]); $i++)
    {
      $item = $matches[0][$i];

      $image_data = '';
      if (isset($matches[1][$i]) && strlen(trim($matches[1][$i])) > 0)
      {
          $image_data = trim($matches[1][$i]);
      }

      preg_match('/url\(\'(.*)\'\)/imU', $item, $match);
      if (! isset($match[1]))
      {
        continue;
      }
      $url = $match[1];
      $filename = basename($url);
      $ext = pathinfo($url, PATHINFO_EXTENSION);

      if (! in_array($ext, $allowed_exts))
        continue;

      $image_base_url = str_replace($filename, '', $url);
      $fsFile = $fs->getFile($url);
      $dir = $fsFile->getFileDir();
      $imageBase = is_object($dir) ? $dir->getPath() : false;

      if (false === $imageBase) // returns false if URL is external, do nothing with that.
        continue;

      $checkedFile = false;
      $fileWebp = $fs->getFile($imageBase . $fsFile->getFileBase() . '.webp');
      $fileWebpCompat = $fs->getFile($imageBase . $fsFile->getFileName() . '.webp');

      if (true === $fileWebp->exists())
      {
        $checkedFile = $image_base_url . $fsFile->getFileBase()  . '.webp';
      }
      elseif (true === $fileWebpCompat->exists())
      {
        $checkedFile = $image_base_url . $fsFile->getFileName() . '.webp';
      }
      else
      {
        $fileWebp_exists = apply_filters('shortpixel/front/webp_notfound', false, $fileWebp, $url, $imageBase);
        if (false !== $fileWebp_exists)
        {
           $checkedFile = $image_base_url . $fsFile->getFileBase()  . '.webp';
        }
        else {
          $fileWebp_exists = apply_filters('shortpixel/front/webp_notfound', false, $fileWebpCompat, $url, $imageBase);
          if (false !== $fileWebp_exists)
          {
             $checkedFile = $image_base_url . $fsFile->getFileName()  . '.webp';
          }
        }
      }

      if ($checkedFile)
      {
          // if webp, then add another URL() def after the targeted one.  (str_replace old full URL def, with new one on main match?
          $target_urldef = $matches[0][$i];
          
          // The target_urldef should remain original to be picked up by str_replace, but the original_definitions are what goes back of the original stuff and should be filtered. 
         // $original_definitions = $this->filterForbiddenInline($target_urldef);
          
          if (! isset($converted[$target_urldef])) // if the same image is on multiple elements, this replace might go double. prevent.
          {
            $converted[] = $target_urldef;
            // Fix: The originals are not being put anymore because this would lead to double images and that's not a good thing.
            $new_urldef = "url('" . $checkedFile . "') $image_data ";
            $content = str_replace($target_urldef, $new_urldef, $content);
          }
      }

    }

    return $content;
  }


  /**
   * FilterForbiddenInline
   * 
   * Filter tags like !important for the targetstring that should not be duplicated or causes issues otherwise. 
   *
   * @param [String] $targetString
   * @return String
   */
  protected function filterForbiddenInline($targetString)
  {
        $search = ['!important'];
        $targetString = str_replace($search, '', $targetString); 
        
        return $targetString;
  }

} // class
