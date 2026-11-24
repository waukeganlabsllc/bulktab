<?php
/**
 * Release builder: one codebase, many slugs.
 *
 * Usage:
 *
 *   php bin/build.php --slug=<slug> [--name="Display Name"] [--src=<dir>]
 *                     [--domain=bulktab] [--free-slug=<slug>] [--no-zip]
 *
 *   Path A (takeover): php bin/build.php --slug=yikes-inc-easy-custom-woocommerce-product-tabs
 *   Path B (own slug): php bin/build.php --slug=<our-slug> --name="<Brand> Product Tabs for WooCommerce"
 *   Pro:               php ../bulktab/bin/build.php --src=. --domain=bulktab-pro --slug=bulktab-pro --free-slug=<free-slug>
 *
 * Steps:
 *  1. Copy <src> minus .distignore entries into <src>/build/<slug>/.
 *  2. Rename <domain>.php to <slug>.php.
 *  3. Rewrite the "Text Domain:" header and every '<domain>' literal passed as the
 *     text domain to an i18n function (PHP via the tokenizer; JS via regex;
 *     block.json "textdomain"); rename languages/<domain>*.{pot,po,mo,json}.
 *  4. Optionally rewrite "Plugin Name:" (and the readme.txt title) with --name,
 *     and the "<domain-of-free>" entry of "Requires Plugins:" with --free-slug.
 *  5. If composer.json has runtime requirements, run composer install --no-dev.
 *  6. Zip to <src>/build/<slug>.zip (top folder <slug>/).
 *
 * CLI only; not shipped (bin/ is in .distignore).
 *
 * @package BulkTab
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DiscouragedPHPFunctions

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

/** Domain argument position (0-based) for each i18n function. */
const BULKTAB_BUILD_I18N = array(
	'__'                         => 1,
	'_e'                         => 1,
	'esc_html__'                 => 1,
	'esc_html_e'                 => 1,
	'esc_attr__'                 => 1,
	'esc_attr_e'                 => 1,
	'_x'                         => 2,
	'_ex'                        => 2,
	'esc_html_x'                 => 2,
	'esc_attr_x'                 => 2,
	'_n'                         => 3,
	'_nx'                        => 4,
	'_n_noop'                    => 2,
	'_nx_noop'                   => 3,
	'translate_nooped_plural'    => 2,
	'load_plugin_textdomain'     => 0,
	'wp_set_script_translations' => 1,
);

/**
 * Print a message and exit.
 *
 * @param string $msg  Message.
 * @param int    $code Exit code.
 */
function bulktab_build_fail( string $msg, int $code = 1 ): void {
	fwrite( STDERR, "build: $msg\n" );
	exit( $code );
}

/**
 * Parse .distignore into patterns.
 *
 * @param string $file Path.
 * @return string[]
 */
