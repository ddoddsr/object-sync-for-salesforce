<?php
/**
 * Class file for the Object_Sync_Sf_Mapping class.
 *
 * @file
 */

if ( ! class_exists( 'Object_Sync_Salesforce' ) ) {
	die();
}

/**
 * Map objects and records between WordPress and Salesforce
 */
class Object_Sync_Sf_Mapping {

	protected $wpdb;
	protected $version;
	protected $slug;
	protected $logging;
	protected $option_prefix;

	protected $fieldmap_table;
	protected $object_map_table;

	public $sync_off;
	public $sync_wordpress_create;
	public $sync_wordpress_update;
	public $sync_wordpress_delete;
	public $sync_sf_create;
	public $sync_sf_update;
	public $sync_sf_delete;
	public $wordpress_events;
	public $salesforce_events;

	public $direction_wordpress_sf;
	public $direction_sf_wordpress;
	public $direction_sync;

	public $direction_wordpress;
	public $direction_salesforce;

	public $salesforce_default_record_type;

	public $array_delimiter;
	public $array_types_from_salesforce;
	public $date_types_from_salesforce;
	public $int_types_from_salesforce;

	public $name_length;

	public $status_success;
	public $status_error;

	public $debug;

	/**
	 * Constructor which sets up links between the systems
	 *
	 * @param object $wpdb A WPDB object.
	 * @param string $version The plugin version.
	 * @param string $slug The plugin slug.
	 * @param object $logging Object_Sync_Sf_Logging.
	 * @param string $option_prefix The plugin option prefix
	 * @throws \Exception
	 */
	public function __construct( $wpdb, $version, $slug, $logging, $option_prefix = '' ) {
		$this->wpdb          = $wpdb;
		$this->version       = $version;
		$this->slug          = $slug;
		$this->option_prefix = isset( $option_prefix ) ? $option_prefix : 'object_sync_for_salesforce_';
		$this->logging       = $logging;

		$this->fieldmap_table   = $this->wpdb->prefix . 'object_sync_sf_field_map';
		$this->object_map_table = $this->wpdb->prefix . 'object_sync_sf_object_map';

		/*
		 * These parameters are how we define when syncing should occur on each field map.
		 * They get used in the admin settings, as well as the push/pull methods to see if something should happen.
		 * It is unclear why the Drupal module used bit flags, but it seems reasonable to keep the convention.
		*/
		$this->sync_off              = 0x0000;
		$this->sync_wordpress_create = 0x0001;
		$this->sync_wordpress_update = 0x0002;
		$this->sync_wordpress_delete = 0x0004;
		$this->sync_sf_create        = 0x0008;
		$this->sync_sf_update        = 0x0010;
		$this->sync_sf_delete        = 0x0020;

		// Define which events are initialized by which system.
		$this->wordpress_events  = array( $this->sync_wordpress_create, $this->sync_wordpress_update, $this->sync_wordpress_delete );
		$this->salesforce_events = array( $this->sync_sf_create, $this->sync_sf_update, $this->sync_sf_delete );

		// Constants for the directions to map things.
		$this->direction_wordpress_sf = 'wp_sf';
		$this->direction_sf_wordpress = 'sf_wp';
		$this->direction_sync         = 'sync';

		$this->direction_wordpress  = array( $this->direction_wordpress_sf, $this->direction_sync );
		$this->direction_salesforce = array( $this->direction_sf_wordpress, $this->direction_sync );

		// This is used when we map a record with default or Master.
		$this->salesforce_default_record_type = 'default';

		// Salesforce has multipicklists and they have a delimiter.
		$this->array_delimiter = ';';
		// What data types in Salesforce should be an array?
		$this->array_types_from_salesforce = array( 'multipicklist' );
		// What data types in Salesforce should be a date field?
		$this->date_types_from_salesforce = array( 'date', 'datetime' );
		// What data types in Salesforce should be an integer?
		$this->int_types_from_salesforce = array( 'integer', 'boolean' );

		// Max length for a mapping field.
		$this->name_length = 128;

		// Statuses for object sync.
		$this->status_success = 1;
		$this->status_error   = 0;

		$this->debug = get_option( $this->option_prefix . 'debug_mode', false );

	}

	/**
	 * Create a fieldmap row between a WordPress and Salesforce object
	 *
	 * @param array $posted The results of $_POST.
	 * @param array $wordpress_fields The fields for the WordPress side of the mapping.
	 * @param array $salesforce_fields The fields for the Salesforce side of the mapping.
	 * @throws \Exception
	 */
	public function create_fieldmap( $posted = array(), $wordpress_fields = array(), $salesforce_fields = array() ) {
		$data = $this->setup_fieldmap_data( $posted, $wordpress_fields, $salesforce_fields );
		if ( version_compare( $this->version, '1.2.5', '>=' ) ) {
			$data['version'] = $this->version;
		}
		$insert = $this->wpdb->insert( $this->fieldmap_table, $data );
		if ( 1 === $insert ) {
			return $this->wpdb->insert_id;
		} else {
			return false;
		}
	}

