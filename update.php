#!/usr/bin/env php
<?php
	set_include_path(get_include_path() . PATH_SEPARATOR .
		dirname(__FILE__) . "/include");

	define('DISABLE_SESSIONS', true);

	chdir(dirname(__FILE__));

	require_once "functions.php";
	require_once "rssfuncs.php";
	require_once "sanity_check.php";
	require_once "config.php";
	require_once "db.php";
	require_once "db-prefs.php";

	if (!defined('PHP_EXECUTABLE'))
		define('PHP_EXECUTABLE', '/usr/bin/php');

	$op = $argv;

	if (count($argv) == 1 || in_array("-help", $op) ) {
		print "Tiny Tiny RSS data update script.\n\n";
		print "Options:\n";
		print "  -feeds              - update feeds\n";
		print "  -feedbrowser        - update feedbrowser\n";
		print "  -daemon             - start single-process update daemon\n";
		print "  -cleanup-tags       - perform tags table maintenance\n";
		print "  -get-feeds          - receive popular feeds from linked instances\n";
		print "  -import USER FILE   - import articles from XML\n";
		print "  -quiet              - don't show messages\n";
		print "	-indexes            - recreate missing schema indexes\n";
		print "  -help               - show this help\n";
		return;
	}

	define('QUIET', in_array("-quiet", $op));

	if (!in_array("-daemon", $op)) {
		$lock_filename = "update.lock";
	} else {
		$lock_filename = "update_daemon.lock";
	}

	$lock_handle = make_lockfile($lock_filename);
	$must_exit = false;

	// Try to lock a file in order to avoid concurrent update.
	if (!$lock_handle) {
		die("error: Can't create lockfile ($lock_filename). ".
			"Maybe another update process is already running.\n");
	}

	// Create a database connection.
	$link = db_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

	init_connection($link);

	if (in_array("-feeds", $op)) {
		// Update all feeds needing a update.
		update_daemon_common($link);

		// Update feedbrowser
		$count = update_feedbrowser_cache($link);
		_debug("Feedbrowser updated, $count feeds processed.");

		// Purge orphans and cleanup tags
		purge_orphans($link, true);

		$rc = cleanup_tags($link, 14, 50000);
		_debug("Cleaned $rc cached tags.");

		get_linked_feeds($link);
	}

	if (in_array("-feedbrowser", $op)) {
		$count = update_feedbrowser_cache($link);
		print "Finished, $count feeds processed.\n";
	}

	if (in_array("-daemon", $op)) {
		$op = array_diff($op, array("-daemon"));
		while (true) {
			passthru(PHP_EXECUTABLE . " " . implode(' ', $op) . " -daemon-loop");
			_debug("Sleeping for " . DAEMON_SLEEP_INTERVAL . " seconds...");
			sleep(DAEMON_SLEEP_INTERVAL);
		}
	}

	if (in_array("-daemon-loop", $op)) {
		if (!make_stampfile('update_daemon.stamp')) {
			die("error: unable to create stampfile\n");
		}

		// Call to the feed batch update function
		// or regenerate feedbrowser cache

		if (rand(0,100) > 30) {
			update_daemon_common($link);
		} else {
			$count = update_feedbrowser_cache($link);
			_debug("Feedbrowser updated, $count feeds processed.");

			purge_orphans($link, true);

			$rc = cleanup_tags($link, 14, 50000);

			_debug("Cleaned $rc cached tags.");

			get_linked_feeds($link);
		}

	}

	if (in_array("-cleanup-tags", $op)) {
		$rc = cleanup_tags($link, 14, 50000);
		_debug("$rc tags deleted.\n");
	}

	if (in_array("-get-feeds", $op)) {
		get_linked_feeds($link);
	}

	if (in_array("-import",$op)) {
		$username = $argv[count($argv) - 2];
		$filename = $argv[count($argv) - 1];

		if (!$username) {
			print "error: please specify username.\n";
			return;
		}

		if (!is_file($filename)) {
			print "error: input filename ($filename) doesn't exist.\n";
			return;
		}

		_debug("importing $filename for user $username...\n");

		$result = db_query($link, "SELECT id FROM ttrss_users WHERE login = '$username'");

		if (db_num_rows($result) == 0) {
			print "error: could not find user $username.\n";
			return;
		}

		$owner_uid = db_fetch_result($result, 0, "id");

		perform_data_import($link, $filename, $owner_uid);

	}

	if (in_array("-indexes", $op)) {
		_debug("PLEASE BACKUP YOUR DATABASE BEFORE PROCEEDING!");
		_debug("Type 'yes' to continue.");

		if (read_stdin() != 'yes')
			exit;

		_debug("clearing existing indexes...");

		if (DB_TYPE == "pgsql") {
			$result = db_query($link, "SELECT relname FROM
				pg_catalog.pg_class WHERE relname LIKE 'ttrss_%'
					AND relname NOT LIKE '%_pkey'
				AND relkind = 'i'");
		} else {
			$result = db_query($link, "SELECT index_name,table_name FROM
				information_schema.statistics WHERE index_name LIKE 'ttrss_%'");
		}

		while ($line = db_fetch_assoc($result)) {
			if (DB_TYPE == "pgsql") {
				$statement = "DROP INDEX " . $line["relname"];
				_debug($statement);
			} else {
				$statement = "ALTER TABLE ".
					$line['table_name']." DROP INDEX ".$line['index_name'];
				_debug($statement);
			}
			db_query($link, $statement, false);
		}

		_debug("reading indexes from schema for: " . DB_TYPE);

		$fp = fopen("schema/ttrss_schema_" . DB_TYPE . ".sql", "r");
		if ($fp) {
			while ($line = fgets($fp)) {
				$matches = array();

				if (preg_match("/^create index ([^ ]+) on ([^ ]+)$/i", $line, $matches)) {
					$index = $matches[1];
					$table = $matches[2];

					$statement = "CREATE INDEX $index ON $table";

					_debug($statement);
					db_query($link, $statement);
				}
			}
			fclose($fp);
		} else {
			_debug("unable to open schema file.");
		}
		_debug("all done.");
	}

	db_close($link);

	if ($lock_handle != false) {
		fclose($lock_handle);
	}

	unlink(LOCK_DIRECTORY . "/$lock_filename");
?>
