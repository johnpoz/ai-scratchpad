<?php
/**
 * Plugin Name:       AI Scratchpad
 * Plugin URI:        https://github.com/johnpoz/ai-scratchpad
 * Description:       A session journal and durable notes kept on the site itself, so every AI assistant picks up where the last one left off. Exposed to any MCP server through the WordPress Abilities API.
 * Version:           0.5.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            John Pozadzides
 * Author URI:        https://github.com/johnpoz
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-scratchpad
 *
 * Registers ten abilities under the ai-scratchpad namespace on the core Abilities
 * API. Any MCP server plugin on this site (Easy MCP AI, the WordPress MCP Adapter)
 * exposes them. The plugin is not an MCP server itself.
 */

defined( 'ABSPATH' ) || exit;

defined( 'AI_SCRATCHPAD_VERSION' )    || define( 'AI_SCRATCHPAD_VERSION', '0.5.1' );
// 4: the built-in MCP server and its sign-in server were removed. Bumping this
// is what runs the one-time cleanup on sites that had them.
defined( 'AI_SCRATCHPAD_DB_VERSION' ) || define( 'AI_SCRATCHPAD_DB_VERSION', 4 );

/* ============================================================================
 * SECTION 1: STORAGE
 * One table holds both halves of the plugin. `kind` separates them:
 *   entry - append-only journal record. Immutable. Corrected by amendment only.
 *   note  - durable page addressed by a path. Edited in place, revision counted.
 * Folders are path prefixes. There is deliberately no folder table.
 * ==========================================================================*/

if ( ! class_exists( 'AI_Scratchpad_Store' ) ) :

final class AI_Scratchpad_Store {

	/** Table name including the site prefix. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ai_scratchpad';
	}

	/**
	 * Create or upgrade the table. Safe to call repeatedly; dbDelta diffs it.
	 * objects_flat is a denormalized, space-delimited copy of `objects` so that
	 * "when did we last touch snippet 68" is a single indexed LIKE instead of a
	 * JSON scan across every row.
	 */
	public static function install() {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`kind` VARCHAR(16) NOT NULL DEFAULT 'entry',
			`type` VARCHAR(32) NOT NULL DEFAULT '',
			`path` VARCHAR(191) NOT NULL DEFAULT '',
			`title` VARCHAR(255) NOT NULL DEFAULT '',
			`body` LONGTEXT NULL,
			`why` TEXT NULL,
			`verified` TEXT NULL,
			`undo` TEXT NULL,
			`waiting_on` TEXT NULL,
			`objects` LONGTEXT NULL,
			`objects_flat` TEXT NULL,
			`tags` VARCHAR(255) NOT NULL DEFAULT '',
			`agent` VARCHAR(191) NOT NULL DEFAULT '',
			`model` VARCHAR(191) NOT NULL DEFAULT '',
			`human` VARCHAR(191) NOT NULL DEFAULT '',
			`session` VARCHAR(64) NOT NULL DEFAULT '',
			`status` VARCHAR(16) NOT NULL DEFAULT 'active',
			`pinned` TINYINT(1) NOT NULL DEFAULT 0,
			`revision` INT UNSIGNED NOT NULL DEFAULT 1,
			`parent_id` BIGINT UNSIGNED NULL DEFAULT NULL,
			`author_user` BIGINT UNSIGNED NOT NULL DEFAULT 0,
			`edited_by_user` BIGINT UNSIGNED NOT NULL DEFAULT 0,
			`edited_by_name` VARCHAR(191) NOT NULL DEFAULT '',
			`edited_at` DATETIME NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY  (`id`),
			KEY `kind_status` (`kind`,`status`),
			KEY `path_idx` (`path`),
			KEY `type_idx` (`type`),
			KEY `created_idx` (`created_at`),
			KEY `pinned_idx` (`pinned`),
			KEY `parent_idx` (`parent_id`)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Only record the version once the table genuinely exists. dbDelta reports
		// success even when MySQL rejected the statement, which hid a reserved-word
		// collision on the `undo` column during the first build.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			update_option( 'ai_scratchpad_db_version', AI_SCRATCHPAD_DB_VERSION );
		}
	}

	/** Insert a row. Returns the new id or a WP_Error. */
	public static function insert( array $data ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$row = wp_parse_args(
			$data,
			array(
				'kind'         => 'entry',
				'type'         => '',
				'path'         => '',
				'title'        => '',
				'body'         => '',
				'why'          => '',
				'verified'     => '',
				'undo'         => '',
				'waiting_on'   => '',
				'objects'      => '',
				'objects_flat' => '',
				'tags'         => '',
				'agent'        => '',
				'model'        => '',
				'human'        => '',
				'session'      => '',
				'status'       => 'active',
				'pinned'       => 0,
				'revision'     => 1,
				'parent_id'    => null,
				'author_user'  => get_current_user_id(),
				'edited_by_user' => 0,
				'edited_by_name' => '',
				'edited_at'    => null,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);

		$ok = $wpdb->insert( self::table(), $row );

		if ( false === $ok ) {
			return new WP_Error( 'scratchpad_db_error', 'Could not write to the scratchpad table.' );
		}

		return (int) $wpdb->insert_id;
	}

	/** Update a row by id. */
	public static function update( $id, array $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		return $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/** Fetch one row by id. */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
	}

	/** Fetch the live note at a path. */
	public static function get_note( $path ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE kind = 'note' AND status = 'active' AND path = %s LIMIT 1",
				$path
			),
			ARRAY_A
		);
	}

	/** The single active handoff, if there is one. */
	public static function active_handoff() {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			"SELECT * FROM {$table} WHERE kind = 'entry' AND type = 'handoff' AND status = 'active' ORDER BY id DESC LIMIT 1",
			ARRAY_A
		);
	}

	/** Archive every active handoff. Called before a new one is written. */
	public static function archive_handoffs() {
		global $wpdb;
		$table = self::table();
		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'archived', updated_at = %s WHERE kind = 'entry' AND type = 'handoff' AND status = 'active'",
				current_time( 'mysql', true )
			)
		);
	}

	/** Normalize a free-form list of touched objects into search tokens. */
	public static function flatten_objects( $objects ) {
		if ( empty( $objects ) || ! is_array( $objects ) ) {
			return '';
		}
		$tokens = array();
		foreach ( $objects as $o ) {
			$o = is_scalar( $o ) ? (string) $o : wp_json_encode( $o );
			$o = strtolower( trim( preg_replace( '/\s+/', '', $o ) ) );
			if ( '' !== $o ) {
				$tokens[] = $o;
			}
		}
		return $tokens ? ' ' . implode( ' ', array_unique( $tokens ) ) . ' ' : '';
	}

	/** Normalize and validate a note path. */
	public static function clean_path( $path ) {
		$path = strtolower( trim( (string) $path ) );
		$path = str_replace( '\\', '/', $path );
		$path = preg_replace( '#/+#', '/', $path );
		$path = trim( $path, '/' );
		$path = preg_replace( '#[^a-z0-9/_.-]#', '-', $path );
		$path = preg_replace( '/-+/', '-', $path );
		return $path;
	}
}

/* ============================================================================
 * SECTION 2: SETTINGS
 * Three switches, all with opinionated defaults. Credential blocking is on
 * because the person who turns it off in month one is not the person reading
 * the notes in month eight. Deletion is off because a confused agent should
 * not be able to erase the site's own history.
 * ==========================================================================*/

final class AI_Scratchpad_Settings {

	public static function defaults() {
		return array(
			'allow_credentials'     => 0,
			'allow_delete'          => 0,
			'bootstrap_entries'     => 10,
			'bootstrap_tree'        => 200,
		);
	}

	/**
	 * Both switches read the same way: ticked means allowed. 0.1.0 shipped one of
	 * them inverted ('block_credentials'), which made "allow everything" mean one
	 * box ticked and one cleared, and cost at least one site its credential
	 * protection. Legacy values are translated here as well as in the upgrade, so
	 * a stale option row can never resurrect the old meaning.
	 */
	public static function get( $key = null ) {
		$stored = (array) get_option( 'ai_scratchpad_settings', array() );

		if ( ! isset( $stored['allow_credentials'] ) && isset( $stored['block_credentials'] ) ) {
			$stored['allow_credentials'] = empty( $stored['block_credentials'] ) ? 1 : 0;
		}
		unset( $stored['block_credentials'] );

		// Keys from the built-in server, removed in 0.5.0. Ignored here as well
		// as deleted on upgrade, so a stale row can never mean anything again.
		foreach ( self::legacy_server_keys() as $legacy ) {
			unset( $stored[ $legacy ] );
		}

		$opts = wp_parse_args( $stored, self::defaults() );
		return ( null === $key ) ? $opts : $opts[ $key ];
	}