	/**
	 * Get one or more fieldmap rows between a WordPress and Salesforce object
	 *
	 * @param int   $id The ID of a desired mapping.
	 * @param array $conditions Array of key=>value to match the mapping by.
	 * @param bool  $reset Unused parameter.
	 * @return array $map a single mapping or $mappings, an array of mappings.
	 * @throws \Exception
	 */
	public function get_fieldmaps( $id = null, $conditions = array(), $reset = false ) {
		$table = $this->fieldmap_table;
		if ( null !== $id ) { // get one fieldmap.
			$map                                    = $this->wpdb->get_row( 'SELECT * FROM ' . $table . ' WHERE id = ' . $id, ARRAY_A );
			$map['salesforce_record_types_allowed'] = maybe_unserialize( $map['salesforce_record_types_allowed'] );

			$map['fields']        = maybe_unserialize( $map['fields'] );
			$map['sync_triggers'] = maybe_unserialize( $map['sync_triggers'] );
			return $map;
		} elseif ( ! empty( $conditions ) ) { // get multiple but with a limitation.
			$mappings    = array();
			$record_type = '';

			// Assemble the SQL.
			if ( ! empty( $conditions ) ) {
				$where = ' WHERE ';
				$i     = 0;
				foreach ( $conditions as $key => $value ) {
					if ( 'salesforce_record_type' === $key ) {
						$record_type = sanitize_text_field( $value );
					} else {
						$i++;
						if ( $i > 1 ) {
							$where .= ' AND ';
						}
						$where .= '`' . $key . '` = "' . $value . '"';
					}
				}
			} else {
				$where = '';
			}

			$mappings = $this->wpdb->get_results( 'SELECT * FROM ' . $table . $where . ' ORDER BY `weight`', ARRAY_A );

			if ( ! empty( $mappings ) ) {
				$mappings = $this->prepare_fieldmap_data( $mappings, $record_type );
			}

			return $mappings;

		} else { // get all of the mappings. ALL THE MAPPINGS.

			// if the version is greater than or equal to 1.5.0, the fieldmap table has a pull_to_drafts column
			if ( version_compare( $this->version, '1.5.0', '>=' ) ) {
				$mappings = $this->wpdb->get_results( "SELECT `id`, `label`, `wordpress_object`, `salesforce_object`, `salesforce_record_types_allowed`, `salesforce_record_type_default`, `fields`, `pull_trigger_field`, `sync_triggers`, `push_async`, `push_drafts`, `pull_to_drafts`, `weight`, `version` FROM $table", ARRAY_A ); // WPCS: unprepared SQL OK.
			} elseif ( version_compare( $this->version, '1.2.5', '>=' ) ) {
				// if the version is greater than or equal to 1.2.5, the fieldmap table has a version column
				$mappings = $this->wpdb->get_results( "SELECT `id`, `label`, `wordpress_object`, `salesforce_object`, `salesforce_record_types_allowed`, `salesforce_record_type_default`, `fields`, `pull_trigger_field`, `sync_triggers`, `push_async`, `push_drafts`, `weight`, `version` FROM $table", ARRAY_A ); // WPCS: unprepared SQL OK.
			} else {
				$mappings = $this->wpdb->get_results( "SELECT `id`, `label`, `wordpress_object`, `salesforce_object`, `salesforce_record_types_allowed`, `salesforce_record_type_default`, `fields`, `pull_trigger_field`, `sync_triggers`, `push_async`, `push_drafts`, `weight` FROM $table", ARRAY_A ); // WPCS: unprepared SQL OK.
			}

			if ( ! empty( $mappings ) ) {
				$mappings = $this->prepare_fieldmap_data( $mappings );
			}

			return $mappings;
		} // End if().
	}

	/**
	 * For a mapping, get the fieldmaps associated with it.
	 *
	 * @param Array $mapping The mapping for which we are getting the fieldmaps.
	 * @param Array $directions The direction of the mapping: from WP to SF or vice-versa.
	 * @see Object_Sync_Sf_Salesforce_Pull::get_pull_query()
	 *
	 * @return Array of mapped fields
	 */
	public function get_mapped_fields( $mapping, $directions = array() ) {
		$mapped_fields = array();
		foreach ( $mapping['fields'] as $fields ) {
			if ( empty( $directions ) || in_array( $fields['direction'], $directions, true ) ) {

				// in version 1.2.0, we provided an option for API name vs label for Salesforce fields
				if ( version_compare( $this->version, '1.2.0', '>=' ) && isset( $fields['salesforce_field']['name'] ) ) {
					$array_key = 'name';
				} else {
					$array_key = 'label';
				}

				// Some field map types (Relation) store a collection of SF objects.
				if ( is_array( $fields['salesforce_field'] ) && ! isset( $fields['salesforce_field'][ $array_key ] ) ) {
					foreach ( $fields['salesforce_field'] as $sf_field ) {
						$mapped_fields[ $sf_field[ $array_key ] ] = $sf_field[ $array_key ];
					}
				} else { // The rest are just a name/value pair.
					$mapped_fields[ $fields['salesforce_field'][ $array_key ] ] = $fields['salesforce_field'][ $array_key ];
				}
			}
		}

		if ( ! empty( $this->get_mapped_record_types ) ) {
			$mapped_fields['RecordTypeId'] = 'RecordTypeId';
		}

		return $mapped_fields;
	}

	/**
	 * Get the mapped record types for a given mapping.
	 *
	 * @param Array $mapping A mapping from which we wish to estract the record type.
	 * @return Array of mappings. Empty if the mapping's record type is default, else full of the record types.
	 */
	public function get_mapped_record_types( $mapping ) {
		return $mapping['salesforce_record_type_default'] === $this->salesforce_default_record_type ? array() : array_filter( maybe_unserialize( $mapping['salesforce_record_types_allowed'] ) );
	}

