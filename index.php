<?php
/*
Script Name: WP Quick Install
Author: Jonathan Buttigieg
Contributors: Ken Vilar
Script URI: http://wp-quick-install.com
Version: 0.1
Licence: GPLv3
Last Update: 15 may 2020

*/

@set_time_limit( 0 );

define( 'WP_API_CORE', 'http://api.wordpress.org/core/version-check/1.7/?locale=' );
define( 'WPQI_CACHE_PATH', 'cache/' );
define( 'WPQI_CACHE_CORE_PATH', WPQI_CACHE_PATH . 'core/' );
define( 'WPQI_CACHE_PLUGINS_PATH', WPQI_CACHE_PATH . 'plugins/' );
define( 'WPQI_ABSPATH', $_SERVER['DOCUMENT_ROOT'] );

require( 'inc/functions.php' );

// Force URL with index.php
if ( empty( $_GET ) && end( ( explode( '/', trim( $_SERVER['REQUEST_URI'], '/' ) ) ) ) == 'wp-quick-install' ) {
	header( 'Location: index.php' );
	die();
}

// Create cache directories
if ( ! is_dir( WPQI_CACHE_PATH ) ) {
	mkdir( WPQI_CACHE_PATH );
}
if ( ! is_dir( WPQI_CACHE_CORE_PATH ) ) {
	mkdir( WPQI_CACHE_CORE_PATH );
}
if ( ! is_dir( WPQI_CACHE_PLUGINS_PATH ) ) {
	mkdir( WPQI_CACHE_PLUGINS_PATH );
}

// We verify if there is a preconfig file
$data = array();
if ( file_exists( 'data.ini' ) ) {
	$data = json_encode( parse_ini_file( 'data.ini' ) );
}

// We add  ../ to directory
$directory = ! empty( $_POST['directory'] ) ? '../' . $_POST['directory'] . '/' : '../';

