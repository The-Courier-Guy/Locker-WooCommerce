<?php
defined( 'ABSPATH' ) || exit; ?>
<input
		type="text"
		id="<?php
		echo esc_attr( $identifier );
		?>"
		name="<?php
		echo esc_attr( $identifier );
		?>"
		value="<?php
		echo esc_attr( $value );
		?>"
		class="widefat"
		<?php
		echo esc_attr( $readonly );
		?>
/>