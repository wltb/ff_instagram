<?php

if(!class_exists('RSSGenerator\Feed'))
	include 'RSSGenerator.php';
include 'Insta_func.php';

class ff_Instagram extends Plugin
{
	function about() {
		return array(
			1.0, // version
			'Generates feeds from Instagram URLs', // description
			'feader', // author
			false, // is_system
		);
	}

	function api_version() {
		return 2;
	}

	function init($host) {
		if (version_compare(VERSION_STATIC, '1.12', '<=') && VERSION_STATIC === VERSION){
			user_error('Hooks not registered. Needs trunk or version > 1.12', E_USER_NOTICE);
			return;
		}
		$host->add_hook($host::HOOK_FETCH_FEED, $this);
		$host->add_hook($host::HOOK_SUBSCRIBE_FEED, $this);
	}

	static function save_feed_icon($icon_url, $icon_file) {
		$contents = fetch_file_contents($icon_url);
		if ($contents) {
			$fp = @fopen($icon_file, "w");

			if ($fp) {
				fwrite($fp, $contents);
				fclose($fp);
				chmod($icon_file, 0644);
			}
		}
	}


	static function create_feed($url, $timestamp, $feed_id) {
		$json = Insta\extract_Insta_JSON($url); //get_Insta_JSON
		#var_dump($json);

		$feed = new RSSGenerator\Feed();
		$username = Insta\get_Insta_username($json);
		$feed->link = $url;
		$feed->title = sprintf("%s / Instagram", $username);
		$feed->description = $json["biography"];

		$icon_url = $json["profile_pic_url"];
		if ($icon_url) {
			$icon_file = ICONS_DIR . "/$feed_id.ico";
			if (! feed_has_icon($feed_id) )
				self::save_feed_icon($icon_url, $icon_file);
			else {
				$ts = filemtime($icon_file);
				if (time() - $ts > 600000) //a week
					self::save_feed_icon($icon_url, $icon_file);
			}
		}

		if ($json["is_private"] === FALSE) {
			while(TRUE) {
				$media = $json["media"];

				foreach($media["nodes"] as $post) {
					$oldest = $post["date"];
					// because of fetch overhead, do not include videos
					// that were seen 2 hours ago (not on the very first fetch)
					if ($post['is_video'] && $timestamp !== false
						&& $timestamp - $post["date"] > 7200)
							continue;

					$item = Insta\convert_Insta_data_to_RSS($post); //prepare_Insta_post_for_RSS
					$item['author'] = $username;
					#var_dump($item);
					$feed->new_item($item);
				}
				$info = $media["page_info"];
				//var_dump(end($media["nodes"])["date"]);
				//var_dump($media["nodes"][count($media["nodes"]) - 1]["date"]);
				if ( ($timestamp !== false && time() - $oldest > 600000) //don't load post older than a week
						|| !$info["has_next_page"])
					break;
				else {
					$json = Insta\extract_Insta_JSON($url, $info["end_cursor"]);
				}
			}
		}

		return $feed->saveXML();
	}

	static function check_url($url) {
		//return TRUE on match, FALSE otherwise
		return preg_match('%^https?://instagram.com/[\w.]+[#/]?$%i',  $url) === 1;
	}

	function hook_subscribe_feed($contents, $url) {
		if(! self::check_url($url))
			return $contents;

		return '<rss version="2.0"><channel/></rss>';
	}

	function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, $timestamp) {
		if(! self::check_url($fetch_url) || $feed_data)
			return $feed_data;

		try {
			return self::create_feed($fetch_url, $timestamp, $feed);
		} catch (Exception $e) {
			user_error("Error for '$fetch_url': " . $e->getMessage());
			return "<error>" . $e->getMessage() . "</error>\n";
		}
	}

}
?>
