<?php
/**
 * Does setup for Publicize in Gutenberg
 *
 * Enqueues UI resources and completes REST setup for enabling
 * Publicize in Gutenberg.
 *
 * @package Jetpack
 * @subpackage Publicize
 * @since 5.9.1
 */

/**
 * Class to set up Gutenberg editor support.
 *
 * @since 5.9.1
 */
class Jetpack_Publicize_Gutenberg {
	/**
	 * Constructor for Jetpack_Publicize_Gutenberg
	 *
	 * Set up hooks to extend legacy Publicize behavior.
	 *
	 * @since 5.9.1
	 */
	public function __construct() {
		// Do edit page specific setup.
		add_action( 'admin_enqueue_scripts', array( $this, 'post_page_enqueue' ) );

		add_action( 'rest_api_init', array( $this, 'add_publicize_rest_fields' ) );

		// Connection list callback.
		add_action( 'wp_ajax_get_publicize_connections', array( $this, 'get_publicize_connections' ) );

		// Set up publicize flags right before post is actually published.
		add_filter( 'rest_pre_insert_post', array( $this, 'process_publicize_from_rest' ), 10, 2 );
	}

	/**
	 * Retrieve current list of connected social accounts.
	 *
	 * Gets current list of connected accounts and send them as
	 * JSON encoded data.
	 *
	 * @since 5.9.1
	 *
	 * @global Publicize_UI $publicize_ui UI handler for instance for Publicize.
	 */
	public function get_publicize_connections() {
		global $publicize_ui;
		if ( isset($_POST['postId'] ) ) {
			$post_id = $_POST['postId'];
		} else {
			$post_id = null;
		}
		wp_send_json_success( $publicize_ui->get_filtered_connection_data( $post_id ) );
	}

