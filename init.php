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
			$e = new Exception("'$fetch_last_error' occured for '$url'");
			$e->url = $url;
			$e->fetch_last_error = $fetch_last_error;
			throw $e;
		}

		$a = json_decode($json, true);
		//var_dump($a);
		if($a === NULL) {
			$e = new Exception("Couldn't extract json data from '$url_m'");
			$e->url = $url_m;
			throw $e;
		}
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
		* (an entry of the array returned by get_Insta_JSON)
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

	static function process_Insta_json($url, $timestamp, $callback, $json=NULL) {
		if(!$json)
			$json = self::get_Insta_JSON($url);
		//var_dump($json);
		$username = self::get_Insta_username($json);
		if ($json["is_private"] === FALSE) {
			while(TRUE) {
				$media = $json["media"];
				$break_outer = False;

				foreach($media["nodes"] as $index => $post) {
					if($timestamp !== false && $timestamp - $post["date"] > 300000 && $index > 6) {
						// on fetches that aren't the first fetch,
						// don't fetch posts that are half a week older than the last fetch-time,
						// but fetch at least 7 items
						$break_outer = True;
						break;
					}

					$item = self::prepare_for_RSS($post);
					$item['author'] = $username;
					#var_dump($item);
					$callback($item); //yield would be nicer
				}
				$info = $media["page_info"];
				//var_dump(end($media["nodes"])["date"]);
				//var_dump($media["nodes"][count($media["nodes"]) - 1]["date"]);
				if ($break_outer || !$info["has_next_page"])
					break;
				else {
					$json = self::get_Insta_JSON($url, $info["end_cursor"]);
				}
			}
		}
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

		$loop_func = function(&$ar) use ($feed) {
			$feed->new_item($ar);
		};

		self::process_Insta_json($url, $timestamp, $loop_func, $json);

		return $feed->saveXML();
	}

	static function check_url($url) {
		//return TRUE on match, FALSE otherwise
		return preg_match('%^https?://(www\.)?instagram\.com/[\w.]+[#/]?$%i',  $url) === 1;
	}

	function hook_subscribe_feed($contents, $url) {
		if(! self::check_url($url))
			return $contents;

		$charset_hack = '<head>
			<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
		</head>';
		$doc = new DOMDocument();
		@$doc->loadHTML($charset_hack . $contents);
		$xpath = new DOMXPath($doc);

		$feed = new RSSGenerator\Feed();
		$feed->link = $xpath->evaluate('string(//meta[@property="og:url"]/@content)');
		$feed->title = $xpath->evaluate('string(//meta[@property="og:title"]/@content)');
		$feed->description = $xpath->evaluate('string(//meta[@property="og:description"]/@content)');

		return $feed->saveXML();
	}

	function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, $timestamp) {
		if(! self::check_url($fetch_url) || $feed_data)
			return $feed_data;

		try {
			return self::create_feed($fetch_url, $timestamp, $feed);
		} catch (Exception $e) {
			if(isset($e->fetch_last_error) && $e->fetch_last_error == "HTTP Code: 404")
			// let ttrss try to fetch it for a nice feedback in the gui.
				return '';

			$msg = $e->getMessage();
			user_error("Error for '$fetch_url': $msg");
			return "<error>$msg</error>\n";
		}
	}

}
?>
