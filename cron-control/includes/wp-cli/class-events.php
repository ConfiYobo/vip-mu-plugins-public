<?php

namespace Automattic\WP\Cron_Control\CLI;

/**
 * Manage Cron Control's data
 */
class Events extends \WP_CLI_Command {
	/**
	 * List cron events
	 *
	 * Intentionally bypasses caching to ensure latest data is shown
	 *
	 * @subcommand list
	 * @synopsis [--status=<pending|completed>] [--page=<page>] [--limit=<limit>] [--format=<format>]
	 */
	public function list_events( $args, $assoc_args ) {
		$events = $this->get_events( $args, $assoc_args );

		// Prevent one from requesting a page that doesn't exist
		// Shouldn't error when first page is requested, though, as that is handled below and is an odd behaviour otherwise
		if ( $events['page'] > $events['total_pages'] && $events['page'] > 1 ) {
			\WP_CLI::error( __( 'Invalid page requested', 'automattic-cron-control' ) );
		}

		// Output in the requested format
		if ( isset( $assoc_args['format'] ) && 'ids' === $assoc_args['format'] ) {
			echo implode( ' ', wp_list_pluck( $events['items'], 'ID' ) );
		} else {
			// Lest someone think the `completed` record should be...complete
			if ( isset( $assoc_args['status'] ) && 'completed' === $assoc_args['status'] ) {
				\WP_CLI::warning( __( 'Entries are purged automatically, so this cannot be relied upon as a record of past event execution.', 'automattic-cron-control' ) );
			}

			// Not much to do
			if ( 0 === $events['total_items'] || empty( $events['items'] ) ) {
				\WP_CLI::warning( __( 'No events to display', 'automattic-cron-control' ) );
				return;
			}

			// Prepare events for display
			$events_for_display      = $this->format_events( $events['items'] );
			$total_events_to_display = count( $events_for_display );

			// Count, noting if showing fewer than all
			if ( $events['total_items'] <= $total_events_to_display ) {
				\WP_CLI::line( sprintf( _n( 'Displaying one entry', 'Displaying all %s entries', $total_events_to_display, 'automattic-cron-control' ), number_format_i18n( $total_events_to_display ) ) );
			} else {
				\WP_CLI::line( sprintf( __( 'Displaying %1$s of %2$s entries, page %3$s of %4$s', 'automattic-cron-control' ), number_format_i18n( $total_events_to_display ), number_format_i18n( $events['total_items'] ), number_format_i18n( $events['page'] ), number_format_i18n( $events['total_pages'] ) ) );
			}

			// And reformat
			$format = 'table';
			if ( isset( $assoc_args['format'] ) ) {
				$format = $assoc_args['format'];
			}

			\WP_CLI\Utils\format_items( $format, $events_for_display, array(
				'ID',
				'action',
				'instance',
				'next_run_gmt',
				'next_run_relative',
				'last_updated_gmt',
				'recurrence',
				'internal_event',
				'schedule_name',
				'event_args',
			) );
		}
	}

	/**
	 * Remove events
	 *
	 * @subcommand delete
	 * @synopsis [--event_id=<event_id>] [--action=<action>] [--completed]
	 */
	public function delete_events( $args, $assoc_args ) {
		// Remove a specific event
		if ( isset( $assoc_args['event_id'] ) ) {
			$this->delete_event_by_id( $args, $assoc_args );
			return;
		}

		// Remove all events with a given action
		if ( isset( $assoc_args['action'] ) ) {
			$this->delete_event_by_action( $args, $assoc_args );
			return;
		}

		// Remove all completed events
		if ( isset( $assoc_args['completed'] ) ) {
			$this->delete_completed_events( $args, $assoc_args );
			return;
		}

		\WP_CLI::error( __( 'Specify something to delete, or see the `cron-control-fixers` command to remove all data.', 'automattic-cron-control' ) );
	}

