<?php
/**
 * Plugin Name: WP Cam Show
 * Description: Monitors a list of Chaturbate rooms and shows an affiliate embed player in the bottom-right corner of the site when one goes live. Configure under Settings → Cam Show.
 * Version: 1.0.1
 * Author: shad-base
 * License: GPL-2.0-or-later
 * Text Domain: wp-camshow
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CBCS_VERSION', '1.0.1' );
define( 'CBCS_OPTION_KEY', 'cbcs_settings' );

function cbcs_default_settings() {
	return array(
		'enabled'  => 1,
		'campaign' => 'r9k9h',
		'tour'     => 'SHBY',
		'track'    => 'embed',
		'rooms'    => '',
		'small_w'  => 420,
		'small_h'  => 260,
		'large_w'  => 850,
		'large_h'  => 528,
	);
}

function cbcs_get_settings() {
	$saved = get_option( CBCS_OPTION_KEY, array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), cbcs_default_settings() );
}

function cbcs_get_rooms() {
	$settings = cbcs_get_settings();
	$raw      = preg_split( '/[\r\n,]+/', (string) $settings['rooms'] );
	$rooms    = array();
	foreach ( $raw as $room ) {
		$room = strtolower( trim( preg_replace( '/[^A-Za-z0-9_]/', '', (string) $room ) ) );
		if ( '' !== $room && ! in_array( $room, $rooms, true ) ) {
			$rooms[] = $room;
		}
	}
	return $rooms;
}

function cbcs_sanitize_settings( $input ) {
	$input = is_array( $input ) ? $input : array();
	$out   = cbcs_default_settings();

	$out['enabled'] = empty( $input['enabled'] ) ? 0 : 1;

	foreach ( array( 'campaign', 'tour', 'track' ) as $key ) {
		$out[ $key ] = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( isset( $input[ $key ] ) ? $input[ $key ] : '' ) );
	}

	$rooms_raw = (string) ( isset( $input['rooms'] ) ? $input['rooms'] : '' );
	$rooms     = array();
	foreach ( preg_split( '/[\r\n,]+/', $rooms_raw ) as $room ) {
		$room = strtolower( trim( preg_replace( '/[^A-Za-z0-9_]/', '', (string) $room ) ) );
		if ( '' !== $room && ! in_array( $room, $rooms, true ) ) {
			$rooms[] = $room;
		}
	}
	$out['rooms'] = implode( "\n", $rooms );

	$mins = array( 'small_w' => 240, 'small_h' => 160, 'large_w' => 320, 'large_h' => 180 );
	foreach ( array( 'small_w', 'small_h', 'large_w', 'large_h' ) as $key ) {
		$value = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : 0;
		$out[ $key ] = ( $value >= $mins[ $key ] ) ? $value : cbcs_default_settings()[ $key ];
	}

	return $out;
}

function cbcs_api_url( $room ) {
	$base = trailingslashit( apply_filters( 'cbcs_api_base', 'https://chaturbate.com/api/chatvideocontext/' ) );
	return $base . rawurlencode( $room ) . '/';
}

function cbcs_http_args() {
	return array(
		'timeout' => 5,
		'headers' => array(
			'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
			'Accept'     => 'application/json, text/plain, */*',
		),
	);
}

function cbcs_fetch_room_status( $room ) {
	$response = wp_remote_get( cbcs_api_url( $room ), cbcs_http_args() );

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return 'error';
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) || empty( $body['room_status'] ) ) {
		return 'error';
	}

	return ( 'public' === $body['room_status'] ) ? 'live' : 'offline';
}

