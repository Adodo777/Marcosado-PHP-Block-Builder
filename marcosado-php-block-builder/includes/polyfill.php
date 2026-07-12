<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'str_starts_with' ) ) {
    function str_starts_with( $haystack, $needle ) {
        return '' === (string) $needle || 0 === strpos( $haystack, $needle );
    }
}