	/**
	 * Run an event given an ID
	 *
	 * @subcommand run
	 * @synopsis <event_id>
	 */
	public function run_event( $args, $assoc_args ) {
		// Validate ID
		if ( ! is_numeric( $args[0] ) ) {
			\WP_CLI::error( __( 'Specify the ID of an event to run', 'automattic-cron-control' ) );
		}

		// Retrieve information needed to execute event
		$event = \Automattic\WP\Cron_Control\get_event_by_id( $args[0] );

		if ( ! is_object( $event ) ) {
			\WP_CLI::error( sprintf( __( 'Failed to locate event %d. Please confirm that the entry exists and that the ID is that of an event.', 'automattic-cron-control' ), $args[0] ) );
		}

		\WP_CLI::line( sprintf( __( 'Found event %1$d with action `%2$s` and instance identifier `%3$s`', 'automattic-cron-control' ), $args[0], $event->action, $event->instance ) );

		// Proceed?
		$now = time();
		if ( $event->timestamp > $now ) {
			\WP_CLI::warning( sprintf( __( 'This event is not scheduled to run until %1$s GMT (%2$s)', 'automattic-cron-control' ), date( TIME_FORMAT, $event->timestamp ), $this->calculate_interval( $event->timestamp - $now ) ) );
		}

		\WP_CLI::confirm( sprintf( __( 'Run this event?', 'automattic-cron-control' ) ) );

		// Environment preparation
		if ( ! defined( 'DOING_CRON' ) ) {
			define( 'DOING_CRON', true );
		}

		// Run the event
		$run = \Automattic\WP\Cron_Control\run_event( $event->timestamp, $event->action_hashed, $event->instance, true );

		// Output based on run attempt
		if ( is_array( $run ) ) {
			\WP_CLI::success( $run['message'] );
		} elseif ( is_wp_error( $run ) ) {
			\WP_CLI::error( $run->get_error_message() );
		} else {
			\WP_CLI::error( __( 'Failed to run event', 'automattic-cron-control' ) );
		}
	}

	/**
	 * Retrieve list of events, and related data, for a given request
	 */
	private function get_events( $args, $assoc_args ) {
		global $wpdb;

		// Accept a status argument, with a default
		$status = 'pending';
		if ( isset( $assoc_args['status'] ) ) {
			$status = $assoc_args['status'];
		}

		// Convert to status used by Event Store
		$event_status = null;
		switch ( $status ) {
			case 'pending' :
				$event_status = \Automattic\WP\Cron_Control\Events_Store::STATUS_PENDING;
				break;

			case 'running' :
				$event_status = \Automattic\WP\Cron_Control\Events_Store::STATUS_RUNNING;
				break;

			case 'completed' :
				$event_status = \Automattic\WP\Cron_Control\Events_Store::STATUS_COMPLETED;
				break;
		}

		if ( is_null( $event_status ) ) {
			\WP_CLI::error( __( 'Invalid status specified', 'automattic-cron-control' ) );
		}

		unset( $status );

		// Total to show
		$limit = 25;
		if ( isset( $assoc_args['limit'] ) && is_numeric( $assoc_args['limit'] ) ) {
			$limit = max( 1, min( absint( $assoc_args['limit'] ), 500 ) );
		}

		// Pagination
		$page = 1;
		if ( isset( $assoc_args['page'] ) && is_numeric( $assoc_args['page'] ) ) {
			$page = absint( $assoc_args['page'] );
		}

		$offset = absint( ( $page - 1 ) * $limit );

		// Query
		$items = \Automattic\WP\Cron_Control\get_events( array(
			'status'   => $event_status,
			'quantity' => $limit,
			'page'     => $page,
		) );

		// Bail if we don't get results
		if ( ! is_array( $items ) ) {
			\WP_CLI::error( __( 'Problem retrieving events', 'automattic-cron-control' ) );
		}

		// Include totals for pagination etc
		$total_items = \Automattic\WP\Cron_Control\count_events_by_status( $event_status );
		$total_pages = ceil( $total_items / $limit );

		return compact( 'status', 'limit', 'page', 'offset', 'items', 'total_items', 'total_pages' );
	}

