<?php
	set_include_path(get_include_path() . PATH_SEPARATOR . "include");

	/* remove ill effects of magic quotes */

	if (get_magic_quotes_gpc()) {
		function stripslashes_deep($value) {
			$value = is_array($value) ?
				array_map('stripslashes_deep', $value) : stripslashes($value);
				return $value;
		}

		$_POST = array_map('stripslashes_deep', $_POST);
		$_GET = array_map('stripslashes_deep', $_GET);
		$_COOKIE = array_map('stripslashes_deep', $_COOKIE);
		$_REQUEST = array_map('stripslashes_deep', $_REQUEST);
	}

	$op = $_REQUEST["op"];

	require_once "functions.php";
	if ($op != "share") require_once "sessions.php";
	require_once "sanity_check.php";
	require_once "config.php";
	require_once "db.php";
	require_once "db-prefs.php";

	no_cache_incantation();

	startup_gettext();

	$script_started = getmicrotime();

	$link = db_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

	if (!$link) {
		if (DB_TYPE == "mysql") {
			print mysql_error();
		}
		// PG seems to display its own errors just fine by default.
		return;
	}

	init_connection($link);

	$subop = $_REQUEST["subop"];
	$mode = $_REQUEST["mode"];

	if ((!$op || $op == "rss" || $op == "dlg") && !$_REQUEST["noxml"]) {
			header("Content-Type: application/xml; charset=utf-8");
	} else {
			header("Content-Type: text/plain; charset=utf-8");
	}

	if (ENABLE_GZIP_OUTPUT) {
		ob_start("ob_gzhandler");
	}

	if (SINGLE_USER_MODE) {
		authenticate_user($link, "admin", null);
	}

	$public_calls = array("globalUpdateFeeds", "rss", "getUnread", "getProfiles", "share",
		"fbexport", "logout", "pubsub");

	if (array_search($op, $public_calls) !== false) {

		handle_public_request($link, $op);
		return;

	} else if (!($_SESSION["uid"] && validate_session($link))) {
		if ($op == 'pref-feeds' && $_REQUEST['subop'] == 'add') {
			header("Content-Type: text/html");
			login_sequence($link);
			render_login_form($link);
		} else {
			header("Content-Type: text/plain");
			print json_encode(array("error" => array("code" => 6)));
		}
		return;
	}

	$purge_intervals = array(
		0  => __("Use default"),
		-1 => __("Never purge"),
		5  => __("1 week old"),
		14 => __("2 weeks old"),
		31 => __("1 month old"),
		60 => __("2 months old"),
		90 => __("3 months old"));

	$update_intervals = array(
		0   => __("Default interval"),
		-1  => __("Disable updates"),
		15  => __("Each 15 minutes"),
		30  => __("Each 30 minutes"),
		60  => __("Hourly"),
		240 => __("Each 4 hours"),
		720 => __("Each 12 hours"),
		1440 => __("Daily"),
		10080 => __("Weekly"));

	$update_intervals_nodefault = array(
		-1  => __("Disable updates"),
		15  => __("Each 15 minutes"),
		30  => __("Each 30 minutes"),
		60  => __("Hourly"),
		240 => __("Each 4 hours"),
		720 => __("Each 12 hours"),
		1440 => __("Daily"),
		10080 => __("Weekly"));

	$update_methods = array(
		0   => __("Default"),
		1   => __("Magpie"),
		2   => __("SimplePie"),
		3   => __("Twitter OAuth"));

	if (DEFAULT_UPDATE_METHOD == "1") {
		$update_methods[0] .= ' (SimplePie)';
	} else {
		$update_methods[0] .= ' (Magpie)';
	}

	$access_level_names = array(
		0 => __("User"),
		5 => __("Power User"),
		10 => __("Administrator"));



	$error = sanity_check($link);

	if ($error['code'] != 0 && $op != "logout") {
		print json_encode(array("error" => $error));
		return;
	}

	switch($op) { // Select action according to $op value.
		case "rpc":
			require_once "modules/backend-rpc.php";
			handle_rpc_request($link);
		break; // rpc

		case "feeds":
			$subop = $_REQUEST["subop"];
			$root = (bool)$_REQUEST["root"];

			switch($subop) {
				case "catchupAll":
					db_query($link, "UPDATE ttrss_user_entries SET
						last_read = NOW(),unread = false WHERE owner_uid = " . $_SESSION["uid"]);
					ccache_zero_all($link, $_SESSION["uid"]);

				break;

				case "collapse":
					$cat_id = db_escape_string($_REQUEST["cid"]);
					$mode = (int) db_escape_string($_REQUEST['mode']);
					toggle_collapse_cat($link, $cat_id, $mode);
					return;
				break;
			}

			if (!$root) {
				print json_encode(outputFeedList($link));
			} else {

				$feeds = outputFeedList($link, false);

				$root = array();
				$root['id'] = 'root';
				$root['name'] = __('Feeds');
				$root['items'] = $feeds['items'];

				$fl = array();
				$fl['identifier'] = 'id';
				$fl['label'] = 'name';
				$fl['items'] = array($root);

				print json_encode($fl);
			}

		break; // feeds

		case "la":
			$id = db_escape_string($_REQUEST['id']);

			$result = db_query($link, "SELECT link FROM ttrss_entries, ttrss_user_entries
				WHERE id = '$id' AND id = ref_id AND owner_uid = '".$_SESSION['uid']."'
				LIMIT 1");

			if (db_num_rows($result) == 1) {
				$article_url = db_fetch_result($result, 0, 'link');
				$article_url = str_replace("\n", "", $article_url);

				header("Location: $article_url");
				return;

			} else {
				print_error(__("Article not found."));
			}
		break;

		case "view":

			$id = db_escape_string($_REQUEST["id"]);
			$cids = explode(",", db_escape_string($_REQUEST["cids"]));
			$mode = db_escape_string($_REQUEST["mode"]);
			$omode = db_escape_string($_REQUEST["omode"]);

			// in prefetch mode we only output requested cids, main article
			// just gets marked as read (it already exists in client cache)

			$articles = array();

			if ($mode == "") {
				array_push($articles, format_article($link, $id, false));
			} else if ($mode == "zoom") {
				array_push($articles, format_article($link, $id, true, true));
			} else if ($mode == "raw") {
				if ($_REQUEST['html']) {
					header("Content-Type: text/html");
					print '<link rel="stylesheet" type="text/css" href="tt-rss.css"/>';
				}

				$article = format_article($link, $id, false);
				print $article['content'];
				return;
			}

			catchupArticleById($link, $id, 0);

			if (!$_SESSION["bw_limit"]) {
				foreach ($cids as $cid) {
					if ($cid) {
						array_push($articles, format_article($link, $cid, false, false));
					}
				}
			}

			print json_encode($articles);

		break; // view

		case "viewfeed":

			$timing_info = getmicrotime();

			$reply = array();

			if ($_REQUEST["debug"]) $timing_info = print_checkpoint("0", $timing_info);

			$omode = db_escape_string($_REQUEST["omode"]);

			$feed = db_escape_string($_REQUEST["feed"]);
			$subop = db_escape_string($_REQUEST["subop"]);
			$view_mode = db_escape_string($_REQUEST["view_mode"]);
			$limit = (int) get_pref($link, "DEFAULT_ARTICLE_LIMIT");
			@$cat_view = db_escape_string($_REQUEST["cat"]) == "true";
			@$next_unread_feed = db_escape_string($_REQUEST["nuf"]);
			@$offset = db_escape_string($_REQUEST["skip"]);
			@$vgroup_last_feed = db_escape_string($_REQUEST["vgrlf"]);
			$order_by = db_escape_string($_REQUEST["order_by"]);

			if (is_numeric($feed)) $feed = (int) $feed;

			/* Feed -5 is a special case: it is used to display auxiliary information
			 * when there's nothing to load - e.g. no stuff in fresh feed */

			if ($feed == -5) {
				print json_encode(generate_dashboard_feed($link));
				return;
			}

			$result = false;

			if ($feed < -10) {
				$label_feed = -11-$feed;
				$result = db_query($link, "SELECT id FROM ttrss_labels2 WHERE
					id = '$label_feed' AND owner_uid = " . $_SESSION['uid']);
			} else if (!$cat_view && is_numeric($feed) && $feed > 0) {
				$result = db_query($link, "SELECT id FROM ttrss_feeds WHERE
					id = '$feed' AND owner_uid = " . $_SESSION['uid']);
			} else if ($cat_view && is_numeric($feed) && $feed > 0) {
				$result = db_query($link, "SELECT id FROM ttrss_feed_categories WHERE
					id = '$feed' AND owner_uid = " . $_SESSION['uid']);
			}

			if ($result && db_num_rows($result) == 0) {
				print json_encode(generate_error_feed($link, __("Feed not found.")));
				return;
			}

			/* Updating a label ccache means recalculating all of the caches
			 * so for performance reasons we don't do that here */

			if ($feed >= 0) {
				ccache_update($link, $feed, $_SESSION["uid"], $cat_view);
			}

			set_pref($link, "_DEFAULT_VIEW_MODE", $view_mode);
			set_pref($link, "_DEFAULT_VIEW_LIMIT", $limit);
			set_pref($link, "_DEFAULT_VIEW_ORDER_BY", $order_by);

			if (!$cat_view && preg_match("/^[0-9][0-9]*$/", $feed)) {
				db_query($link, "UPDATE ttrss_feeds SET last_viewed = NOW()
					WHERE id = '$feed' AND owner_uid = ".$_SESSION["uid"]);
			}

			$reply['headlines'] = array();

			if (!$next_unread_feed)
				$reply['headlines']['id'] = $feed;
			else
				$reply['headlines']['id'] = $next_unread_feed;

			$reply['headlines']['is_cat'] = (bool) $cat_view;

			$override_order = false;

			if (get_pref($link, "SORT_HEADLINES_BY_FEED_DATE", $owner_uid)) {
				$date_sort_field = "updated";
			} else {
				$date_sort_field = "date_entered";
			}

			switch ($order_by) {
				case "date":
					if (get_pref($link, 'REVERSE_HEADLINES', $owner_uid)) {
						$override_order = "$date_sort_field";
					} else {
						$override_order = "$date_sort_field DESC";
					}
					break;

				case "title":
					if (get_pref($link, 'REVERSE_HEADLINES', $owner_uid)) {
						$override_order = "title DESC, $date_sort_field";
					} else {
						$override_order = "title, $date_sort_field DESC";
					}
					break;

				case "score":
					if (get_pref($link, 'REVERSE_HEADLINES', $owner_uid)) {
						$override_order = "score, $date_sort_field";
					} else {
						$override_order = "score DESC, $date_sort_field DESC";
					}
					break;
			}

			if ($_REQUEST["debug"]) $timing_info = print_checkpoint("04", $timing_info);

			$ret = format_headlines_list($link, $feed, $subop,
				$view_mode, $limit, $cat_view, $next_unread_feed, $offset,
				$vgroup_last_feed, $override_order);

			$topmost_article_ids = $ret[0];
			$headlines_count = $ret[1];
			$returned_feed = $ret[2];
			$disable_cache = $ret[3];
			$vgroup_last_feed = $ret[4];

//			if ($_REQUEST["debug"]) print_r($ret);

			$reply['headlines']['content'] =& $ret[5]['content'];
			$reply['headlines']['toolbar'] =& $ret[5]['toolbar'];

			if ($_REQUEST["debug"]) $timing_info = print_checkpoint("05", $timing_info);

			$reply['headlines-info'] = array("count" => (int) $headlines_count,
				"vgroup_last_feed" => $vgroup_last_feed,
				"disable_cache" => (bool) $disable_cache);

			if ($_REQUEST["debug"]) $timing_info = print_checkpoint("20", $timing_info);

			if (is_array($topmost_article_ids) && !get_pref($link, 'COMBINED_DISPLAY_MODE') && !$_SESSION["bw_limit"]) {
				$articles = array();

				foreach ($topmost_article_ids as $id) {
					array_push($articles, format_article($link, $id, false));
				}

				$reply['articles'] = $articles;
			}

//			if ($subop) {
//				$reply['counters'] = getAllCounters($link, $omode, $feed);
//			}

			if ($_REQUEST["debug"]) $timing_info = print_checkpoint("30", $timing_info);

			$reply['runtime-info'] = make_runtime_info($link);

			print json_encode($reply);
		break; // viewfeed

		case "pref-feeds":
			require_once "modules/pref-feeds.php";
			module_pref_feeds($link);
		break; // pref-feeds

		case "pref-filters":
			require_once "modules/pref-filters.php";
			module_pref_filters($link);
		break; // pref-filters

		case "pref-labels":
			require_once "modules/pref-labels.php";
			module_pref_labels($link);
		break; // pref-labels

		case "pref-prefs":
			require_once "modules/pref-prefs.php";
			module_pref_prefs($link);
		break; // pref-prefs

		case "pref-users":
			require_once "modules/pref-users.php";
			module_pref_users($link);
		break; // prefs-users

		case "help":
			require_once "modules/help.php";
			module_help($link);
		break; // help

		case "dlg":
			require_once "modules/popup-dialog.php";
			module_popup_dialog($link);
		break; // dlg

		case "pref-instances":
			require_once "modules/pref-instances.php";
			module_pref_instances($link);
		break; // pref-instances

		case "digestTest":
			print_r(prepare_headlines_digest($link, $_SESSION["uid"]));
		break; // digestTest

		case "digestSend":
			send_headlines_digests($link);
		break; // digestSend

		case "loading":
			header("Content-type: text/html");
			print __("Loading, please wait...") . " " .
				"<img src='images/indicator_tiny.gif'>";
		break; // loading

		default:
			header("Content-Type: text/plain");
			print json_encode(array("error" => array("code" => 7)));
		break; // fallback
	} // Select action according to $op value.

	// We close the connection to database.
	db_close($link);
?>
