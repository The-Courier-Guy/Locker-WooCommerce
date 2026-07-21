<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap">
	<h1>TCG Locker Create Shipment</h1>

	<div class="container">
		<div class="row">
			<div class="col-12" id="locker-full-div">
				<img style="width:300px"
					src="
					<?php
						echo esc_url( plugin_dir_url( __FILE__ ) . '../dist/tcg_lockers.png' );
					?>
					">
				<br>
			</div>
		</div>

		<div class="row">
			<div id="try-again-div" class="col-md-4"></div>

			<div id="form-content-pudo" class="col-md-8">

				<form id="submit_shipment_form" role="form" class="form-horizontal text-left" method="post">

					<?php
					wp_nonce_field( 'pudo_submit_shipment', 'pudo_nonce' );
					?>
					<input type="hidden" name="orderID" value="
					<?php
					echo esc_attr( $order_id );
					?>
					">
					<input id="service-level-code" type="hidden" name="serviceLevelCode">
					<input id="raw_pudo_method" type="hidden" name="raw_pudo_method"
                           value="<?php echo esc_attr( $rawPudoMethod ); ?>">

					<div class="form-group z-formgroup">
						<strong>Select Method</strong><br>
						<input disabled type="radio" name="pudo-method" value="l2l"
							<?php checked( $pudoMethod, 'l2l' ); ?> /><label>Locker to Locker</label><br>
						<input disabled type="radio" name="pudo-method" value="l2d"
							<?php checked( $pudoMethod, 'l2d' ); ?> /><label>Locker to Door</label><br>
						<input disabled type="radio" name="pudo-method" value="d2l"
							<?php checked( $pudoMethod, 'd2l' ); ?> /><label>Door to Locker</label><br>
						<input disabled type="radio" name="pudo-method" value="d2d"
							<?php checked( $pudoMethod, 'd2d' ); ?> /><label>Door to Door</label>
					</div>

					<div id="selectionContainer">

						<div class="form-group z-formgroup pudo-source-locker pudo-hidden">
							<strong>Select Source Locker</strong><br>
							<select class="form-control" name="pudo-source-locker" id="pudo-source-locker">
								<?php
								foreach ( $lockers as $pudo_locker ) :
									?>
									<option value="
									<?php
									echo esc_attr( $pudo_locker['code'] );
									?>
									"
											<?php
											selected( $lockerOriginCode, $pudo_locker['code'] );
											?>
											>
										<?php
										echo esc_html( $pudo_locker['name'] );
										?>
									</option>
									<?php
								endforeach;
								?>
							</select>
						</div>

						<div class="form-group z-formgroup pudo-destination-locker pudo-hidden">
							<strong>Select Destination Locker</strong><br>
							<select class="form-control" name="pudo-destination-locker" id="pudo-destination-locker">
								<?php
								foreach ( $lockers as $pudo_locker ) :
									?>
									<option value="
									<?php
									echo esc_attr( $pudo_locker['code'] );
									?>
									"
											<?php
											selected( $lockerDestinationCode, $pudo_locker['code'] );
											?>
											>
										<?php
										echo esc_html( $pudo_locker['name'] );
										?>
									</option>
									<?php
								endforeach;
								?>
							</select>
						</div>

						<div class="form-group pudo-d2d-pricing z-formgroup pudo-hidden">
							<strong id="d2d-rate-name"></strong><br>
							<div id="d2d-rate"></div>
							<select name="serviceLevelCodeD2D" id="d2d-select"></select>
						</div>

						<div class="form-group z-formgroup pudo-locker-size pudo-hidden">
							<strong>Select Locker Type</strong><br>
							<select name="pudo-locker-size" id="pudo-locker-size"></select>
						</div>

						<div class="form-group z-formgroup pudo-hidden pudo-submit">
							<div class="col">
								<input type="submit" class="btn btn-primary form-control" value="Continue"
										id="pudo-submit-btn">
							</div>
						</div>

					</div>
				</form>

				<div id="spinner" style="display: none;">
					<div class="spinner"></div>
					<span>Loading...</span>
				</div>

			</div>
		</div>
	</div>
</div>