	/**
	 * Format event data into something human-readable
	 */
	private function format_events( $events ) {
		$formatted_events = array();

		// Reformat events
		foreach ( $events as $event ) {
			$row = array(
				'ID'                => $event->ID,
				'action'            => $event->action,
				'instance'          => $event->instance,
				'next_run_gmt'      => date( TIME_FORMAT, $event->timestamp ),
				'next_run_relative' => '',
				'last_updated_gmt'  => date( TIME_FORMAT, strtotime( $event->last_modified ) ),
				'recurrence'        => __( 'Non-repeating', 'automattic-cron-control' ),
				'internal_event'    => '',
				'schedule_name'     => __( 'n/a', 'automattic-cron-control' ),
				'event_args'        => '',
			);

			if ( $event->status === \Automattic\WP\Cron_Control\Events_Store::STATUS_PENDING ) {
				$row['next_run_relative'] = $this->calculate_interval( $event->timestamp - time() );
			}

			$row['internal_event'] = \Automattic\WP\Cron_Control\is_internal_event( $event->action ) ? __( 'true', 'automattic-cron-control' ) : '';

			$row['event_args'] = maybe_serialize( $event->args );

			if ( \Automattic\WP\Cron_Control\Events_Store::STATUS_COMPLETED === $event->status ) {
				$instance = md5( $row['event_args'] );
				$row['instance'] = "{$instance} - {$row['instance']}";
			}

			if ( isset( $event->interval ) && $event->interval ) {
				$row['recurrence'] = $this->calculate_interval( $event->interval );
			}

			if ( isset( $event->schedule ) && $event->schedule ) {
				$row['schedule_name'] = $event->schedule;
			}

			$formatted_events[] = $row;
		}

		// Sort results
		if ( ! empty( $formatted_events ) ) {
			usort( $formatted_events, array( $this, 'sort_events' ) );
		}

		return $formatted_events;
	}

	/**
	 * Sort events by timestamp, then action name
	 */
	private function sort_events( $first, $second ) {
		// Timestamp is usually sufficient
		if ( isset( $first['next_run_gmt'] ) ) {
			$first_timestamp = strtotime( $first['next_run_gmt'] );
			$second_timestamp = strtotime( $second['next_run_gmt'] );
		} elseif ( isset( $first['timestamp'] ) ) {
			$first_timestamp = $first['timestamp'];
			$second_timestamp = $second['timestamp'];
		} else {
			return 0;
		}

		if ( $first_timestamp !== $second_timestamp ) {
			return $first_timestamp - $second_timestamp;
		}

		// If timestamps are equal, consider action
		return strnatcasecmp( $first['action'], $second['action'] );
	}

	/**
	 * Convert a time interval into human-readable format.
	 *
	 * Similar to WordPress' built-in `human_time_diff()` but returns two time period chunks instead of just one.
	 *
	 * Borrowed from WP-CLI
	 *
	 * @param int $since An interval of time in seconds
	 * @return string The interval in human readable format
	 */
	private function calculate_interval( $since ) {
		if ( $since <= 0 ) {
			return 'now';
		}

		$since = absint( $since );

		// array of time period chunks
		$chunks = array(
			array( 60 * 60 * 24 * 365 , \_n_noop( '%s year', '%s years' ) ),
			array( 60 * 60 * 24 * 30 , \_n_noop( '%s month', '%s months' ) ),
			array( 60 * 60 * 24 * 7, \_n_noop( '%s week', '%s weeks' ) ),
			array( 60 * 60 * 24 , \_n_noop( '%s day', '%s days' ) ),
			array( 60 * 60 , \_n_noop( '%s hour', '%s hours' ) ),
			array( 60 , \_n_noop( '%s minute', '%s minutes' ) ),
			array(  1 , \_n_noop( '%s second', '%s seconds' ) ),
		);

		// we only want to output two chunks of time here, eg:
		// x years, xx months
		// x days, xx hours
		// so there's only two bits of calculation below:

		// step one: the first chunk
		for ( $i = 0, $j = count( $chunks ); $i < $j; $i++ ) {
			$seconds = $chunks[$i][0];
			$name = $chunks[$i][1];

			// finding the biggest chunk (if the chunk fits, break)
			if ( ( $count = floor( $since / $seconds ) ) != 0 ){
				break;
			}
		}

		// set output var
		$output = sprintf( \_n( $name[0], $name[1], $count ), $count );

		// step two: the second chunk
		if ( $i + 1 < $j ) {
			$seconds2 = $chunks[$i + 1][0];
			$name2    = $chunks[$i + 1][1];

			if ( ( $count2 = floor( ( $since - ( $seconds * $count ) ) / $seconds2 ) ) != 0 ) {
				// add to output var
				$output .= ' ' . sprintf( \_n( $name2[0], $name2[1], $count2 ), $count2 );
			}
		}

		return $output;
	}

