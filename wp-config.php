<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the
 * installation. You don't have to use the web site, you can
 * copy this file to "wp-config.php" and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * MySQL settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://codex.wordpress.org/Editing_wp-config.php
 *
 * @package WordPress
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'kelsodesign' );

/** MySQL database username */
define( 'DB_USER', 'kelso' );

/** MySQL database password */
define( 'DB_PASSWORD', 'Parker17*' );

/** MySQL hostname */
define( 'DB_HOST', 'localhost' );

/** Database Charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The Database Collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'ms8u+BsinO{t]6CTqkEB2h9qCD^^2D@QVkil!@sXE[UnwNeUOWf`Epl-_0P{&-o8' );
define( 'SECURE_AUTH_KEY',  'ae]78@#nmB;pb8N[jCSpL COVqh<73$:{AF4ZsUg!BZxyYHeC;(}}DQ^_flx`@||' );
define( 'LOGGED_IN_KEY',    'X_YL?mO.C-vPK.p*bcc2p(#w,)!iCTV^[BkuWrTIZ1b;x+IFX}OS~EkYYyF3Y`?n' );
define( 'NONCE_KEY',        'eRoR4uwu,!n|Mz-W ]4q <5t0h4]0fK(KtaHhe 7&::Te?Q5<Ml11w~{6]VcK7SD' );
define( 'AUTH_SALT',        '``^BWVjmY1j]R!Z`?||8z~{ip:et<EMSH_(<Ha&9qC.L*LFR[KI||! uAYw%}W,i' );
define( 'SECURE_AUTH_SALT', '-v0O%YgA@ LZ1DuW*woFUt6cqp#SR8-UJqCZ2PGm]3fJ3J.%1|M[A5{e63pHCs8B' );
define( 'LOGGED_IN_SALT',   'Btd4:mX17yt!VE|hyQ1E/r65qh>5$`5^_X_)M&YqRV0h<ZI8W)3(=+rFJxb{@)Y=' );
define( 'NONCE_SALT',       'K=<Ev9Q[H8/AWqS{G7()-X&6P7<mY57CP*Bfp5Jsqc+H~Q,G&g~Pju[OcBv|uT3f' );

/**#@-*/

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the Codex.
 *
 * @link https://codex.wordpress.org/Debugging_in_WordPress
 */
define( 'WP_DEBUG', true );

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __FILE__ ) . '/' );
}

/** Sets up WordPress vars and included files. */
require_once( ABSPATH . 'wp-settings.php' );

@ini_set( 'upload_max_size' , '500M' );
@ini_set( 'post_max_size', '500m');
@ini_set( 'memory_limit', '500m' );
