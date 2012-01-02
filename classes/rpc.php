<?php
class RPC extends Protected_Handler {

	function csrf_ignore($method) {
		$csrf_ignored = array("sanitycheck", "buttonplugin", "exportget");

		return array_search($method, $csrf_ignored) !== false;
	}

	function setprofile() {
		$id = db_escape_string($_REQUEST["id"]);

		$_SESSION["profile"] = $id;
		$_SESSION["prefs_cache"] = array();
	}

	function exportget() {
		$exportname = CACHE_DIR . "/export/" .
			sha1($_SESSION['uid'] . $_SESSION['login']) . ".xml";

		if (file_exists($exportname)) {
			header("Content-type: text/xml");

			if (function_exists('gzencode')) {
				header("Content-Disposition: attachment; filename=TinyTinyRSS_exported.xml.gz");
				echo gzencode(file_get_contents($exportname));
			} else {
				header("Content-Disposition: attachment; filename=TinyTinyRSS_exported.xml");
				echo file_get_contents($exportname);
			}
		} else {
			echo "File not found.";
		}
	}

	function exportrun() {
		$offset = (int) db_escape_string($_REQUEST['offset']);
		$exported = 0;
		$limit = 250;

		if ($offset < 10000 && is_writable(CACHE_DIR . "/export")) {
			$result = db_query($this->link, "SELECT
					ttrss_entries.guid,
					ttrss_entries.title,
					content,
					marked,
					published,
					score,
					note,
					link,
					tag_cache,
					label_cache,
					ttrss_feeds.title AS feed_title,
					ttrss_feeds.feed_url AS feed_url,
					ttrss_entries.updated
				FROM
					ttrss_user_entries LEFT JOIN ttrss_feeds ON (ttrss_feeds.id = feed_id),
					ttrss_entries
				WHERE
					(marked = true OR feed_id IS NULL) AND
					ref_id = ttrss_entries.id AND
					ttrss_user_entries.owner_uid = " . $_SESSION['uid'] . "
				ORDER BY ttrss_entries.id LIMIT $limit OFFSET $offset");

			$exportname = sha1($_SESSION['uid'] . $_SESSION['login']);

			if ($offset == 0) {
				$fp = fopen(CACHE_DIR . "/export/$exportname.xml", "w");
				fputs($fp, "<articles schema-version=\"".SCHEMA_VERSION."\">");
			} else {
				$fp = fopen(CACHE_DIR . "/export/$exportname.xml", "a");
			}

			if ($fp) {

				while ($line = db_fetch_assoc($result)) {
					fputs($fp, "<article>");

					foreach ($line as $k => $v) {
						fputs($fp, "<$k><![CDATA[$v]]></$k>");
					}

					fputs($fp, "</article>");
				}

				$exported = db_num_rows($result);

				if ($exported < $limit && $exported > 0) {
					fputs($fp, "</articles>");
				}

				fclose($fp);
			}

		}

		print json_encode(array("exported" => $exported));
	}

	function remprofiles() {
		$ids = explode(",", db_escape_string(trim($_REQUEST["ids"])));

		foreach ($ids as $id) {
			if ($_SESSION["profile"] != $id) {
				db_query($this->link, "DELETE FROM ttrss_settings_profiles WHERE id = '$id' AND
							owner_uid = " . $_SESSION["uid"]);
			}
		}
	}

	// Silent
	function addprofile() {
		$title = db_escape_string(trim($_REQUEST["title"]));
		if ($title) {
			db_query($this->link, "BEGIN");

			$result = db_query($this->link, "SELECT id FROM ttrss_settings_profiles
				WHERE title = '$title' AND owner_uid = " . $_SESSION["uid"]);

			if (db_num_rows($result) == 0) {

				db_query($this->link, "INSERT INTO ttrss_settings_profiles (title, owner_uid)
							VALUES ('$title', ".$_SESSION["uid"] .")");

				$result = db_query($this->link, "SELECT id FROM ttrss_settings_profiles WHERE
					title = '$title'");

				if (db_num_rows($result) != 0) {
					$profile_id = db_fetch_result($result, 0, "id");

					if ($profile_id) {
						initialize_user_prefs($this->link, $_SESSION["uid"], $profile_id);
					}
				}
			}

			db_query($this->link, "COMMIT");
		}
	}

	// Silent
	function saveprofile() {
		$id = db_escape_string($_REQUEST["id"]);
		$title = db_escape_string(trim($_REQUEST["value"]));

		if ($id == 0) {
			print __("Default profile");
			return;
		}

		if ($title) {
			db_query($this->link, "BEGIN");

			$result = db_query($this->link, "SELECT id FROM ttrss_settings_profiles
				WHERE title = '$title' AND owner_uid =" . $_SESSION["uid"]);

			if (db_num_rows($result) == 0) {
				db_query($this->link, "UPDATE ttrss_settings_profiles
							SET title = '$title' WHERE id = '$id' AND
							owner_uid = " . $_SESSION["uid"]);
				print $title;
			} else {
				$result = db_query($this->link, "SELECT title FROM ttrss_settings_profiles
							WHERE id = '$id' AND owner_uid =" . $_SESSION["uid"]);
				print db_fetch_result($result, 0, "title");
			}

			db_query($this->link, "COMMIT");
		}
	}

	// Silent
	function remarchive() {
		$ids = explode(",", db_escape_string($_REQUEST["ids"]));

		foreach ($ids as $id) {
			$result = db_query($this->link, "DELETE FROM ttrss_archived_feeds WHERE
		(SELECT COUNT(*) FROM ttrss_user_entries
							WHERE orig_feed_id = '$id') = 0 AND
		id = '$id' AND owner_uid = ".$_SESSION["uid"]);

			$rc = db_affected_rows($this->link, $result);
		}
	}

	function addfeed() {
		$feed = db_escape_string($_REQUEST['feed']);
		$cat = db_escape_string($_REQUEST['cat']);
		$login = db_escape_string($_REQUEST['login']);
		$pass = db_escape_string($_REQUEST['pass']);

		$rc = subscribe_to_feed($this->link, $feed, $cat, $login, $pass);

		print json_encode(array("result" => $rc));
	}

	function extractfeedurls() {
		$urls = get_feeds_from_html($_REQUEST['url']);

		print json_encode(array("urls" => $urls));
	}

	function togglepref() {
		$key = db_escape_string($_REQUEST["key"]);
		set_pref($this->link, $key, !get_pref($this->link, $key));
		$value = get_pref($this->link, $key);

		print json_encode(array("param" =>$key, "value" => $value));
	}

	function setpref() {
		$value = str_replace("\n", "<br/>", $_REQUEST['value']);

		$key = db_escape_string($_REQUEST["key"]);
		$value = db_escape_string($value);

		set_pref($this->link, $key, $value);

		print json_encode(array("param" =>$key, "value" => $value));
	}

	function mark() {
		$mark = $_REQUEST["mark"];
		$id = db_escape_string($_REQUEST["id"]);

		if ($mark == "1") {
			$mark = "true";
		} else {
			$mark = "false";
		}

		$result = db_query($this->link, "UPDATE ttrss_user_entries SET marked = $mark
					WHERE ref_id = '$id' AND owner_uid = " . $_SESSION["uid"]);

		print json_encode(array("message" => "UPDATE_COUNTERS"));
	}

	function delete() {
		$ids = db_escape_string($_REQUEST["ids"]);

		$result = db_query($this->link, "DELETE FROM ttrss_user_entries
		WHERE ref_id IN ($ids) AND owner_uid = " . $_SESSION["uid"]);

		print json_encode(array("message" => "UPDATE_COUNTERS"));
	}

	function unarchive() {
		$ids = db_escape_string($_REQUEST["ids"]);

		$result = db_query($this->link, "UPDATE ttrss_user_entries
					SET feed_id = orig_feed_id, orig_feed_id = NULL
					WHERE ref_id IN ($ids) AND owner_uid = " . $_SESSION["uid"]);

		print json_encode(array("message" => "UPDATE_COUNTERS"));
	}

	function archive() {
		$ids = explode(",", db_escape_string($_REQUEST["ids"]));

		foreach ($ids as $id) {
			archive_article($this->link, $id, $_SESSION["uid"]);
		}

		print json_encode(array("message" => "UPDATE_COUNTERS"));
	}

	function publ() {
		$pub = $_REQUEST["pub"];
		$id = db_escape_string($_REQUEST["id"]);
		$note = trim(strip_tags(db_escape_string($_REQUEST["note"])));

		if ($pub == "1") {
			$pub = "true";
		} else {
			$pub = "false";
		}

		$result = db_query($this->link, "UPDATE ttrss_user_entries SET
			published = $pub
			WHERE ref_id = '$id' AND owner_uid = " . $_SESSION["uid"]);

		$pubsub_result = false;

		if (PUBSUBHUBBUB_HUB) {
			$rss_link = get_self_url_prefix() .
				"/public.php?op=rss&id=-2&key=" .
				get_feed_access_key($this->link, -2, false);

			$p = new Publisher(PUBSUBHUBBUB_HUB);

			$pubsub_result = $p->publish_update($rss_link);
		}

		print json_encode(array("message" => "UPDATE_COUNTERS",
			"pubsub_result" => $pubsub_result));
	}

	function getAllCounters() {
		$last_article_id = (int) $_REQUEST["last_article_id"];

		$reply = array();

		 if ($seq) $reply['seq'] = $seq;

		 if ($last_article_id != getLastArticleId($this->link)) {
				$omode = $_REQUEST["omode"];

			if ($omode != "T")
				$reply['counters'] = getAllCounters($this->link, $omode);
			else
				$reply['counters'] = getGlobalCounters($this->link);
		 }

		 $reply['runtime-info'] = make_runtime_info($this->link);

		 print json_encode($reply);
	}

	/* GET["cmode"] = 0 - mark as read, 1 - as unread, 2 - toggle */
	function catchupSelected() {
		$ids = explode(",", db_escape_string($_REQUEST["ids"]));
		$cmode = sprintf("%d", $_REQUEST["cmode"]);

		catchupArticlesById($this->link, $ids, $cmode);

		print json_encode(array("message" => "UPDATE_COUNTERS"));
	}

	function markSelected() {
		$ids = explode(",", db_escape_string($_REQUEST["ids"]));
		$cmode = sprintf("%d", $_REQUEST["cmode"]);

		markArticlesById($this->link, $ids, $cmode);

		print json_encode(array("message" => "UPDATE_COUNTERS"));
	}

	function publishSelected() {
		$ids = explode(",", db_escape_string($_REQUEST["ids"]));
		$cmode = sprintf("%d", $_REQUEST["cmode"]);

		publishArticlesById($this->link, $ids, $cmode);

		print json_encode(array("message" => "UPDATE_COUNTERS"));
	}

	function sanityCheck() {
		$_SESSION["hasAudio"] = $_REQUEST["hasAudio"] === "true";

		$reply = array();

		$reply['error'] = sanity_check($this->link);

		if ($reply['error']['code'] == 0) {
			$reply['init-params'] = make_init_params($this->link);
			$reply['runtime-info'] = make_runtime_info($this->link);
		}

		print json_encode($reply);
	}

	function setArticleTags() {
		global $memcache;

		$id = db_escape_string($_REQUEST["id"]);

		$tags_str = db_escape_string($_REQUEST["tags_str"]);
		$tags = array_unique(trim_array(explode(",", $tags_str)));

		db_query($this->link, "BEGIN");

		$result = db_query($this->link, "SELECT int_id FROM ttrss_user_entries WHERE
				ref_id = '$id' AND owner_uid = '".$_SESSION["uid"]."' LIMIT 1");

		if (db_num_rows($result) == 1) {

			$tags_to_cache = array();

			$int_id = db_fetch_result($result, 0, "int_id");

			db_query($this->link, "DELETE FROM ttrss_tags WHERE
				post_int_id = $int_id AND owner_uid = '".$_SESSION["uid"]."'");

			foreach ($tags as $tag) {
				$tag = sanitize_tag($tag);

				if (!tag_is_valid($tag)) {
					continue;
				}

				if (preg_match("/^[0-9]*$/", $tag)) {
					continue;
				}

				//					print "<!-- $id : $int_id : $tag -->";

				if ($tag != '') {
					db_query($this->link, "INSERT INTO ttrss_tags
								(post_int_id, owner_uid, tag_name) VALUES ('$int_id', '".$_SESSION["uid"]."', '$tag')");
				}

				array_push($tags_to_cache, $tag);
			}

			/* update tag cache */

			sort($tags_to_cache);
			$tags_str = join(",", $tags_to_cache);

			db_query($this->link, "UPDATE ttrss_user_entries
				SET tag_cache = '$tags_str' WHERE ref_id = '$id'
						AND owner_uid = " . $_SESSION["uid"]);
		}

		db_query($this->link, "COMMIT");

		if ($memcache) {
			$obj_id = md5("TAGS:".$_SESSION["uid"].":$id");
			$memcache->delete($obj_id);
		}

		$tags = get_article_tags($this->link, $id);
		$tags_str = format_tags_string($tags, $id);
		$tags_str_full = join(", ", $tags);

		if (!$tags_str_full) $tags_str_full = __("no tags");

		print json_encode(array("tags_str" => array("id" => $id,
				"content" => $tags_str, "content_full" => $tags_str_full)));
	}

	function regenOPMLKey() {
		update_feed_access_key($this->link, 'OPML:Publish',
		false, $_SESSION["uid"]);

		$new_link = opml_publish_url($this->link);

		print json_encode(array("link" => $new_link));
	}

	function completeTags() {
		$search = db_escape_string($_REQUEST["search"]);

		$result = db_query($this->link, "SELECT DISTINCT tag_name FROM ttrss_tags
				WHERE owner_uid = '".$_SESSION["uid"]."' AND
				tag_name LIKE '$search%' ORDER BY tag_name
				LIMIT 10");

		print "<ul>";
		while ($line = db_fetch_assoc($result)) {
			print "<li>" . $line["tag_name"] . "</li>";
		}
		print "</ul>";
	}

	function purge() {
		$ids = explode(",", db_escape_string($_REQUEST["ids"]));
		$days = sprintf("%d", $_REQUEST["days"]);

		foreach ($ids as $id) {

			$result = db_query($this->link, "SELECT id FROM ttrss_feeds WHERE
				id = '$id' AND owner_uid = ".$_SESSION["uid"]);

			if (db_num_rows($result) == 1) {
				purge_feed($this->link, $id, $days);
			}
		}
	}

	function getArticles() {
		$ids = explode(",", db_escape_string($_REQUEST["ids"]));
		$articles = array();

		foreach ($ids as $id) {
			if ($id) {
				array_push($articles, format_article($this->link, $id, 0, false));
			}
		}

		print json_encode($articles);
	}

	function checkDate() {
		$date = db_escape_string($_REQUEST["date"]);
		$date_parsed = strtotime($date);

		print json_encode(array("result" => (bool)$date_parsed,
			"date" => date("c", $date_parsed)));
	}

	function assigntolabel() {
		return $this->labelops(true);
	}

	function removefromlabel() {
		return $this->labelops(false);
	}

	function labelops($assign) {
		$reply = array();

		$ids = explode(",", db_escape_string($_REQUEST["ids"]));
		$label_id = db_escape_string($_REQUEST["lid"]);

		$label = db_escape_string(label_find_caption($this->link, $label_id,
		$_SESSION["uid"]));

		$reply["info-for-headlines"] = array();

		if ($label) {

			foreach ($ids as $id) {

				if ($assign)
					label_add_article($this->link, $id, $label, $_SESSION["uid"]);
				else
					label_remove_article($this->link, $id, $label, $_SESSION["uid"]);

				$labels = get_article_labels($this->link, $id, $_SESSION["uid"]);

				array_push($reply["info-for-headlines"],
				array("id" => $id, "labels" => format_article_labels($labels, $id)));

			}
		}

		$reply["message"] = "UPDATE_COUNTERS";

		print json_encode($reply);
	}

	function updateFeedBrowser() {
		$search = db_escape_string($_REQUEST["search"]);
		$limit = db_escape_string($_REQUEST["limit"]);
		$mode = (int) db_escape_string($_REQUEST["mode"]);

		print json_encode(array("content" =>
			make_feed_browser($this->link, $search, $limit, $mode),
				"mode" => $mode));
	}

	// Silent
	function massSubscribe() {

		$payload = json_decode($_REQUEST["payload"], false);
		$mode = $_REQUEST["mode"];

		if (!$payload || !is_array($payload)) return;

		if ($mode == 1) {
			foreach ($payload as $feed) {

				$title = db_escape_string($feed[0]);
				$feed_url = db_escape_string($feed[1]);

				$result = db_query($this->link, "SELECT id FROM ttrss_feeds WHERE
					feed_url = '$feed_url' AND owner_uid = " . $_SESSION["uid"]);

				if (db_num_rows($result) == 0) {
					$result = db_query($this->link, "INSERT INTO ttrss_feeds
									(owner_uid,feed_url,title,cat_id,site_url)
									VALUES ('".$_SESSION["uid"]."',
									'$feed_url', '$title', NULL, '')");
				}
			}
		} else if ($mode == 2) {
			// feed archive
			foreach ($payload as $id) {
				$result = db_query($this->link, "SELECT * FROM ttrss_archived_feeds
					WHERE id = '$id' AND owner_uid = " . $_SESSION["uid"]);

				if (db_num_rows($result) != 0) {
					$site_url = db_escape_string(db_fetch_result($result, 0, "site_url"));
					$feed_url = db_escape_string(db_fetch_result($result, 0, "feed_url"));
					$title = db_escape_string(db_fetch_result($result, 0, "title"));

					$result = db_query($this->link, "SELECT id FROM ttrss_feeds WHERE
						feed_url = '$feed_url' AND owner_uid = " . $_SESSION["uid"]);

					if (db_num_rows($result) == 0) {
						$result = db_query($this->link, "INSERT INTO ttrss_feeds
										(owner_uid,feed_url,title,cat_id,site_url)
									VALUES ('$id','".$_SESSION["uid"]."',
									'$feed_url', '$title', NULL, '$site_url')");
					}
				}
			}
		}
	}

	function digestgetcontents() {
		$article_id = db_escape_string($_REQUEST['article_id']);

		$result = db_query($this->link, "SELECT content,title,link,marked,published
			FROM ttrss_entries, ttrss_user_entries
			WHERE id = '$article_id' AND ref_id = id AND owner_uid = ".$_SESSION['uid']);

		$content = sanitize($this->link, db_fetch_result($result, 0, "content"));
		$title = strip_tags(db_fetch_result($result, 0, "title"));
		$article_url = htmlspecialchars(db_fetch_result($result, 0, "link"));
		$marked = sql_bool_to_bool(db_fetch_result($result, 0, "marked"));
		$published = sql_bool_to_bool(db_fetch_result($result, 0, "published"));

		print json_encode(array("article" =>
			array("id" => $article_id, "url" => $article_url,
				"tags" => get_article_tags($this->link, $article_id),
				"marked" => $marked, "published" => $published,
				"title" => $title, "content" => $content)));
	}

	function digestupdate() {
		$feed_id = db_escape_string($_REQUEST['feed_id']);
		$offset = db_escape_string($_REQUEST['offset']);
		$seq = db_escape_string($_REQUEST['seq']);

		if (!$feed_id) $feed_id = -4;
		if (!$offset) $offset = 0;

		$reply = array();

		$reply['seq'] = $seq;

		$headlines = api_get_headlines($this->link, $feed_id, 30, $offset,
				'', ($feed_id == -4), true, false, "unread", "updated DESC", 0, 0);

		$reply['headlines'] = array();
		$reply['headlines']['title'] = getFeedTitle($this->link, $feed_id);
		$reply['headlines']['content'] = $headlines;

		print json_encode($reply);
	}

	function digestinit() {
		$tmp_feeds = api_get_feeds($this->link, -4, true, false, 0);

		$feeds = array();

		foreach ($tmp_feeds as $f) {
			if ($f['id'] > 0 || $f['id'] == -4) array_push($feeds, $f);
		}

		print json_encode(array("feeds" => $feeds));
	}

	function catchupFeed() {
		$feed_id = db_escape_string($_REQUEST['feed_id']);
		$is_cat = db_escape_string($_REQUEST['is_cat']) == "true";

		catchup_feed($this->link, $feed_id, $is_cat);

		print json_encode(array("message" => "UPDATE_COUNTERS"));
	}

	function quickAddCat() {
		$cat = db_escape_string($_REQUEST["cat"]);

		add_feed_category($this->link, $cat);

		$result = db_query($this->link, "SELECT id FROM ttrss_feed_categories WHERE
			title = '$cat' AND owner_uid = " . $_SESSION["uid"]);

		if (db_num_rows($result) == 1) {
			$id = db_fetch_result($result, 0, "id");
		} else {
			$id = 0;
		}

		print_feed_cat_select($this->link, "cat_id", $id);
	}

	function regenFeedKey() {
		$feed_id = db_escape_string($_REQUEST['id']);
		$is_cat = db_escape_string($_REQUEST['is_cat']) == "true";

		$new_key = update_feed_access_key($this->link, $feed_id, $is_cat);

		print json_encode(array("link" => $new_key));
	}

	// Silent
	function clearKeys() {
		db_query($this->link, "DELETE FROM ttrss_access_keys WHERE
			owner_uid = " . $_SESSION["uid"]);
	}

	// Silent
	function clearArticleKeys() {
		db_query($this->link, "UPDATE ttrss_user_entries SET uuid = '' WHERE
			owner_uid = " . $_SESSION["uid"]);

		return;
	}


	function verifyRegexp() {
		$reg_exp = $_REQUEST["reg_exp"];

		$status = @preg_match("/$reg_exp/i", "TEST") !== false;

		print json_encode(array("status" => $status));
	}

	// TODO: unify with digest-get-contents?
	function cdmGetArticle() {
		$ids = array(db_escape_string($_REQUEST["id"]));
		$cids = explode(",", $_REQUEST["cids"]);

		$ids = array_merge($ids, $cids);

		$rv = array();

		foreach ($ids as $id) {
			$id = (int)$id;

			$result = db_query($this->link, "SELECT content,
					ttrss_feeds.site_url AS site_url FROM ttrss_user_entries, ttrss_feeds,
					ttrss_entries
					WHERE feed_id = ttrss_feeds.id AND ref_id = '$id' AND
					ttrss_entries.id = ref_id AND
					ttrss_user_entries.owner_uid = ".$_SESSION["uid"]);

			if (db_num_rows($result) != 0) {
				$line = db_fetch_assoc($result);

				$article_content = sanitize($this->link, $line["content"],
					false, false, $line['site_url']);

				array_push($rv,
					array("id" => $id, "content" => $article_content));
			}
		}

		print json_encode($rv);
	}

	function scheduleFeedUpdate() {
		$feed_id = db_escape_string($_REQUEST["id"]);
		$is_cat =  db_escape_string($_REQUEST['is_cat']) == 'true';

		$message = __("Your request could not be completed.");

		if ($feed_id >= 0) {
			if (!$is_cat) {
				$message = __("Feed update has been scheduled.");

				db_query($this->link, "UPDATE ttrss_feeds SET
					last_update_started = '1970-01-01',
					last_updated = '1970-01-01' WHERE id = '$feed_id' AND
					owner_uid = ".$_SESSION["uid"]);

			} else {
				$message = __("Category update has been scheduled.");

				if ($feed_id)
				$cat_query = "cat_id = '$feed_id'";
				else
				$cat_query = "cat_id IS NULL";

				db_query($this->link, "UPDATE ttrss_feeds SET
						last_update_started = '1970-01-01',
						last_updated = '1970-01-01' WHERE $cat_query AND
						owner_uid = ".$_SESSION["uid"]);
			}
		} else {
			$message = __("Can't update this kind of feed.");
		}

		print json_encode(array("message" => $message));
		return;
	}

	function buttonPlugin() {
		$pclass = basename($_REQUEST['plugin']) . "_button";
		$method = $_REQUEST['plugin_method'];

		if (class_exists($pclass)) {
			$plugin = new $pclass($this->link);
			if (method_exists($plugin, $method)) {
				return $plugin->$method();
			}
		}
	}

	function genHash() {
		$hash = sha1(uniqid(rand(), true));

		print json_encode(array("hash" => $hash));
	}
}
?>