	/**
	 * Delete an event by ID
	 */
	private function delete_event_by_id( $args, $assoc_args ) {
		$jid = absint( $assoc_args['event_id'] );

		// Validate ID
		if ( ! $jid ) {
			\WP_CLI::error( __( 'Invalid event ID', 'automattic-cron-control' ) );
		}

		\WP_CLI::line( __( 'Locating event...', 'automattic-cron-control' ) . "\n" );

		// Look up full event object
		$event = \Automattic\WP\Cron_Control\get_event_by_id( $jid );

		if ( is_object( $event ) ) {
			// Warning about Internal Events
			if ( \Automattic\WP\Cron_Control\is_internal_event( $event->action ) ) {
				\WP_CLI::warning( __( 'This is an event created by the Cron Control plugin. It will recreated automatically.', 'automattic-cron-control' ) );
			}

			\WP_CLI::line( sprintf( __( 'Execution time: %s GMT', 'automattic-cron-control' ), date( TIME_FORMAT, $event->timestamp ) ) );
			\WP_CLI::line( sprintf( __( 'Action: %s', 'automattic-cron-control' ), $event->action ) );
			\WP_CLI::line( sprintf( __( 'Instance identifier: %s', 'automattic-cron-control' ), $event->instance ) );
			\WP_CLI::line( '' );
			\WP_CLI::confirm( sprintf( __( 'Are you sure you want to delete this event?', 'automattic-cron-control' ) ) );

			// Try to delete the item and provide some relevant output
			\Automattic\WP\Cron_Control\_suspend_event_creation();
			$deleted = \Automattic\WP\Cron_Control\delete_event_by_id( $event->ID, true );
			\Automattic\WP\Cron_Control\_resume_event_creation();

			if ( false === $deleted ) {
				\WP_CLI::error( sprintf( __( 'Failed to delete event %d', 'automattic-cron-control' ), $jid ) );
			} else {
				\Automattic\WP\Cron_Control\_flush_internal_caches();
				\WP_CLI::success( sprintf( __( 'Removed event %d', 'automattic-cron-control' ), $jid ) );
				return;
			}
		}

		\WP_CLI::error( sprintf( __( 'Failed to delete event %d. Please confirm that the entry exists and that the ID is that of an event.', 'automattic-cron-control' ), $jid ) );
	}