function cbcs_probe_room( $room ) {
	$key    = 'cbcs_status_' . md5( $room );
	$cached = get_transient( $key );

	$result = array(
		'room'         => $room,
		'cached'       => ( false === $cached ) ? 'none' : (string) $cached,
		'http_code'    => '',
		'status'       => 'error',
		'room_status'  => '',
		'snippet'      => '',
		'url'          => cbcs_api_url( $room ),
	);

	$response = wp_remote_get( $result['url'], cbcs_http_args() );

	if ( is_wp_error( $response ) ) {
		$result['snippet'] = 'WP_Error: ' . $response->get_error_message();
		return $result;
	}

	$result['http_code'] = (string) (int) wp_remote_retrieve_response_code( $response );
	$body                = wp_remote_retrieve_body( $response );
	$result['snippet']   = trim( substr( (string) $body, 0, 160 ) );

	$decoded = json_decode( (string) $body, true );
	if ( is_array( $decoded ) && ! empty( $decoded['room_status'] ) ) {
		$result['room_status'] = (string) $decoded['room_status'];
		$result['status']      = ( 'public' === $decoded['room_status'] ) ? 'live' : 'offline';
	}

	return $result;
}

function cbcs_room_status( $room ) {
	$key    = 'cbcs_status_' . md5( $room );
	$cached = get_transient( $key );
	if ( false !== $cached && in_array( $cached, array( 'live', 'offline', 'error' ), true ) ) {
		return $cached;
	}

	$status = cbcs_fetch_room_status( $room );
	$ttl    = ( 'error' === $status )
		? (int) apply_filters( 'cbcs_error_ttl', 30 )
		: (int) apply_filters( 'cbcs_status_ttl', 90 );

	set_transient( $key, $status, max( 10, $ttl ) );
	return $status;
}

function cbcs_get_live_room() {
	foreach ( cbcs_get_rooms() as $room ) {
		if ( 'live' === cbcs_room_status( $room ) ) {
			return $room;
		}
	}
	return '';
}

function cbcs_embed_url( $room ) {
	$settings = cbcs_get_settings();
	$base     = apply_filters( 'cbcs_embed_base', 'https://cbxyz.com/in/' );

	return add_query_arg(
		array(
			'tour'     => $settings['tour'],
			'campaign' => $settings['campaign'],
			'track'    => $settings['track'],
			'room'     => $room,
		),
		$base
	);
}

function cbcs_render_widget() {
	if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		return;
	}

	$settings = cbcs_get_settings();
	if ( empty( $settings['enabled'] ) ) {
		return;
	}

	$room = cbcs_get_live_room();
	if ( '' === $room ) {
		return;
	}

	wp_enqueue_style( 'cbcs', plugins_url( 'assets/camshow.css', __FILE__ ), array(), CBCS_VERSION );
	wp_enqueue_script( 'cbcs', plugins_url( 'assets/camshow.js', __FILE__ ), array(), CBCS_VERSION, true );

	$small_w = max( 240, (int) $settings['small_w'] );
	$small_h = max( 160, (int) $settings['small_h'] );
	$large_w = max( 320, (int) $settings['large_w'] );
	$large_h = max( 180, (int) $settings['large_h'] );
	$ar_inv  = $small_h > 0 ? round( $small_w / $small_h, 4 ) : 1.6154;

	$style = sprintf(
		'--cbcs-small-w:%dpx;--cbcs-small-h:%dpx;--cbcs-large-w:%dpx;--cbcs-large-h:%dpx;--cbcs-ar-inv:%s;',
		$small_w,
		$small_h,
		$large_w,
		$large_h,
		$ar_inv
	);

	$dismiss_hours = (int) apply_filters( 'cbcs_dismiss_hours', 6 );
	?>
	<div id="cbcs-widget" class="cbcs-widget" style="<?php echo esc_attr( $style ); ?>" data-room="<?php echo esc_attr( $room ); ?>" data-dismiss-hours="<?php echo esc_attr( (string) $dismiss_hours ); ?>">
		<div class="cbcs-bar">
			<span class="cbcs-live"><span class="cbcs-dot" aria-hidden="true"></span><?php esc_html_e( 'LIVE', 'wp-camshow' ); ?></span>
			<span class="cbcs-room"><?php echo esc_html( '@' . $room ); ?></span>
			<span class="cbcs-actions">
				<button type="button" class="cbcs-btn cbcs-expand" aria-expanded="false" aria-label="<?php esc_attr_e( 'Expand player', 'wp-camshow' ); ?>">⤢</button>
				<button type="button" class="cbcs-btn cbcs-close" aria-label="<?php esc_attr_e( 'Close player', 'wp-camshow' ); ?>">✕</button>
			</span>
		</div>
		<div class="cbcs-frame">
			<iframe
				src="<?php echo esc_url( cbcs_embed_url( $room ) ); ?>"
				title="<?php echo esc_attr( sprintf( __( 'Live stream: %s', 'wp-camshow' ), '@' . $room ) ); ?>"
				scrolling="no"
				allowfullscreen
			></iframe>
		</div>
	</div>
	<script>(function(){var w=document.getElementById("cbcs-widget");if(!w){return}try{var d=JSON.parse(localStorage.getItem("cbcsHide")||"null");if(d&&d.until>Date.now()&&d.room===w.getAttribute("data-room")){w.dataset.cbcsDismissed="1";w.style.display="none"}}catch(e){}})();</script>
	<?php
}
add_action( 'wp_footer', 'cbcs_render_widget', 10 );