	/**
	 * Update a fieldmap row between a WordPress and Salesforce object
	 *
	 * @param array $posted It's $_POST.
	 * @param array $wordpress_fields The fields for the WordPress side of the mapping.
	 * @param array $salesforce_fields The fields for the Salesforce side of the mapping.
	 * @param int   $id The ID of the mapping.
	 * @return boolean
	 * @throws \Exception
	 */
	public function update_fieldmap( $posted = array(), $wordpress_fields = array(), $salesforce_fields = array(), $id = '' ) {
		$data = $this->setup_fieldmap_data( $posted, $wordpress_fields, $salesforce_fields );
		if ( version_compare( $this->version, '1.2.5', '>=' ) && ! isset( $data['updated'] ) ) {
			$data['version'] = $this->version;
		}
		$update = $this->wpdb->update(
			$this->fieldmap_table,
			$data,
			array(
				'id' => $id,
			)
		);
		if ( false === $update ) {
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Setup fieldmap data
	 * Sets up the database entry for mapping the object types between Salesforce and WordPress
	 *
	 * @param array $posted It's $_POST.
	 * @param array $wordpress_fields The fields for the WordPress side of the mapping.
	 * @param array $salesforce_fields The fields for the Salesforce side of the mapping.
	 * @return array $data the fieldmap's data for the database
	 */
	private function setup_fieldmap_data( $posted = array(), $wordpress_fields = array(), $salesforce_fields = array() ) {
		$data = array(
			'label'             => $posted['label'],
			'name'              => sanitize_title( $posted['label'] ),
			'salesforce_object' => $posted['salesforce_object'],
			'wordpress_object'  => $posted['wordpress_object'],
		);
		if ( isset( $posted['wordpress_field'] ) && is_array( $posted['wordpress_field'] ) && isset( $posted['salesforce_field'] ) && is_array( $posted['salesforce_field'] ) ) {
			$setup['fields'] = array();
			foreach ( $posted['wordpress_field'] as $key => $value ) {
				$method_key = array_search( $value, array_column( $wordpress_fields, 'key' ), true );
				if ( ! isset( $posted['direction'][ $key ] ) ) {
					$posted['direction'][ $key ] = 'sync';
				}
				if ( ! isset( $posted['is_prematch'][ $key ] ) ) {
					$posted['is_prematch'][ $key ] = false;
				}
				if ( ! isset( $posted['is_key'][ $key ] ) ) {
					$posted['is_key'][ $key ] = false;
				}
				if ( ! isset( $posted['is_delete'][ $key ] ) ) {
					$posted['is_delete'][ $key ] = false;
				}
				if ( false === $posted['is_delete'][ $key ] ) {
					// I think it's good to over-mention that updateable is really how the Salesforce api spells it.
					$updateable_key = array_search( $posted['salesforce_field'][ $key ], array_column( $salesforce_fields, 'name' ), true );

					$salesforce_field_attributes = array();
					foreach ( $salesforce_fields[ $updateable_key ] as $sf_key => $sf_value ) {
						if ( isset( $sf_value ) && ! is_array( $sf_value ) ) {
							$salesforce_field_attributes[ $sf_key ] = esc_attr( $sf_value );
						} elseif ( ! empty( $sf_value ) && is_array( $sf_value ) ) {
							$salesforce_field_attributes[ $sf_key ] = maybe_unserialize( $sf_value );
						} else {
							$salesforce_field_attributes[ $sf_key ] = '';
						}
					}

					$setup['fields'][ $key ] = array(
						'wordpress_field'  => array(
							'label'   => sanitize_text_field( $posted['wordpress_field'][ $key ] ),
							'methods' => maybe_unserialize( $wordpress_fields[ $method_key ]['methods'] ),
							'type'    => isset( $wordpress_fields[ $method_key ]['type'] ) ? sanitize_text_field( $wordpress_fields[ $method_key ]['type'] ) : 'text',
						),
						'salesforce_field' => $salesforce_field_attributes,
						'is_prematch'      => sanitize_text_field( $posted['is_prematch'][ $key ] ),
						'is_key'           => sanitize_text_field( $posted['is_key'][ $key ] ),
						'direction'        => sanitize_text_field( $posted['direction'][ $key ] ),
						'is_delete'        => sanitize_text_field( $posted['is_delete'][ $key ] ),
					);

					// If the WordPress key or the Salesforce key are blank, remove this incomplete mapping.
					// This prevents https://github.com/MinnPost/object-sync-for-salesforce/issues/82 .
					if (
						empty( $setup['fields'][ $key ]['wordpress_field']['label'] )
						||
						empty( $setup['fields'][ $key ]['salesforce_field']['name'] )
					) {
						unset( $setup['fields'][ $key ] );
					}
				}
			} // End foreach() on WordPress fields.
			$data['fields'] = maybe_serialize( $setup['fields'] );
		} elseif ( isset( $posted['fields'] ) && is_array( $posted['fields'] ) ) {
			// if $posted['fields'] is already set, use that
			$data['fields'] = maybe_serialize( $posted['fields'] );
		} // End if() WordPress fields are present.

		if ( isset( $posted['salesforce_record_types_allowed'] ) ) {
			$data['salesforce_record_types_allowed'] = maybe_serialize( $posted['salesforce_record_types_allowed'] );
		} else {
			$data['salesforce_record_types_allowed'] = maybe_serialize(
				array(
					$this->salesforce_default_record_type => $this->salesforce_default_record_type,
				)
			);
		}
		if ( isset( $posted['salesforce_record_type_default'] ) ) {
			$data['salesforce_record_type_default'] = $posted['salesforce_record_type_default'];
		} else {
			$data['salesforce_record_type_default'] = maybe_serialize( $this->salesforce_default_record_type );
		}
		if ( isset( $posted['pull_trigger_field'] ) ) {
			$data['pull_trigger_field'] = $posted['pull_trigger_field'];
		}
		if ( isset( $posted['sync_triggers'] ) && is_array( $posted['sync_triggers'] ) ) {
			$setup['sync_triggers'] = array();
			foreach ( $posted['sync_triggers'] as $key => $value ) {
				$setup['sync_triggers'][ $key ] = esc_html( $posted['sync_triggers'][ $key ] );
			}
		} else {
			$setup['sync_triggers'] = array();
		}
		$data['sync_triggers'] = maybe_serialize( $setup['sync_triggers'] );
		if ( isset( $posted['pull_trigger_field'] ) ) {
			$data['pull_trigger_field'] = $posted['pull_trigger_field'];
		}
		$data['push_async']     = isset( $posted['push_async'] ) ? $posted['push_async'] : '';
		$data['push_drafts']    = isset( $posted['push_drafts'] ) ? $posted['push_drafts'] : '';
		$data['pull_to_drafts'] = isset( $posted['pull_to_drafts'] ) ? $posted['pull_to_drafts'] : '';
		$data['weight']         = isset( $posted['weight'] ) ? $posted['weight'] : '';
		return $data;
	}

	/**
	 * Delete a fieldmap row between a WordPress and Salesforce object
	 *
	 * @param int $id The ID of a field mapping.
	 * @return Boolean
	 * @throws \Exception
	 */
	public function delete_fieldmap( $id = '' ) {
		$data   = array(
			'id' => $id,
		);
		$delete = $this->wpdb->delete( $this->fieldmap_table, $data );
		if ( 1 === $delete ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Create an object map row between a WordPress and Salesforce object
	 *
	 * @param array $posted It's $_POST.
	 * @return false|Int of field mapping between WordPress and Salesforce objects
	 * @throws \Exception
	 */
	public function create_object_map( $posted = array() ) {
		$data            = $this->setup_object_map_data( $posted );
		$data['created'] = current_time( 'mysql' );
		// Check to see if we don't know the salesforce id and it is not a temporary id, or if this is pending.
		// If it is using a temporary id, the map will get updated after it finishes running; it won't call this method unless there's an error, which we should log.
		if ( substr( $data['salesforce_id'], 0, 7 ) !== 'tmp_sf_' || ( isset( $data['action'] ) && 'pending' === $data['action'] ) ) {
			unset( $data['action'] );
			$insert = $this->wpdb->insert( $this->object_map_table, $data );
		} else {
			$status = 'error';
			if ( isset( $this->logging ) ) {
				$logging = $this->logging;
			} elseif ( class_exists( 'Object_Sync_Sf_Logging' ) ) {
				$logging = new Object_Sync_Sf_Logging( $this->wpdb, $this->version );
			}
			$logging->setup(
				sprintf(
					// translators: %1$s is the name of a WordPress object. %2$s is the id of that object.
					esc_html__( 'Error Mapping: error caused by trying to map the WordPress %1$s with ID of %2$s to Salesforce ID starting with "tmp_sf_", which is invalid.', 'object-sync-for-salesforce' ),
					esc_attr( $data['wordpress_object'] ),
					absint( $data['wordpress_id'] )
				),
				'',
				0,
				0,
				$status
			);
			return false;
		}
		if ( 1 === $insert ) {
			return $this->wpdb->insert_id;
		} elseif ( false !== strpos( $this->wpdb->last_error, 'Duplicate entry' ) ) {
			// this error should never happen now, I think. But let's watch and see.
			$mapping = $this->load_all_by_salesforce( $data['salesforce_id'] )[0];
			$id      = $mapping['id'];
			$status  = 'error';
			if ( isset( $this->logging ) ) {
				$logging = $this->logging;
			} elseif ( class_exists( 'Object_Sync_Sf_Logging' ) ) {
				$logging = new Object_Sync_Sf_Logging( $this->wpdb, $this->version );
			}
			$logging->setup(
				sprintf(
					// translators: %1$s is the status word "Error". %1$s is the Id of a Salesforce object. %2$s is the ID of a mapping object.
					esc_html__( 'Error: Mapping: there is already a WordPress object mapped to the Salesforce object %1$s and the mapping object ID is %2$s', 'object-sync-for-salesforce' ),
					esc_attr( $data['salesforce_id'] ),
					absint( $id )
				),
				print_r( $mapping, true ), // log whatever we have for the mapping object, so print the array
				0,
				0,
				$status
			);
			return $id;
		} else {
			return false;
		}
	}

	/**
	 * Get all object map rows between WordPress and Salesforce objects.
	 *
	 * This replaces previous functionality that would return a single object map if there was only one, rather than a multi-dimensional array.
	 *
	 * @param array $conditions Limitations on the SQL query for object mapping rows.
	 * @param bool $reset Unused parameter.
	 * @return $mappings
	 */
	public function get_all_object_maps( $conditions = array(), $reset = false ) {
		$table = $this->object_map_table;
		$order = ' ORDER BY object_updated, created';
		if ( ! empty( $conditions ) ) { // get multiple but with a limitation.
			$mappings = array();

			if ( ! empty( $conditions ) ) {
				$where = ' WHERE ';
				$i     = 0;
				foreach ( $conditions as $key => $value ) {
					$i++;
					if ( $i > 1 ) {
						$where .= ' AND ';
					}
					$where .= '`' . $key . '` = "' . $value . '"';
				}
			} else {
				$where = '';
			}

			$mappings = $this->wpdb->get_results( 'SELECT * FROM ' . $table . $where . $order, ARRAY_A );
		} else { // get all of the mappings. ALL THE MAPPINGS.
			$mappings = $this->wpdb->get_results( 'SELECT * FROM ' . $table . $order, ARRAY_A );
		}

		return $mappings;

	}

	/**
	 * Get one or more object map rows between WordPress and Salesforce objects
	 *
	 * @deprecated since 1.8.0
	 * @param array $conditions Limitations on the SQL query for object mapping rows.
	 * @param bool  $reset Unused parameter.
	 * @return array $map or $mappings
	 * @throws \Exception
	 */
	public function get_object_maps( $conditions = array(), $reset = false ) {
		$table = $this->object_map_table;
		$order = ' ORDER BY object_updated, created';
		if ( ! empty( $conditions ) ) { // get multiple but with a limitation.
			$mappings = array();

			if ( ! empty( $conditions ) ) {
				$where = ' WHERE ';
				$i     = 0;
				foreach ( $conditions as $key => $value ) {
					$i++;
					if ( $i > 1 ) {
						$where .= ' AND ';
					}
					$where .= '`' . $key . '` = "' . $value . '"';
				}
			} else {
				$where = '';
			}

			$mappings = $this->wpdb->get_results( 'SELECT * FROM ' . $table . $where . $order, ARRAY_A );
			if ( ! empty( $mappings ) && 1 === $this->wpdb->num_rows ) {
				$mappings = $mappings[0];
			}
		} else { // get all of the mappings. ALL THE MAPPINGS.
			$mappings = $this->wpdb->get_results( 'SELECT * FROM ' . $table . $order, ARRAY_A );
			if ( ! empty( $mappings ) && 1 === $this->wpdb->num_rows ) {
				$mappings = $mappings[0];
			}
		}

		return $mappings;

	}

	/**
	 * Update an object map row between a WordPress and Salesforce object
	 *
	 * @param array $posted It's $_POST.
	 * @param array $id The ID of the object map row.
	 * @return boolean
	 * @throws \Exception
	 */
	public function update_object_map( $posted = array(), $id = '' ) {
		$data = $this->setup_object_map_data( $posted );
		if ( ! isset( $data['object_updated'] ) ) {
			$data['object_updated'] = current_time( 'mysql' );
		}
		$update = $this->wpdb->update(
			$this->object_map_table,
			$data,
			array(
				'id' => $id,
			)
		);
		if ( false === $update ) {
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Setup the data for the object map
	 *
	 * @param array $posted It's $_POST.
	 * @return array $data Filtered array with only the keys that are in the object map database table. Strips out things from WordPress form if they're present.
	 */
	private function setup_object_map_data( $posted = array() ) {
		$allowed_fields   = $this->wpdb->get_col( "DESC {$this->object_map_table}", 0 );
		$allowed_fields[] = 'action'; // we use this in both directions even though it isn't in the database; we remove it from the array later if it is present

		$data = array_intersect_key( $posted, array_flip( $allowed_fields ) );
		return $data;
	}

	/**
	 * Delete an object map row between a WordPress and Salesforce object
	 *
	 * @param int|array $id The ID or IDs of the object map row(s).
	 * @return boolean
	 * @throws \Exception
	 */
	public function delete_object_map( $id = '' ) {
		if ( is_string( $id ) || is_int( $id ) ) {
			$data   = array(
				'id' => $id,
			);
			$delete = $this->wpdb->delete( $this->object_map_table, $data );
			if ( 1 === $delete ) {
				return true;
			} else {
				return false;
			}
		} elseif ( is_array( $id ) ) {
			$ids    = implode( ',', array_map( 'absint', $id ) );
			$delete = $this->wpdb->query( "DELETE FROM $this->object_map_table WHERE ID IN ($ids)" );
			if ( false !== $delete ) {
				return true;
			} else {
				return false;
			}
		}
	}

	/**
	 * Generate a temporary ID to store while waiting for a push or pull to complete, before the record has been assigned a new ID
	 *
	 * @param string $direction Whether this is part of a push or pull action
	 * @return string $id is a temporary string that will be replaced if the modification is successful
	 */
	public function generate_temporary_id( $direction ) {
		if ( 'push' === $direction ) {
			$prefix = 'tmp_sf_';
		} elseif ( 'pull' === $direction ) {
			$prefix = 'tmp_wp_';
		}
		$id = uniqid( $prefix, true );
		return $id;
	}

	/**
	 * Returns Salesforce object mappings for a given WordPress object.
	 *
	 * @param string $object_type Type of object to load.
	 * @param int    $object_id Unique identifier of the target object to load.
	 * @param bool   $reset Whether or not the cache should be cleared and fetch from current data.
	 *
	 * @return SalesforceMappingObject
	 *   The requested SalesforceMappingObject or FALSE if none was found.
	 */
	public function load_all_by_wordpress( $object_type, $object_id, $reset = false ) {
		$conditions = array(
			'wordpress_id'     => $object_id,
			'wordpress_object' => $object_type,
		);
		return $this->get_all_object_maps( $conditions, $reset );
	}

	/**
	 * Returns one or more Salesforce object mappings for a given WordPress object.
	 *
	 * @deprecated since 1.8.0
	 * @param string $object_type Type of object to load.
	 * @param int    $object_id Unique identifier of the target object to load.
	 * @param bool   $reset Whether or not the cache should be cleared and fetch from current data.
	 *
	 * @return SalesforceMappingObject
	 *   The requested SalesforceMappingObject or FALSE if none was found.
	 */
	public function load_by_wordpress( $object_type, $object_id, $reset = false ) {
		$conditions = array(
			'wordpress_id'     => $object_id,
			'wordpress_object' => $object_type,
		);
		return $this->get_object_maps( $conditions, $reset );
	}

	/**
	 * Returns Salesforce object mappings for a given Salesforce object.
	 *
	 * @param string $salesforce_id Type of object to load.
	 * @param bool   $reset Whether or not the cache should be cleared and fetch from current data.
	 *
	 * @return array $maps all the fieldmaps that match the Salesforce Id
	 */
	public function load_all_by_salesforce( $salesforce_id, $reset = false ) {
		$conditions = array(
			'salesforce_id' => $salesforce_id,
		);

		$maps = $this->get_all_object_maps( $conditions, $reset );

		return $maps;
	}

	/**
	 * Returns one or more Salesforce object mappings for a given Salesforce object.
	 *
	 * @deprecated since 1.8.0
	 * @param string $salesforce_id Type of object to load.
	 * @param bool   $reset Whether or not the cache should be cleared and fetch from current data.
	 *
	 * @return array $map
	 *   The most recent fieldmap
	 */
	public function load_by_salesforce( $salesforce_id, $reset = false ) {
		$conditions = array(
			'salesforce_id' => $salesforce_id,
		);

		$map = $this->get_object_maps( $conditions, $reset );

		if ( isset( $map[0] ) && is_array( $map[0] ) && count( $map ) > 1 ) {
			$status = 'notice';
			$log    = '';
			$log   .= 'Mapping: there is more than one mapped WordPress object for the Salesforce object ' . $salesforce_id . '. These WordPress IDs are: ';
			$i      = 0;
			foreach ( $map as $mapping ) {
				$i++;
				if ( isset( $mapping['wordpress_id'] ) ) {
					$log .= 'object type: ' . $mapping['wordpress_object'] . ', id: ' . $mapping['wordpress_id'];
				}
				if ( count( $map ) !== $i ) {
					$log .= '; ';
				} else {
					$log .= '.';
				}
			}
			$map = $map[0];
			// Create log entry for multiple maps.
			if ( isset( $this->logging ) ) {
				$logging = $this->logging;
			} elseif ( class_exists( 'Object_Sync_Sf_Logging' ) ) {
				$logging = new Object_Sync_Sf_Logging( $this->wpdb, $this->version );
			}
			$logging->setup(
				sprintf(
					// translators: %1$s is the Id of a Salesforce object.
					esc_html__( 'Notice: Mapping: there is more than one mapped WordPress object for the Salesforce object %2$s', 'object-sync-for-salesforce' ),
					esc_attr( $salesforce_id )
				),
				$log,
				0,
				0,
				$status
			);
		} // End if().

		return $map;
	}

	/**
	 * Map values between WordPress and Salesforce objects.
	 *
	 * @param array  $mapping Mapping object.
	 * @param array  $object WordPress or Salesforce object data.
	 * @param array  $trigger The thing that triggered this mapping.
	 * @param bool   $use_soap Flag to enforce use of the SOAP API.
	 * @param bool   $is_new Indicates whether a mapping object for this entity already exists.
	 * @param string $object_id_field optionally pass the object id field name
	 *
	 * @return array Associative array of key value pairs.
	 */
	public function map_params( $mapping, $object, $trigger, $use_soap = false, $is_new = true, $object_id_field = '' ) {

		$params = array();

		$has_missing_required_salesforce_field = false;
		foreach ( $mapping['fields'] as $fieldmap ) {

			$wordpress_haystack  = array_values( $this->wordpress_events );
			$salesforce_haystack = array_values( $this->salesforce_events );

			$fieldmap['wordpress_field']['methods'] = maybe_unserialize( $fieldmap['wordpress_field']['methods'] );

			$wordpress_field = $fieldmap['wordpress_field']['label'];

			if ( version_compare( $this->version, '1.2.0', '>=' ) && isset( $fieldmap['salesforce_field']['name'] ) ) {
				$salesforce_field = $fieldmap['salesforce_field']['name'];
				// Load the type of the Salesforce field. We can use this to handle Salesforce field value issues that come up based on what the field sends into WordPress or expects from WordPress.
				$salesforce_field_type = $fieldmap['salesforce_field']['type'];
			} else {
				$salesforce_field = $fieldmap['salesforce_field']['label'];
			}

			// A WordPress event caused this.
			if ( in_array( $trigger, array_values( $wordpress_haystack ), true ) ) {

				// Is the field in WordPress an array, if we unserialize it? Salesforce wants it to be an imploded string.
				if ( is_array( maybe_unserialize( $object[ $wordpress_field ] ) ) ) {
					$object[ $wordpress_field ] = implode( $this->array_delimiter, $object[ $wordpress_field ] );
				}

				if ( isset( $salesforce_field_type ) ) {
					// Is the Salesforce field a date, and is the WordPress value a valid date?
					// According to https://salesforce.stackexchange.com/questions/57032/date-format-with-salesforce-rest-api
					if ( in_array( $salesforce_field_type, $this->date_types_from_salesforce ) ) {
						if ( '' === $object[ $wordpress_field ] ) {
							$object[ $wordpress_field ] = null;
						} else {
							if ( false !== strtotime( $object[ $wordpress_field ] ) ) {
								$timestamp = strtotime( $object[ $wordpress_field ] );
							} else {
								$timestamp = $object[ $wordpress_field ];
							}
							if ( 'datetime' === $salesforce_field_type ) {
								$object[ $wordpress_field ] = date_i18n( 'c', $timestamp );
							} else {
								$object[ $wordpress_field ] = date_i18n( 'Y-m-d', $timestamp );
							}
						}
					}

					// Boolean SF fields only want real boolean values. NULL is also not allowed.
					if ( 'boolean' === $salesforce_field_type ) {
						$object[ $wordpress_field ] = (bool) $object[ $wordpress_field ];
					}
				}

				$params[ $salesforce_field ] = $object[ $wordpress_field ];

				// If the field is a key in Salesforce, remove it from $params to avoid upsert errors from Salesforce,
				// but still put its name in the params array so we can check for it later.
				if ( '1' === $fieldmap['is_key'] ) {
					if ( ! $use_soap ) {
						unset( $params[ $salesforce_field ] );
					}
					$params['key'] = array(
						'salesforce_field' => $salesforce_field,
						'wordpress_field'  => $wordpress_field,
						'value'            => $object[ $wordpress_field ],
					);
				}

				// If the field is a prematch in Salesforce, put its name in the params array so we can check for it later.
				if ( '1' === $fieldmap['is_prematch'] ) {
					$params['prematch'] = array(
						'salesforce_field' => $salesforce_field,
						'wordpress_field'  => $wordpress_field,
						'value'            => $object[ $wordpress_field ],
					);
				}

				// Skip fields that aren't being pushed to Salesforce.
				if ( ! in_array( $fieldmap['direction'], array_values( $this->direction_wordpress ), true ) ) {
					// The trigger is a WordPress trigger, but the fieldmap direction is not a WordPress direction.
					unset( $params[ $salesforce_field ] );
				}

				// I think it's good to over-mention that updateable is really how the Salesforce api spells it.
				// Skip fields that aren't updateable when mapping params because Salesforce will error otherwise.
				// This happens after dealing with the field types because key and prematch should still be available to the plugin, even if the values are not updateable in Salesforce.
				if ( 1 !== (int) $fieldmap['salesforce_field']['updateable'] ) {
					unset( $params[ $salesforce_field ] );
				}

				// This case means the following:
				//    this field is expected by the fieldmap
				//    Salesforce's api reports that this field is required
				//    we do not have a WordPress value for this field, or it's empty
				//    it also means the field has not been unset by prematch, updateable, key, or directional flags prior to this check.
				// When this happens, we should flag that we're missing a required Salesforce field
				if ( in_array( $salesforce_field, $params ) && false === filter_var( $fieldmap['salesforce_field']['nillable'], FILTER_VALIDATE_BOOLEAN ) && ( ! isset( $object[ $wordpress_field ] ) || '' === $object[ $wordpress_field ] ) ) {
					$has_missing_required_salesforce_field = true;
				}

				// we don't need a continue with the unset methods because there's no array being created down here
			} elseif ( in_array( $trigger, $salesforce_haystack, true ) ) {

				// A Salesforce event caused this.

				if ( isset( $salesforce_field_type ) && isset( $object[ $salesforce_field ] ) && ! is_null( $object[ $salesforce_field ] ) ) {
					// Salesforce provides multipicklist values as a delimited string. If the
					// destination field in WordPress accepts multiple values, explode the string into an array and then serialize it.
					if ( in_array( $salesforce_field_type, $this->array_types_from_salesforce ) ) {
						$object[ $salesforce_field ] = explode( $this->array_delimiter, $object[ $salesforce_field ] );
					}

					// Handle specific data types from Salesforce.
					switch ( $salesforce_field_type ) {
						case ( in_array( $salesforce_field_type, $this->date_types_from_salesforce ) ):
							$format = get_option( 'date_format', 'U' );
							if ( isset( $fieldmap['wordpress_field']['type'] ) && 'datetime' === $fieldmap['wordpress_field']['type'] ) {
								$format = 'Y-m-d H:i:s';
							}
							if ( 'tribe_events' === $mapping['wordpress_object'] && class_exists( 'Tribe__Events__Main' ) ) {
								$format = 'Y-m-d H:i:s';
							}
							if ( 'datetime' === $salesforce_field_type ) {
								// Note: the Salesforce REST API appears to always return datetimes as GMT values. We should retrieve them that way, then format them to deal with them in WordPress appropriately.
								// We should not do any converting unless it's a datetime, because if it's a date, Salesforce stores it as midnight. We don't want to convert that.
								$object[ $salesforce_field ] = get_date_from_gmt( $object[ $salesforce_field ], 'Y-m-d\TH:i:s\Z' ); // convert from GMT to local date/time based on WordPress time zone setting.
							}
							$object[ $salesforce_field ] = date_i18n( $format, strtotime( $object[ $salesforce_field ] ) );
							break;
						case ( in_array( $salesforce_field_type, $this->int_types_from_salesforce ) ):
							$object[ $salesforce_field ] = isset( $object[ $salesforce_field ] ) ? (int) $object[ $salesforce_field ] : 0;
							break;
						case 'text':
							$object[ $salesforce_field ] = (string) $object[ $salesforce_field ];
							break;
						case 'url':
							$object[ $salesforce_field ] = esc_url_raw( $object[ $salesforce_field ] );
							break;
					}
				}

				// Make an array because we need to store the methods for each field as well.
				if ( isset( $object[ $salesforce_field ] ) && '' !== $object[ $salesforce_field ] ) {
					$params[ $wordpress_field ]          = array();
					$params[ $wordpress_field ]['value'] = $object[ $salesforce_field ];
				} else {
					// If we try to save certain fields with empty values, WordPress will silently start skipping stuff. This keeps that from happening.
					continue;
				}

				// If the field is a key in Salesforce, disregard since this is caused by a Salesforce event. We're setting up data to be stored in WordPress here, and WordPress is not concerned with external key designations in Salesforce.

				// If the field is a prematch in Salesforce, put its name in the params array so we can check for it later.
				if ( '1' === $fieldmap['is_prematch'] ) {
					$params['prematch'] = array(
						'salesforce_field' => $salesforce_field,
						'wordpress_field'  => $wordpress_field,
						'value'            => $object[ $salesforce_field ],
						'method_match'     => isset( $fieldmap['wordpress_field']['methods']['match'] ) ? $fieldmap['wordpress_field']['methods']['match'] : $fieldmap['wordpress_field']['methods']['read'],
						'method_read'      => $fieldmap['wordpress_field']['methods']['read'],
						'method_create'    => $fieldmap['wordpress_field']['methods']['create'],
						'method_update'    => $fieldmap['wordpress_field']['methods']['update'],
					);
				}

				// Skip fields that aren't being pulled from Salesforce.
				if ( ! in_array( $fieldmap['direction'], array_values( $this->direction_salesforce ), true ) ) {
					// The trigger is a Salesforce trigger, but the fieldmap direction is not a Salesforce direction.
					unset( $params[ $wordpress_field ] );
					// we also need to continue here, so it doesn't create an empty array below for fields that are WordPress -> Salesforce only
					continue;
				}

				switch ( $trigger ) {
					case $this->sync_sf_create:
						$params[ $wordpress_field ]['method_modify'] = $fieldmap['wordpress_field']['methods']['create'];
						break;
					case $this->sync_sf_update:
						$params[ $wordpress_field ]['method_modify'] = $fieldmap['wordpress_field']['methods']['update'];
						break;
					case $this->sync_sf_delete:
						$params[ $wordpress_field ]['method_modify'] = $fieldmap['wordpress_field']['methods']['delete'];
						break;
				}

				$params[ $wordpress_field ]['method_read'] = $fieldmap['wordpress_field']['methods']['read'];

			} // End if().
		} // End foreach().

		if ( true === $has_missing_required_salesforce_field ) {
			update_option( $this->option_prefix . 'missing_required_data_id_' . $object[ $object_id_field ], true, false );
			return array();
		}

		return $params;

	}

	/**
	 * Prepare field map data for use
	 *
	 * @param array  $mappings Array of fieldmaps.
	 * @param string $record_type Optional Salesforce record type to see if it is allowed or not.
	 *
	 * @return array $mappings Associative array of field maps ready to use
	 */
	private function prepare_fieldmap_data( $mappings, $record_type = '' ) {

		foreach ( $mappings as $id => $mapping ) {
			$mappings[ $id ]['salesforce_record_types_allowed'] = maybe_unserialize( $mapping['salesforce_record_types_allowed'] );
			$mappings[ $id ]['fields']                          = maybe_unserialize( $mapping['fields'] );
			$mappings[ $id ]['sync_triggers']                   = maybe_unserialize( $mapping['sync_triggers'] );
			if ( '' !== $record_type && ! in_array( $record_type, $mappings[ $id ]['salesforce_record_types_allowed'], true ) ) {
				unset( $mappings[ $id ] );
			}
		}

		return $mappings;

	}

	/**
	 * Check object map table to see if there have been any failed object map create attempts
	 *
	 * @return array $errors Associative array of rows that failed to finish from either system
	 */
	public function get_failed_object_maps() {
		$table       = $this->object_map_table;
		$errors      = array();
		$push_errors = $this->wpdb->get_results( 'SELECT * FROM ' . $table . ' WHERE salesforce_id LIKE "tmp_sf_%"', ARRAY_A );
		$pull_errors = $this->wpdb->get_results( 'SELECT * FROM ' . $table . ' WHERE wordpress_id LIKE "tmp_wp_%"', ARRAY_A );
		if ( ! empty( $push_errors ) ) {
			$errors['push_errors'] = $push_errors;
		}
		if ( ! empty( $pull_errors ) ) {
			$errors['pull_errors'] = $pull_errors;
		}
		return $errors;
	}

	/**
	 * Check object map table to see if there have been any failed object map create attempts
	 *
	 * @param int   $id The ID of a desired mapping.
	 *
	 * @return array $error Associative array of single row that failed to finish based on id
	 */
	public function get_failed_object_map( $id ) {
		$table     = $this->object_map_table;
		$error     = array();
		$error_row = $this->wpdb->get_row( 'SELECT * FROM ' . $table . ' WHERE id = "' . $id . '"', ARRAY_A );
		if ( ! empty( $error_row ) ) {
			$error = $error_row;
		}
		return $error;
	}

}
