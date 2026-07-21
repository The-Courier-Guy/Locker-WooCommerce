<?php
defined( 'ABSPATH' ) || exit; ?>

<p>
	<label>
	<?php
		echo esc_html( $properties['display_name'] ) . ':';
	?>
	</label>
	<?php
	require $formFieldTemplateFile;
	?>
</p>