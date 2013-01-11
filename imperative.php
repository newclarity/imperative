<?php
/**
 * The Missing require_library() for Embedded Libraries within WordPress Plugins and Themes.
 *
 * Follows Semantic Versioning 2.0.0-rc.1 rules; i.e. major version introduce breaking API changes.
 * @see: http://semver.org
 *
 * @package Imperative
 * @version 0.0.10
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
      $this->plugins_loaded( true );
      $this->release_memory();
    }

    /**
     */
    function activate_plugin() {
      $to_remove = false;

      foreach ( $this->_libraries as $versions ) {
        if ( 1 < count( $versions ) ) {
          $first_library = current( $versions );
          foreach( $versions as $library ) {
            if ( $first_library->major_version == $library->major_version )
              continue;
            $to_remove = $library->plugin_file;

            if ( $this->is_plugin_activation() ) {

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
      if ( ! $to_remove )
        $this->plugins_loaded( true );
      $this->release_memory();
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
     * @param string $library_name
     * @param string $version
     * @param string $plugin_file
     * @param string $library_path
     * @param array $args
     */
    function require_library( $library_name, $version, $plugin_file, $library_path, $args = array() ) {
      $plugin_file = $this->_un_symlink_plugin_file( $plugin_file );

      $args['library_name'] = $library_name;
      $args['version'] = $version;
      $args['major_version'] = intval( substr( $version, 0, strpos( $version, '.' ) ) );
      $args['plugin_file'] = $plugin_file;
      $args['library_path'] = ( '/' == $library_path[0] ) ? $library_path : dirname( $plugin_file ) . "/{$library_path}";

      /**
       * Allow libraries to be kept in /wp-content/libraries/ to aid in debugging
       * a site that has more than one plugin using the same libraries.
       */
      if ( ! is_file( $args['library_path'] ) )
        $args['library_path'] = WP_CONTENT_DIR . "/{$library_path}";

      /**
       * This assumes same named and same version are literally the same. Which only works when everyone places
       * nice but then the WordPress ecosystem has checks & balances for those that don't play nice.
       */
      $this->_libraries[$library_name][$version] = (object)$args;
    }

    /**
     * @param string $plugin_file
     * @return string
     */
    private function _un_symlink_plugin_file( $plugin_file ) {
      if ( isset( $this->_plugin_files[$plugin_file] ) ) {
        $symlinked_plugin_file = $this->_plugin_files[$plugin_file];
      } else {
        /**
         * Save the symlinked path to test against later.
         */
        $realpath = realpath( $plugin_file );
        /*
         * Handle plugins that are symlinked
         */
        $symlinked_plugin_file =  WP_PLUGIN_DIR . '/' . basename( dirname( $plugin_file ) ) . '/' . basename( $plugin_file );
        /*
         * Handle plugins that included in the plugin directory but w/o their own subdirectory
         */
        $symlinked_plugin_file = str_replace( '/plugins/plugins/', '/plugins/', $symlinked_plugin_file );
        if ( ! is_file( $symlinked_plugin_file ) ) {
          global $pagenow;
          /**
           * This is a super hack to figure out the path name for this plugin when it is symlinked
           * but it's not using the same directory name as the source, i.e. if source is:
           *    ~/Plugins/my-plugin/my-plugin.php
           * and symlink in site is:
           *    ~/Sites/my-site/wp-content/plugins/my-plugin-dev/my-plugin.php
           */
          if ( 'plugins.php' == $pagenow && isset( $_GET['action'] ) && 'activate' == $_GET['action'] ) {
            /**
             * get_plugins() is very expensive to run but we only need to do on plugin activate
             */
            require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
            $plugins = array_keys( get_plugins() );
          } else {
            $plugins = get_option( 'active_plugins' );
          }
          $basename_regex = preg_quote( basename( $symlinked_plugin_file ) );
          foreach( $plugins as $plugin_slug ) {
            /**
             * Find the plugin slug with that same main filename, ignoring the containing directory
             */
            if ( preg_match( "#/{$basename_regex}$#", $plugin_slug ) ) {
              /**
               * Compose the filename based on the plugin's slug. This is almost certainly a symlink
               */
              $symlinked_plugin_file = WP_CONTENT_DIR . "/plugins/{$plugin_slug}";
              /**
               * See if the realpath of this plugin is the same as the realpath passed in.
               * And make sure the file actually exists.
               */
              if ( realpath( $symlinked_plugin_file ) == $realpath && is_file( $symlinked_plugin_file ) ) {
                break;
              }
            }
          }
        }
        $this->_plugin_files[$plugin_file] = $symlinked_plugin_file;
        /*
         * We only want to do this once.
         */
        add_action( 'activate_plugin', array( $this, 'activate_plugin' ) );
      }
      return $symlinked_plugin_file;
    }

    /**
     * @param string $plugin_file
     * @param string|bool $loader_file
     */
    function register_plugin_loader( $plugin_file, $loader_file = false ) {
      $plugin_file = $this->_un_symlink_plugin_file( $plugin_file );
      $plugin_dir = dirname( $plugin_file );
      if ( ! $loader_file ) {
        $loader_file = "{$plugin_dir}/loader.php";
        if ( ! file_exists( $loader_file ) ) {
          $loader_file = preg_replace( '#(.*?)\.php$#', '$1-loader.php', $plugin_file );
        }
      }
      if ( '/' != $loader_file[0] )
        $loader_file = dirname( $plugin_file ) . "/{$loader_file}";
      if ( ! file_exists( $loader_file ) ) {
        $message = __( '%s specified as a WP_Library_Manager loader file for the %s plugin does not exist.', 'imperative' );
        echo '<div class="error"><p><strong>ERROR</strong>: ' . sprintf( $message, $loader_file, $plugin_file ) . '</p></div>';
      }
      $this->_loaders[$plugin_file] = $loader_file;
    }

    /**
     * @return string
     */
    function get_current_library() {
      return $this->_current_library;
    }

    /**
     * Load libraries and any required loaders after the plugins are loaded
     *
     * @param bool $force
     */
    function plugins_loaded( $force = false ) {
      if ( ! $force && $this->is_plugin_activation() || $this->is_plugin_error_scrape() )
        return;

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

        if ( file_exists( $library->library_path ) ) {
          /*
           * Assign a property so libraries can know which one was loaded, if needed.
           */
          $this->_current_library = $library;
          require_once( $library->library_path );
        }
      }

      /**
       * Allow processing to happen after libraries are loaded.
       */
      do_action( 'libraries_loaded' );

      /**
       * Load any of the plugin loaders that were registered.
       */
      if ( count( $this->_loaders ) ) {
        global $plugin;
        $save_plugin = $plugin;
        $loaders = apply_filters( 'plugin_loaders', $this->_loaders );
        foreach( $loaders as $plugin => $loader ) {
          require_once( $loader );
        }
        $plugin = $save_plugin;
      }

      /**
       * Allow processing to happen after plugin loaders are loaded.
       */
      do_action( 'plug_loaders_loaded' );

      if ( ! $force )
        $this->release_memory();

    }

    /**
     * Releases memory used by this class after it's needed.
     *
     * @return bool
     */
    function release_memory() {
      /**
       * Release all the memory we used for this.
       */
      foreach( array_keys( get_object_vars( $this ) ) as $property ) {
        $this->$property = null;
      }
    }

    /**
     * Used to check if we are in an activation callback on the Plugins page.
     *
     * @return bool
     */
    function is_plugin_activation() {
      global $pagenow;
      return 'plugins.php' == $pagenow
        && isset( $_GET['action'] ) && 'activate' == $_GET['action']
        && isset( $_GET['plugin'] );
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
     * @return bool
     */
    function is_plugin_uninstall() {
      global $pagenow;
      return 'plugins.php' == $pagenow
        && isset( $_GET['action'] ) && 'delete-selected' == $_GET['action']
        && isset( $_GET['checked'] ) && is_array( $_GET['checked'] );
    }

  }
  new WP_Library_Manager();

  /**
   * @param string $library_name
   * @param string $version
   * @param string $plugin_file
   * @param string $library_path
   * @param array $args
   */
  function require_library( $library_name, $version, $plugin_file, $library_path, $args = array() ) {
    WP_Library_Manager::this()->require_library( $library_name, $version, $plugin_file, $library_path, $args );
  }

  /**
   * @param string $plugin_file
   * @param string|bool $loader_file
   * @param array $args
   */
  function register_plugin_loader( $plugin_file, $loader_file = false, $args = array() ) {
    $args['loader_type'] = 'plugin';
    WP_Library_Manager::this()->register_plugin_loader( $plugin_file, $loader_file, $args );
  }

  /**
   * @param string $plugin_file
   * @param string|bool $loader_file
   * @param array $args
   */
  function register_theme_loader( $plugin_file, $loader_file = false, $args = array() ) {
    $args['loader_type'] = 'theme';
    WP_Library_Manager::this()->register_plugin_loader( $plugin_file, $loader_file, $args );
  }

  /**
   * @deprecated 0.0.4
   * @param string $plugin_file
   * @param string|bool $loader_file
   * @param array $args
   */
  function register_loader( $plugin_file, $loader_file = false, $args = array() ) {
    _deprecated_function( __FUNCTION__, '0.0.4 of library Imperative', 'register_plugin_loader' );
    register_plugin_loader( $plugin_file, $loader_file, $args );
  }
}
