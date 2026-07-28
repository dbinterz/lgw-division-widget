<?php
/**
 * lgw-champ-engine.php — v2026.31.4
 *
 * Common championship abstraction. Both championship back-ends — the new
 * group-knockout engine (`lgw_gchamp_<id>`, lgw-gchamp.php) and the legacy
 * section-bracket engine (`lgw_champ_<id>`, lgw-champ.php) — are wrapped behind
 * a single interface so callers speak ONE vocabulary and never branch on the
 * storage back-end.
 *
 * Adding a third engine later = one new class implementing LGW_Champ_Engine,
 * registered in lgw_champ_engines(). Zero caller edits.
 *
 * Load order: after lgw-champ.php + lgw-gchamp.php (whose helpers we may reuse),
 * before lgw-entry.php (the primary consumer). Wired in $lgw_modules.
 *
 * This module NEVER edits lgw-champ.php / lgw-gchamp.php — the adapters wrap
 * their option storage from the outside.
 *
 * @package LGW
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * One championship back-end. Implementations wrap a single option-key family.
 *
 * The normalised array returned by get() has a stable shape regardless of
 * engine: { id, engine, title, discipline, entries[], preference_fields[] }.
 */
interface LGW_Champ_Engine {
	/** Machine name of this engine ('gchamp' | 'champ'). */
	public function name(): string;
	/** Does a championship with this id live in this engine? */
	public function exists( string $id ): bool;
	/** Normalised championship array, or null if absent here. */
	public function get( string $id ): ?array;
	/** Has the draw been run/started (appending would reshape brackets)? */
	public function draw_started( string $id ): bool;
	/** Append an entry-string pre-draw. true on success (incl. already-present); WP_Error('draw_started') if drawn. */
	public function append_entry( string $id, string $entry, array $prefs = array() ): mixed;
	/** Remove an entry-string pre-draw. true on success; WP_Error('draw_started') if drawn. */
	public function remove_entry( string $id, string $entry ): mixed;
	/** [ ['id'=>, 'title'=>, 'engine'=>], ... ] for every championship in this engine. */
	public function list(): array;
}

/**
 * Shared machinery. Concrete engines supply only the option prefix, the
 * draw-started test, and (optionally) preference read/write.
 */
abstract class LGW_Champ_Engine_Base implements LGW_Champ_Engine {

	/** Option-name prefix, e.g. 'lgw_gchamp_'. */
	abstract protected function prefix(): string;

	/** Engine-specific "the draw has started" test against the raw option array. */
	abstract protected function is_drawn( array $raw ): bool;

	/** Write preferences into the raw option (no-op for engines without prefs). */
	protected function write_prefs( array &$raw, string $entry, array $prefs ): void {}

	/** Remove preferences for an entry (no-op for engines without prefs). */
	protected function remove_prefs( array &$raw, string $entry ): void {}

	/** Filter out option ids that share the prefix but are not championships. */
	protected function id_is_champ( string $id ): bool { return true; }

	protected function opt( string $id ): string { return $this->prefix() . sanitize_key( $id ); }

	public function exists( string $id ): bool {
		$raw = get_option( $this->opt( $id ), array() );
		return is_array( $raw ) && isset( $raw['title'] );
	}

	public function get( string $id ): ?array {
		$raw = get_option( $this->opt( $id ), array() );
		if ( ! is_array( $raw ) || ! isset( $raw['title'] ) ) return null;
		return array(
			'id'                => sanitize_key( $id ),
			'engine'            => $this->name(),
			'title'             => $raw['title'],
			'discipline'        => $raw['discipline'] ?? 'singles',
			'entries'           => array_values( $raw['entries'] ?? array() ),
			'preference_fields' => $raw['preference_fields'] ?? array(),
		);
	}

	public function draw_started( string $id ): bool {
		$raw = get_option( $this->opt( $id ), array() );
		return is_array( $raw ) ? $this->is_drawn( $raw ) : false;
	}

	public function append_entry( string $id, string $entry, array $prefs = array() ): mixed {
		$opt = $this->opt( $id );
		$raw = get_option( $opt, array() );
		if ( ! is_array( $raw ) || ! isset( $raw['title'] ) ) {
			return new WP_Error( 'lgw_champ_engine', 'Championship not found.' );
		}
		if ( $this->is_drawn( $raw ) ) {
			return new WP_Error( 'draw_started', 'Draw already started — needs manual placement.' );
		}
		$norm    = lgw_champ_engine_norm( $entry );
		$entries = array_values( $raw['entries'] ?? array() );
		foreach ( $entries as $e ) {
			if ( lgw_champ_engine_norm( $e ) === $norm ) return true; // already present — idempotent
		}
		$entries[]      = $entry;
		$raw['entries'] = $entries;
		$this->write_prefs( $raw, $entry, $prefs );
		update_option( $opt, $raw );
		return true;
	}

	public function remove_entry( string $id, string $entry ): mixed {
		$opt = $this->opt( $id );
		$raw = get_option( $opt, array() );
		if ( ! is_array( $raw ) ) return true;
		if ( $this->is_drawn( $raw ) ) {
			return new WP_Error( 'draw_started', 'Draw already started — remove via the draw tool.' );
		}
		$norm = lgw_champ_engine_norm( $entry );
		if ( isset( $raw['entries'] ) && is_array( $raw['entries'] ) ) {
			$raw['entries'] = array_values( array_filter( $raw['entries'], static function ( $e ) use ( $norm ) {
				return lgw_champ_engine_norm( $e ) !== $norm;
			} ) );
		}
		$this->remove_prefs( $raw, $entry );
		update_option( $opt, $raw );
		return true;
	}