	/** Rewrite the stored option into the new vocabulary. Runs once, on upgrade. */
	public static function migrate_to_permissions() {
		$stored = (array) get_option( 'ai_scratchpad_settings', array() );
		if ( ! array_key_exists( 'block_credentials', $stored ) ) {
			return false;
		}
		$stored['allow_credentials'] = empty( $stored['block_credentials'] ) ? 1 : 0;
		unset( $stored['block_credentials'] );
		update_option( 'ai_scratchpad_settings', $stored );
		return true;
	}

	/** Settings keys that belonged only to the built-in server (0.3.0 to 0.4.2). */
	public static function legacy_server_keys() {
		return array( 'enable_builtin_server', 'enabled_tools' );
	}

	/**
	 * One-time cleanup for sites upgraded from 0.3.0 to 0.4.2, which could run
	 * the built-in MCP server and its OAuth sign-in server. Removes only what
	 * belonged to those two: the connector token table, the registered OAuth
	 * clients, any unexpired authorization codes, and the two settings keys.
	 * The journal and notes table, the database version and every other setting
	 * are left exactly as they were. Safe to run more than once.
	 *
	 * Neither server added rewrite rules or query vars (the consent screen read
	 * a plain query argument), so there is nothing to flush.
	 */
	public static function remove_builtin_server() {
		global $wpdb;

		$stored = get_option( 'ai_scratchpad_settings', null );
		if ( is_array( $stored ) ) {
			$clean = $stored;
			foreach ( self::legacy_server_keys() as $legacy ) {
				unset( $clean[ $legacy ] );
			}
			if ( $clean !== $stored ) {
				update_option( 'ai_scratchpad_settings', $clean );
			}
		}

		delete_option( 'ai_scratchpad_oauth_clients' );

		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}ai_scratchpad_tokens`" );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_ai_sp_oauth_code_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_ai_sp_oauth_code_' ) . '%'
			)
		);

		return true;
	}

	public static function register() {
		register_setting(
			'ai_scratchpad',
			'ai_scratchpad_settings',
			array(
				'type'              => 'object',
				'sanitize_callback' => function ( $in ) {
					$d = AI_Scratchpad_Settings::defaults();

					return array(
						'allow_credentials' => empty( $in['allow_credentials'] ) ? 0 : 1,
						'allow_delete'      => empty( $in['allow_delete'] ) ? 0 : 1,
						'bootstrap_entries' => max( 1, min( 100, (int) ( isset( $in['bootstrap_entries'] ) ? $in['bootstrap_entries'] : $d['bootstrap_entries'] ) ) ),
						'bootstrap_tree'    => max( 10, min( 2000, (int) ( isset( $in['bootstrap_tree'] ) ? $in['bootstrap_tree'] : $d['bootstrap_tree'] ) ) ),
					);
				},
				'default'           => self::defaults(),
			)
		);
	}
}

/* ============================================================================
 * SECTION 3: CREDENTIAL GUARD
 * A notes store is exactly where a tired agent pastes a token. On by default,
 * and it refuses the write rather than silently redacting, so the agent learns
 * and reaches for the password manager instead. A site owner who genuinely
 * wants secrets in here can switch it off under Settings.
 * ==========================================================================*/

final class AI_Scratchpad_Guard {

	/** Patterns chosen for a low false-positive rate on ordinary prose and code. */
	private static function patterns() {
		return array(
			'OpenAI-style key'            => '/\bsk-[A-Za-z0-9_\-]{20,}\b/',
			'Anthropic key'               => '/\bsk-ant-[A-Za-z0-9_\-]{20,}\b/',
			'GitHub token'                => '/\bgh[pousr]_[A-Za-z0-9]{16,}\b/',
			'AWS access key id'           => '/\bAKIA[0-9A-Z]{16}\b/',
			'Slack token'                 => '/\bxox[baprs]-[A-Za-z0-9\-]{10,}\b/',
			'Google API key'              => '/\bAIza[0-9A-Za-z_\-]{30,}\b/',
			'Private key block'           => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
			'JSON web token'              => '/\beyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\b/',
			'WordPress application password' => '/\b(?:[A-Za-z0-9]{4}\s){5}[A-Za-z0-9]{4}\b/',
			'Assigned password or secret' => '/\b(?:password|passwd|secret|api[_\-]?key|access[_\-]?token)\s*[:=]\s*[\'"]?[^\s\'"]{8,}/i',
		);
	}

	/**
	 * Scan text. Returns the human-readable names of any patterns that matched,
	 * or an empty array when the text is clean.
	 */
	public static function scan( $text ) {
		if ( AI_Scratchpad_Settings::get( 'allow_credentials' ) ) {
			return array();
		}
		$hits = array();
		foreach ( self::patterns() as $label => $regex ) {
			if ( preg_match( $regex, (string) $text ) ) {
				$hits[] = $label;
			}
		}
		return $hits;
	}

	/** Scan several fields at once. Returns a WP_Error, or null when clean. */
	public static function check( array $fields ) {
		$hits = array();
		foreach ( $fields as $text ) {
			$hits = array_merge( $hits, self::scan( $text ) );
		}
		$hits = array_unique( $hits );

		if ( ! $hits ) {
			return null;
		}

		return new WP_Error(
			'scratchpad_credential_blocked',
			sprintf(
				'Refused: this looks like it contains a credential (%s). Store the secret in a password manager and write a pointer here instead, for example "API key is in Vaultwarden under Acme SMTP". A site administrator can turn this check off under AI Scratchpad, Settings.',
				implode( ', ', $hits )
			)
		);
	}
}

/* ============================================================================
 * SECTION 4: ABILITIES
 * The whole MCP surface. Registered on the core Abilities API, so every MCP
 * server on the site exposes them without knowing this plugin exists.
 * ==========================================================================*/

final class AI_Scratchpad_Abilities {

	const NS = 'ai-scratchpad';

	/** Every ability is administrator-only. This is a site operations tool. */
	public static function can() {
		return current_user_can( 'manage_options' );
	}

	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'ai-scratchpad',
			array(
				'label'       => 'AI Scratchpad',
				'description' => 'Session journal and durable notes kept on the site itself.',
			)
		);
	}

	/** Shorthand so each registration below stays readable. */
	private static function add( $name, $label, $description, $input, $output, $callback, $read_only = false ) {
		wp_register_ability(
			self::NS . '/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'ai-scratchpad',
				'input_schema'        => $input,
				'output_schema'       => $output,
				'execute_callback'    => $callback,
				'permission_callback' => array( __CLASS__, 'can' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readOnlyHint'    => (bool) $read_only,
						'destructiveHint' => false,
					),
				),
			)
		);
	}

	private static function obj( $properties, $required = array() ) {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
		if ( $required ) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	private static function str( $description ) {
		return array(
			'type'        => 'string',
			'description' => $description,
		);
	}

	public static function register() {

		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		/* ---- 1. bootstrap -------------------------------------------------
		 * The one call an assistant makes at the start of a session. */
		self::add(
			'bootstrap',
			'Read the scratchpad',
			'Call this first, before doing anything else on this site. Returns the active handoff from the last session, every pinned note in full, the paths of all other notes, recent journal entries, anything waiting on a human, and a fingerprint of the site.',
			self::obj(
				array(
					'entries' => array(
						'type'        => 'integer',
						'description' => 'How many recent journal entries to include. Defaults to the site setting.',
					),
				)
			),
			array( 'type' => 'object' ),
			array( __CLASS__, 'do_bootstrap' ),
			true
		);

		/* ---- 2. write-entry ----------------------------------------------- */
		self::add(
			'write-entry',
			'Write a journal entry',
			'Append one record to the session journal: what you did and why. Writing type "handoff" archives the previous handoff automatically. Journal entries are never edited, only amended.',
			self::obj(
				array(
					'type'       => array(
						'type'        => 'string',
						'enum'        => array( 'handoff', 'change', 'decision', 'gotcha', 'question' ),
						'description' => 'handoff: state of play for the next session. change: something you altered. decision: a choice made and why. gotcha: a trap worth remembering. question: something only a human can answer.',
					),
					'title'      => self::str( 'One line, readable months later with no context.' ),
					'why'        => self::str( 'Why this was done, in plain language. The most valuable field here.' ),
					'body'       => self::str( 'Detail, in Markdown.' ),
					'objects'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'What was touched, as type:id tokens: post:52, snippet:125, option:name, file:functions.php.',
					),
					'verified'   => self::str( 'What you checked, and how you checked it.' ),
					'undo'       => self::str( 'How to reverse this.' ),
					'waiting_on' => self::str( 'What a human still has to do. Surfaces at the top of the next bootstrap.' ),
					'agent'      => self::str( 'Your name, for example Claude.' ),
					'model'      => self::str( 'Your model identifier.' ),
					'human'      => self::str( 'Who you were working with, or "unattended".' ),
					'session'    => self::str( 'Any stable string that groups this session into one thread.' ),
					'tags'       => self::str( 'Comma separated.' ),
					// No items type on purpose. Clients and connectors disagree about whether
					// an id travels as 12 or "12", and a Draft 2020-12 integer constraint
					// rejects the string form before the callback ever runs. The ids are
					// cast server-side instead.
					'acknowledges' => array(
						'type'        => 'array',
						'description' => 'Ids of human comments from bootstrap that this record answers.',
					),
					'resolves'     => array(
						'type'        => 'array',
						'description' => 'Ids of earlier entries whose waiting_on has now been satisfied. Only when it truly was.',
					),
				),
				array( 'type', 'title' )
			),
			array( 'type' => 'object' ),
			array( __CLASS__, 'do_write_entry' )
		);

		/* ---- 3. write-note ------------------------------------------------ */
		self::add(
			'write-note',
			'Create or replace a note',
			'Durable knowledge, not session history: how this site works, conventions, gotchas that keep costing time. Addressed by a path such as conventions/naming or gotchas/pods-rest, where the prefix is the folder. Replaces the note at that path.',
			self::obj(
				array(
					'path'              => self::str( 'Folder and name, for example infrastructure/caching. Lowercase, slashes for folders.' ),
					'title'             => self::str( 'Human readable title.' ),
					'body'              => self::str( 'The note, in Markdown.' ),
					'pinned'            => array(
						'type'        => 'boolean',
						'description' => 'Pinned notes are returned in full by bootstrap on every session. Use for standing instructions and hard-won traps. Keep the set small.',
					),
					'tags'              => self::str( 'Comma separated.' ),
					'expected_revision' => array(
						'type'        => 'integer',
						'description' => 'Optional. Fails the write if the note has changed since you read it at this revision.',
					),
				),
				array( 'path', 'body' )
			),
			array( 'type' => 'object' ),
			array( __CLASS__, 'do_write_note' )
		);

		/* ---- 4. edit-note -------------------------------------------------- */
		self::add(
			'edit-note',
			'Edit part of a note',
			'Replace one exact piece of text inside a note without resending the whole thing. The search string must appear exactly once unless replace_all is set.',
			self::obj(
				array(
					'path'        => self::str( 'Path of the note to edit.' ),
					'old_string'  => self::str( 'Exact text to replace, including whitespace.' ),
					'new_string'  => self::str( 'Replacement text. An empty string deletes the match.' ),
					'replace_all' => array(
						'type'        => 'boolean',
						'description' => 'Replace every occurrence instead of requiring exactly one.',
					),
				),
				array( 'path', 'old_string', 'new_string' )
			),
			array( 'type' => 'object' ),
			array( __CLASS__, 'do_edit_note' )
		);

		/* ---- 5. get --------------------------------------------------------- */
		self::add(
			'get',
			'Read one entry or note',
			'Fetch a single journal entry by id, or a single note by path, in full.',
			self::obj(
				array(
					'id'   => array(
						'type'        => 'integer',
						'description' => 'Journal entry or note id.',
					),
					'path' => self::str( 'Note path. Use this or id.' ),
				)
			),
			array( 'type' => 'object' ),
			array( __CLASS__, 'do_get' ),
			true
		);

		/* ---- 6. search ------------------------------------------------------ */
		self::add(
			'search',
			'Search the scratchpad',
			'Search journal entries and notes together. Filter by free text, by an object that was touched, by folder, by type, by agent, or by date.',
			self::obj(
				array(
					'query'            => self::str( 'Free text across title, body, why and path.' ),
					'kind'             => array(
						'type' => 'string',
						'enum' => array( 'entry', 'note', 'any' ),
					),
					'type'             => self::str( 'Journal entry type: handoff, change, decision, gotcha, question.' ),
					'object'           => self::str( 'An object token, for example snippet:68. Answers "when did we last touch this".' ),
					'path_prefix'      => self::str( 'Limit to a note folder, for example gotchas/.' ),
					'agent'            => self::str( 'Which assistant wrote it.' ),
					'since'            => self::str( 'ISO 8601 date. Only results at or after this.' ),
					'until'            => self::str( 'ISO 8601 date. Only results at or before this.' ),
					'include_archived' => array( 'type' => 'boolean' ),
					'limit'            => array( 'type' => 'integer' ),
				)
			),
			array( 'type' => 'object' ),
			array( __CLASS__, 'do_search' ),
			true
		);

		/* ---- 7. amend -------------------------------------------------------- */
		self::add(
			'amend',
			'Amend a journal entry',
			'Correct an earlier journal entry by appending a visible amendment. The original is never rewritten, because a journal you can quietly edit is not a record.',
			self::obj(
				array(
					'id'    => array( 'type' => 'integer' ),
					'body'  => self::str( 'The correction.' ),
					'why'   => self::str( 'Why the original was wrong.' ),
					'agent' => self::str( 'Your name.' ),
					'acknowledges' => array(
						'type'        => 'array',
						'description' => 'Ids of human comments this amendment answers.',
					),
					'resolves'     => array(
						'type'        => 'array',
						'description' => 'Ids of earlier entries whose waiting_on request has now been satisfied.',
					),
				),
				array( 'id', 'body' )
			),
			array( 'type' => 'object' ),
			array( __CLASS__, 'do_amend' )
		);

		/* ---- 8. move-note ----------------------------------------------------- */
		self::add(
			'move-note',
			'Move or archive a note',
			'Rename a note, move it to another folder, move a whole folder, or archive it. Archiving hides a note without destroying it.',
			self::obj(
				array(
					'from'    => self::str( 'Existing note path, or a folder prefix ending in a slash to move everything under it.' ),
					'to'      => self::str( 'New path or new folder prefix.' ),
					'archive' => array(
						'type'        => 'boolean',
						'description' => 'Archive instead of moving. Ignores "to".',
					),
				),
				array( 'from' )
			),
			array( 'type' => 'object' ),
			array( __CLASS__, 'do_move_note' )
		);

		/* ---- 9. export --------------------------------------------------------- */
		self::add(
			'export',
			'Export the whole scratchpad',
			'Return every entry and note as structured data, so it can be mirrored into a repository or an external knowledge base. A record that exists only in one database dies with that database.',
			self::obj(
				array(
					'since'            => self::str( 'ISO 8601 date. Only what changed at or after this.' ),
					'include_archived' => array( 'type' => 'boolean' ),
				)
			),
			array( 'type' => 'object' ),
			array( __CLASS__, 'do_export' ),
			true
		);

		/* ---- 10. delete, only when a human has switched it on -------------------- */
		if ( AI_Scratchpad_Settings::get( 'allow_delete' ) ) {
			self::add(
				'delete',
				'Permanently delete',
				'Permanently remove one entry or note. This cannot be undone. Prefer archiving.',
				self::obj(
					array(
						'id'      => array( 'type' => 'integer' ),
						'path'    => self::str( 'Note path. Use this or id.' ),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true. Guards against an accidental call.',
						),
					),
					array( 'confirm' )
				),
				array( 'type' => 'object' ),
				array( __CLASS__, 'do_delete' )
			);
		}
	}

	/* ------------------------------------------------------------------------
	 * Callbacks
	 * ----------------------------------------------------------------------*/

	/** Trim a stored row down to what an assistant actually needs to read. */
	public static function shape( $row, $full = true ) {
		if ( ! $row ) {
			return null;
		}
		$out = array(
			'id'         => (int) $row['id'],
			'kind'       => $row['kind'],
			'created_at' => $row['created_at'],
			'updated_at' => $row['updated_at'],
			'status'     => $row['status'],
			'title'      => $row['title'],
		);

		if ( 'note' === $row['kind'] ) {
			$out['path']     = $row['path'];
			$out['pinned']   = (bool) $row['pinned'];
			$out['revision'] = (int) $row['revision'];

			// A human correcting a note is the most important signal in here. Say so
			// plainly rather than leaving the assistant to notice a revision bump.
			if ( ! empty( $row['edited_by_name'] ) ) {
				$out['last_edited_by_human'] = array(
					'name' => $row['edited_by_name'],
					'at'   => $row['edited_at'],
					'note' => 'A person edited this note by hand. Treat it as correcting whatever an assistant wrote before.',
				);
			}
		} elseif ( 'comment' === $row['kind'] ) {
			$out['parent_id'] = (int) $row['parent_id'];
			$out['author']    = $row['edited_by_name'];
			$out['answered']  = ( 'active' !== $row['status'] );
		} else {
			$out['type']    = $row['type'];
			$out['why']     = $row['why'];
			$out['agent']   = $row['agent'];
			$out['model']   = $row['model'];
			$out['human']   = $row['human'];
			$out['session'] = $row['session'];
			$out['objects'] = $row['objects'] ? json_decode( $row['objects'], true ) : array();
			if ( $row['verified'] ) {
				$out['verified'] = $row['verified'];
			}
			if ( $row['undo'] ) {
				$out['undo'] = $row['undo'];
			}
			if ( $row['waiting_on'] ) {
				$out['waiting_on'] = $row['waiting_on'];
			}
		}

		if ( $row['tags'] ) {
			$out['tags'] = $row['tags'];
		}

		if ( $full ) {
			$out['body'] = $row['body'];
		}

		return $out;
	}

	/** A short description of the site, so an assistant does not have to go looking. */
	private static function site_fingerprint() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active  = (array) get_option( 'active_plugins', array() );
		$all     = get_plugins();
		$plugins = array();
		foreach ( $active as $file ) {
			if ( isset( $all[ $file ] ) ) {
				$plugins[] = $all[ $file ]['Name'] . ' ' . $all[ $file ]['Version'];
			}
		}
		$theme = wp_get_theme();

		return array(
			'name'           => get_bloginfo( 'name' ),
			'url'            => home_url(),
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'theme'          => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'active_plugins' => $plugins,
		);
	}

	public static function do_bootstrap( $input = array() ) {
		global $wpdb;
		$table = self::table_or_fail();
		if ( is_wp_error( $table ) ) {
			return $table;
		}

		$input    = is_array( $input ) ? $input : array();
		$settings = AI_Scratchpad_Settings::get();
		$limit    = isset( $input['entries'] ) ? max( 1, min( 100, (int) $input['entries'] ) ) : (int) $settings['bootstrap_entries'];

		$handoff = AI_Scratchpad_Store::active_handoff();

		$pinned = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE kind = 'note' AND status = 'active' AND pinned = 1 ORDER BY path ASC",
			ARRAY_A
		);

		$tree = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT path, title, updated_at, pinned FROM {$table} WHERE kind = 'note' AND status = 'active' ORDER BY path ASC LIMIT %d",
				(int) $settings['bootstrap_tree']
			),
			ARRAY_A
		);

		$note_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE kind = 'note' AND status = 'active'" );

		$recent = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE kind = 'entry' ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		// Deliberately not filtered by status: archiving a handoff must never hide
		// something a human still has to do.
		$waiting = $wpdb->get_results(
			"SELECT id, created_at, title, waiting_on, agent FROM {$table} WHERE kind = 'entry' AND waiting_on <> '' ORDER BY id DESC LIMIT 25",
			ARRAY_A
		);

		// Comments a human left that no assistant has answered yet. These outrank
		// everything else in the response: a person took the trouble to write on a
		// specific entry or note, and the next session must see it.
		$comments = $wpdb->get_results(
			"SELECT c.id, c.parent_id, c.created_at, c.body, c.edited_by_name AS author,
			        p.kind AS on_kind, p.title AS on_title, p.path AS on_path
			   FROM {$table} c
			   LEFT JOIN {$table} p ON p.id = c.parent_id
			  WHERE c.kind = 'comment' AND c.status = 'active'
			  ORDER BY c.id ASC LIMIT 50",
			ARRAY_A
		);

		$read_me_first = $handoff
			? 'There is an active handoff from the last session. Read it before changing anything.'
			: 'No handoff on file yet. Write one before this session ends, with ai-scratchpad/write-entry type handoff.';

		if ( $comments ) {
			$read_me_first = sprintf(
				'%d unanswered comment%s from a human. Read human_comments first, before the handoff. %s',
				count( $comments ),
				1 === count( $comments ) ? '' : 's',
				$read_me_first
			);
		}

		return array(
			'read_me_first'    => $read_me_first,
			'site'             => self::site_fingerprint(),
			'active_handoff'   => self::shape( $handoff ),
			'pinned_notes'     => array_map( array( __CLASS__, 'shape' ), (array) $pinned ),
			'note_count'       => $note_count,
			'notes'            => $tree,
			'notes_truncated'  => $note_count > count( (array) $tree ),
			'recent_entries'   => array_map(
				function ( $r ) {
					return AI_Scratchpad_Abilities::shape( $r, false );
				},
				(array) $recent
			),
			'waiting_on_human' => $waiting,
			'human_comments'   => $comments,
			'human_comments_note' => $comments
				? 'A human left these and no assistant has answered them. Address them before other work, then acknowledge them by passing their ids as "acknowledges" on write-entry or amend.'
				: '',
			'settings'         => array(
				'credential_blocking' => ! $settings['allow_credentials'],
				'delete_enabled'      => (bool) $settings['allow_delete'],
			),
		);
	}

	public static function do_write_entry( $input = array() ) {
		$table = self::table_or_fail();
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$input = is_array( $input ) ? $input : array();

		$guard = AI_Scratchpad_Guard::check(
			array(
				isset( $input['title'] ) ? $input['title'] : '',
				isset( $input['body'] ) ? $input['body'] : '',
				isset( $input['why'] ) ? $input['why'] : '',
				isset( $input['undo'] ) ? $input['undo'] : '',
				isset( $input['verified'] ) ? $input['verified'] : '',
				isset( $input['waiting_on'] ) ? $input['waiting_on'] : '',
			)
		);
		if ( $guard ) {
			return $guard;
		}

		$type     = isset( $input['type'] ) ? $input['type'] : 'change';
		$objects  = isset( $input['objects'] ) ? (array) $input['objects'] : array();
		$archived = 0;

		if ( 'handoff' === $type ) {
			$archived = (int) AI_Scratchpad_Store::archive_handoffs();
		}

		$id = AI_Scratchpad_Store::insert(
			array(
				'kind'         => 'entry',
				'type'         => $type,
				'title'        => sanitize_text_field( isset( $input['title'] ) ? $input['title'] : '' ),
				'body'         => (string) ( isset( $input['body'] ) ? $input['body'] : '' ),
				'why'          => (string) ( isset( $input['why'] ) ? $input['why'] : '' ),
				'verified'     => (string) ( isset( $input['verified'] ) ? $input['verified'] : '' ),
				'undo'         => (string) ( isset( $input['undo'] ) ? $input['undo'] : '' ),
				'waiting_on'   => (string) ( isset( $input['waiting_on'] ) ? $input['waiting_on'] : '' ),
				'objects'      => $objects ? wp_json_encode( array_values( $objects ) ) : '',
				'objects_flat' => AI_Scratchpad_Store::flatten_objects( $objects ),
				'tags'         => sanitize_text_field( isset( $input['tags'] ) ? $input['tags'] : '' ),
				'agent'        => sanitize_text_field( isset( $input['agent'] ) ? $input['agent'] : '' ),
				'model'        => sanitize_text_field( isset( $input['model'] ) ? $input['model'] : '' ),
				'human'        => sanitize_text_field( isset( $input['human'] ) ? $input['human'] : '' ),
				'session'      => sanitize_text_field( isset( $input['session'] ) ? $input['session'] : '' ),
			)
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$acknowledged = self::acknowledge_comments( isset( $input['acknowledges'] ) ? $input['acknowledges'] : array() );
		$resolved     = self::resolve_waiting( isset( $input['resolves'] ) ? $input['resolves'] : array() );

		return array(
			'id'                => $id,
			'type'              => $type,
			'resolved_waiting'  => $resolved,
			'archived_previous' => $archived,
			'acknowledged_comments' => $acknowledged,
			'message'           => 'handoff' === $type
				? ( $archived
					? 'Handoff written. The previous handoff was archived, not deleted.'
					: 'Handoff written. This is the first one on this site.' )
				: 'Entry written.',
		);
	}

	public static function do_write_note( $input = array() ) {
		$table = self::table_or_fail();
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$input = is_array( $input ) ? $input : array();

		$guard = AI_Scratchpad_Guard::check(
			array(
				isset( $input['title'] ) ? $input['title'] : '',
				isset( $input['body'] ) ? $input['body'] : '',
			)
		);
		if ( $guard ) {
			return $guard;
		}

		$path = AI_Scratchpad_Store::clean_path( isset( $input['path'] ) ? $input['path'] : '' );
		if ( '' === $path ) {
			return new WP_Error( 'scratchpad_bad_path', 'A note needs a path, for example conventions/naming.' );
		}

		$existing = AI_Scratchpad_Store::get_note( $path );

		if ( $existing && isset( $input['expected_revision'] ) && (int) $input['expected_revision'] !== (int) $existing['revision'] ) {
			return new WP_Error(
				'scratchpad_revision_conflict',
				sprintf(
					'The note at %1$s is now at revision %2$d, not %3$d. Someone changed it since you read it. Read it again and merge.',
					$path,
					(int) $existing['revision'],
					(int) $input['expected_revision']
				)
			);
		}

		$fields = array(
			'title'  => sanitize_text_field( isset( $input['title'] ) ? $input['title'] : ( $existing ? $existing['title'] : $path ) ),
			'body'   => (string) ( isset( $input['body'] ) ? $input['body'] : '' ),
			'tags'   => sanitize_text_field( isset( $input['tags'] ) ? $input['tags'] : ( $existing ? $existing['tags'] : '' ) ),
			'pinned' => isset( $input['pinned'] ) ? ( $input['pinned'] ? 1 : 0 ) : ( $existing ? (int) $existing['pinned'] : 0 ),
		);

		if ( $existing ) {
			$fields['revision'] = (int) $existing['revision'] + 1;
			AI_Scratchpad_Store::update( $existing['id'], $fields );
			return array(
				'id'       => (int) $existing['id'],
				'path'     => $path,
				'revision' => $fields['revision'],
				'created'  => false,
				'message'  => 'Note replaced.',
			);
		}

		$id = AI_Scratchpad_Store::insert( array_merge( $fields, array( 'kind' => 'note', 'path' => $path ) ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return array(
			'id'       => $id,
			'path'     => $path,
			'revision' => 1,
			'created'  => true,
			'message'  => 'Note created.',
		);
	}

	public static function do_edit_note( $input = array() ) {
		$table = self::table_or_fail();
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$input = is_array( $input ) ? $input : array();

		$path = AI_Scratchpad_Store::clean_path( isset( $input['path'] ) ? $input['path'] : '' );
		$note = AI_Scratchpad_Store::get_note( $path );

		if ( ! $note ) {
			return new WP_Error( 'scratchpad_not_found', sprintf( 'No note at %s.', $path ) );
		}

		$old = (string) ( isset( $input['old_string'] ) ? $input['old_string'] : '' );
		$new = (string) ( isset( $input['new_string'] ) ? $input['new_string'] : '' );

		$guard = AI_Scratchpad_Guard::check( array( $new ) );
		if ( $guard ) {
			return $guard;
		}

		$count = substr_count( $note['body'], $old );

		if ( 0 === $count ) {
			return new WP_Error( 'scratchpad_no_match', 'That text does not appear in the note. Read the note and copy the text exactly, whitespace included.' );
		}

		if ( $count > 1 && empty( $input['replace_all'] ) ) {
			return new WP_Error(
				'scratchpad_ambiguous',
				sprintf( 'That text appears %d times. Include more surrounding text to make it unique, or set replace_all.', $count )
			);
		}

		$body = empty( $input['replace_all'] )
			? implode( $new, explode( $old, $note['body'], 2 ) )
			: str_replace( $old, $new, $note['body'] );

		AI_Scratchpad_Store::update(
			$note['id'],
			array(
				'body'     => $body,
				'revision' => (int) $note['revision'] + 1,
			)
		);

		return array(
			'path'         => $path,
			'replacements' => empty( $input['replace_all'] ) ? 1 : $count,
			'revision'     => (int) $note['revision'] + 1,
		);
	}

	public static function do_get( $input = array() ) {
		global $wpdb;
		$table = self::table_or_fail();
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$input = is_array( $input ) ? $input : array();

		if ( ! empty( $input['path'] ) ) {
			$row = AI_Scratchpad_Store::get_note( AI_Scratchpad_Store::clean_path( $input['path'] ) );
		} elseif ( ! empty( $input['id'] ) ) {
			$row = AI_Scratchpad_Store::get( (int) $input['id'] );
		} else {
			return new WP_Error( 'scratchpad_bad_request', 'Pass either id or path.' );
		}

		if ( ! $row ) {
			return new WP_Error( 'scratchpad_not_found', 'Nothing found.' );
		}

		$out = self::shape( $row );

		// Amendments hang off the entry they correct.
		$amendments = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE parent_id = %d ORDER BY id ASC", (int) $row['id'] ),
			ARRAY_A
		);
		if ( $amendments ) {
			$out['amendments'] = array_map( array( __CLASS__, 'shape' ), $amendments );
		}

		return $out;
	}

	public static function do_search( $input = array() ) {
		global $wpdb;
		$table = self::table_or_fail();
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$input = is_array( $input ) ? $input : array();

		$where = array( '1=1' );
		$args  = array();

		if ( empty( $input['include_archived'] ) ) {
			$where[] = "status = 'active'";
		}

		$kind = isset( $input['kind'] ) ? $input['kind'] : 'any';
		if ( 'any' !== $kind ) {
			$where[] = 'kind = %s';
			$args[]  = $kind;
		}

		if ( ! empty( $input['type'] ) ) {
			$where[] = 'type = %s';
			$args[]  = $input['type'];
		}

		if ( ! empty( $input['agent'] ) ) {
			$where[] = 'agent LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $input['agent'] ) . '%';
		}

		if ( ! empty( $input['path_prefix'] ) ) {
			$where[] = 'path LIKE %s';
			$args[]  = $wpdb->esc_like( AI_Scratchpad_Store::clean_path( $input['path_prefix'] ) ) . '%';
		}

		if ( ! empty( $input['object'] ) ) {
			$token   = strtolower( preg_replace( '/\s+/', '', $input['object'] ) );
			$where[] = 'objects_flat LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( ' ' . $token . ' ' ) . '%';
		}

		if ( ! empty( $input['query'] ) ) {
			$like    = '%' . $wpdb->esc_like( $input['query'] ) . '%';
			$where[] = '(title LIKE %s OR body LIKE %s OR why LIKE %s OR path LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}

		if ( ! empty( $input['since'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = gmdate( 'Y-m-d H:i:s', strtotime( $input['since'] ) );
		}

		if ( ! empty( $input['until'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = gmdate( 'Y-m-d H:i:s', strtotime( $input['until'] ) );
		}

		$limit  = isset( $input['limit'] ) ? max( 1, min( 200, (int) $input['limit'] ) ) : 25;
		$args[] = $limit;

		$sql  = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return array(
			'count'   => count( (array) $rows ),
			'results' => array_map(
				function ( $r ) {
					return AI_Scratchpad_Abilities::shape( $r, false );
				},
				(array) $rows
			),
			'hint'    => 'Bodies are omitted here. Use ai-scratchpad/get with the id or path to read one in full.',
		);
	}

	public static function do_amend( $input = array() ) {
		$table = self::table_or_fail();
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$input = is_array( $input ) ? $input : array();

		$parent = AI_Scratchpad_Store::get( (int) ( isset( $input['id'] ) ? $input['id'] : 0 ) );
		if ( ! $parent || 'entry' !== $parent['kind'] ) {
			return new WP_Error( 'scratchpad_not_found', 'No journal entry with that id.' );
		}

		$guard = AI_Scratchpad_Guard::check(
			array(
				isset( $input['body'] ) ? $input['body'] : '',
				isset( $input['why'] ) ? $input['why'] : '',
			)
		);
		if ( $guard ) {
			return $guard;
		}

		$id = AI_Scratchpad_Store::insert(
			array(
				'kind'      => 'entry',
				'type'      => 'amendment',
				'title'     => 'Amendment to #' . (int) $parent['id'] . ': ' . $parent['title'],
				'body'      => (string) ( isset( $input['body'] ) ? $input['body'] : '' ),
				'why'       => (string) ( isset( $input['why'] ) ? $input['why'] : '' ),
				'parent_id' => (int) $parent['id'],
				'agent'     => sanitize_text_field( isset( $input['agent'] ) ? $input['agent'] : '' ),
			)
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$acknowledged = self::acknowledge_comments( isset( $input['acknowledges'] ) ? $input['acknowledges'] : array() );
		$resolved     = self::resolve_waiting( isset( $input['resolves'] ) ? $input['resolves'] : array() );

		return array(
			'id'      => $id,
			'amends'  => (int) $parent['id'],
			'resolved_waiting' => $resolved,
			'acknowledged_comments' => $acknowledged,
			'message' => 'Amendment recorded. The original entry is unchanged, as it should be.',
		);
	}

	public static function do_move_note( $input = array() ) {
		global $wpdb;
		$table = self::table_or_fail();
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$input = is_array( $input ) ? $input : array();

		$raw_from = (string) ( isset( $input['from'] ) ? $input['from'] : '' );
		$from     = AI_Scratchpad_Store::clean_path( $raw_from );
		if ( '' === $from ) {
			return new WP_Error( 'scratchpad_bad_path', 'Pass the path to move.' );
		}

		$is_folder = ( '/' === substr( $raw_from, -1 ) );

		if ( ! empty( $input['archive'] ) ) {
			$sql = $is_folder
				? $wpdb->prepare( "UPDATE {$table} SET status = 'archived' WHERE kind = 'note' AND status = 'active' AND path LIKE %s", $wpdb->esc_like( $from ) . '%' )
				: $wpdb->prepare( "UPDATE {$table} SET status = 'archived' WHERE kind = 'note' AND status = 'active' AND path = %s", $from );
			$n   = $wpdb->query( $sql );
			return array(
				'archived' => (int) $n,
				'message'  => 'Archived, not deleted. Search with include_archived to find it again.',
			);
		}

		$to = AI_Scratchpad_Store::clean_path( isset( $input['to'] ) ? $input['to'] : '' );
		if ( '' === $to ) {
			return new WP_Error( 'scratchpad_bad_path', 'Pass "to", or set archive.' );
		}

		if ( $is_folder ) {
			$rows  = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, path FROM {$table} WHERE kind = 'note' AND status = 'active' AND path LIKE %s", $wpdb->esc_like( $from ) . '%' ),
				ARRAY_A
			);
			$moved = 0;
			foreach ( (array) $rows as $r ) {
				$new = $to . substr( $r['path'], strlen( $from ) );
				AI_Scratchpad_Store::update( $r['id'], array( 'path' => AI_Scratchpad_Store::clean_path( $new ) ) );
				++$moved;
			}
			return array(
				'moved' => $moved,
				'from'  => $from,
				'to'    => $to,
			);
		}

		$note = AI_Scratchpad_Store::get_note( $from );
		if ( ! $note ) {
			return new WP_Error( 'scratchpad_not_found', sprintf( 'No note at %s.', $from ) );
		}
		if ( AI_Scratchpad_Store::get_note( $to ) ) {
			return new WP_Error( 'scratchpad_path_taken', sprintf( 'There is already a note at %s.', $to ) );
		}

		AI_Scratchpad_Store::update( $note['id'], array( 'path' => $to ) );

		return array(
			'moved' => 1,
			'from'  => $from,
			'to'    => $to,
		);
	}

	public static function do_export( $input = array() ) {
		global $wpdb;
		$table = self::table_or_fail();
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$input = is_array( $input ) ? $input : array();

		$where = array( '1=1' );
		$args  = array();

		if ( empty( $input['include_archived'] ) ) {
			$where[] = "status = 'active'";
		}
		if ( ! empty( $input['since'] ) ) {
			$where[] = 'updated_at >= %s';
			$args[]  = gmdate( 'Y-m-d H:i:s', strtotime( $input['since'] ) );
		}

		$sql  = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id ASC';
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

		$entries = array();
		$notes   = array();
		foreach ( (array) $rows as $r ) {
			if ( 'note' === $r['kind'] ) {
				$notes[] = self::shape( $r );
			} else {
				$entries[] = self::shape( $r );
			}
		}

		return array(
			'site'        => home_url(),
			'exported_at' => current_time( 'mysql', true ),
			'plugin'      => 'ai-scratchpad ' . AI_SCRATCHPAD_VERSION,
			'entry_count' => count( $entries ),
			'note_count'  => count( $notes ),
			'entries'     => $entries,
			'notes'       => $notes,
		);
	}

	public static function do_delete( $input = array() ) {
		global $wpdb;
		$table = self::table_or_fail();
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$input = is_array( $input ) ? $input : array();

		if ( ! AI_Scratchpad_Settings::get( 'allow_delete' ) ) {
			return new WP_Error( 'scratchpad_delete_disabled', 'Deletion is switched off for this site.' );
		}
		if ( empty( $input['confirm'] ) ) {
			return new WP_Error( 'scratchpad_confirm_required', 'Set confirm to true. This cannot be undone.' );
		}

		if ( ! empty( $input['path'] ) ) {
			$row = AI_Scratchpad_Store::get_note( AI_Scratchpad_Store::clean_path( $input['path'] ) );
		} else {
			$row = AI_Scratchpad_Store::get( (int) ( isset( $input['id'] ) ? $input['id'] : 0 ) );
		}

		if ( ! $row ) {
			return new WP_Error( 'scratchpad_not_found', 'Nothing found.' );
		}

		$wpdb->delete( $table, array( 'id' => (int) $row['id'] ) );

		return array(
			'deleted' => (int) $row['id'],
			'message' => 'Permanently deleted.',
		);
	}

	/** Guard every callback: a missing table means the plugin never activated cleanly. */
	/**
	 * Mark human comments answered. Only comments are touched, and only ones that
	 * are still open, so a stray id can neither archive a journal entry nor
	 * re-close something already handled.
	 */
	public static function acknowledge_comments( $ids ) {
		global $wpdb;

		// Connectors vary in how an array of ids survives the trip: a real array, a
		// JSON string, or a comma-separated string all turn up in practice. Normalize
		// rather than silently acknowledging nothing, which is what 0.2.1 did.
		if ( is_string( $ids ) ) {
			$decoded = json_decode( $ids, true );
			$ids     = is_array( $decoded ) ? $decoded : explode( ',', $ids );
		}

		$ids = array_filter( array_map( 'intval', (array) $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$table  = AI_Scratchpad_Store::table();
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$now    = current_time( 'mysql', true );
		$params = array_merge( array( $now ), $ids );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'archived', updated_at = %s
				  WHERE kind = 'comment' AND status = 'active' AND id IN ({$in})",
				$params
			)
		);
	}

	/**
	 * Clear the waiting_on field on entries a human has now dealt with.
	 *
	 * bootstrap deliberately surfaces waiting_on regardless of status, so archiving
	 * a handoff cannot hide something a person still owes. The cost of that choice
	 * is that a resolved request would otherwise sit at the top of every future
	 * session forever. This is how it gets closed.
	 */
	public static function resolve_waiting( $ids ) {
		global $wpdb;

		if ( is_string( $ids ) ) {
			$decoded = json_decode( $ids, true );
			$ids     = is_array( $decoded ) ? $decoded : explode( ',', $ids );
		}

		$ids = array_filter( array_map( 'intval', (array) $ids ) );
		if ( ! $ids ) {
			return 0;
		}

		$table  = AI_Scratchpad_Store::table();
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$now    = current_time( 'mysql', true );
		$params = array_merge( array( $now ), $ids );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET waiting_on = '', updated_at = %s
				  WHERE kind = 'entry' AND waiting_on <> '' AND id IN ({$in})",
				$params
			)
		);
	}

	private static function table_or_fail() {
		global $wpdb;
		$table  = AI_Scratchpad_Store::table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			AI_Scratchpad_Store::install();
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		}
		return $exists ? $table : new WP_Error( 'scratchpad_no_table', 'The scratchpad table is missing and could not be created.' );
	}
}

/* ============================================================================
 * SECTION 5: ADMIN
 * One screen for the human. Read mostly: this is where you audit what the
 * assistants have been doing, not where you do the work.
 * ==========================================================================*/

final class AI_Scratchpad_Admin {

	public static function menu() {
		add_menu_page(
			'AI Scratchpad',
			'AI Scratchpad',
			'manage_options',
			'ai-scratchpad',
			array( __CLASS__, 'render' ),
			'dashicons-welcome-write-blog',
			81
		);
		add_submenu_page(
			'ai-scratchpad',
			'Settings',
			'Settings',
			'manage_options',
			'ai-scratchpad-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	/* -- form handlers ---------------------------------------------------- */

	public static function register_actions() {
		add_action( 'admin_post_ai_scratchpad_comment', array( __CLASS__, 'handle_comment' ) );
		add_action( 'admin_post_ai_scratchpad_edit_note', array( __CLASS__, 'handle_edit_note' ) );
	}

	private static function back( $id, $flag ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => 'ai-scratchpad',
					'entry' => (int) $id,
					'done'  => $flag,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * A person writing on an entry or a note. Stored as its own row with
	 * kind 'comment' and parent_id set, so it cannot be confused with something an
	 * assistant wrote, and it stays open until an assistant answers it.
	 */
	public static function handle_comment() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$id = isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0;
		check_admin_referer( 'ai_scratchpad_comment_' . $id );

		$parent = AI_Scratchpad_Store::get( $id );
		if ( ! $parent ) {
			wp_die( 'No such record.' );
		}

		$body = isset( $_POST['body'] ) ? trim( wp_unslash( $_POST['body'] ) ) : '';
		if ( '' === $body ) {
			self::back( $id, 'empty' );
		}

		$user = wp_get_current_user();

		AI_Scratchpad_Store::insert(
			array(
				'kind'           => 'comment',
				'title'          => 'Comment on #' . $id,
				'body'           => $body,
				'parent_id'      => $id,
				'human'          => $user->display_name,
				'edited_by_user' => $user->ID,
				'edited_by_name' => $user->display_name,
				'status'         => 'active',
			)
		);

		self::back( $id, 'commented' );
	}

	/** A person editing a note by hand. Bumps the revision and records who. */
	public static function handle_edit_note() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$id = isset( $_POST['note_id'] ) ? (int) $_POST['note_id'] : 0;
		check_admin_referer( 'ai_scratchpad_edit_note_' . $id );

		$note = AI_Scratchpad_Store::get( $id );
		if ( ! $note || 'note' !== $note['kind'] ) {
			wp_die( 'No such note.' );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : $note['title'];
		$body  = isset( $_POST['body'] ) ? trim( wp_unslash( $_POST['body'] ) ) : '';

		// The credential guard applies to people too. A pasted token is a pasted
		// token whoever typed it.
		$guard = AI_Scratchpad_Guard::check( array( $title, $body ) );
		if ( $guard ) {
			set_transient( 'ai_scratchpad_admin_error_' . get_current_user_id(), $guard->get_error_message(), 60 );
			self::back( $id, 'blocked' );
		}

		$user = wp_get_current_user();

		AI_Scratchpad_Store::update(
			$id,
			array(
				'title'          => $title,
				'body'           => $body,
				'revision'       => (int) $note['revision'] + 1,
				'edited_by_user' => $user->ID,
				'edited_by_name' => $user->display_name,
				'edited_at'      => current_time( 'mysql', true ),
			)
		);

		self::back( $id, 'edited' );
	}

	private static function notices() {
		$done = isset( $_GET['done'] ) ? sanitize_key( $_GET['done'] ) : '';
		if ( ! $done ) {
			return;
		}
		$map = array(
			'commented' => array( 'success', 'Comment saved. The next assistant to call bootstrap will see it before anything else.' ),
			'edited'    => array( 'success', 'Note saved, revision bumped, and your name recorded against the edit.' ),
			'empty'     => array( 'warning', 'Nothing to save: the comment was empty.' ),
			'blocked'   => array( 'error', 'Refused: that looked like it contained a credential.' ),
		);
		if ( ! isset( $map[ $done ] ) ) {
			return;
		}
		$extra = get_transient( 'ai_scratchpad_admin_error_' . get_current_user_id() );
		if ( $extra ) {
			delete_transient( 'ai_scratchpad_admin_error_' . get_current_user_id() );
		}
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s%s</p></div>',
			esc_attr( $map[ $done ][0] ),
			esc_html( $map[ $done ][1] ),
			$extra ? ' ' . esc_html( $extra ) : ''
		);
	}

	/* -- screens ---------------------------------------------------------- */

	public static function render() {
		global $wpdb;
		$table = AI_Scratchpad_Store::table();

		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'journal';
		$id   = isset( $_GET['entry'] ) ? (int) $_GET['entry'] : 0;

		echo '<div class="wrap"><h1>AI Scratchpad</h1>';

		self::notices();

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( array( 'journal' => 'Journal', 'notes' => 'Notes' ) as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url( admin_url( 'admin.php?page=ai-scratchpad&view=' . $slug ) ),
				$view === $slug ? 'nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';

		if ( $id ) {
			self::render_single( $id );
			echo '</div>';
			return;
		}

		if ( 'notes' === $view ) {
			self::render_notes( $table );
		} else {
			self::render_journal( $table );
		}

		echo '</div>';
	}

	/** Journal list, filtered by status. Active is the default view. */
	private static function render_journal( $table ) {
		global $wpdb;

		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'active';
		if ( ! in_array( $status, array( 'active', 'archived', 'all' ), true ) ) {
			$status = 'active';
		}

		$counts = $wpdb->get_results(
			"SELECT status, COUNT(*) AS n FROM {$table} WHERE kind = 'entry' GROUP BY status",
			OBJECT_K
		);
		$n_active   = isset( $counts['active'] ) ? (int) $counts['active']->n : 0;
		$n_archived = isset( $counts['archived'] ) ? (int) $counts['archived']->n : 0;

		$links = array(
			'active'   => 'Active (' . $n_active . ')',
			'archived' => 'Archived (' . $n_archived . ')',
			'all'      => 'All (' . ( $n_active + $n_archived ) . ')',
		);

		echo '<ul class="subsubsub">';
		$i = 0;
		foreach ( $links as $slug => $label ) {
			printf(
				'<li>%s<a href="%s" class="%s">%s</a></li>',
				$i++ ? ' | ' : '',
				esc_url( admin_url( 'admin.php?page=ai-scratchpad&view=journal&status=' . $slug ) ),
				$status === $slug ? 'current' : '',
				esc_html( $label )
			);
		}
		echo '</ul><div class="clear"></div>';

		if ( 'all' === $status ) {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE kind = 'entry' ORDER BY created_at DESC, id DESC LIMIT 300", ARRAY_A );
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE kind = 'entry' AND status = %s ORDER BY created_at DESC, id DESC LIMIT 300",
					$status
				),
				ARRAY_A
			);
		}

		echo '<table class="widefat striped"><thead><tr><th>When (UTC)</th><th>Type</th><th>Title</th><th>Why</th><th>Agent</th><th>Status</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">Nothing here yet.</td></tr>';
		}
		foreach ( (array) $rows as $r ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $r['created_at'] ),
				esc_html( $r['type'] ),
				esc_url( admin_url( 'admin.php?page=ai-scratchpad&entry=' . (int) $r['id'] ) ),
				esc_html( $r['title'] ),
				esc_html( wp_trim_words( $r['why'], 18 ) ),
				esc_html( $r['agent'] ),
				esc_html( $r['status'] )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Notes, grouped by folder. Folders are path prefixes, so the tree is derived
	 * rather than stored. Notes with no slash in their path sit at the root.
	 */
	private static function render_notes( $table ) {
		global $wpdb;

		$show_archived = ! empty( $_GET['archived'] );

		$rows = $show_archived
			? $wpdb->get_results( "SELECT * FROM {$table} WHERE kind = 'note' ORDER BY path ASC LIMIT 500", ARRAY_A )
			: $wpdb->get_results( "SELECT * FROM {$table} WHERE kind = 'note' AND status = 'active' ORDER BY path ASC LIMIT 500", ARRAY_A );

		printf(
			'<ul class="subsubsub"><li><a href="%s" class="%s">Active</a> | </li><li><a href="%s" class="%s">Including archived</a></li></ul><div class="clear"></div>',
			esc_url( admin_url( 'admin.php?page=ai-scratchpad&view=notes' ) ),
			$show_archived ? '' : 'current',
			esc_url( admin_url( 'admin.php?page=ai-scratchpad&view=notes&archived=1' ) ),
			$show_archived ? 'current' : ''
		);

		$tree = array();
		foreach ( (array) $rows as $r ) {
			$path   = (string) $r['path'];
			$pos    = strrpos( $path, '/' );
			$folder = ( false === $pos ) ? '' : substr( $path, 0, $pos );
			$leaf   = ( false === $pos ) ? $path : substr( $path, $pos + 1 );

			$r['_leaf'] = $leaf;
			$tree[ $folder ][] = $r;
		}
		ksort( $tree );

		if ( ! $tree ) {
			echo '<p>No notes yet. An assistant creates them with ai-scratchpad/write-note.</p>';
			return;
		}

		foreach ( $tree as $folder => $notes ) {
			printf(
				'<h3 style="margin:24px 0 6px;">%s <span style="font-weight:400;color:#646970;">(%d)</span></h3>',
				$folder ? '<code>' . esc_html( $folder ) . '/</code>' : 'Root',
				count( $notes )
			);
			echo '<table class="widefat striped"><thead><tr><th style="width:28%">Name</th><th>Title</th><th style="width:6%">Pinned</th><th style="width:6%">Rev</th><th style="width:16%">Updated (UTC)</th><th style="width:14%">Last human edit</th></tr></thead><tbody>';
			foreach ( $notes as $r ) {
				printf(
					'<tr><td><a href="%s"><code>%s</code></a></td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
					esc_url( admin_url( 'admin.php?page=ai-scratchpad&entry=' . (int) $r['id'] ) ),
					esc_html( $r['_leaf'] ),
					esc_html( $r['title'] ),
					$r['pinned'] ? '&#9733;' : '',
					(int) $r['revision'],
					esc_html( $r['updated_at'] ),
					esc_html( $r['edited_by_name'] ? $r['edited_by_name'] : '' )
				);
			}
			echo '</tbody></table>';
		}
	}

	/** One entry or note in full, with its comment thread. */
	private static function render_single( $id ) {
		global $wpdb;
		$table = AI_Scratchpad_Store::table();

		$row = AI_Scratchpad_Store::get( $id );
		if ( ! $row ) {
			echo '<p>That record does not exist.</p>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=ai-scratchpad' ) ) . '">Back</a></p>';
			return;
		}

		$is_note = ( 'note' === $row['kind'] );
		$editing = $is_note && ! empty( $_GET['edit'] );

		echo '<h2>' . esc_html( $row['title'] ) . '</h2>';
		echo '<p><em>' . esc_html( $row['created_at'] ) . ' UTC, ' . esc_html( $row['agent'] ? $row['agent'] : 'unknown agent' ) . '</em>';
		if ( $is_note ) {
			echo ' &middot; <code>' . esc_html( $row['path'] ) . '</code> &middot; revision ' . (int) $row['revision'];
		}
		echo '</p>';

		if ( ! empty( $row['edited_by_name'] ) ) {
			printf(
				'<div class="notice notice-info inline"><p>Last edited by hand by <strong>%s</strong> on %s UTC.</p></div>',
				esc_html( $row['edited_by_name'] ),
				esc_html( $row['edited_at'] )
			);
		}

		if ( $editing ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'ai_scratchpad_edit_note_' . $id );
			echo '<input type="hidden" name="action" value="ai_scratchpad_edit_note">';
			echo '<input type="hidden" name="note_id" value="' . (int) $id . '">';
			echo '<p><input type="text" name="title" class="large-text" value="' . esc_attr( $row['title'] ) . '"></p>';
			echo '<p><textarea name="body" rows="20" class="large-text code">' . esc_textarea( $row['body'] ) . '</textarea></p>';
			echo '<p>Saving bumps the revision and records your name against the change, so the next assistant knows a person corrected it.</p>';
			submit_button( 'Save note' );
			echo '</form>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=ai-scratchpad&entry=' . $id ) ) . '">Cancel</a></p>';
			return;
		}

		if ( $row['why'] ) {
			echo '<p><strong>Why:</strong> ' . esc_html( $row['why'] ) . '</p>';
		}
		echo '<pre style="white-space:pre-wrap;background:#fff;border:1px solid #ccd0d4;padding:12px;">' . esc_html( $row['body'] ) . '</pre>';
		if ( $row['undo'] ) {
			echo '<p><strong>Undo:</strong> ' . esc_html( $row['undo'] ) . '</p>';
		}
		if ( $row['verified'] ) {
			echo '<p><strong>Verified:</strong> ' . esc_html( $row['verified'] ) . '</p>';
		}
		if ( $row['waiting_on'] ) {
			echo '<p><strong>Waiting on a human:</strong> ' . esc_html( $row['waiting_on'] ) . '</p>';
		}

		if ( $is_note ) {
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=ai-scratchpad&entry=' . $id . '&edit=1' ) ) . '">Edit this note</a></p>';
		}

		self::render_comments( $table, $id );

		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=ai-scratchpad' ) ) . '">Back</a></p>';
	}

	private static function render_comments( $table, $id ) {
		global $wpdb;

		$comments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE kind = 'comment' AND parent_id = %d ORDER BY id ASC",
				$id
			),
			ARRAY_A
		);

		echo '<h3>Comments</h3>';

		if ( ! $comments ) {
			echo '<p>No comments yet.</p>';
		}

		foreach ( (array) $comments as $c ) {
			printf(
				'<div style="border-left:4px solid %s;background:#fff;padding:10px 14px;margin:0 0 10px;"><p style="margin:0 0 6px;"><strong>%s</strong> <span style="color:#646970;">%s UTC</span> %s</p><div style="white-space:pre-wrap;">%s</div></div>',
				'active' === $c['status'] ? '#d63638' : '#00a32a',
				esc_html( $c['edited_by_name'] ? $c['edited_by_name'] : 'Someone' ),
				esc_html( $c['created_at'] ),
				'active' === $c['status']
					? '<span style="color:#d63638;">waiting for an assistant</span>'
					: '<span style="color:#00a32a;">answered</span>',
				esc_html( $c['body'] )
			);
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ai_scratchpad_comment_' . $id );
		echo '<input type="hidden" name="action" value="ai_scratchpad_comment">';
		echo '<input type="hidden" name="parent_id" value="' . (int) $id . '">';
		echo '<p><textarea name="body" rows="4" class="large-text" placeholder="Leave a note for the next assistant. It appears at the top of the next bootstrap and stays there until one answers it."></textarea></p>';
		submit_button( 'Add comment', 'secondary' );
		echo '</form>';
	}

	public static function render_settings() {
		$s = AI_Scratchpad_Settings::get();
		echo '<div class="wrap"><h1>AI Scratchpad Settings</h1>';

		echo '<p>These abilities are published through the WordPress Abilities API. To reach an AI client they need a separate MCP server plugin on this site, such as Easy MCP AI or the official WordPress MCP Adapter.</p>';

		echo '<p>The switches below are permissions. Ticked means allowed. Neither of the first two is ticked on a fresh install.</p>';

		if ( $s['allow_credentials'] ) {
			echo '<div class="notice notice-warning"><p><strong>Credentials are allowed in the scratchpad.</strong> Assistants can write passwords, API keys and tokens into these notes in plain text, where anyone with admin access and every future AI session will read them.</p></div>';
		}
		if ( $s['allow_delete'] ) {
			echo '<div class="notice notice-warning"><p><strong>Deletion is allowed.</strong> An assistant can permanently destroy journal entries and notes.</p></div>';
		}

		echo '<form method="post" action="options.php">';
		settings_fields( 'ai_scratchpad' );
		echo '<table class="form-table"><tbody>';

		printf(
			'<tr><th scope="row">Credentials</th><td><label><input type="checkbox" name="ai_scratchpad_settings[allow_credentials]" value="1" %s> Allow assistants to store passwords, API keys and tokens</label><p class="description">Off by default: writes that look like they contain a secret are refused, and the assistant is told to store a pointer instead. Tick this only if you deliberately want secrets in the site database.</p></td></tr>',
			checked( $s['allow_credentials'], 1, false )
		);

		printf(
			'<tr><th scope="row">Deletion</th><td><label><input type="checkbox" name="ai_scratchpad_settings[allow_delete]" value="1" %s> Allow assistants to permanently delete entries and notes</label><p class="description">Off by default. Archiving is always available and is reversible.</p></td></tr>',
			checked( $s['allow_delete'], 1, false )
		);

		printf(
			'<tr><th scope="row">Entries in bootstrap</th><td><input type="number" min="1" max="100" name="ai_scratchpad_settings[bootstrap_entries]" value="%d"><p class="description">How many recent journal entries an assistant receives at session start.</p></td></tr>',
			(int) $s['bootstrap_entries']
		);

		echo '</tbody></table>';
		submit_button();
		echo '</form>';

		echo '</div>';
	}
}

endif;

/* ============================================================================
 * SECTION 6: WIRING
 * ==========================================================================*/

// No MCP server lives here. The abilities are registered on the core Abilities
// API, and whichever MCP server plugin the site runs (Easy MCP AI, the WordPress
// MCP Adapter) publishes them. 0.3.0 to 0.4.2 bundled a server and an OAuth
// sign-in server of their own; 0.5.0 removed both.

register_activation_hook( __FILE__, array( 'AI_Scratchpad_Store', 'install' ) );

// Covers sites upgraded in place, and the prototype's snippet-created table.
// Database version 4 is the first without the built-in server, so a site coming
// from any earlier version also has that server's leftovers removed, once.
add_action(
	'plugins_loaded',
	static function () {
		if ( (int) get_option( 'ai_scratchpad_db_version' ) !== AI_SCRATCHPAD_DB_VERSION ) {
			AI_Scratchpad_Settings::remove_builtin_server();
			AI_Scratchpad_Store::install();
			AI_Scratchpad_Settings::migrate_to_permissions();
		}
	},
	5
);

add_action( 'wp_abilities_api_categories_init', array( 'AI_Scratchpad_Abilities', 'register_category' ) );
add_action( 'wp_abilities_api_init', array( 'AI_Scratchpad_Abilities', 'register' ) );
add_action( 'admin_menu', array( 'AI_Scratchpad_Admin', 'menu' ) );
add_action( 'admin_init', array( 'AI_Scratchpad_Settings', 'register' ) );
AI_Scratchpad_Admin::register_actions();
