<?php
defined( 'ABSPATH' ) || exit; ?>

<textarea
		id="<?php
		echo esc_attr( $identifier );
		?>"
		name="<?php
		echo esc_attr( $identifier );
		?>"
		class="widefat"
		rows="10"
	<?php
	echo esc_attr( $readonly );
	?>
>
<?php
	echo esc_textarea( $value );
?>
</textarea>