function bulktab_build_ignores( string $file ): array {
	$out = array( '/.git', '/build' );
	if ( is_readable( $file ) ) {
		foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$out[] = rtrim( $line, '/' );
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Whether a relative path (forward slashes, no leading slash) is ignored.
 * "/x" is anchored at the root; "x" matches the basename at any depth.
 *
 * @param string   $rel      Relative path.
 * @param string[] $patterns Patterns.
 */
function bulktab_build_is_ignored( string $rel, array $patterns ): bool {
	$base = basename( $rel );
	foreach ( $patterns as $p ) {
		if ( '/' === $p[0] ) {
			if ( fnmatch( substr( $p, 1 ), $rel, FNM_PATHNAME ) ) {
				return true;
			}
		} elseif ( false !== strpos( $p, '/' ) ) {
			if ( fnmatch( $p, $rel, FNM_PATHNAME ) ) {
				return true;
			}
		} elseif ( fnmatch( $p, $base ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Copy $src to $dst, skipping ignored paths.
 *
 * @param string   $src      Source root.
 * @param string   $dst      Destination root.
 * @param string[] $patterns Ignore patterns.
 * @return int Files copied.
 */
function bulktab_build_copy( string $src, string $dst, array $patterns ): int {
	$count = 0;
	$stack = array( '' );
	while ( $stack ) {
		$rel_dir = array_pop( $stack );
		$entries = scandir( $src . '/' . $rel_dir );
		foreach ( $entries as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$rel = ltrim( $rel_dir . '/' . $name, '/' );
			if ( bulktab_build_is_ignored( $rel, $patterns ) ) {
				continue;
			}
			$from = $src . '/' . $rel;
			if ( is_link( $from ) ) {
				continue;
			}
			if ( is_dir( $from ) ) {
				$stack[] = $rel;
				continue;
			}
			$to = $dst . '/' . $rel;
			if ( ! is_dir( dirname( $to ) ) ) {
				mkdir( dirname( $to ), 0755, true );
			}
			copy( $from, $to ) || bulktab_build_fail( "copy failed: $rel" );
			++$count;
		}
	}
	return $count;
}

/**
 * Rewrite text-domain literals in i18n calls of one PHP source.
 *
 * Only a string literal that is exactly the old domain and sits at the
 * domain position of a known i18n function call is replaced.
 *
 * @param string $code Source.
 * @param string $from Old domain.
 * @param string $to   New domain.
 * @param int    $hits Replacements made (out).
 */
function bulktab_build_rewrite_php( string $code, string $from, string $to, int &$hits = 0 ): string {
	$tokens = token_get_all( $code );
	$n      = count( $tokens );
	$calls  = array(); // Stack of open calls: [ name|null, depth_at_open, arg_index ].
	$depth  = 0;
	$out    = '';
	$skip   = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );

	for ( $i = 0; $i < $n; $i++ ) {
		$t    = $tokens[ $i ];
		$text = is_array( $t ) ? $t[1] : $t;

		if ( '(' === $t ) {
			// Find the callee: previous significant token.
			$name = null;
			$j    = $i - 1;
			while ( $j >= 0 && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], $skip, true ) ) {
				--$j;
			}
			if ( $j >= 0 && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], bulktab_build_name_tokens(), true ) ) {
				$candidate = ltrim( $tokens[ $j ][1], '\\' );
				$k         = $j - 1;
				while ( $k >= 0 && is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], $skip, true ) ) {
					--$k;
				}
				$prev     = $k >= 0 ? $tokens[ $k ] : null;
				$is_plain = true;
				if ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST ), true ) ) {
					$is_plain = false;
				}
				if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && is_array( $prev ) && T_NULLSAFE_OBJECT_OPERATOR === $prev[0] ) {
					$is_plain = false;
				}
				if ( $is_plain && isset( BULKTAB_BUILD_I18N[ $candidate ] ) ) {
					$name = $candidate;
				}
			}
			++$depth;
			$calls[] = array( $name, $depth, 0 );
			$out    .= $text;
			continue;
		}

		if ( ')' === $t ) {
			if ( $calls && end( $calls )[1] === $depth ) {
				array_pop( $calls );
			}
			--$depth;
			$out .= $text;
			continue;
		}

		if ( in_array( $t, array( '[', '{' ), true ) || ( is_array( $t ) && in_array( $t[0], bulktab_build_open_tokens(), true ) ) ) {
			++$depth;
			$out .= $text;
			continue;
		}
		if ( ']' === $t || '}' === $t ) {
			--$depth;
			$out .= $text;
			continue;
		}

		if ( ',' === $t && $calls && end( $calls )[1] === $depth ) {
			++$calls[ count( $calls ) - 1 ][2];
			$out .= $text;
			continue;
		}

		if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] && $calls ) {
			$top = end( $calls );
			if ( null !== $top[0] && $top[1] === $depth && BULKTAB_BUILD_I18N[ $top[0] ] === $top[2] ) {
				$quote = $text[0];
				if ( $text === $quote . $from . $quote ) {
					$text = $quote . $to . $quote;
					++$hits;
				}
			}
		}
		$out .= $text;
	}
	return $out;
}

/**
 * Tokens that can name a called function.
 *
 * @return int[]
 */
function bulktab_build_name_tokens(): array {
	$names = array( T_STRING );
	foreach ( array( 'T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED' ) as $c ) {
		if ( defined( $c ) ) {
			$names[] = constant( $c );
		}
	}
	return $names;
}

/**
 * Tokens that open a bracket closed by "]" or "}".
 *
 * @return int[]
 */
function bulktab_build_open_tokens(): array {
	$open = array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES );
	if ( defined( 'T_ATTRIBUTE' ) ) {
		$open[] = constant( 'T_ATTRIBUTE' );
	}
	return $open;
}

/**
 * Rewrite text domain in JS i18n calls: __( '…', 'bulktab' ) etc.
 *
 * @param string $code Source.
 * @param string $from Old domain.
 * @param string $to   New domain.
 * @param int    $hits Replacements (out).
 */