	/**
	 * Delete all events of the same action
	 */
	private function delete_event_by_action( $args, $assoc_args ) {
		$action = $assoc_args['action'];

		// Validate entry
		if ( empty( $action ) ) {
			\WP_CLI::error( __( 'Invalid action', 'automattic-cron-control' ) );
		}

		// Warning about Internal Events
		if ( \Automattic\WP\Cron_Control\is_internal_event( $action ) ) {
			\WP_CLI::warning( __( 'This is an event created by the Cron Control plugin. It will recreated automatically.', 'automattic-cron-control' ) );
		}

		// Set defaults needed to gather all events
		$assoc_args['page']  = 1;
		$assoc_args['limit'] = 50;

		// Gather events
		\WP_CLI::line( __( 'Gathering events...', 'automattic-cron-control' ) );

		$events_to_delete = array();

		$events = $this->get_events( $args, $assoc_args );

		\WP_CLI::line( sprintf( _n( 'Found one event to check', 'Found %s events to check', $events['total_items'], 'automattic-cron-control' ), number_format_i18n( $events['total_items'] ) ) );

		$search_progress = \WP_CLI\Utils\make_progress_bar( sprintf( __( 'Searching events for those with the action `%s`', 'automattic-cron-control' ), $action ), $events['total_items'] );

		// Loop and pull out events to be deleted
		do {
			if ( ! is_array( $events ) || empty( $events['items'] ) ) {
				break;
			}

			// Check events for those that should be deleted
			foreach ( $events['items'] as $single_event ) {
				if ( $single_event->action === $action ) {
					$events_to_delete[] = (array) $single_event;
				}

				$search_progress->tick();
			}

			// Proceed to next batch
			$assoc_args['page']++;

			if ( $assoc_args['page'] > $events['total_pages'] ) {
				break;
			}

			$events = $this->get_events( $args, $assoc_args );
		} while( $events['page'] <= $events['total_pages'] );

		$search_progress->finish();

		\WP_CLI::line( '' );

		// Nothing more to do
		if ( empty( $events_to_delete ) ) {
			\WP_CLI::error( sprintf( __( 'No events with action `%s` found', 'automattic-cron-control' ), $action ) );
		}

		// List the items to remove
		$total_to_delete = count( $events_to_delete );

		\WP_CLI::line( sprintf( _n( 'Found one event with action `%2$s`:', 'Found %1$s events with action `%2$s`:', $total_to_delete, 'automattic-cron-control' ), number_format_i18n( $total_to_delete ), $action ) );

		if ( $total_to_delete <= $assoc_args['limit'] ) {
			// Sort results
			if ( ! empty( $events_to_delete ) ) {
				usort( $events_to_delete, array( $this, 'sort_events' ) );
			}


			\WP_CLI\Utils\format_items( 'table', $events_to_delete, array(
				'ID',
				'created',
				'last_modified',
				'timestamp',
				'instance',
			) );
		} else {
			\WP_CLI::warning( sprintf( __( 'Events are not displayed as there are more than %s to remove', 'automattic-cron-control' ), number_format_i18n( $assoc_args['limit'] ) ) );
		}

		\WP_CLI::line( '' );
		\WP_CLI::confirm( _n( 'Are you sure you want to delete this event?', 'Are you sure you want to delete these events?', $total_to_delete, 'automattic-cron-control' ) );

		// Remove the items
		$delete_progress = \WP_CLI\Utils\make_progress_bar( __( 'Deleting events', 'automattic-cron-control' ), $total_to_delete );

		$events_deleted       = array();
		$events_deleted_count = $events_failed_delete = 0;

		// Don't create new events while deleting events
		\Automattic\WP\Cron_Control\_suspend_event_creation();

		foreach ( $events_to_delete as $event_to_delete ) {
			$deleted = \Automattic\WP\Cron_Control\delete_event_by_id( $event_to_delete['ID'], false );

			$events_deleted[] = array(
				'ID'      => $event_to_delete['ID'],
				'deleted' => false === $deleted ? 'no' : 'yes',
			);

			if ( $deleted ) {
				$events_deleted_count++;
			} else {
				$events_failed_delete++;
			}

			$delete_progress->tick();
		}

		$delete_progress->finish();

		// When deletes succeed, sync internal caches
		if ( $events_deleted_count > 0 ) {
			\Automattic\WP\Cron_Control\_flush_internal_caches();
		}

		// New events can be created now that removal is complete
		\Automattic\WP\Cron_Control\_resume_event_creation();

		// List the removed items
		\WP_CLI::line( "\n" . __( 'RESULTS:', 'automattic-cron-control' ) );

		if ( 1 === $total_to_delete && 1 === $events_deleted_count ) {
			\WP_CLI::success( sprintf( __( 'Deleted one event: %d', 'automattic-cron-control' ), $events_deleted[0]['ID'] ) );
		} else {
			if ( $events_deleted_count === $total_to_delete ) {
				\WP_CLI::success( sprintf( __( 'Deleted %s events', 'automattic-cron-control' ), number_format_i18n( $events_deleted_count ) ) );
			} else {
				\WP_CLI::warning( sprintf( __( 'Expected to delete %1$s events, but could only delete %2$s events. It\'s likely that some events were executed while this command ran.', 'automattic-cron-control' ), number_format_i18n( $total_to_delete ), number_format_i18n( $events_deleted_count ) ) );
			}

			// Limit just to failed deletes when many events are removed
			if ( count( $events_deleted ) > $assoc_args['limit'] ) {
				$events_deleted = array_filter( $events_deleted, function( $event ) {
					if ( 'no' === $event['deleted'] ) {
						return $event;
					} else {
						return false;
					}
				} );

				if ( count( $events_deleted ) > 0 ) {
					\WP_CLI::line( "\n" . __( 'Events that couldn\'t be deleted:', 'automattic-cron-control' ) );
				}
			} else {
				\WP_CLI::line( "\n" . __( 'Events deleted:', 'automattic-cron-control' ) );
			}

			// Don't display a table if there's nothing to display
			if ( count( $events_deleted ) > 0 ) {
				\WP_CLI\Utils\format_items( 'table', $events_deleted, array(
					'ID',
					'deleted',
				) );
			}
		}

		return;
	}

	/**
	 * Delete all completed events
	 */
	private function delete_completed_events( $args, $assoc_args ) {
		$count = \Automattic\WP\Cron_Control\count_events_by_status( \Automattic\WP\Cron_Control\Events_Store::STATUS_COMPLETED );

		\WP_CLI::confirm( sprintf( _n( 'Found one completed event to remove. Continue?', 'Found %s completed events to remove. Continue?', $count, 'automattic-cron-control' ), number_format_i18n( $count ) ) );

		\Automattic\WP\Cron_Control\Events_Store::instance()->purge_completed_events( false );

		\WP_CLI::success( __( 'Entries removed', 'automattic-cron-control' ) );
	}
}

\WP_CLI::add_command( 'cron-control events', 'Automattic\WP\Cron_Control\CLI\Events' );
