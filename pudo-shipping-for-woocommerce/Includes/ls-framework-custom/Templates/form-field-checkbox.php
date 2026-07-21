<?php

defined( 'ABSPATH' ) || exit;
$pudo_checked_attr  = ( ! empty( $value ) ? 'checked="checked"' : '' );
$pudo_readonly_attr = ( ! empty( $readonly ) ) ? 'disabled="disabled"' : '';
?>

<input
		type="checkbox"
		id="<?php
		echo esc_attr( $identifier );
		?>"
		name="<?php
		echo esc_attr( $identifier );
		?>"
		<?php
		echo esc_attr( $pudo_checked_attr );
		?>
		<?php
		echo esc_attr( $pudo_readonly_attr );
		?>
/>