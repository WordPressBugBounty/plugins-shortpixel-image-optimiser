<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;
use ShortPixel\Controller\QueueController as QueueController;
use ShortPixel\Controller\QuotaController as QuotaController;
use ShortPixel\Controller\AjaxController as AjaxController;
use ShortPixel\Controller\AdminController as AdminController;
use ShortPixel\Controller\ImageEditorController as ImageEditorController;
use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\FileSystemController;
use ShortPixel\Controller\OtherMediaController as OtherMediaController;
use ShortPixel\NextGenController as NextGenController;

use ShortPixel\Controller\Queue\MediaLibraryQueue as MediaLibraryQueue;
use ShortPixel\Controller\Queue\CustomQueue as CustomQueue;

use ShortPixel\Helper\InstallHelper as InstallHelper;
use ShortPixel\Helper\UiHelper as UiHelper;

use ShortPixel\Model\AccessModel as AccessModel;
use ShortPixel\Model\SettingsModel as SettingsModel;

/** Plugin class
 * This class is meant for: WP Hooks, init of runtime and Controller Routing.
 */
class ShortPixelPlugin {

	private static $instance;
	protected static $modelsLoaded = array(); // don't require twice, limit amount of require looksups..

	protected $is_noheaders = false;

	protected $plugin_path;
	protected $plugin_url;

	protected $shortPixel; // shortpixel megaclass

	protected $admin_pages = array();  // admin page hooks.

	public function __construct() {
		// $this->initHooks();
		add_action( 'plugins_loaded', [$this, 'lowInit'], 5 ); // early as possible init.
		
	}

	/** LowInit after all Plugins are loaded. Core WP function can still be missing. This should mostly add hooks */
	public function lowInit() {

		$this->plugin_path = plugin_dir_path( SHORTPIXEL_PLUGIN_FILE );
		$this->plugin_url  = plugin_dir_url( SHORTPIXEL_PLUGIN_FILE );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
		if ( isset( $_REQUEST['noheader'] ) ) {
			$this->is_noheaders = true;
		}

		/*
		Filter to prevent SPIO from starting. This can be used by third-parties to prevent init when needed for a particular situation.
		* Hook into plugins_loaded with priority lower than 5 */
		$init = apply_filters( 'shortpixel/plugin/init', true );

		if (false === $init ) {
			return;
		}


		$front        = new Controller\FrontController(); // init front checkers
		$admin        = Controller\AdminController::getInstance();
		$adminNotices = Controller\AdminNoticesController::getInstance(); // Hook in the admin notices.

		$this->initHooks();
		$this->ajaxHooks();

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			WPCliController::getInstance();
		}

