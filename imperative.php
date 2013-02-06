<?php
/**
 * The Missing require_library() for Embedded Libraries within WordPress Plugins and Themes.
 *
 * The code in this library is much more complicated than is could be if WordPress core were
 * changed only slightly to accompdate it. One example:
 *    http://core.trac.wordpress.org/ticket/22802#comment:41
 *
 * Follows Semantic Versioning 2.0.0-rc.1 rules; i.e. major version introduce breaking API changes.
 * @see: http://semver.org
 *
 * @package Imperative
 * @version 0.2.1
 * @author Mike Schinkel <mike@newclarity.net>
 * @author Micah Wood <micah@newclarity.net>
 * @license GPL-2.0+ <http://opensource.org/licenses/gpl-2.0.php>
 * @copyright Copyright (c) 2012, NewClarity LLC
 *
 * @todo Add tested support for loading libraries via themes.
 *
 * @todo Add support for Windows file systems.
 *
 */
if ( ! class_exists( 'WP_Library_Manager' ) ) {
  /**
   */
  class WP_Library_Manager {
    /**
     * @var bool
     */
    static $loading_plugin_loaders = false;
    /**
     * @var bool
     */
    static $uninstalling_plugin = false;
    /**
     * @var WP_Library_Manager $_this
     */
    private static $_this;
    /**
     * @var array
     */
    private $_libraries = array();
    /**
     * @var array
     */
    private $_library_keys = array();
    /**
     * @var array
     */
    private $_loaders = array();
    /**
     * @var array
     */
    private $_plugin_files = array();
    /**
     * @var object
     */
    private $_current_library;

    /**
     * @return WP_Library_Manager
     */
    static function this() {
      return self::$_this;
    }

    /**
     *
     */
    function __construct() {
      if ( self::$_this instanceof WP_Library_Manager ) {
        $message = __( '%s is a singleton class and cannot be instantiated more than once. Use WP_Library_Manager::this() instead.', 'imperative' );
        echo '<div class="error"><p><strong>ERROR</strong>: ' . sprintf( $message, get_class( $this ) ) . '</p></div>';
      }
      /*
       *  WP_Library_Manager::me() is needed to allow plugins to remove hooks if needed.
       *
       */
      self::$_this = &$this;
      add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ), 0 );  // Priorty = 0, do early.
      add_action( 'admin_notices', array( $this, 'admin_notices' ), 0 );  // Priorty = 0, do early.

      if ( $this->is_plugin_activation() )
        add_action( 'activate_plugin', array( $this, 'activate_plugin' ) );

      if ( $this->is_plugin_uninstall() ) {
        foreach( $_GET['checked'] as $plugin ) {
          add_action( "uninstall_{$plugin}", array( $this, 'uninstall_plugin' ) );
        }
      }
    }

    /**
     *
     */
    function uninstall_plugin() {
      /**
       * Grab the slug of the file being uninstalled, it's in the current filter prefixed with 'uninstall_'
       */
      self::$uninstalling_plugin = preg_replace( '#^uninstall_(.*)$#', '$1', current_filter() );
      /**
       * Grab the slug of the file being uninstalled, it's in the current filter prefixed with 'uninstall_'
       */
      $this->_load_libraries();
      $this->_load_plugin_loaders();
      $this->_release_memory();
      self::$uninstalling_plugin = false;
    }

    /**
     * @param string $plugin_filepath
     * @param array $args
     *
     * @return string
     */
    private function _get_loader_filepath( $plugin_filepath, $args = array() ) {
      $plugin_dir = dirname( $plugin_filepath );
      $loader_file = "{$plugin_dir}/loader.php";
      if ( ! file_exists( $loader_file ) ) {
        $loader_file = preg_replace( '#(.*?)\.php$#', '$1-loader.php', $plugin_filepath );
      }
      if ( '/' != $loader_file[0] )
        $loader_file = dirname( $plugin_filepath ) . "/{$loader_file}";
      if ( ! file_exists( $loader_file ) ) {
        $message = __( '%s specified as a WP_Library_Manager loader file for the %s plugin does not exist.', 'imperative' );
        echo '<div class="error"><p><strong>ERROR</strong>: ' . sprintf( $message, $loader_file, $plugin_filepath ) . '</p></div>';
      }
      return $loader_file;
    }

    /**
     * Take the plugin slug passed in from WordPress and fixup the filenames fix this plugin to support symlinking
     *
     * This is called after our plugin was loaded, but since we haven't really loaded
     *
     * @param string $plugin_file
     */
    private function _fixup_symlinked( $plugin_file ) {

      $plugin_filepath = WP_PLUGIN_DIR . "/{$plugin_file}";
      $plugin_dir = dirname( $plugin_filepath );
      $real_filepath = realpath( $plugin_filepath );
      $this->_loaders[$real_filepath] = $this->_get_loader_filepath( $plugin_filepath );
      foreach( $this->_library_keys[$real_filepath] as $library_name => $library_keys ) {
        $library = $this->_libraries[$library_keys['library']][$library_keys['version']];

        $library->plugin_file = $plugin_filepath;
        $library_filepath = "{$plugin_dir}/{$library->library_file}";

        if ( ! file_exists( $library_filepath ) )
          $library_filepath = WP_CONTENT_DIR . "/{$library->library_file}";

        if ( file_exists( $library_filepath ) )
          $library->library_file = $library_filepath;

        $this->_libraries[$library_keys['library']][$library_keys['version']] = $library;
      }
    }

    /**
     */
    function has_libraries( $plugin_slug ) {
      return isset( $this->_library_keys[$this->_get_plugin_file_from_slug( $plugin_slug )] );
    }

    /**
     */
    function has_loader( $plugin_slug ) {
      return isset( $this->_loaders[$this->_get_plugin_file_from_slug( $plugin_slug )] );
    }

    /**
     */
    private function _get_plugin_file_from_slug( $plugin_slug ) {
      /**
       * TODO: Make this Windows IIS compatible.
       */
      return '/' != $plugin_slug[0] ? WP_PLUGIN_DIR . "/{$plugin_slug}" : $plugin_slug;
    }

    /**
     */
    function activate_plugin( $plugin_slug ) {
      $to_remove = false;
      if ( $this->has_loader( $plugin_slug ) ) {
        $this->_fixup_symlinked( $plugin_slug );
        foreach ( $this->_libraries as $versions ) {
          if ( 1 < count( $versions ) ) {
            $first_library = current( $versions );
            foreach( $versions as $library ) {
              if ( $first_library->major_version == $library->major_version )
                continue;
              $to_remove = $library->plugin_file;

              if ( $this->is_plugin_activation( $library->plugin_file ) ) {

                $plugin_files = get_plugins();
                $this_plugin_slug = substr( current_filter(), strlen( 'activate_') );
                $this_plugin = $plugin_files[$this_plugin_slug];

                $other_plugin_slug = ltrim( str_replace( WP_PLUGIN_DIR, '', $first_library->plugin_file ), '/' );
                $other_plugin = $plugin_files[$other_plugin_slug];

                if ( preg_match( "#{$this_plugin_slug}$#", $library->plugin_file ) ) {
                  $this_version = $library->version;
                  $other_version = $first_library->version;
                } else {
                  $this_version = $first_library->version;
                  $other_version = $library->version;
                }

                if ( version_compare( $this_version, $other_version ) ) {
                  $guilty_plugin = $other_plugin['Name'];
                  $newer_version = $this_version;
                } else {
                  $guilty_plugin = $this_plugin['Name'];
                  $newer_version =  $other_version;
                }

                $message = sprintf( __( '<p><strong>Plugin Activation Error:</strong> The plugin you trying to activate named
                  <strong>%s</strong> contains <strong>version %s</strong> of the <strong>%s</strong> embedded library
                  and it conflicts with <strong>version %s</strong> of the same library
                  used by the <strong>%s</strong> plugin which your site has active.</p>
                  <p>To resolve this issue you can:</p>
                  <p>&nbsp;&nbsp;&nbsp;1.) Choose not to use the <strong>%s</strong> plugin. If so you don\'t need to do anything.</p>
                  <p>&nbsp;&nbsp;&nbsp;2.) Deactivate the <strong>%s</strong> plugin and choose to activate the <strong>%s</strong> plugin instead.</p>
                  <p>&nbsp;&nbsp;&nbsp;3.) Contact the author(s) of the <strong>%s</strong> plugin via their support page and ask them to upgrade to <strong>version %s</strong> of the <strong>%s</strong> embedded library.</p>',
                  'imperative' ),
                  $this_plugin['Name'], $this_version,
                  $library->library_name,
                  $other_version, $other_plugin['Name'],
                  $this_plugin['Name'],
                  $other_plugin['Name'], $this_plugin['Name'], $guilty_plugin,
                  $newer_version, $library->library_name
                );
                $activation_error = $this->get_activation_error();
                if ( $activation_error )
                  $message = "{$activation_error}<hr>{$message}";
                $this->update_activation_error( $message );
              }
              break;
            }
          }
        }
        if ( $to_remove ) {
          foreach ( $this->_libraries as $library_name => $versions )
            foreach( $versions as $version => $library )
              if ( $to_remove == $library->plugin_file )
                unset( $this->_libraries[$library_name][$version] );

          unset( $this->_loaders[$to_remove] );

          $plugin_files = array_flip( $this->_plugin_files );
          unset( $this->_plugin_files[$plugin_files[$to_remove]] );

          global $status, $page;

          if ( ! $this->is_plugin_error_scrape() ) {
            $redirect = self_admin_url( "plugins.php?error=true&plugin={$this_plugin_slug}&plugin_status={$status}&paged={$page}" );
            wp_redirect( add_query_arg( '_error_nonce', wp_create_nonce( 'plugin-activation-error_' . $this_plugin_slug ), $redirect ) );
            exit;
          }

        }
      }
      if ( ! $to_remove ) {
        $this->_load_libraries();
        $this->_load_plugin_loaders();
      }
      $this->_release_memory();
    }

    /**
     * @return mixed|void
     */
    function get_activation_error() {
      return get_option( 'imperative_activation_error' );
    }

    /**
     * @param string $message
     */
    function update_activation_error( $message ) {
      update_option( 'imperative_activation_error', $message );
    }

    /**
     */
    function delete_activation_error() {
      return delete_option( 'imperative_activation_error' );
    }

    /**
     *
     */
    function admin_notices() {
      $activation_error = $this->get_activation_error();
      if ( $activation_error ) {
        echo '<div class="error">' . $activation_error .'</div>';
      }
      $this->delete_activation_error();
    }

    /**
     * Get the full plugin filepath if available in a global variable from WordPress
     *
     * @param string $plugin_file
     * @param array $args
     *
     * @return string
     */
    function _get_plugin_filepath( $plugin_file, $args = array() ) {
      global $mu_plugin, $network_plugin, $plugin;
      if ( isset( $mu_plugin ) ) {
        $virtual_filepath = $mu_plugin;
      } else if ( isset( $network_plugin ) ) {
        $virtual_filepath = $network_plugin;
      } else if ( isset( $plugin ) ) {
        $virtual_filepath = $plugin;
      } else {
        $virtual_filepath = false;
      }
      if ( $virtual_filepath != $plugin_file ) {
        if ( $this->is_plugin_activation( $virtual_filepath ) ) {
          $plugin_file = WP_PLUGIN_DIR . "/{$virtual_filepath}";
        } else if ( $this->is_plugin_uninstall( $virtual_filepath ) ) {
          if ( isset( $_GET['checked'] ) )
              foreach( $_GET['checked'] as $plugin_slug ) {
                $virtual_filepath = WP_PLUGIN_DIR . "/{$plugin_slug}";
              if ( realpath( $virtual_filepath ) == $plugin_file ) {
                $plugin_file = $virtual_filepath;
                break;
              }
            }
        } else if ( isset( $virtual_filepath ) ) {
          $plugin_file = $virtual_filepath;
        }
      }
      return $plugin_file;
    }

    /**
     * @param string $library_name
     * @param string $version
     * @param string $plugin_file
     * @param string $library_file
     * @param array $args
     */
    function require_library( $library_name, $version, $plugin_file, $library_file, $args = array() ) {

      $fixedup_plugin_file = $this->_get_plugin_filepath( $plugin_file, $args );

      $args['library_name'] = $library_name;
      $args['version'] = $version;
      $args['major_version'] = intval( substr( $version, 0, strpos( $version, '.' ) ) );
      $args['plugin_file'] = $fixedup_plugin_file;

      if ( $this->is_plugin_activation( $fixedup_plugin_file ) ) {
        /**
         * If plugin activation we'll store partial and wait to fixup in 'active_plugin' hook.
         * Otherwise let's get the real filename
         */
        $args['library_file'] =  $library_file;
      } else if ( is_file(  $library_filepath = dirname( $fixedup_plugin_file ) . "/{$library_file}" ) ) {
        /**
         * If the library is embedded in the plugin in "{$plugin_dir}/libraries/{$lib_name}/{$lib_name}.php"
         */
        $args['library_file'] =  $library_filepath;
      } else if ( is_file(  $library_filepath = WP_CONTENT_DIR . "/{$library_file}" ) ) {
        /**
         * If the library is in WP_CONTENT_DIR . "/libraries/{$lib_name}/{$lib_name}.php"
         */
        $args['library_file'] =  $library_filepath;
      } else {
        /*
         * Default to something. It's probably wrong...
         */
        $args['library_file'] =  $library_file;
      }

      /**
       * This assumes same named and same version are literally the same. Which only works when everyone places
       * nice but then the WordPress ecosystem has checks & balances for those that don't play nice.
       */
      $this->_libraries[$library_name][$version] = (object)$args;

      /**
       * Save the keys for this plugin file so we can fixup the filenames to support symlinking
       */
      $this->_library_keys[$plugin_file][$library_name] = array(
        'library' => $library_name,
        'version' => $version,
        'plugin'  => $fixedup_plugin_file,
      );
    }

    /**
     * @param string $plugin_file
     * @param array $args
     */
    function register_loader( $plugin_file, $args = array() ) {
      $fixup_plugin_file = $this->_get_plugin_filepath( $plugin_file, $args );
      $this->_loaders[$fixup_plugin_file] = $this->_get_loader_filepath( $plugin_file, $args );
      add_action( 'activate_plugin', array( $this, 'activate_plugin' ) );
    }

    /**
     * @return object
     */
    function get_current_library() {
      return $this->_current_library;
    }

    /**
     * Load libraries after WordPress declares plugins are loaded
     *
     */
    function plugins_loaded() {
      if ( $this->is_plugin_uninstall() || $this->is_plugin_activation() || $this->is_plugin_error_scrape() ) {
        /**
         * We need to delay or omit loading (other) plugins in each of these cases
         */
        return;
      }
      $this->_load_libraries();
      $this->_load_plugin_loaders();
      $this->_release_memory();
    }

    /**
     * Load libraries
     */
    private function _load_libraries() {
      /**
       * For each of the libraries that plugins and themes said they required.
       */
      foreach ( $this->_libraries as $version => $versions ) {
        if ( 1 == count( $versions ) ) {
          /**
           * There's only one version, no need to choose.
           */
          $library = current( $versions );
        } else {
          /**
           * Find the copy of the library with the highest version #
           */
          uksort( $versions, 'version_compare' );
          $library = end( $versions );
        }

        if ( file_exists( $library->library_file ) ) {
          /*
           * Assign a property so libraries can know which one was loaded, if needed.
           */
          $this->_current_library = $library;
          require_once( $library->library_file );
        }
      }

      /**
       * Allow processing to happen after libraries are loaded.
       */
      do_action( 'libraries_loaded' );

    }

    /**
     * Load required plugin loaders
     */
    private function _load_plugin_loaders() {
      /**
       * Load any of the plugin loaders that were registered.
       */
      if ( count( $this->_loaders ) ) {
        global $plugin;
        $save_plugin = $plugin;
        $loaders = apply_filters( 'plugin_loaders', $this->_loaders );
        self::$loading_plugin_loaders = true;
        foreach( $loaders as $plugin => $loader ) {
          require_once( $loader );
        }
        self::$loading_plugin_loaders = false;
        $plugin = $save_plugin;
      }

      /**
       * Allow processing to happen after plugin loaders are loaded.
       */
      do_action( 'plug_loaders_loaded' );

    }

    /**
     * Releases memory used by this class after it's needed.
     *
     * @return bool
     */
    private function _release_memory() {
      /**
       * Release all the memory we used for this.
       */
      foreach( array_keys( get_object_vars( $this ) ) as $property ) {
        $this->$property = null;
      }
      $this->_library_keys = array();
    }

    /**
     * Used to check if we are in an activation callback on the Plugins page.
     *
     * If no parameter is passed tests for the general case.
     * If a plugin file is passed tests for the specific plugin.
     *
     * @param bool|string $plugin_file
     *
     * @return bool
     */
    function is_plugin_activation( $plugin_file = false ) {
      global $plugin, $pagenow;
      $is_plugin_activation = 'plugins.php' == $pagenow
        && isset( $_GET['action'] ) && 'activate' == $_GET['action']
        && isset( $_GET['plugin'] );
      if ( $is_plugin_activation && $plugin_file ) {
        $is_plugin_activation = ! is_null( $plugin )
          && is_string( $plugin )
          && '/' != $plugin[0]
          && preg_match( '#' . preg_quote( $_GET['plugin'] ) . '$#', $plugin_file );
      }
      return $is_plugin_activation;
    }

    /**
     * Used to check if we are in an activation callback on the Plugins page.
     *
     * @return bool
     */
    function is_plugin_error_scrape() {
      global $pagenow;
      return 'plugins.php' == $pagenow
        && isset( $_GET['action'] ) && 'error_scrape' == $_GET['action']
        && isset( $_GET['plugin'] );
    }

    /**
     * Used to check if we are in an activation callback on the Plugins page.
     *
     * If no parameter is passed tests for the general case.
     * If a plugin file is passed tests for the specific plugin.
     *
     * @param bool|string $plugin_file
     *
     * @return bool
     */
    function is_plugin_uninstall( $plugin_file = false ) {
      global $pagenow;
      $is_plugin_uninstall = 'plugins.php' == $pagenow
        && isset( $_GET['action'] ) && 'delete-selected' == $_GET['action']
        && isset( $_GET['checked'] ) && is_array( $_GET['checked'] );
      if ( $is_plugin_uninstall && $plugin_file ) {
        $is_plugin_uninstall = false;
        foreach( $_GET['checked'] as $plugin_slug ) {
          if ( preg_match( '#' . preg_quote( $plugin_slug ) . '$#', $plugin_file ) ) {
            $is_plugin_uninstall = true;
            break;
          }
        }
      }
      return $is_plugin_uninstall;
    }

  }
  new WP_Library_Manager();

  /**
   * @param string $library_name
   * @param string $version
   * @param string $plugin_file
   * @param string $library_file
   * @param array $args
   */
  function require_library( $library_name, $version, $plugin_file, $library_file, $args = array() ) {
    WP_Library_Manager::this()->require_library( $library_name, $version, $plugin_file, $library_file, $args );
  }

  /**
   * @param string $plugin_file
   * @param array $args
   */
  function register_plugin_loader( $plugin_file, $args = array() ) {
    $args['loader_type'] = 'plugin';
    WP_Library_Manager::this()->register_loader( $plugin_file, $args );
  }

  /**
   * @param string $plugin_file
   * @param string|bool $loader_file
   * @param array $args
   */
  function register_theme_loader( $plugin_file, $loader_file = false, $args = array() ) {
    $args['loader_type'] = 'theme';
    WP_Library_Manager::this()->register_loader( $plugin_file, $loader_file, $args );
  }

  /**
   * @deprecated 0.0.4
   * @param string $plugin_file
   * @param string|bool $loader_file
   * @param array $args
   */
  function register_loader( $plugin_file, $loader_file, $args = array() ) {
    _deprecated_function( __FUNCTION__, '0.0.4 of library Imperative', 'register_plugin_loader' );
    register_plugin_loader( $plugin_file, $args );
  }
}