	/**
	 * Add rest field to 'post' for Publicize support
	 *
	 * Sets up 'publicize' schema to submit publicize sharing title
	 * and individual connection sharing enables/disables. This schema
	 * is registered with the 'post' endpoint REST endpoint so publicize
	 * options can be saved when a post is published.
	 *
	 * @since 5.9.1
	 */
	public function add_publicize_rest_fields() {
		// Schema for wpas.submit[] field.
		$publicize_submit_schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => esc_html__( 'Publicize data for publishing post', 'jetpack' ),
			'type'       => 'object',
			'properties' => array(
				'connections' => array(
					'description' => esc_html__( 'List of connections to be shared to (or not).', 'jetpack' ),
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'unique_id'    => array(
								'description' => esc_html__( 'Unique identifier string for a connection', 'jetpack' ),
								'type'        => 'string',
							),
							'should_share' => array(
								'description' => esc_html__( 'Whether or not connection should be shared to.', 'jetpack' ),
								'type'        => 'boolean',
							),

						),
					),
				),
				'title'       => array(
					'description' => esc_html__( 'Optional title to share post with.', 'jetpack' ),
					'type'        => 'string',
				),
			),
		);

		// Registering the publicize field with post endpoint.
		register_rest_field(
			'post',
			'publicize',
			array(
				'get_callback'    => null,
				'update_callback' => null, // Data read/processed before publishing post by 'rest_pre_insert_post' filter.
				'schema'          => $publicize_submit_schema,
			)
		);
	}

	/**
	 * Set up Publicize meta fields for publishing post.
	 *
	 * Process 'publicize' REST field to setup Publicize for publishing
	 * post. Sets post meta keys to enable/disable each connection for
	 * the post and sets publicize title meta key if a title message
	 * is provided.
	 *
	 * @since 5.9.1
	 *
	 * @param stdClass        $new_post_obj Updated post object about to be inserted view REST endpoint.
	 * @param WP_REST_Request $request      Request object, possibly containing 'publicize' field {@see add_publicize_rest_fields()}.
	 *
	 * @return WP_Post Returns the original $new_post value unchanged.
	 */
	public function process_publicize_from_rest( $new_post_obj, $request ) {
		global $publicize;
		if ( property_exists( $new_post_obj, 'ID' ) ) {
			$post = get_post( $new_post_obj->ID );
		} else {
			return $new_post_obj;
		}

		// If 'publicize' field has been set from editor and post is about to be published.
		if ( isset( $request['publicize'] )
				&& ( property_exists( $new_post_obj, 'post_status' ) && ( 'publish' === $new_post_obj->post_status ) )
				&& ( 'publish' !== $post->post_status ) ) {

			$publicize_field = $request['publicize'];

			if ( empty( $publicize_field['title'] ) ) {
				delete_post_meta( $post->ID, $publicize->POST_MESS );
			} else {
				update_post_meta( $post->ID, $publicize->POST_MESS, trim( stripslashes( $publicize_field['title'] ) ) );
			}
			if ( isset( $publicize_field['connections'] ) ) {
				foreach ( (array) $publicize->get_services( 'connected' ) as $service_name => $connections ) {
					foreach ( $connections as $connection ) {
						if ( ! empty( $connection->unique_id ) ) {
							$unique_id = $connection->unique_id;
						} elseif ( ! empty( $connection['connection_data']['token_id'] ) ) {
							$unique_id = $connection['connection_data']['token_id'];
						}

						if ( $this->connection_should_share( $publicize_field['connections'], $unique_id ) ) {
							// Delete skip flag meta key.
							delete_post_meta( $post->ID, $publicize->POST_SKIP . $unique_id );
						} else {
							// Flag connection to be skipped for this post.
							update_post_meta( $post->ID, $publicize->POST_SKIP . $unique_id, 1 );
						}
					}
				}
			}
		}
		// Just pass post object through.
		return $new_post_obj;
	}

	/**
	 * Checks if a connection should be shared to.
	 *
	 * Checks $connection_id against $connections_array to see if the connection associated
	 * with $connection_id should be shared to. Will return true if $connection_id is in the
	 * array and 'should_share' property is set to true, and will default to false otherwise.
	 *
	 * @since 5.9.1
	 *
	 * @param array  $connections_array 'connections' from 'publicize' REST field {@see add_publicize_rest_fields()}.
	 * @param string $connection_id     Connection identifier string that is unique for each connection.
	 * @return boolean True if connection should be shared to, false otherwise.
	 */
	private function connection_should_share( $connections_array, $connection_id ) {
		foreach ( $connections_array as $connection ) {
			if ( isset( $connection['unique_id'] )
				&& ( $connection['unique_id'] === $connection_id )
				&& $connection['should_share'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Enqueue scripts when they are needed for the edit page
	 *
	 * Enqueues necessary scripts for edit page for Gutenberg
	 * editor only.
	 *
	 * @since 5.9.1
	 *
	 * @global Publicize_UI $publicize_ui UI handler for instance for Publicize.
	 *
	 * @param string $hook Current page url.
	 */
	public function post_page_enqueue( $hook ) {
		global $publicize_ui;

		if ( ( 'post-new.php' === $hook || 'post.php' === $hook ) && ! isset( $_GET['classic-editor'] ) ) { // Input var okay.
			wp_enqueue_style( 'social-logos', null, array( 'genericons' ) );

			if ( is_rtl() ) {
				wp_enqueue_style(
					'publicize',
					plugins_url( 'assets/rtl/publicize-rtl.css', __FILE__ ),
					array( 'dashicons' ),
					'20120925'
				);
			} else {
				wp_enqueue_style(
					'publicize',
					plugins_url( 'assets/publicize.css', __FILE__ ),
					array( 'dashicons' ),
					'20120925'
				);
			}

			wp_enqueue_script(
				'modules-publicize-gutenberg_js',
				plugins_url( '_inc/build/modules-publicize-gutenberg.js', JETPACK__PLUGIN_FILE ),
				array(
					'jquery',
					'wp-edit-post',
					'wp-data',
					'wp-components',
				),
				false,
				true
			);

			wp_localize_script( 'modules-publicize-gutenberg_js', 'gutenberg_publicize_setup',
				array(
					'connectionList' => wp_json_encode( $publicize_ui->get_filtered_connection_data() ),
					'allServices'    => wp_json_encode( $publicize_ui->get_available_service_data() ),
				)
			);

		}
	}
}
