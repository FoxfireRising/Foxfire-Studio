<?php
/**
 * Checks every sprintf() and printf() in the plugin whose format is a
 * translated string: the number of arguments must match the placeholders in
 * the English text, or PHP 8 stops with an error the first time that line
 * runs. Run with plain PHP: php tests/php/placeholders.php
 */
$root = dirname( __DIR__, 2 ) . '/plugin/foxfire-studio';
$bad  = 0;
$seen = 0;
$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
foreach ( $it as $f ) {
	if ( '.php' !== substr( $f, -4 ) ) {
		continue;
	}
	$toks = array_values(
		array_filter(
			token_get_all( file_get_contents( $f ) ),
			function ( $t ) {
				return ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
			}
		)
	);
	$n = count( $toks );
	for ( $i = 0; $i < $n; $i++ ) {
		$t = $toks[ $i ];
		if ( ! is_array( $t ) || T_STRING !== $t[0] || ! in_array( $t[1], array( 'sprintf', 'printf' ), true ) || '(' !== $toks[ $i + 1 ] ) {
			continue;
		}
		/* Split the call's arguments at top-level commas. */
		$depth = 0;
		$args  = array( array() );
		for ( $j = $i + 2; $j < $n; $j++ ) {
			$x = $toks[ $j ];
			if ( '(' === $x || '[' === $x || '{' === $x ) {
				++$depth;
			} elseif ( ')' === $x || ']' === $x || '}' === $x ) {
				if ( 0 === $depth ) {
					break;
				}
				--$depth;
			} elseif ( ',' === $x && 0 === $depth ) {
				$args[] = array();
				continue;
			}
			$args[ count( $args ) - 1 ][] = $x;
		}
		$first = $args[0];
		/* Formats: __( 'x' ... ), _n( 'one', 'many' ... ), or a ternary of them: every literal that is a translation's first argument. */
		$formats = array();
		for ( $k = 0; $k < count( $first ); $k++ ) {
			if ( is_array( $first[ $k ] ) && T_STRING === $first[ $k ][0] && preg_match( '/^(__|_x|_n|_nx|esc_html__|esc_attr__)$/', $first[ $k ][1] ) && '(' === $first[ $k + 1 ] && is_array( $first[ $k + 2 ] ) && T_CONSTANT_ENCAPSED_STRING === $first[ $k + 2 ][0] ) {
				$formats[] = stripslashes( substr( $first[ $k + 2 ][1], 1, -1 ) );
				if ( '_n' === $first[ $k ][1] && ',' === $first[ $k + 3 ] && is_array( $first[ $k + 4 ] ) ) {
					$formats[] = stripslashes( substr( $first[ $k + 4 ][1], 1, -1 ) );
				}
			}
		}
		if ( ! $formats ) {
			continue;
		}
		$given = count( $args ) - 1;
		foreach ( $formats as $fmt ) {
			++$seen;
			$clean = str_replace( '%%', '', $fmt );
			preg_match_all( '/%(?:(\d+)\$)?[-+ 0\'.\d]*[bcdeEfFgGosuxX]/', $clean, $m );
			$need = 0;
			$plain = 0;
			foreach ( $m[1] as $pos ) {
				if ( '' === $pos ) {
					++$plain;
				} else {
					$need = max( $need, (int) $pos );
				}
			}
			$need = max( $need, $plain );
			if ( $need !== $given ) {
				++$bad;
				echo 'FAIL ' . str_replace( $root . '/', '', $f ) . ':' . $t[2] . " needs $need arguments, has $given: $fmt\n";
			}
		}
	}
}
echo "$seen translated formats checked, $bad wrong\n";
exit( $bad ? 1 : 0 );
