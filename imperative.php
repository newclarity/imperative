<?php
/**
 * The Missing register_library() for Embedded Libraries within WordPress Plugins and Themes.
 *
 * @package Imperative
 * @version 0.0.0
 * @author Mike Schinkel <mike@newclarity.net>
 * @author Micah Wood <micah@newclarity.net>
 * @license GPL-2.0+ <http://opensource.org/licenses/gpl-2.0.php>
 * @copyright Copyright (c) 2012, NewClarity LLC
 *
 */
if ( class_exists( 'WP_Library_Manager' ) )
  return;

/**
 */
class WP_Library_Manager {
  /**
   * @var WP_Library_Manager $_me
   */
  private static $_me;
  /**
   * @var array
   */
  private $_libraries = array();
  /**
   * @var array
   */
  private $_applicants = array();
  /**
   * @var array
   */
  private $_loaders = array();
  /**
   * @var object
   */
  private $_current_library;

  /**
   * @return WP_Library_Manager
   */
  static function me() {
    return self::$_me;
  }
  /**
   *
   */
  function __construct() {
    if ( ! self::$_me instanceof WP_Library_Manager ) {
      $message = __( '%s is a singleton class and cannot be instantiated more than once. Use WP_Library_Manager::me() instead.', 'imperative' );
      echo '<div class="error"><p><strong>ERROR</strong>: ' . sprintf( $message, get_class( $this ) ) . '</p></div>';
    }
    /*
     *  WP_Library_Manager::me() is needed to allow plugins to remove hooks if needed.
     */
    self::$_me = &$this;
    add_action( 'after_setup_theme', array( $this, 'after_setup_theme' ), 0 );  // Priorty = 0, do early.
  }

  /**
   * @param string $library_name
   * @param string $version
   * @param string $plugin_file
   * @param string $library_path
   * @param array $args
   * @return bool
   */
  function register_library(  $library_name, $version, $plugin_file, $library_path, $args = array() ) {
    $args['library_name'] = $library_name;
    $args['version'] = $version;
    $args['plugin_file'] = $plugin_file;
    $args['$library_path'] = $library_path;
    if ( empty( $args['operator'] ) )
      $args['operator'] = '>=';

    /**
     * This assumes same named and same version are literally the same. Which only works when everyone places
     * nice but then the WordPress ecosystem has checks & balances for those that don't play nice.
     */
    $this->_libraries[$library_name][$version] = dirname( $plugin_file ) . "/{$library_path}";

    $this->_applicants[] = (object)$args;

    return true;
  }

  /**
   * @param string $plugin_file
   * @param string $plugin_name
   * @param callable $loader_file
   */
  function register_plugin_loader( $plugin_file, $plugin_name, $loader_file ) {
    if ( ! file_exists( $loader_file ) ) {
      $message = __( '%s specified as a WP_Library_Manager loader file for the %s plugin does not exist.', 'imperative' );
      echo '<div class="error"><p><strong>ERROR</strong>: ' . sprintf( $message, $loader_file, $plugin_file ) . '</p></div>';
    }
    $this->_libraries[$plugin_file] = (object)array(
      'plugin_name' => $plugin_name,
      'plugin_file' => $plugin_file,
      'loader_file' => $loader_file,
    );
  }

  /**
   * @return array
   */
  function get_current_library() {
    return $this->_current_library;
  }

  function after_setup_theme() {
    foreach ( $this->_libraries as $versions ) {
      if ( 1 == count( $versions ) ) {
        $library = $versions[0];
      } else {
        /**
         *
         */
        $library = $versions[0];    // @todo Make it work here
        /**
         *
         */
      }
      if ( ! file_exists( $library->filepath ) ) {
        $this->_current_library = &$library;
        require_once( $library->filepath );
      }
    }
    do_action( 'libraries_loaded' );
  }
}
new WP_Library_Manager();

/**
 * @param string $library_name
 * @param string $version
 * @param string $plugin_file
 * @param string $library_path
 * @param array $args
 * @return bool
 */
function register_library( $library_name, $version, $plugin_file, $library_path, $args = array() ) {
  return WP_Library_Manager::me()->register_library( $library_name, $version, $plugin_file, $library_path, $args );
}
/**
 * @param string $plugin_file
 * @param callable $loader_file
 */
function register_plugin_loader( $plugin_file, $plugin_name, $loader_file ) {
  WP_Library_Manager::me()->register_plugin_loader( $plugin_file, $plugin_name, $loader_file );
}

