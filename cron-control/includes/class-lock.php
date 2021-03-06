<?php

namespace Automattic\WP\Cron_Control;

class Lock {
	/**
	 * Set a lock and limit how many concurrent jobs are permitted
	 *
	 * @param $lock     string  Lock name
	 * @param $limit    int     Concurrency limit
	 * @param $timeout  int     Timeout in seconds
	 *
	 * @return bool
	 */
	public static function check_lock( $lock, $limit = null, $timeout = null ) {
		// Timeout, should a process die before its lock is freed
		if ( ! is_numeric( $timeout ) ) {
			$timeout = LOCK_DEFULT_TIMEOUT_IN_MINUTES * \MINUTE_IN_SECONDS;
		}

		// Check for, and recover from, deadlock
		if ( self::get_lock_timestamp( $lock ) < time() - $timeout ) {
			self::reset_lock( $lock );
			return true;
		}

		// Default limit for concurrent events
		if ( ! is_numeric( $limit ) ) {
			$limit = LOCK_DEFAULT_LIMIT;
		}

		// Check if process can run
		if ( self::get_lock_value( $lock ) >= $limit ) {
			return false;
		} else {
			wp_cache_incr( self::get_key( $lock ) );
			return true;
		}
	}

	/**
	 * When event completes, allow another
	 */
	public static function free_lock( $lock, $expires = 0 ) {
		if ( self::get_lock_value( $lock ) > 1 ) {
			wp_cache_decr( self::get_key( $lock ) );
		} else {
			wp_cache_set( self::get_key( $lock ), 0, null, $expires );
		}

		wp_cache_set( self::get_key( $lock, 'timestamp' ), time(), null, $expires );

		return true;
	}

	/**
	 * Build cache key
	 */
	private static function get_key( $lock, $type = 'lock' ) {
		switch ( $type ) {
			case 'lock' :
				return "a8ccc_lock_{$lock}";
				break;

			case 'timestamp' :
				return "a8ccc_lock_ts_{$lock}";
				break;
		}

		return false;
	}

	/**
	 * Ensure lock entries are initially set
	 */
	public static function prime_lock( $lock, $expires = 0 ) {
		wp_cache_add( self::get_key( $lock ), 0, null, $expires );
		wp_cache_add( self::get_key( $lock, 'timestamp' ), time(), null, $expires );

		return null;
	}

	/**
	 * Retrieve a lock from cache
	 */
	public static function get_lock_value( $lock ) {
		return (int) wp_cache_get( self::get_key( $lock ), null, true );
	}

	/**
	 * Retrieve a lock's timestamp
	 */
	public static function get_lock_timestamp( $lock ) {
		return (int) wp_cache_get( self::get_key( $lock, 'timestamp' ), null, true );
	}

	/**
	 * Clear a lock's current values, in order to free it
	 */
	public static function reset_lock( $lock, $expires = 0 ) {
		wp_cache_set( self::get_key( $lock ), 0, null, $expires );
		wp_cache_set( self::get_key( $lock, 'timestamp' ), time(), null, $expires );

		return true;
	}
}
