<?php

if(!class_exists('RSSGenerator\Feed'))
	include 'RSSGenerator.php';

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
		if ($contents && mb_strlen($contents, '8bit') < 65535) {
			$fp = @fopen($icon_file, "w");

			if ($fp) {
				fwrite($fp, $contents);
				fclose($fp);
				chmod($icon_file, 0644);
			}
		}
	}
	/*
		* Takes a URL, loads and tries to extract a serialzed JSON.
		* Most likely only works on Instagram URLs.
		*
		* @param string $url    Should be an Instagram user URL
		* @param int $max_id   should be an int that is an Instagram post id
		*
		* @return array    deserialized JSON
	*/
	static function get_Insta_JSON($url, $max_id=false) {
		$url_m = rtrim(build_url(parse_url($url)), "/");
		$url_m .= '?__a=1';
		if($max_id !== false) {
			$url_m .= "&max_id=$max_id";
		}

		$json = fetch_file_contents($url_m);
		#echo $json;
		if (! $json) {
			global $fetch_last_error;
			throw new Exception("'$fetch_last_error' occured for '$url'");
		}

		$a = json_decode($json, true);
		//var_dump($a);
		if($a === NULL)
			throw new Exception("Couldn't extract json data from '$url_m'");
		return $a["user"];
	}

	/*
		* These function work on an array as returned by get_Insta_JSON
	*/

	static function get_Insta_username($json) {
		$name = $json["full_name"];
		if(!$name)
			$name = $json["username"];
		return trim($name);
	}

	/*
		* Takes an Instagram entry
		* (an entry of the array returned by get_Insta_user_media)
		* and extracts & formats its entries so they can be inserted into a RSS feed

		* @param array $entry    a deserialized Instagram entry
		*
		* @return array    formatted data, indexed with the corresponding RSS elements
	*/
	static function prepare_for_RSS($entry) {
		$item = array();

		#link
		$item["link"] = 'https://instagram.com/p/' . $entry["code"];

		#author
		#$item["author"] = get_Insta_username($entry);

		#date
		$item["pubDate"] = date(DATE_RSS, $entry["date"]);

		#title
		#$item["title"] = $entry["user"]["full_name"];

		##comments
		$item["slash_comments"] = $entry["comments"]["count"];

		#content
		if ($entry['is_video'] === false) {
			$item["content"] = sprintf('<img src="%s"/>', $entry["display_src"]);
		} else{
			$doc = new DOMDocument();
			$html = fetch_file_contents($item["link"]);
			@$doc->loadHTML($html);
			#echo $doc->saveXML();
			$xpath = new DOMXPath($doc);

			$height = $xpath->evaluate('string(//meta[@property="og:video:height"]/@content)');
			$width = $xpath->evaluate('string(//meta[@property="og:video:width"]/@content)');
			$type = $xpath->evaluate('string(//meta[@property="og:video:type"]/@content)');
			$v_url = $xpath->evaluate('string(//meta[@property="og:video:secure_url"]/@content)');

			$item["content"] = sprintf("<video controls width='$width' height='$height' poster='%s'>\n"
				. "<source src='$v_url' type='$type'></source>\n"
				. "Your browser does not support the video tag.</video>",
				$entry["display_src"]);
		}

		$caption = $entry["caption"];
		if($caption) {
			#heuristic: Suppose that all @xyz strings are Instagram references
			# and turn them into hyperlinks.
			$caption = preg_replace('/@([\w.]+\w)/',
					'<a href="https://instagram.com/$1">@$1</a>', $caption);

			$item["content"] = sprintf("<p>%s</p><p>%s</p>", $item["content"], trim($caption));
		}

		#tags - still somewhere?
		/*if($entry["tags"])
			$item["category"] = $entry["tags"];*/

		return $item;
	}

	static function create_feed($url, $timestamp, $feed_id) {
		$json = self::get_Insta_JSON($url);
		#var_dump($json);

		$feed = new RSSGenerator\Feed();
		$username = self::get_Insta_username($json);
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
					// because of fetch overhead, most likely do not include videos
					// that were already seen 2 hours ago (not on the very first fetch)
					if ($post['is_video'] && $timestamp !== false
						&& $timestamp - $post["date"] > 7200 && mt_rand(0, 99) < 90)
							continue;

					$item = self::prepare_for_RSS($post);
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
					$json = self::get_Insta_JSON($url, $info["end_cursor"]);
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
