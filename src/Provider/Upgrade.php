<?php
/**
 * Upgrade routines.
 *
 * @package SatisPress
 * @license GPL-2.0-or-later
 * @since 0.3.0
 */

declare ( strict_types = 1 );

namespace SatisPress\Provider;

use const SatisPress\VERSION;
use Cedaro\WP\Plugin\AbstractHookProvider;
use SatisPress\Exception\ExceptionInterface;
use SatisPress\Capabilities;
use SatisPress\Htaccess;
use SatisPress\PackageManager;
use SatisPress\ReleaseManager;
use SatisPress\Storage\Local;
use SatisPress\Storage\StorageInterface;

/**
 * Class for upgrade routines.
 *
 * @since 0.3.0
 */
class Upgrade extends AbstractHookProvider {
	/**
	 * Version option name.
	 *
	 * @var string
	 */
	const VERSION_OPTION_NAME = 'satispress_version';

	/**
	 * Htaccess handler.
	 *
	 * @var Htaccess
	 */
	protected $htaccess;

	/**
	 * Package manager.
	 *
	 * @var PackageManager
	 */
	protected $package_manager;

	/**
	 * Release manager.
	 *
	 * @var ReleaseManager
	 */
	protected $release_manager;

	/**
	 * Storage service.
	 *
	 * @var StorageInterface
	 */
	protected $storage;

	/**
	 * Constructor.
	 *
	 * @since 0.3.0
	 *
	 * @param PackageManager   $package_manager Package manager.
	 * @param ReleaseManager   $release_manager Release manager.
	 * @param StorageInterface $storage         Storage service.
	 * @param Htaccess         $htaccess        Htaccess handler.
	 */
	public function __construct( PackageManager $package_manager, ReleaseManager $release_manager, StorageInterface $storage, Htaccess $htaccess ) {
		$this->htaccess        = $htaccess;
		$this->package_manager = $package_manager;
		$this->release_manager = $release_manager;
		$this->storage         = $storage;
	}

	/**
	 * Register hooks.
	 *
	 * @since 0.3.0
	 */
	public function register_hooks() {
		add_action( 'admin_init', [ $this, 'maybe_upgrade' ] );
	}

	/**
	 * Upgrade when the database version is outdated.
	 *
	 * @since 0.3.0
	 */
	public function maybe_upgrade() {
		$saved_version = get_option( self::VERSION_OPTION_NAME, '0' );

		if ( version_compare( $saved_version, VERSION, '<' ) ) {
			Capabilities::register();
			$this->cache_packages();
			$this->setup_storage();
		}

		if ( version_compare( $saved_version, VERSION, '<' ) ) {
			update_option( self::VERSION_OPTION_NAME, VERSION );
		}
	}

	/**
	 * Cache existing packages.
	 *
	 * If any packages are already whitelisted before upgrading to 0.3.0, cache
	 * them so checksums can be generated for packages.json.
	 *
	 * @since 0.3.0
	 */
	protected function cache_packages() {
		$packages = $this->package_manager->get_packages();

		foreach ( $packages as $package ) {
			try {
				$this->release_manager->archive( $package->get_installed_release() );
			} catch ( ExceptionInterface $e ) { }
		}
	}

	/**
	 * Set up the local storage provider.
	 *
	 * Creates the cache path if it doesn't exist and adds an .htaccess file to
	 * prevent HTTP access on Apache.
	 *
	 * @since 0.3.0
	 */
	protected function setup_storage() {
		$upload_config = wp_upload_dir();

		if ( ! $this->storage instanceof Local ) {
			return;
		}

		// Rename the old /satispress directory if it exists.
		// The new directory contains a random suffix.
		$old_path = path_join( $upload_config['basedir'], 'satispress' );
		if ( file_exists( $old_path ) ) {
			rename( $old_path, $this->storage->get_base_directory() );
		}

		if ( ! wp_mkdir_p( $this->storage->get_base_directory() ) ) {
			return;
		}

		$this->htaccess->add_rules( [ 'deny from all' ] );
		$this->htaccess->save();
	}
}