function bulktab_build_rewrite_js( string $code, string $from, string $to, int &$hits = 0 ): string {
	$re = '/(\b(?:__|_x|_n|_nx|sprintf\s*\(\s*__)\s*\((?:[^()]|\((?:[^()])*\))*?,\s*)([\'"])' . preg_quote( $from, '/' ) . '\2(\s*\))/s';
	return (string) preg_replace_callback(
		$re,
		static function ( $m ) use ( $to, &$hits ) {
			++$hits;
			return $m[1] . $m[2] . $to . $m[2] . $m[3];
		},
		$code
	);
}

/**
 * Replace the value of a plugin header line.
 *
 * @param string $code   File contents.
 * @param string $header Header name, e.g. "Text Domain".
 * @param string $value  New value.
 * @param bool   $found  Whether the header was found (out).
 */
function bulktab_build_set_header( string $code, string $header, string $value, bool &$found = false ): string {
	$re    = '/^([ \t\/*#@]*' . preg_quote( $header, '/' ) . ':[ \t]*)(.*)$/mi';
	$found = (bool) preg_match( $re, $code );
	return (string) preg_replace_callback(
		$re,
		static function ( $m ) use ( $value ) {
			return $m[1] . $value;
		},
		$code,
		1
	);
}

/**
 * Delete a directory tree.
 *
 * @param string $dir Directory.
 */
function bulktab_build_rmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $it as $f ) {
		$f->isDir() && ! $f->isLink() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	rmdir( $dir );
}

/**
 * List files under $dir (relative, sorted).
 *
 * @param string $dir Directory.
 * @return string[]
 */
function bulktab_build_files( string $dir ): array {
	$files = array();
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( $f->isFile() ) {
			$files[] = str_replace( '\\', '/', substr( $f->getPathname(), strlen( $dir ) + 1 ) );
		}
	}
	sort( $files );
	return $files;
}

/**
 * Run the build.
 */