		add_action ('init', [$this, 'init']);
		add_action( 'admin_init', [ $this, 'admin_init' ] );
	}

	public function init()
	{
		Controller\CronController::getInstance();  // cron jobs - must be init to function!

	}


	/** Mainline Admin Init. Tasks that can be loaded later should go here */
	public function admin_init() {
			// This runs activation thing. Should be -after- init
			$this->check_plugin_version();


			$notices             = Notices::getInstance(); // This hooks the ajax listener
			$quotaController = QuotaController::getInstance();
			$quotaController->getQuota();

			/* load_plugin_textdomain( 'shortpixel-image-optimiser', false, plugin_basename( dirname( SHORTPIXEL_PLUGIN_FILE ) ) . '/lang' ); */
	}

	/** Function to get plugin settings
     *
     * @return SettingsModel The settings model object.
     */
	public function settings() {
			return SettingsModel::getInstance();
	}

	/** Function to get all enviromental variables
     *
     * @return EnvironmentModel
     */
	public function env() {
		return Model\EnvironmentModel::getInstance();
	}

	/** Get the SPIO FileSystemController
	 * 
	 * @return FileSystemController 
	 */
	public function fileSystem() {
		return new Controller\FileSystemController();
	}

	/** Create instance. This should not be needed to call anywhere else than main plugin file
     * This should not be called *after* plugins_loaded action
     **/
	public static function getInstance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new ShortPixelPlugin();
		}
		return self::$instance;

	}

	/** Hooks for all WordPress related hooks
     * For now hooks in the lowInit, asap.
     */
	public function initHooks() {

		add_action( 'admin_menu', array( $this, 'admin_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) ); // admin scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles' ) ); // admin styles
		add_action( 'admin_enqueue_scripts', array( $this, 'load_admin_scripts' ), 90 ); // loader via route.
		add_action( 'enqueue_block_assets', array($this, 'load_admin_scripts'), 90);
		// defer notices a little to allow other hooks ( notable adminnotices )

		$queueController = new QueueController();
		add_action( 'shortpixel-thumbnails-regenerated', array( $queueController, 'thumbnailsChangedHookLegacy' ), 10, 4 );
		add_action( 'rta/image/thumbnails_regenerated', array( $queueController, 'thumbnailsChangedHook' ), 10, 2 );
		add_action( 'rta/image/thumbnails_removed', array( $queueController, 'thumbnailsChangedHook' ), 10, 2 );
		add_action('rta/image/scaled_image_regenerated', array($queueController, 'scaledImageChangedHook'), 10, 2);


		// Media Library - Actions to route screen
		add_action( 'load-upload.php', array( $this, 'route' ) );
		add_action( 'load-post.php', array( $this, 'route' ) );

		$admin = AdminController::getInstance();
		$imageEditor = ImageEditorController::getInstance();
		$access = AccessModel::getInstance();

		// Handle for EMR
		add_action( 'wp_handle_replace', array( $admin, 'handleReplaceHook' ) );

		// Action / hook for who wants to use CRON. Please refer to manual / support to prevent loss of credits.
		add_action( 'shortpixel/hook/processqueue', array( $admin, 'processQueueHook' ) );
		add_action( 'shortpixel/hook/scancustomfolders', array($admin, 'scanCustomFoldersHook'));

		// Action for media library gallery view
		//add_filter('attachment_fields_to_edit', array($admin, 'editAttachmentScreen'), 10, 2);
		add_action('print_media_templates', array($admin, 'printComparer'));

		// Placeholder function for heic and such, return placeholder URL in image to help w/ database replacements after conversion.
		add_filter('wp_get_attachment_url', array($admin, 'checkPlaceHolder'), 10, 2);

		add_filter('rest_post_dispatch', [$admin, 'checkRestMedia'],10, 3);

		/** When automagically process images when uploaded is on */
		if ( $this->env()->is_autoprocess ) {
			// compat filter to shortcircuit this in cases.  (see external - visualcomposer)
			if ( apply_filters( 'shortpixel/init/automedialibrary', true ) ) {

      			add_action( 'shortpixel-thumbnails-before-regenerate', array( $admin, 'preventImageHook' ), 10, 1 );

						add_action( 'enable-media-replace-upload-done', array( $admin, 'handleReplaceEnqueue' ), 10, 3 );

				add_filter( 'wp_generate_attachment_metadata', array( $admin, 'handleImageUploadHook' ), 5, 2 );
				add_action('add_attachment', array($admin, 'addAttachmentHook'));
				// @integration MediaPress
				add_filter( 'mpp_generate_metadata', array( $admin, 'handleImageUploadHook' ), 10, 2 );
			}
		}

		$isAdminUser = $access->userIsAllowed('is_admin_user');

		$this->env()->setDefaultViewModeList();// set default mode as list. only @ first run

		add_filter( 'plugin_action_links_' . plugin_basename( SHORTPIXEL_PLUGIN_FILE ), array( $admin, 'generatePluginLinks' ) );// for plugin settings page

		// for cleaning up the WebP images when an attachment is deleted . Loading this early because it's possible other plugins delete files in the uploads, but we need those to remove backups.
		add_action( 'delete_attachment', array( $admin, 'onDeleteAttachment' ), 5 );
		add_action( 'mime_types', array( $admin, 'addMimes' ) );

		// integration with WP/LR Sync plugin
		//add_action( 'wplr_update_media', array( AjaxController::getInstance(), 'onWpLrUpdateMedia' ), 10, 2 );
		add_action( 'wplr_sync_media', array( AjaxController::getInstance(), 'onWpLrSyncMedia' ), 10, 2 );

		add_action( 'admin_bar_menu', array( $admin, 'toolbar_shortpixel_processing' ), 999 );

		// Image Editor Actions
		add_filter('load_image_to_edit_path', array($imageEditor, 'getImageForEditor'), 10, 3);
		add_filter('wp_save_image_editor_file', array($imageEditor, 'saveImageFile'), 10, 5);  // hook when saving
	//	add_action('update_post_meta', array($imageEditor, 'checkUpdateMeta'), 10, 4 );

		if ( $isAdminUser ) {
			// toolbar notifications

			// deactivate conflicting plugins if found
			add_action( 'admin_post_shortpixel_deactivate_conflict_plugin', array( '\ShortPixel\Helper\InstallHelper', 'deactivateConflictingPlugin' ) );

			// only if the key is not yet valid or the user hasn't bought any credits.
			// @todo This should not be done here.
			$settings     = $this->settings();
			$stats        = $settings->currentStats;
			$totalCredits = isset( $stats['APICallsQuotaNumeric'] ) ? $stats['APICallsQuotaNumeric'] + $stats['APICallsQuotaOneTimeNumeric'] : 0;
			$keyControl = ApiKeyController::getInstance();


			if ( true || false === $keyControl->keyIsVerified() || $totalCredits < 4000 ) {
				require_once 'class/view/shortpixel-feedback.php';
				new ShortPixelFeedback( SHORTPIXEL_PLUGIN_FILE, 'shortpixel-image-optimiser' );
			}
		}

		if (is_admin())
		{
			  add_filter('pre_get_posts', array($admin, 'filter_listener'));
		}

		if ($this->env()->is_multisite)
		{
			 add_action('network_admin_menu', [$this, 'admin_network_pages']) ;
		}

	}

	protected function ajaxHooks() {

		// Ajax hooks. Should always be prepended with ajax_ and *must* check on nonce in function
		add_action( 'wp_ajax_shortpixel_image_processing', array( AjaxController::getInstance(), 'ajax_processQueue' ) );

		// Custom Media

		//add_action( 'wp_ajax_shortpixel_get_backup_size', array( AjaxController::getInstance(), 'ajax_getBackupFolderSize' ) );

		add_action( 'wp_ajax_shortpixel_propose_upgrade', array( AjaxController::getInstance(), 'ajax_proposeQuotaUpgrade' ) );
		add_action( 'wp_ajax_shortpixel_check_quota', array( AjaxController::getInstance(), 'ajax_checkquota' ) );


		add_action( 'wp_ajax_shortpixel_ajaxRequest', array( AjaxController::getInstance(), 'ajaxRequest' ) );
		add_action( 'wp_ajax_shortpixel_settingsRequest', array( AjaxController::getInstance(), 'settingsRequest'));

	}

	/** Hook in our admin pages */
	public function admin_pages() {
		$admin_pages = array();
		// settings page
		$admin_pages[] = add_options_page( __( 'ShortPixel Settings', 'shortpixel-image-optimiser' ), 'ShortPixel', 'manage_options', 'wp-shortpixel-settings', array( $this, 'route' ) );

		$otherMediaController = OtherMediaController::getInstance();
		if ( $otherMediaController->showMenuItem() ) {
			/*translators: title and menu name for the Other media page*/
			$admin_pages[] = add_media_page( __( 'Custom Media Optimized by ShortPixel', 'shortpixel-image-optimiser' ), __( 'Custom Media', 'shortpixel-image-optimiser' ), 'edit_others_posts', 'wp-short-pixel-custom', array( $this, 'route' ) );
		}
		/*translators: title and menu name for the Bulk Processing page*/
		$admin_pages[] = add_media_page( __( 'ShortPixel Bulk Process', 'shortpixel-image-optimiser' ), __( 'Bulk ShortPixel', 'shortpixel-image-optimiser' ), 'edit_others_posts', 'wp-short-pixel-bulk', array( $this, 'route' ) );

		$this->admin_pages = $admin_pages;
	}

	public function admin_network_pages()
	{
		  	add_menu_page(__('Shortpixel MU', 'shortpixel-image-optimiser'), __('Shortpixel', 'shortpixel_image_optimiser'), 'manage_sites', 'shortpixel-network-settings', [$this, 'route'], $this->plugin_url('res/img/shortpixel.png') );
	}

	/** All scripts should be registed, not enqueued here (unless global wp-admin is needed )
     *
     * Not all those registered must be enqueued however.
     */
	public function admin_scripts( $hook_suffix ) {

		$settings       = \wpSPIO()->settings();
		$ajaxController = AjaxController::getInstance();

		$secretKey = $ajaxController->getProcessorKey();

		$keyControl = \ShortPixel\Controller\ApiKeyController::getInstance();
		$apikey     = $keyControl->getKeyForDisplay();

		$is_bulk_page = \wpSPIO()->env()->is_bulk_page;

		$queueController = new QueueController(['is_bulk' =>  $is_bulk_page ]);
		$quotaController = QuotaController::getInstance();

	 wp_register_script('shortpixel-folderbrowser', plugins_url('/res/js/shortpixel-folderbrowser.js', SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

	 wp_localize_script('shortpixel-folderbrowser', 'spio_folderbrowser', array(
		 		'strings' => array(
						'loading' => __('Loading', 'shortpixel-image-optimiser'),
						'empty_result' => __('No Directories found that can be added to Custom Folders', 'shortpixel-image-optimiser'),
				),
				'icons' => array(
						'folder_closed' => plugins_url('res/img/filebrowser/folder-closed.svg', SHORTPIXEL_PLUGIN_FILE),
						'folder_open' => plugins_url('res/img/filebrowser/folder-closed.svg', SHORTPIXEL_PLUGIN_FILE),
				),
	 ));

		wp_register_script( 'jquery.knob.min.js', plugins_url( '/res/js/jquery.knob.min.js', SHORTPIXEL_PLUGIN_FILE ), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		wp_register_script( 'shortpixel-debug', plugins_url( '/res/js/debug.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'jquery-ui-draggable' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		wp_register_script( 'shortpixel-tooltip', plugins_url( '/res/js/shortpixel-tooltip.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		$tooltip_localize = array(
			'processing' => __('Processing... ','shortpixel-image-optimiser'),
			'pause' =>  __('Click to pause', 'shortpixel-image-optimiser'),
			'resume' => __('Click to resume', 'shortpixel-image-optimiser'),
			'item' => __('item in queue', 'shortpixel-image-optimiser'),
			'items' => __('items in queue', 'shortpixel-image-optimiser'),
		);

		wp_localize_script( 'shortpixel-tooltip', 'spio_tooltipStrings', $tooltip_localize);

		wp_register_script( 'shortpixel-settings', plugins_url( 'res/js/shortpixel-settings.js', SHORTPIXEL_PLUGIN_FILE ), array('shortpixel-shiftselect', 'shortpixel-inline-help'), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		wp_register_script('shortpixel-shiftselect', plugins_url('res/js/shift-select.js', SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true);

		wp_localize_script('shortpixel-settings', 'settings_strings', UiHelper::getSettingsStrings(false));


		wp_register_script( 'shortpixel-onboarding', plugins_url( 'res/js/shortpixel-onboarding.js', SHORTPIXEL_PLUGIN_FILE ), array('shortpixel-settings'), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		wp_register_script('shortpixel-media', plugins_url('res/js/shortpixel-media.js',  SHORTPIXEL_PLUGIN_FILE), array('jquery'), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true);

		wp_register_script('shortpixel-inline-help', plugins_url('res/js/shortpixel-inline-help.js',  SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true);

		// This filter is from ListMediaViewController for the media library grid display, executive script in shortpixel-media.js.

		$filters = array('optimized' => array(
					'all' => __('Any ShortPixel State', 'shortpixel-image-optimiser'),
					'optimized' => __('Optimized', 'shortpixel-image-optimiser'),
					'unoptimized' => __('Unoptimized', 'shortpixel-image-optimiser'),
					'prevented' => __('Optimization Error', 'shortpixer-image-optimiser'),
		));

		$editor_localize = ImageEditorController::localizeScript();
		$editor_localize['mediafilters'] = $filters;
		wp_localize_script('shortpixel-media', 'spio_media', $editor_localize);

		wp_register_script( 'shortpixel-processor', plugins_url( '/res/js/shortpixel-processor.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'shortpixel-tooltip' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		 // How often JS processor asks for next tick on server. Low for fastestness and high loads, high number for surviving servers.
		$interval = apply_filters( 'shortpixel/processor/interval', 3000 );

		// If the queue is empty how often to check if something new appeared from somewhere. Excluding the manual items added by current processor user.
		$deferInterval = apply_filters( 'shortpixel/process/deferInterval', 60000 );

		wp_localize_script(
            'shortpixel-processor',
            'ShortPixelProcessorData',
            array(
				'bulkSecret'        => $secretKey,
				'isBulkPage'        => (bool) $is_bulk_page,
				'screenURL'         => false,
				'workerURL'         => plugins_url( 'res/js/shortpixel-worker.js', SHORTPIXEL_PLUGIN_FILE ),
				'nonce_process'     => wp_create_nonce( 'processing' ),
				'nonce_exit'        => wp_create_nonce( 'exit_process' ),
				'nonce_ajaxrequest' => wp_create_nonce( 'ajax_request' ),
				'nonce_settingsrequest' => wp_create_nonce('settings_request'),
				'startData'         => ( \wpSPIO()->env()->is_screen_to_use ) ? $queueController->getStartupData() : false,
				'interval'          => $interval,
				'deferInterval'     => $deferInterval,
				'debugIsActive' 		=> (\wpSPIO()->env()->is_debug) ? 'true' : 'false',
				'autoMediaLibrary'  => ($settings->autoMediaLibrary) ? 'true' : 'false',
            )
        );

		/*** SCREENS */
		wp_register_script('shortpixel-screen-base', plugins_url( '/res/js/screens/screen-base.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'shortpixel-processor' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		wp_register_script('shortpixel-screen-item-base', plugins_url( '/res/js/screens/screen-item-base.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'shortpixel-processor', 'shortpixel-screen-base'), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		wp_register_script( 'shortpixel-screen-media', plugins_url( '/res/js/screens/screen-media.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'shortpixel-processor', 'shortpixel-screen-base', 'shortpixel-screen-item-base' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		wp_register_script( 'shortpixel-screen-custom', plugins_url( '/res/js/screens/screen-custom.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'shortpixel-processor', 'shortpixel-screen-base', 'shortpixel-screen-item-base' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		wp_register_script( 'shortpixel-screen-nolist', plugins_url( '/res/js/screens/screen-nolist.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'shortpixel-processor', 'shortpixel-screen-base' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

	  $screen_localize = array(  // Item Base
			'startAction' => __('Processing... ','shortpixel-image-optimiser'),
			'startActionAI' => __('Generating Alt Text', 'shortpixel-image-optimiser'),
			'fatalError' => __('ShortPixel encountered a fatal error when optimizing images. Please check the issue below. If this is caused by a bug please contact our support', 'shortpixel-image-optimiser'),
			'fatalErrorStop' => __('ShortPixel has encounted multiple errors and has now stopped processing', 'shortpixel-image-optimiser'),
			'fatalErrorStopText' => __('No items are being processed. To try again after solving the issues, please reload the page ', 'shortpixel-image-optimiser'),
			'fatalError500' => __('A fatal error HTTP 500 has occurred. On the bulk screen, this may be caused by the script running out of memory. Check your error log, increase memory or disable heavy plugins.'),

		);

	 $screen_localize_custom = array( // Custom Screen
			'stopActionMessage' => __('Folder scan has stopped', 'shortpixel-image-optimiser'),
		);

	 $screen_localize_media = [ 
			'hide_ai' => apply_filters('shortpixel/settings/no_ai', false),
			'hide_spio_in_popups' => apply_filters('shortpixel/js/media/hide_in_popups', false), 
	 ];

		wp_localize_script('shortpixel-screen-media', 'spio_mediascreen_settings', $screen_localize_media); 

		wp_localize_script( 'shortpixel-screen-base', 'spio_screenStrings', array_merge($screen_localize, $screen_localize_custom));

		wp_register_script( 'shortpixel-screen-bulk', plugins_url( '/res/js/screens/screen-bulk.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'shortpixel-processor', 'shortpixel-screen-base'), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
		$panel = isset( $_GET['panel'] ) ? sanitize_text_field( wp_unslash($_GET['panel']) ) : false;

		$bulkLocalize = [
			'endBulk'   => __( 'This will stop the bulk processing and take you back to the start. Are you sure you want to do this?', 'shortpixel-image-optimiser' ),
			'reloadURL' => admin_url( 'upload.php?page=wp-short-pixel-bulk'),
		];
		if ( $panel ) {
			$bulkLocalize['panel'] = $panel;
        }

		// screen translations. Can all be loaded on the same var, since only one screen can be active.
		wp_localize_script( 'shortpixel-screen-bulk', 'shortPixelScreen', $bulkLocalize );

		wp_register_script( 'shortpixel', plugins_url( '/res/js/shortpixel.js', SHORTPIXEL_PLUGIN_FILE ), array( 'jquery', 'jquery.knob.min.js' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

		// Using an Array within another Array to protect the primitive values from being cast to strings
		$ShortPixelConstants = array(
			array(
				'WP_PLUGIN_URL'     => plugins_url( '', SHORTPIXEL_PLUGIN_FILE ),
				'WP_ADMIN_URL'      => admin_url(),
				'API_IS_ACTIVE'     => $keyControl->keyIsVerified(),
				'AJAX_URL'          => admin_url( 'admin-ajax.php' ),
				'BULK_SECRET'       => $secretKey,
				'nonce_ajaxrequest' => wp_create_nonce( 'ajax_request' ),
				'HAS_QUOTA'         => ( $quotaController->hasQuota() ) ? 1 : 0,

			),
		);

		if ( Log::isManualDebug() ) {
			Log::addInfo( 'Ajax Manual Debug Mode' );
			$logLevel                           = Log::getLogLevel();
			$ShortPixelConstants[0]['AJAX_URL'] = admin_url( 'admin-ajax.php?SHORTPIXEL_DEBUG=' . $logLevel );
		}

		$jsTranslation = array(
			'optimizeWithSP'              => __( 'ShortPixel', 'shortpixel-image-optimiser' ),
			'optimize'              => __( 'Optimize', 'shortpixel-image-optimiser' ),
			'redoLossy'                   => __( 'Re-optimize Lossy', 'shortpixel-image-optimiser' ),
			'redoGlossy'                  => __( 'Re-optimize Glossy', 'shortpixel-image-optimiser' ),
			'redoLossless'                => __( 'Re-optimize Lossless', 'shortpixel-image-optimiser' ),
			'redoSmartcrop'               => __( 'Re-optimize with SmartCrop', 'shortpixel-image-optimiser'),
			'redoSmartcropless'           => __( 'Re-optimize without SmartCrop', 'shortpixel-image-optimiser'),
			'restoreOriginal'             => __( 'Restore Originals', 'shortpixel-image-optimiser' ),
			'markCompleted' 							=> __('Mark as completed' ,'shortpixel-image-optimiser'),
			'areYouSureStopOptimizing'    => __( 'Are you sure you want to stop optimizing the folder {0}?', 'shortpixel-image-optimiser' ),
			'pleaseDoNotSetLesserSize'    => __( "Please do not set a {0} less than the {1} of the largest thumbnail which is {2}, to be able to still regenerate all your thumbnails in case you'll ever need this.", 'shortpixel-image-optimiser' ),
			'pleaseDoNotSetLesser1024'    => __( "Please do not set a {0} less than 1024, to be able to still regenerate all your thumbnails in case you'll ever need this.", 'shortpixel-image-optimiser' ),
			'confirmBulkRestore'          => __( 'Are you sure you want to restore from backup all the images in your Media Library optimized with ShortPixel?', 'shortpixel-image-optimiser' ),
			'confirmBulkCleanup'          => __( "Are you sure you want to cleanup the ShortPixel metadata info for the images in your Media Library optimized with ShortPixel? This will make ShortPixel 'forget' that it optimized them and will optimize them again if you re-run the Bulk Optimization process.", 'shortpixel-image-optimiser' ),
			'alertDeliverWebPAltered'     => __( "Warning: Using this method alters the structure of the rendered HTML code (IMG tags get included in PICTURE tags), which, in some rare \ncases, can lead to CSS/JS inconsistencies.\n\nPlease test this functionality thoroughly after activating!\n\nIf you notice any issue, just deactivate it and the HTML will will revert to the previous state.", 'shortpixel-image-optimiser' ),
			'alertDeliverWebPUnaltered'   => __( 'This option will serve both WebP and the original image using the same URL, based on the web browser capabilities, please make sure you\'re serving the images from your server and not using a CDN which caches the images.', 'shortpixel-image-optimiser' ),
			'originalImage'               => __( 'Original image', 'shortpixel-image-optimiser' ),
			'optimizedImage'              => __( 'Optimized image', 'shortpixel-image-optimiser' ),
			'loading'                     => __( 'Loading...', 'shortpixel-image-optimiser' ),

		);

		wp_localize_script( 'shortpixel', '_spTr', $jsTranslation );
		wp_localize_script( 'shortpixel', 'ShortPixelConstants', $ShortPixelConstants );

	}

	public function admin_styles() {

		wp_register_style( 'shortpixel-folderbrowser', plugins_url( '/res/css/shortpixel-folderbrowser.css', SHORTPIXEL_PLUGIN_FILE ),[], SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

		//wp_register_style( 'shortpixel', plugins_url( '/res/css/short-pixel.css', SHORTPIXEL_PLUGIN_FILE ), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

		// notices. additional styles for SPIO.
		wp_register_style( 'shortpixel-notices', plugins_url( '/res/css/shortpixel-notices.css', SHORTPIXEL_PLUGIN_FILE ), array( 'shortpixel-admin' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

		wp_register_style('notices-module', plugins_url('/build/shortpixel/notices/src/css/notices.css', SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION);

		// other media screen
		wp_register_style( 'shortpixel-othermedia', plugins_url( '/res/css/shortpixel-othermedia.css', SHORTPIXEL_PLUGIN_FILE ), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

		// load everywhere, because we are inconsistent.
		wp_register_style( 'shortpixel-toolbar', plugins_url( '/res/css/shortpixel-toolbar.css', SHORTPIXEL_PLUGIN_FILE ), array( 'dashicons' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

		// @todo Might need to be removed later on
		wp_register_style( 'shortpixel-admin', plugins_url( '/res/css/shortpixel-admin.css', SHORTPIXEL_PLUGIN_FILE ), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

		wp_register_style( 'shortpixel-bulk', plugins_url( '/res/css/shortpixel-bulk.css', SHORTPIXEL_PLUGIN_FILE ), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

		wp_register_style( 'shortpixel-nextgen', plugins_url( '/res/css/shortpixel-nextgen.css', SHORTPIXEL_PLUGIN_FILE ), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

		wp_register_style( 'shortpixel-settings', plugins_url( '/res/css/shortpixel-settings.css', SHORTPIXEL_PLUGIN_FILE ), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

	}


	/** Load Style via Route, on demand */
	public function load_style( $name ) {
		if ( $this->is_noheaders ) {  // fail silently, if this is a no-headers request.
			return;
		}

		if ( wp_style_is( $name, 'registered' ) ) {
			wp_enqueue_style( $name );
		} else {
			Log::addWarn( "Style $name was asked for, but not registered", $_SERVER['REQUEST_URI'] );
		}
	}

	/** Load Style via Route, on demand */
	public function load_script( $script ) {
		if ( $this->is_noheaders ) {  // fail silently, if this is a no-headers request.
			return;
		}

		if ( ! is_array( $script ) ) {
			$script = array( $script );
		}

		foreach ( $script as $index => $name ) {
			if ( wp_script_is( $name, 'registered' ) ) {
				wp_enqueue_script( $name );
			} else {
				Log::addWarn( "Script $name was asked for, but not registered", $_SERVER['REQUEST_URI']  );
			}
		}
	}

	/** This is separated from route to load in head, preventing unstyled content all the time */
	 public function load_admin_scripts( $hook_suffix ) {
		global $plugin_page;
		$screen_id = $this->env()->screen_id;

		$load_processor = array( 'shortpixel', 'shortpixel-processor' );  // a whole suit needed for processing, not more. Always needs a screen as well!
		$load_bulk      = array();  // the whole suit needed for bulking.
		if ( \wpSPIO()->env()->is_screen_to_use ) {
			$this->load_script( $load_processor );
			$this->load_style( 'shortpixel-toolbar' );
			$this->load_style('shortpixel-notices');
			$this->load_style('notices-module');
		}

		if ( $plugin_page == 'wp-shortpixel-settings' || $plugin_page == 'shortpixel-network-settings' ) {

			$this->load_script( 'shortpixel-screen-nolist' ); // screen
			$this->load_script( 'shortpixel-settings' );

			// @todo Load onboarding only when no api key / onboarding required
			$this->load_script('shortpixel-onboarding');

			$this->load_style( 'shortpixel-admin' );

			$this->load_style( 'shortpixel-settings' );

		} elseif ( $plugin_page == 'wp-short-pixel-bulk' ) {
			$this->load_script( 'shortpixel-screen-bulk' );

			$this->load_style( 'shortpixel-admin' );
			$this->load_style( 'shortpixel-bulk' );
		} elseif ( $screen_id == 'upload' || $screen_id == 'attachment' ) {

			$this->load_script( 'shortpixel-screen-media' ); // screen
			$this->load_script( 'shortpixel-media' );

			$this->load_style( 'shortpixel-admin' );
			$this->load_style( 'notices-module');
		//	$this->load_style( 'shortpixel' );

			if ( $this->env()->is_debug ) {
				$this->load_script( 'shortpixel-debug' );
			}

		} elseif ( $plugin_page == 'wp-short-pixel-custom' ) { // custom media
		//	$this->load_style( 'shortpixel' );

			$this->load_script( 'shortpixel-folderbrowser' );

			$this->load_style( 'shortpixel-admin' );
			$this->load_style( 'shortpixel-folderbrowser' );
			$this->load_style( 'shortpixel-othermedia' );
			$this->load_script( 'shortpixel-screen-custom' ); // screen

		} elseif ( NextGenController::getInstance()->isNextGenScreen() ) {

			$this->load_script( 'shortpixel-screen-custom' ); // screen
			$this->load_style( 'shortpixel-admin' );

		//	$this->load_style( 'shortpixel' );
			$this->load_style( 'shortpixel-nextgen' );
		}
		elseif (true === $this->env()->is_gutenberg_editor || true === $this->env()->is_classic_editor)
		{
			$this->load_script( $load_processor );
			$this->load_script( 'shortpixel-screen-media' ); // screen
			$this->load_script( 'shortpixel-media' );

			$this->load_style( 'shortpixel-admin' );
		}
		elseif (true === \wpSPIO()->env()->is_screen_to_use  )
		{
			// If our screen, but we don't have a specific handler for it, do the no-list screen.
			$this->load_script( 'shortpixel-screen-nolist' ); // screen
		}

	}

	/** Route, based on the page slug
     *
     * Principially all page controller should be routed from here.
     */
	public function route() {
		global $plugin_page;

		$default_action = 'load'; // generic action on controller.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
		$action         = isset( $_REQUEST['sp-action'] ) ? sanitize_text_field( wp_unslash($_REQUEST['sp-action']) ) : $default_action;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
		$template_part  = isset( $_GET['part'] ) ? sanitize_text_field( wp_unslash($_GET['part']) ) : false;

		$controller = false;

		$url       = menu_page_url( $plugin_page, false );
		$screen_id = \wpSPIO()->env()->screen_id;

        switch ( $plugin_page ) {
            case 'wp-shortpixel-settings': // settings
						$controller = 'ShortPixel\Controller\View\SettingsViewController';
        	break;
					 case 'shortpixel-network-settings':
					 	$controller = 'ShortPixel\Controller\View\MultiSiteViewController';
					break;
          case 'wp-short-pixel-custom': // other media
						if ('folders'  === $template_part )
						{
							$controller = 'ShortPixel\Controller\View\OtherMediaFolderViewController';
						}
						elseif('scan' === $template_part)
						{
							$controller = 'ShortPixel\Controller\View\OtherMediaScanViewController';
						}
						else {
							$controller = 'ShortPixel\Controller\View\OtherMediaViewController';
						}

        	break;
        	case 'wp-short-pixel-bulk':
						$controller = '\ShortPixel\Controller\View\BulkViewController';
           break;
           case null:
            default:
                switch ( $screen_id ) {
					case 'upload':
                  $controller = '\ShortPixel\Controller\View\ListMediaViewController';
                        break;
					case 'attachment'; // edit-media
                   $controller = '\ShortPixel\Controller\View\EditMediaViewController';
                     break;
                }
                break;

		}
		if ( $controller !== false ) {
			$c = $controller::getInstance();
			$c->setControllerURL( $url );
			if ( method_exists( $c, $action ) ) {
				$c->$action();
			} else {
				Log::addWarn( "Attempted Action $action on $controller does not exist!" );
				$c->$default_action();
			}
		}
	}


	// Get the plugin URL, based on real URL.
	public function plugin_url( $urlpath = '' ) {
		$url = trailingslashit( $this->plugin_url );
		if ( strlen( $urlpath ) > 0 ) {
			$url .= $urlpath;
		}
		return $url;
	}

	// Get the plugin path.
	public function plugin_path( $path = '' ) {
		$plugin_path = trailingslashit( $this->plugin_path );
		if ( strlen( $path ) > 0 ) {
			$plugin_path .= $path;
		}

		return $plugin_path;
	}

	/** Returns defined admin page hooks. Internal use - check states via environmentmodel
     *
     * @returns Array
     */
	public function get_admin_pages() {
		return $this->admin_pages;
	}

	protected function check_plugin_version() {
      $version     = SHORTPIXEL_IMAGE_OPTIMISER_VERSION;
			$db_version = $this->settings()->currentVersion;

		if ( $version !== $db_version ) {
			InstallHelper::activatePlugin();
			$this->settings()->currentVersion = $version;

		}
	}




} // class plugin
