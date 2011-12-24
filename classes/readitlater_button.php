<?php
class ReadItLater_Button extends Plugin_Button {
	function render($article_id) {
		$rv = "<img src=\"".theme_image($this->link, 'images/art-readitlater.png')."\"
			class='tagsPic' style=\"cursor : pointer\"
			onclick=\"readArticleLater($article_id)\"
			title='".__('Read it later')."'>";

		return $rv;
	}

	function getInfo() {
		$id = db_escape_string($_REQUEST['id']);

		$result = db_query($this->link, "SELECT title, link
				FROM ttrss_entries, ttrss_user_entries
				WHERE id = '$id' AND ref_id = id AND owner_uid = " .$_SESSION['uid']);

		if (db_num_rows($result) != 0) {
			$title = truncate_string(strip_tags(db_fetch_result($result, 0, 'title')),
				100, '...');
			$article_link = db_fetch_result($result, 0, 'link');
		}

		print json_encode(array("title" => $title, "link" => $article_link,
				"id" => $id));
	}


}
?>