function bulktab_build_main(): void {

	$opts = getopt( '', array( 'slug:', 'name:', 'src:', 'domain:', 'free-slug:', 'no-zip', 'help' ) );
	if ( isset( $opts['help'] ) || empty( $opts['slug'] ) ) {
		fwrite( STDERR, "usage: php bin/build.php --slug=<slug> [--name=\"Display Name\"] [--src=<dir>] [--domain=bulktab] [--free-slug=<slug>] [--no-zip]\n" );
		exit( isset( $opts['help'] ) ? 0 : 2 );
	}

	$slug      = (string) $opts['slug'];
	$domain    = isset( $opts['domain'] ) ? (string) $opts['domain'] : 'bulktab';
	$name      = isset( $opts['name'] ) ? (string) $opts['name'] : null;
	$free_slug = isset( $opts['free-slug'] ) ? (string) $opts['free-slug'] : null;
	$src       = realpath( isset( $opts['src'] ) ? (string) $opts['src'] : dirname( __DIR__ ) );

	foreach ( array_filter( array( $slug, $domain, $free_slug ) ) as $s ) {
		if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $s ) ) {
			bulktab_build_fail( "invalid slug/domain: $s (lowercase letters, digits and dashes)" );
		}
	}
	if ( false === $src || ! is_file( "$src/$domain.php" ) ) {
		bulktab_build_fail( "main file $domain.php not found in " . ( $src ? $src : '(missing --src)' ) );
	}

	$build_root = "$src/build";
	$dest       = "$build_root/$slug";
	$zip_path   = "$build_root/$slug.zip";

	bulktab_build_rmdir( $dest );
	if ( is_file( $zip_path ) ) {
		unlink( $zip_path );
	}
	mkdir( $dest, 0755, true );

	// 1. Copy.
	$patterns = bulktab_build_ignores( "$src/.distignore" );
	$copied   = bulktab_build_copy( $src, $dest, $patterns );

	// 2. Main file.
	if ( $slug !== $domain ) {
		rename( "$dest/$domain.php", "$dest/$slug.php" );
	}
	$main = "$dest/$slug.php";

	// 3. Text domain in code.
	$php_hits = 0;
	$js_hits  = 0;
	foreach ( bulktab_build_files( $dest ) as $rel ) {
		$path = "$dest/$rel";
		$ext  = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
		if ( 'php' === $ext ) {
			$code = file_get_contents( $path );
			$new  = bulktab_build_rewrite_php( $code, $domain, $slug, $php_hits );
			if ( $new !== $code ) {
				file_put_contents( $path, $new );
			}
		} elseif ( 'js' === $ext && false === strpos( $rel, '.min.' ) ) {
			$code = file_get_contents( $path );
			$new  = bulktab_build_rewrite_js( $code, $domain, $slug, $js_hits );
			if ( $new !== $code ) {
				file_put_contents( $path, $new );
			}
		} elseif ( 'block.json' === basename( $rel ) ) {
			$code = file_get_contents( $path );
			$new  = preg_replace( '/("textdomain"\s*:\s*")' . preg_quote( $domain, '/' ) . '"/', '${1}' . $slug . '"', $code );
			file_put_contents( $path, $new );
		} elseif ( 0 === strpos( $rel, 'languages/' ) && 0 === strpos( basename( $rel ), $domain ) && preg_match( '/\.(pot|po|mo|json|l10n\.php)$/', $rel ) ) {
			$renamed = dirname( $path ) . '/' . $slug . substr( basename( $rel ), strlen( $domain ) );
			rename( $path, $renamed );
		}
	}

	// Headers.
	$code  = file_get_contents( $main );
	$found = false;
	$code  = bulktab_build_set_header( $code, 'Text Domain', $slug, $found );
	if ( ! $found ) {
		bulktab_build_fail( 'no "Text Domain:" header in main file' );
	}
	if ( null !== $name ) {
		$code = bulktab_build_set_header( $code, 'Plugin Name', $name, $found );
		if ( is_file( "$dest/readme.txt" ) ) {
			$readme = file_get_contents( "$dest/readme.txt" );
			$readme = preg_replace( '/^===\s*.*?\s*===\s*$/m', '=== ' . str_replace( '$', '\\$', $name ) . ' ===', $readme, 1 );
			file_put_contents( "$dest/readme.txt", $readme );
		}
	}
	if ( null !== $free_slug ) {
		$code = preg_replace_callback(
			'/^([ \t\/*#@]*Requires Plugins:[ \t]*)(.*)$/mi',
			static function ( $m ) use ( $free_slug ) {
				$list = array_map( 'trim', explode( ',', $m[2] ) );
				$list = array_map(
					static function ( $s ) use ( $free_slug ) {
						return 'bulktab' === $s ? $free_slug : $s;
					},
					$list
				);
				return $m[1] . implode( ', ', $list );
			},
			$code,
			1
		);
	}
	file_put_contents( $main, $code );

	// 4. Runtime Composer dependencies (none today; the plugin has its own autoloader).
	$composer = is_file( "$src/composer.json" ) ? json_decode( (string) file_get_contents( "$src/composer.json" ), true ) : null;
	$runtime  = array();
	if ( is_array( $composer ) && ! empty( $composer['require'] ) ) {
		$runtime = array_filter(
			array_keys( $composer['require'] ),
			static function ( $pkg ) {
				return 'php' !== $pkg && 0 !== strpos( $pkg, 'ext-' );
			}
		);
	}
	if ( $runtime ) {
		copy( "$src/composer.json", "$dest/composer.json" );
		if ( is_file( "$src/composer.lock" ) ) {
			copy( "$src/composer.lock", "$dest/composer.lock" );
		}
		passthru( 'composer install --no-dev --optimize-autoloader --no-interaction --no-progress --working-dir=' . escapeshellarg( $dest ), $rc );
		if ( 0 !== $rc ) {
			bulktab_build_fail( 'composer install --no-dev failed' );
		}
		unlink( "$dest/composer.json" );
		if ( is_file( "$dest/composer.lock" ) ) {
			unlink( "$dest/composer.lock" );
		}
	}

	// 5. Zip.
	if ( ! isset( $opts['no-zip'] ) ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			bulktab_build_fail( 'ZipArchive (ext-zip) is required' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			bulktab_build_fail( "cannot create $zip_path" );
		}
		$zip->addEmptyDir( $slug );
		foreach ( bulktab_build_files( $dest ) as $rel ) {
			$zip->addFile( "$dest/$rel", "$slug/$rel" );
		}
		$zip->close();
	}

	printf(
		"build: %d files -> %s (text domain '%s' -> '%s': %d PHP, %d JS)%s\n",
		$copied,
		$dest,
		$domain,
		$slug,
		$php_hits,
		$js_hits,
		isset( $opts['no-zip'] ) ? '' : "\nbuild: zip -> $zip_path"
	);
}

bulktab_build_main();