if ( isset( $_GET['action'] ) ) {

	switch ( $_GET['action'] ) {

		case "check_before_upload" :

			$data = array();

			/*--------------------------*/
			/*	We verify if we can connect to DB or WP is not installed yet
			/*--------------------------*/

			// DB Test
			try {
				$db = new PDO( 'mysql:host=' . $_POST['dbhost'] . ';dbname=' . $_POST['dbname'], $_POST['uname'], $_POST['pwd'] );
			} catch ( Exception $e ) {
				$data['db'] = "error etablishing connection";
			}

			// WordPress test
			if ( file_exists( $directory . 'wp-config.php' ) ) {
				$data['wp'] = "error directory";
			}

			// We send the response
			echo json_encode( $data );

			break;

		case "download_wp" :

			// Get WordPress language
			$language = substr( $_POST['language'], 0, 6 );

			// Get WordPress data
			$wp = json_decode( file_get_contents( WP_API_CORE . $language ) )->offers[0];

			/*--------------------------*/
			/*	We download the latest version of WordPress
			/*--------------------------*/

			if ( ! file_exists( WPQI_CACHE_CORE_PATH . 'wordpress-' . $wp->version . '-' . $language . '.zip' ) ) {
				file_put_contents( WPQI_CACHE_CORE_PATH . 'wordpress-' . $wp->version . '-' . $language . '.zip', file_get_contents( $wp->download ) );
			}

			break;

		case "unzip_wp" :

			// Get WordPress language
			$language = substr( $_POST['language'], 0, 6 );

			// Get WordPress data
			$wp = json_decode( file_get_contents( WP_API_CORE . $language ) )->offers[0];

			/*--------------------------*/
			/*	We create the website folder with the files and the WordPress folder
			/*--------------------------*/

			// If we want to put WordPress in a subfolder we create it
			if ( ! empty( $directory ) ) {
				// Let's create the folder
				mkdir( $directory );

				// We set the good writing rights
				chmod( $directory, 0755 );
			}

			$zip = new ZipArchive;

			// We verify if we can use the archive
			if ( $zip->open( WPQI_CACHE_CORE_PATH . 'wordpress-' . $wp->version . '-' . $language . '.zip' ) === true ) {

				// Let's unzip
				$zip->extractTo( '.' );
				$zip->close();

				// We scan the folder
				$files = scandir( 'wordpress' );

				// We remove the "." and ".." from the current folder and its parent
				$files = array_diff( $files, array( '.', '..' ) );

				// We move the files and folders
				foreach ( $files as $file ) {
					rename( 'wordpress/' . $file, $directory . '/' . $file );
				}

				rmdir( 'wordpress' ); // We remove WordPress folder
				unlink( $directory . '/license.txt' ); // We remove licence.txt
				unlink( $directory . '/readme.html' ); // We remove readme.html
				unlink( $directory . '/wp-content/plugins/hello.php' ); // We remove Hello Dolly plugin
			}

			break;

		case "wp_config" :

			/*--------------------------*/
			/*	Let's create the wp-config file
			/*--------------------------*/

			// We retrieve each line as an array
			$config_file = file( $directory . 'wp-config-sample.php' );

			// Managing the security keys
			$secret_keys = explode( "\n", file_get_contents( 'https://api.wordpress.org/secret-key/1.1/salt/' ) );

			foreach ( $secret_keys as $k => $v ) {
				$secret_keys[ $k ] = substr( $v, 28, 64 );
			}

			// We change the data
			$key = 0;
			foreach ( $config_file as &$line ) {

				if ( '$table_prefix =' == substr( $line, 0, 15 ) ) {
					$line = '$table_prefix = \'' . sanit( $_POST['prefix'] ) . "';\r\n";
					continue;
				}

				if ( ! preg_match( '/^define\(\s?\'([A-Z_]+)\',([ ]+)/', $line, $match ) ) {
					continue;
				}

				$constant = $match[1];

				switch ( $constant ) {
					case 'WP_DEBUG'       :

						// Debug mod
						if ( (int) $_POST['debug'] == 1 ) {
							$line = "define( 'WP_DEBUG', true );\r\n";

							// Display error
							if ( (int) $_POST['debug_display'] == 1 ) {
								$line .= "\r\n\n " . "/** WordPress errors will be displayed on the screen */" . "\r\n";
								$line .= "define( 'WP_DEBUG_DISPLAY', true );\r\n";
							}

							// To write error in a log files
							if ( (int) $_POST['debug_log'] == 1 ) {
								$line .= "\r\n\n " . "/** Log all the errors into a file */" . "\r\n";
								$line .= "define( 'WP_DEBUG_LOG', true );\r\n";
							}
						}

						// We add the extras constant
						if ( ! empty( $_POST['uploads'] ) ) {
							$line .= "\r\n\n " . "/** Destination folder for uploaded files */" . "\r\n";
							$line .= "define( 'UPLOADS', '" . sanit( $_POST['uploads'] ) . "' );";
						}

						if ( (int) $_POST['post_revisions'] >= 0 ) {
							$line .= "\r\n\n " . "/** Disabling article reviews */" . "\r\n";
							$line .= "define( 'WP_POST_REVISIONS', " . (int) $_POST['post_revisions'] . " );";
						}

						if ( (int) $_POST['disallow_file_edit'] == 1 ) {
							$line .= "\r\n\n " . "/** Deactivation of the theme and extension editor */" . "\r\n";
							$line .= "define( 'DISALLOW_FILE_EDIT', true );";
						}

						if ( (int) $_POST['autosave_interval'] >= 60 ) {
							$line .= "\r\n\n " . "/** Automatic backup interval */" . "\r\n";
							$line .= "define( 'AUTOSAVE_INTERVAL', " . (int) $_POST['autosave_interval'] . " );";
						}

						if ( ! empty( $_POST['wpcom_api_key'] ) ) {
							$line .= "\r\n\n " . "/** WordPress.com API Key */" . "\r\n";
							$line .= "define( 'WPCOM_API_KEY', '" . $_POST['wpcom_api_key'] . "' );";
						}

						$line .= "\r\n\n " . "/** Increase the limit memory */" . "\r\n";
						$line .= "define( 'WP_MEMORY_LIMIT', '128M' );" . "\r\n";

						break;
					case 'DB_NAME'     :
						$line = "define( 'DB_NAME', '" . sanit( $_POST['dbname'] ) . "' );\r\n";
						break;
					case 'DB_USER'     :
						$line = "define( 'DB_USER', '" . sanit( $_POST['uname'] ) . "' );\r\n";
						break;
					case 'DB_PASSWORD' :
						$line = "define( 'DB_PASSWORD', '" . sanit( $_POST['pwd'] ) . "' );\r\n";
						break;
					case 'DB_HOST'     :
						$line = "define( 'DB_HOST', '" . sanit( $_POST['dbhost'] ) . "' );\r\n";
						break;
					case 'AUTH_KEY'         :
					case 'SECURE_AUTH_KEY'  :
					case 'LOGGED_IN_KEY'    :
					case 'NONCE_KEY'        :
					case 'AUTH_SALT'        :
					case 'SECURE_AUTH_SALT' :
					case 'LOGGED_IN_SALT'   :
					case 'NONCE_SALT'       :
						$line = "define( '" . $constant . "', '" . $secret_keys[ $key ++ ] . "' );\r\n";
						break;

					case 'WPLANG' :
						$line = "define( 'WPLANG', '" . sanit( $_POST['language'] ) . "' );\r\n";
						break;
				}
			}
			unset( $line );

			$handle = fopen( $directory . 'wp-config.php', 'w' );
			foreach ( $config_file as $line ) {
				fwrite( $handle, $line );
			}
			fclose( $handle );

			// We set the good rights to the wp-config file
			chmod( $directory . 'wp-config.php', 0666 );

			break;

		case "install_wp" :

			/*--------------------------*/
			/*	Let's install WordPress database
			/*--------------------------*/

			define( 'WP_INSTALLING', true );

			/** Load WordPress Bootstrap */
			require_once( $directory . 'wp-load.php' );

			/** Load WordPress Administration Upgrade API */
			require_once( $directory . 'wp-admin/includes/upgrade.php' );

			/** Load wpdb */
			require_once( $directory . 'wp-includes/wp-db.php' );

			// Get WordPress language
			$language = substr( $_POST['language'], 0, 6 );

			// WordPress installation
			wp_install( $_POST['weblog_title'], $_POST['user_login'], $_POST['admin_email'], (int) $_POST['blog_public'], '', $_POST['admin_password'], $language );

			// We update the options with the right siteurl et homeurl value
			$protocol = ! is_ssl() ? 'http' : 'https';
			$get      = basename( dirname( __FILE__ ) );
			$dir      = str_replace( '../', '', $directory );
			$link     = $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
			$url      = preg_replace( "#$get.*$#", $dir, $link );
			$url      = trim( $url, '/' );

			update_option( 'siteurl', $url );
			update_option( 'home', $url );
			update_option( 'timezone_string', 'UTC+10' );
			update_option( 'time_format', 'g:i a' );

			/*--------------------------*/
			/*	We remove the default content
			/*--------------------------*/

			if ( $_POST['default_content'] == '1' ) {
				wp_delete_post( 1, true ); // We remove the article "Hello World"
				wp_delete_post( 2, true ); // We remove the "Exemple page"
			}

			/*--------------------------*/
			/*	We update permalinks
			/*--------------------------*/
			if ( ! empty( $_POST['permalink_structure'] ) ) {
				update_option( 'permalink_structure', $_POST['permalink_structure'] );
			}

			/*--------------------------*/
			/*	We update the media settings
			/*--------------------------*/

			if ( ! empty( $_POST['thumbnail_size_w'] ) || ! empty( $_POST['thumbnail_size_h'] ) ) {
				update_option( 'thumbnail_size_w', (int) $_POST['thumbnail_size_w'] );
				update_option( 'thumbnail_size_h', (int) $_POST['thumbnail_size_h'] );
				update_option( 'thumbnail_crop', (int) $_POST['thumbnail_crop'] );
			}

			if ( ! empty( $_POST['medium_size_w'] ) || ! empty( $_POST['medium_size_h'] ) ) {
				update_option( 'medium_size_w', (int) $_POST['medium_size_w'] );
				update_option( 'medium_size_h', (int) $_POST['medium_size_h'] );
			}

			if ( ! empty( $_POST['large_size_w'] ) || ! empty( $_POST['large_size_h'] ) ) {
				update_option( 'large_size_w', (int) $_POST['large_size_w'] );
				update_option( 'large_size_h', (int) $_POST['large_size_h'] );
			}

			update_option( 'uploads_use_yearmonth_folders', (int) $_POST['uploads_use_yearmonth_folders'] );

			/*--------------------------*/
			/*	We add the pages we found in the data.ini file
			/*--------------------------*/

			// We check if data.ini exists
			if ( file_exists( 'data.ini' ) ) {

				// We parse the file and get the array
				$file = parse_ini_file( 'data.ini' );

				// We verify if we have at least one page
				if ( count( $file['posts'] ) >= 1 ) {

					foreach ( $file['posts'] as $post ) {

						// We get the line of the page configuration
						$pre_config_post = explode( "-", $post );
						$post            = array();

						foreach ( $pre_config_post as $config_post ) {

							// We retrieve the page title
							if ( preg_match( '#title::#', $config_post ) == 1 ) {
								$post['title'] = str_replace( 'title::', '', $config_post );
							}

							// We retrieve the status (publish, draft, etc...)
							if ( preg_match( '#status::#', $config_post ) == 1 ) {
								$post['status'] = str_replace( 'status::', '', $config_post );
							}

							// On retrieve the post type (post, page or custom post types ...)
							if ( preg_match( '#type::#', $config_post ) == 1 ) {
								$post['type'] = str_replace( 'type::', '', $config_post );
							}

							// We retrieve the content
							if ( preg_match( '#content::#', $config_post ) == 1 ) {
								$post['content'] = str_replace( 'content::', '', $config_post );
							}

							// We retrieve the slug
							if ( preg_match( '#slug::#', $config_post ) == 1 ) {
								$post['slug'] = str_replace( 'slug::', '', $config_post );
							}

							// We retrieve the title of the parent
							if ( preg_match( '#parent::#', $config_post ) == 1 ) {
								$post['parent'] = str_replace( 'parent::', '', $config_post );
							}

						} // foreach

						if ( isset( $post['title'] ) && ! empty( $post['title'] ) ) {

							$parent = get_page_by_title( trim( $post['parent'] ) );
							$parent = $parent ? $parent->ID : 0;

							// Let's create the page
							$args = array(
								'post_title'     => trim( $post['title'] ),
								'post_name'      => $post['slug'],
								'post_content'   => trim( $post['content'] ),
								'post_status'    => $post['status'],
								'post_type'      => $post['type'],
								'post_parent'    => $parent,
								'post_author'    => 1,
								'post_date'      => date( 'Y-m-d H:i:s' ),
								'post_date_gmt'  => gmdate( 'Y-m-d H:i:s' ),
								'comment_status' => 'closed',
								'ping_status'    => 'closed'
							);
							wp_insert_post( $args );

						}

					}
				}
			}

			break;

		case "install_theme" :

			/** Load WordPress Bootstrap */
			require_once( $directory . 'wp-load.php' );

			/** Load WordPress Administration Upgrade API */
			require_once( $directory . 'wp-admin/includes/upgrade.php' );

			/*--------------------------*/
			/*	We install the new theme
			/*--------------------------*/

			// We verify if theme.zip exists
			if ( file_exists( 'theme.zip' ) ) {

				$zip = new ZipArchive;

				// We verify we can use it
				if ( $zip->open( 'theme.zip' ) === true ) {

					// We retrieve the name of the folder
					$stat       = $zip->statIndex( 0 );
					$theme_name = str_replace( '/', '', $stat['name'] );

					// We unzip the archive in the themes folder
					$zip->extractTo( $directory . 'wp-content/themes/' );
					$zip->close();

					// Let's activate the theme
					// Note : The theme is automatically activated if the user asked to remove the default theme
					if ( $_POST['activate_theme'] == 1 || $_POST['delete_default_themes'] == 1 ) {
						switch_theme( $theme_name, $theme_name );
					}

					// Let's remove the Tweenty family
					if ( $_POST['delete_default_themes'] == 1 ) {
						delete_theme( 'twentynineteen' );
						delete_theme( 'twentyseventeen' );
						delete_theme( 'twentysixteen' );
						delete_theme( 'twentyfifteen' );
						delete_theme( 'twentyfourteen' );
						delete_theme( 'twentythirteen' );
						delete_theme( 'twentytwelve' );
						delete_theme( 'twentyeleven' );
						delete_theme( 'twentyten' );
					}

					// We delete the _MACOSX folder (bug with a Mac)
					delete_theme( '__MACOSX' );

				}
			}

			break;

		case "install_plugins" :

			/*--------------------------*/
			/*	Let's retrieve the plugin folder
			/*--------------------------*/

			if ( ! empty( $_POST['plugins'] ) ) {

				$plugins     = explode( ";", $_POST['plugins'] );
				$plugins     = array_map( 'trim', $plugins );
				$plugins_dir = $directory . 'wp-content/plugins/';

				foreach ( $plugins as $plugin ) {

					// We retrieve the plugin XML file to get the link to downlad it
					$plugin_repo = file_get_contents( "http://api.wordpress.org/plugins/info/1.0/$plugin.json" );

					if ( $plugin_repo && $plugin = json_decode( $plugin_repo ) ) {

						$plugin_path = WPQI_CACHE_PLUGINS_PATH . $plugin->slug . '-' . $plugin->version . '.zip';

						if ( ! file_exists( $plugin_path ) ) {
							// We download the lastest version
							if ( $download_link = file_get_contents( $plugin->download_link ) ) {
								file_put_contents( $plugin_path, $download_link );
							}
						}

						// We unzip it
						$zip = new ZipArchive;
						if ( $zip->open( $plugin_path ) === true ) {
							$zip->extractTo( $plugins_dir );
							$zip->close();
						}
					}
				}
			}

			if ( $_POST['plugins_premium'] == 1 ) {

				// We scan the folder
				$plugins = scandir( 'plugins' );

				// We remove the "." and ".." corresponding to the current and parent folder
				$plugins = array_diff( $plugins, array( '.', '..' ) );

				// We move the archives and we unzip
				foreach ( $plugins as $plugin ) {

					// We verify if we have to retrive somes plugins via the WP Quick Install "plugins" folder
					if ( preg_match( '#(.*).zip$#', $plugin ) == 1 ) {

						$zip = new ZipArchive;

						// We verify we can use the archive
						if ( $zip->open( 'plugins/' . $plugin ) === true ) {

							// We unzip the archive in the plugin folder
							$zip->extractTo( $plugins_dir );
							$zip->close();

						}
					}
				}
			}

			/*--------------------------*/
			/*	We activate extensions
			/*--------------------------*/

			if ( $_POST['activate_plugins'] == 1 ) {

				/** Load WordPress Bootstrap */
				require_once( $directory . 'wp-load.php' );

				/** Load WordPress Plugin API */
				require_once( $directory . 'wp-admin/includes/plugin.php' );

				// Activation
				activate_plugins( array_keys( get_plugins() ) );
			}

			break;

		case "success" :

			/*--------------------------*/
			/*	If we have a success we add the link to the admin and the website
			/*--------------------------*/

			/** Load WordPress Bootstrap */
			require_once( $directory . 'wp-load.php' );

			/** Load WordPress Administration Upgrade API */
			require_once( $directory . 'wp-admin/includes/upgrade.php' );

			/*--------------------------*/
			/*	We update permalinks
			/*--------------------------*/
			if ( ! empty( $_POST['permalink_structure'] ) ) {
				file_put_contents( $directory . '.htaccess', null );
				flush_rewrite_rules();
			}

			echo '<div id="errors" class="alert alert-danger"><p style="margin:0;"><strong>' . _( 'Warning' ) . '</strong>: Don\'t forget to delete WP Quick Install folder.</p></div>';

			// Link to the admin
			echo '<a href="' . admin_url() . '" class="button" style="margin-right:5px;" target="_blank">' . _( 'Log In' ) . '</a>';
			echo '<a href="' . home_url() . '" class="button" target="_blank">' . _( 'Go to website' ) . '</a>';

			break;
	}
} else { ?>
    <!DOCTYPE html>
    <html xmlns="http://www.w3.org/1999/xhtml" lang="fr">
    <head>
        <meta charset="utf-8"/>
        <title>WP Quick Install</title>
        <!-- Get out Google! -->
        <meta name="robots" content="noindex, nofollow">
        <!-- CSS files -->
        <link rel="stylesheet"
              href="//fonts.googleapis.com/css?family=Open+Sans%3A300italic%2C400italic%2C600italic%2C300%2C400%2C600&#038;subset=latin%2Clatin-ext&#038;ver=3.9.1"/>
        <link rel="stylesheet" href="assets/css/style.min.css"/>
        <link rel="stylesheet" href="assets/css/buttons.min.css"/>
        <link rel="stylesheet" href="assets/css/bootstrap.min.css"/>
    </head>
    <body class="wp-core-ui">
    <h1 id="logo"><a href="http://wp-quick-install.com">WordPress</a></h1>
	<?php
	$parent_dir = realpath( dirname( dirname( __FILE__ ) ) );
	if ( is_writable( $parent_dir ) ) { ?>

        <div id="response"></div>
        <div class="progress" style="display:none;">
            <div class="progress-bar progress-bar-striped active" style="width: 0%;"></div>
        </div>
        <div id="success" style="display:none; margin: 10px 0;">
            <h1 style="margin: 0"><?php echo _( 'The world is yours' ); ?></h1>
            <p><?php echo _( 'WordPress has been installed.' ); ?></p>
        </div>
        <form method="post" action="">

            <div id="errors" class="alert alert-danger" style="display:none;">
                <strong><?php echo _( 'Warning' ); ?></strong>
            </div>

            <h1><?php echo _( 'Warning' ); ?></h1>
            <p><?php echo _( 'This file must be in the wp-quick-install folder and not be present in the root of your project.' ); ?></p>

            <h1><?php echo _( 'Database Information' ); ?></h1>
            <p><?php echo _( "Below you should enter your database connection details. If you&#8217;re not sure about these, contact your host." ); ?></p>

            <!----Database Information---->
            <input type="hidden" name="dbhost" id="dbhost" value="localhost"/>
            <input type="hidden" name="default_content" id="default_content" value="1"/>
            <!----end Database Information---->

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="dbname"><?php echo _( 'Database name' ); ?></label></th>
                    <td><input name="dbname" id="dbname" type="text" size="25" value="wordpress" class="required"/></td>
                    <td><?php echo _( 'The name of the database you want to run WP in.' ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="uname"><?php echo _( 'Database username' ); ?></label></th>
                    <td><input name="uname" id="uname" type="text" size="25" value="username" class="required"/></td>
                    <td><?php echo _( 'Your MySQL username' ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pwd"><?php echo _( 'Password' ); ?></label></th>
                    <td><input name="pwd" id="pwd" type="text" size="25" value="password"/></td>
                    <td><?php echo _( '&hellip;and your MySQL password.' ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="prefix"><?php echo _( 'Table Prefix' ); ?></label></th>
                    <td><input name="prefix" id="prefix" type="text" value="wp_" size="25" class="required"/></td>
                    <td><?php echo _( 'If you want to run multiple WordPress installations in a single database, change this.' ); ?></td>
                </tr>
            </table>

            <h1><?php echo _( 'Required Information' ); ?></h1>
            <p><?php echo _( 'Thank you to provide the following information. Don\'t worry, you will be able to change it later.' ); ?></p>

            <!----Required Information---->
            <input type="hidden" name="language" id="language" value="en_AU"/>
            <input type="hidden" name="directory" id="directory" value=""/>
            <!----end Required Information---->

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="weblog_title"><?php echo _( 'Site Title' ); ?></label></th>
                    <td><input name="weblog_title" type="text" id="weblog_title" size="25" value="" class="required"/>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="user_login"><?php echo _( 'Username' ); ?></label></th>
                    <td>
                        <input name="user_login" type="text" id="user_login" size="25" value="" class="required"/>
                        <p><?php echo _( 'Usernames can have only alphanumeric characters, spaces, underscores, hyphens, periods and the @ symbol.' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="admin_password"><?php echo _( 'Password' ); ?></label>
                        <p><?php echo _( 'A password will be automatically generated for you if you leave this blank.' ); ?></p>
                    </th>
                    <td>
						<?php $pw = random_pw( 12 ); ?>
                        <input name="admin_password" type="password" id="admin_password" size="25" value=""/>
                        <p><?php echo _( 'Hint: The password should be at least seven characters long. To make it stronger, use upper and lower case letters, numbers and symbols like ! " ? $ % ^ &amp; ).' . "<br />Suggested PW: " . htmlspecialchars( $pw ) . "<br />Be sure to copy the password to a safe place." ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="admin_email"><?php echo _( 'Your E-mail' ); ?></label></th>
                    <td><input name="admin_email" type="text" id="admin_email" size="25" value="" class="required"/>
                        <p><?php echo _( 'Double-check your email address before continuing.' ); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="blog_public"><?php echo _( 'Privacy' ); ?></label></th>
                    <td colspan="2"><label><input type="checkbox" id="blog_public" name="blog_public" value="1"
                                                  checked="checked"/> <?php echo _( 'Allow search engines to index this site.' ); ?>
                        </label></td>
                </tr>
            </table>

            <!----Theme Information---->
            <input type="hidden" name="activate_theme" id="activate_theme" value="1"/>
            <input type="hidden" name="delete_default_themes" id="delete_default_themes" value="1"/>
            <!----end Theme Information---->

            <!----Extensions Information---->
            <input type="hidden" name="plugins" id="plugins" value=""/>
            <input type="hidden" name="plugins_premium" id="plugins_premium" value="1"/>
            <input type="hidden" name="activate_plugins" id="activate_plugins" value="1"/>
            <!----end Extensions Information---->

            <!----Permalinks Information---->
            <input type="hidden" name="permalink_structure" id="permalink_structure" value="/%postname%/"/>
            <!----end Permalinks Information---->

            <!----Media Information---->
            <input type="hidden" name="upload_dir" id="upload_dir" value=""/>
            <input type="hidden" name="uploads_use_yearmonth_folders" id="uploads_use_yearmonth_folders" value="1"/>
            <!----end Media Information---->

            <!----wp-config.php Information---->
            <input type="hidden" name="post_revisions" id="post_revisions" min="0" value="5"/>
            <input type="hidden" name="disallow_file_edit" id="disallow_file_edit" value="1"/>
            <input type="hidden" name="autosave_interval" id="autosave_interval" value="7200"/>
            <input type="hidden" name="debug" id="debug" value="0"/>
            <input type="hidden" name="wpcom_api_key" id="wpcom_api_key" value=""/>
            <!----end wp-config.php Information---->

            <p class="step"><span id="submit" class="button button-large"><?php echo _( 'Install WordPress' ); ?></span>
            </p>

        </form>
        <script>var data = <?php echo $data; ?>;</script>
        <script src="assets/js/script.js"></script>
	<?php
	} else { ?>

        <div class="alert alert-error" style="margin-bottom: 0;">
            <strong><?php echo _( 'Warning !' ); ?></strong>
            <p style="margin-bottom:0;"><?php echo _( 'You don\'t have the good permissions rights on ' ) . basename( $parent_dir ) . _( '. Thank you to set the good files permissions.' ); ?></p>
        </div>

		<?php
	}
	?>
    </body>
    </html>
	<?php
}