function cbcs_cron_schedules( $schedules ) {
	$schedules['cbcs_60s'] = array(
		'interval' => 60,
		'display'  => __( 'Every minute', 'wp-camshow' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'cbcs_cron_schedules' );

function cbcs_cron_refresh_rooms() {
	$settings = cbcs_get_settings();
	if ( empty( $settings['enabled'] ) ) {
		return;
	}
	foreach ( cbcs_get_rooms() as $room ) {
		cbcs_room_status( $room );
	}
}
add_action( 'cbcs_cron_refresh', 'cbcs_cron_refresh_rooms' );

register_activation_hook(
	__FILE__,
	function () {
		if ( ! wp_next_scheduled( 'cbcs_cron_refresh' ) ) {
			wp_schedule_event( time() + 60, 'cbcs_60s', 'cbcs_cron_refresh' );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'cbcs_cron_refresh' );
	}
);

function cbcs_admin_menu() {
	add_options_page(
		__( 'Cam Show', 'wp-camshow' ),
		__( 'Cam Show', 'wp-camshow' ),
		'manage_options',
		'cbcs',
		'cbcs_render_settings_page'
	);
}
add_action( 'admin_menu', 'cbcs_admin_menu' );

function cbcs_admin_init() {
	register_setting(
		'cbcs_settings_group',
		CBCS_OPTION_KEY,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'cbcs_sanitize_settings',
			'default'           => cbcs_default_settings(),
		)
	);
}
add_action( 'admin_init', 'cbcs_admin_init' );

function cbcs_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = cbcs_get_settings();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Cam Show', 'wp-camshow' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'cbcs_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enabled', 'wp-camshow' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="cbcs_settings[enabled]" value="1" <?php checked( $settings['enabled'], 1 ); ?>>
							<?php esc_html_e( 'Show the floating player when a monitored room is live', 'wp-camshow' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cbcs-campaign"><?php esc_html_e( 'Campaign ID', 'wp-camshow' ); ?></label></th>
					<td><input id="cbcs-campaign" type="text" class="regular-text" name="cbcs_settings[campaign]" value="<?php echo esc_attr( $settings['campaign'] ); ?>" placeholder="r9k9h"></td>
				</tr>
				<tr>
					<th scope="row"><label for="cbcs-tour"><?php esc_html_e( 'Tour ID', 'wp-camshow' ); ?></label></th>
					<td><input id="cbcs-tour" type="text" class="regular-text" name="cbcs_settings[tour]" value="<?php echo esc_attr( $settings['tour'] ); ?>" placeholder="SHBY"></td>
				</tr>
				<tr>
					<th scope="row"><label for="cbcs-track"><?php esc_html_e( 'Track', 'wp-camshow' ); ?></label></th>
					<td><input id="cbcs-track" type="text" class="regular-text" name="cbcs_settings[track]" value="<?php echo esc_attr( $settings['track'] ); ?>" placeholder="embed"></td>
				</tr>
				<tr>
					<th scope="row"><label for="cbcs-rooms"><?php esc_html_e( 'Rooms', 'wp-camshow' ); ?></label></th>
					<td>
						<textarea id="cbcs-rooms" name="cbcs_settings[rooms]" rows="6" cols="40"><?php echo esc_textarea( $settings['rooms'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One Chaturbate username per line, in priority order. When several are live, the first one in this list is shown.', 'wp-camshow' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Small player size', 'wp-camshow' ); ?></th>
					<td>
						<label>W <input type="number" min="240" step="1" class="small-text" name="cbcs_settings[small_w]" value="<?php echo esc_attr( (string) $settings['small_w'] ); ?>"> px</label>
						<label style="margin-left:12px">H <input type="number" min="160" step="1" class="small-text" name="cbcs_settings[small_h]" value="<?php echo esc_attr( (string) $settings['small_h'] ); ?>"> px</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Expanded player size', 'wp-camshow' ); ?></th>
					<td>
						<label>W <input type="number" min="320" step="1" class="small-text" name="cbcs_settings[large_w]" value="<?php echo esc_attr( (string) $settings['large_w'] ); ?>"> px</label>
						<label style="margin-left:12px">H <input type="number" min="180" step="1" class="small-text" name="cbcs_settings[large_h]" value="<?php echo esc_attr( (string) $settings['large_h'] ); ?>"> px</label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Live status', 'wp-camshow' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Per-room checks from this server right now. Use this to confirm whether the chaturbate.com API is reachable from your web host (some datacenters get 403). Cached values come from the 90s transient; the probe always makes a fresh request.', 'wp-camshow' ); ?>
		</p>

		<?php
		$rooms_list  = cbcs_get_rooms();
		$next_cron  = wp_next_scheduled( 'cbcs_cron_refresh' );
		?>
		<p>
			<strong><?php esc_html_e( 'Cron refresher:', 'wp-camshow' ); ?></strong>
			<?php
			if ( $next_cron ) {
				echo esc_html( human_time_diff( time(), $next_cron ) . ' ' . __( 'from now', 'wp-camshow' ) );
			} else {
				echo '<span style="color:#c00">' . esc_html__( 'Not scheduled', 'wp-camshow' ) . '</span>';
			}
			?>
		</p>

		<?php if ( empty( $rooms_list ) ) : ?>
			<p><em><?php esc_html_e( 'Add rooms above to see live checks.', 'wp-camshow' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:980px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Room', 'wp-camshow' ); ?></th>
						<th><?php esc_html_e( 'Cached', 'wp-camshow' ); ?></th>
						<th><?php esc_html_e( 'Fresh probe', 'wp-camshow' ); ?></th>
						<th><?php esc_html_e( 'HTTP', 'wp-camshow' ); ?></th>
						<th><?php esc_html_e( 'room_status', 'wp-camshow' ); ?></th>
						<th><?php esc_html_e( 'Body snippet', 'wp-camshow' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rooms_list as $room ) :
						$p = cbcs_probe_room( $room );
						$row_color = '';
						if ( 'live' === $p['status'] ) {
							$row_color = 'background:#e8f6ee';
						} elseif ( 'offline' === $p['status'] ) {
							$row_color = 'background:#fff';
						} else {
							$row_color = 'background:#fdecea';
						}
						?>
						<tr style="<?php echo esc_attr( $row_color ); ?>">
							<td><code>@<?php echo esc_html( $room ); ?></code></td>
							<td><?php echo esc_html( $p['cached'] ); ?></td>
							<td><strong><?php echo esc_html( $p['status'] ); ?></strong></td>
							<td><?php echo esc_html( $p['http_code'] ); ?></td>
							<td><?php echo esc_html( $p['room_status'] ); ?></td>
							<td style="font-family:monospace;font-size:11px;word-break:break-all;max-width:420px"><?php echo esc_html( $p['snippet'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description" style="max-width:980px">
				<?php esc_html_e( 'If "Fresh probe" shows "error" with HTTP 403 / 503 / a Cloudflare challenge page, your web server is being blocked by Chaturbate. In that case the plugin cannot detect live status from this server. Add `add_filter( "cbcs_api_base", fn() => "https://your-affiliate-whitelist-host/api/chatvideocontext/" );` (or use the alternative JSON endpoint via `cbcs_api_base`) via a small mu-plugin or your theme. A page-cache plugin (LiteSpeed/WP Super Cache/Cloudflare APO) may also be serving a cached page that omits the player; purge the cache after going live.', 'wp-camshow' ); ?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}