	public function list(): array {
		global $wpdb;
		$prefix = $this->prefix();
		$like   = $wpdb->esc_like( $prefix ) . '%';
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
				$like
			)
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$id = substr( $r->option_name, strlen( $prefix ) );
			if ( ! $this->id_is_champ( $id ) ) continue;
			$val = maybe_unserialize( $r->option_value );
			if ( is_array( $val ) && isset( $val['title'] ) ) {
				$out[] = array( 'id' => $id, 'title' => $val['title'], 'engine' => $this->name() );
			}
		}
		return $out;
	}
}

/** New group-knockout engine (lgw-gchamp.php). Supports date/location preferences. */
class LGW_Gchamp_Engine extends LGW_Champ_Engine_Base {
	public function name(): string { return 'gchamp'; }
	protected function prefix(): string { return 'lgw_gchamp_'; }
	protected function is_drawn( array $raw ): bool { return ! empty( $raw['draw_complete'] ); }

	protected function write_prefs( array &$raw, string $entry, array $prefs ): void {
		if ( empty( $prefs ) ) return;
		$allowed = $raw['preference_fields'] ?? array();
		$store   = array();
		foreach ( array( 'date', 'location' ) as $f ) {
			if ( in_array( $f, $allowed, true ) && ! empty( $prefs[ $f ] ) ) $store[ $f ] = $prefs[ $f ];
		}
		if ( $store ) {
			$raw['entry_preferences']           = $raw['entry_preferences'] ?? array();
			$raw['entry_preferences'][ $entry ] = $store;
		}
	}

	protected function remove_prefs( array &$raw, string $entry ): void {
		if ( isset( $raw['entry_preferences'][ $entry ] ) ) unset( $raw['entry_preferences'][ $entry ] );
	}
}

/**
 * Legacy section-bracket engine (lgw-champ.php).
 *
 * No preferences (base no-ops). Draw is "started" when any section or the final
 * has a draw version/in-progress flag; at that point the champ's own draw step
 * has fixed the section layout, so appending to entries[] must NOT happen from
 * here (mirrors lgw-champ.php:1465-1468). Sections are deliberately NOT rebuilt
 * on append — lgw_champ_build_sections() SHUFFLES (lgw-champ.php:1425); the
 * champ draw step rebuilds sections from entries[] when undrawn
 * (lgw-champ.php:1471), so a pre-draw entry flows into the draw automatically.
 */
class LGW_Champ_Engine_Legacy extends LGW_Champ_Engine_Base {
	public function name(): string { return 'champ'; }
	protected function prefix(): string { return 'lgw_champ_'; }

	protected function is_drawn( array $raw ): bool {
		if ( ! empty( $raw['final_draw_version'] ) || ! empty( $raw['final_draw_in_progress'] ) ) return true;
		foreach ( $raw as $k => $v ) {
			if ( $v && is_string( $k ) && preg_match( '/^section_\d+_draw_(version|in_progress)$/', $k ) ) return true;
		}
		return false;
	}

	/** Reject options that share the lgw_champ_ prefix but are not championships. */
	protected function id_is_champ( string $id ): bool {
		if ( $id === 'priority' ) return false;             // lgw_champ_priority
		if ( substr( $id, -9 ) === '_settings' ) return false; // lgw_champ_*_settings
		return true;
	}
}

/** Registered engines, in resolution-precedence order (gchamp wins on id clash). */
function lgw_champ_engines(): array {
	static $engines = null;
	if ( $engines === null ) {
		$engines = array( new LGW_Gchamp_Engine(), new LGW_Champ_Engine_Legacy() );
	}
	return $engines;
}

/** Resolve the engine that owns this championship id, or null if none. */
function lgw_champ_engine( string $id ): ?LGW_Champ_Engine {
	$id = sanitize_key( $id );
	if ( $id === '' ) return null;
	foreach ( lgw_champ_engines() as $engine ) {
		if ( $engine->exists( $id ) ) return $engine;
	}
	return null;
}

/** Every championship across all engines, deduped by id (gchamp precedence), title-sorted. */
function lgw_champ_engine_list(): array {
	$by_id = array();
	foreach ( lgw_champ_engines() as $engine ) {
		foreach ( $engine->list() as $row ) {
			if ( ! isset( $by_id[ $row['id'] ] ) ) $by_id[ $row['id'] ] = $row; // first engine wins
		}
	}
	$out = array_values( $by_id );
	usort( $out, static function ( $a, $b ) { return strcasecmp( $a['title'], $b['title'] ); } );
	return $out;
}

/** Normalise an entry-string for comparison (delegates to gchamp's normaliser when present). */
function lgw_champ_engine_norm( $s ): string {
	if ( function_exists( 'lgw_gchamp_norm_entry' ) ) return strtolower( lgw_gchamp_norm_entry( $s ) );
	return strtolower( preg_replace( '/\s+/', ' ', trim( (string) $s ) ) );
}
