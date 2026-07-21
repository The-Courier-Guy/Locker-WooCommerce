<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkout "Selected TCG Locker option" summary + Change button.
 *
 * Expected variables:
 * - $selected_label (string)
 * - $selected_method (string)
 */

if ( empty( $selected_label ) ) {
	return;
}
?>
<tr class="pudo-selected-option">
	<th>
	<?php
		echo esc_html__( 'Locker option', 'pudo-shipping-for-woocommerce' );
	?>
	</th>
	<td>
		<span id="pudo-selected-option-label" style="margin-right: 8px;">
		<?php
			echo esc_html( $selected_label );
		?>
		</span>
		<button
				type="button"
				class="button"
				id="pudo-change-option"
				data-pudo-change="1"
				data-pudo-method="
				<?php
				echo esc_attr( $selected_method ?? '' );
				?>
				"
		>
			<?php
			echo esc_html__( 'Change Locker', 'pudo-shipping-for-woocommerce' );
			?>
		</button>
	</td>
</tr>

