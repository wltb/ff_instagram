<?php

if(!class_exists('RSSGenerator_Inst\Feed'))
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
		$host->add_hook($host::HOOK_FEED_PARSED, $this);
		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);

		$this->host = $host;
	}

	//TODO unify this
	static function check_feed_icon($icon_url, $feed_id) {
		if ($icon_url) {
			$icon_file = ICONS_DIR . "/$feed_id.ico";
			if (! feed_has_icon($feed_id) ) self::save_feed_icon($icon_url, $icon_file);
			else {
				/*
				$ts = filemtime($icon_file);
				if (time() - $ts > 600000) //a week
					self::save_feed_icon($icon_url, $icon_file);
				*/
			}
		}
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
		* This function work on an array as returned by get_Insta_JSON
	*/

	static function get_Insta_username($json) {
		$name = $json["full_name"];
		if(!$name)
			$name = $json["username"];
		return trim($name);
	}

	/*
		$url should be a URL to an Instagram video page.
	*/

	static function fetch_Insta_video($url) {
		$doc = new DOMDocument();
		$html = fetch_file_contents($url);
		@$doc->loadHTML($html);
		#echo $doc->saveXML();
		$xpath = new DOMXPath($doc);

		$script = $xpath->query('//script[@type="text/javascript" and contains(., "GraphSidecar")]');
		//var_dump($script);
		if($script->length === 1) {
			$script = $script->item(0)->textContent;
			$json = preg_replace('/^\s*window._sharedData\s*=\s*|\s*;\s*$/', '', $script);
			$json = json_decode($json, true);

			$data = $json["entry_data"]["PostPage"][0]["media"];
			//unset($data["comments"]);unset($data["likes"]);
			//var_dump($data);
			$edges = $data["edge_sidecar_to_children"]["edges"];
			if(!$edges || !is_array($edges)) user_error("'$url': Something wrong.");

			$html = '';
			foreach($edges as $edge) {
				$node = $edge['node']; //really...
				$i_url = $node["display_url"];

				if($node["is_video"]) {
					$v_url = $node["video_url"];
					$html .= "<video controls muted poster='$i_url'>\n"
						. "<source src='$v_url' type='video/mp4'/></video>";
				} else $html .= "<img src='$i_url'/>";
			}
			return $html;
		}


		$height = $xpath->evaluate('string(//meta[@property="og:video:height"]/@content)');
		$width = $xpath->evaluate('string(//meta[@property="og:video:width"]/@content)');
		$type = $xpath->evaluate('string(//meta[@property="og:video:type"]/@content)');
		$v_url = $xpath->evaluate('string(//meta[@property="og:video:secure_url"]/@content)');
		$poster = $xpath->evaluate('string(//meta[@property="og:image"]/@content)');

		return "<video controls muted width='$width' height='$height' poster='$poster'>\n"
				. "<source src='$v_url' type='$type'></source>\n"
				. "Your browser does not support the video tag.</video>";
		// TODO $xpath->evaluate('string(//meta[@property="og:description"]/@content)'); -> title
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
		$item['is_video'] = $entry['is_video'];  // deferred fetch
		$item['multi'] = $entry["__typename"] === 'GraphSidecar';

		if ($item['is_video'] || $item['multi']) {
			$item["content"] = '';//self::fetch_Insta_video($item["link"]);
		} else{
			$item["content"] = sprintf('<p><img src="%s"/></p>', $entry["display_src"]);
		}

		$caption = $entry["caption"];
		if($caption) {
			#heuristic: Suppose that all @xyz strings are Instagram references
			# and turn them into hyperlinks.
			$caption = preg_replace('/@([\w.]+\w)/',
					'<a href="https://instagram.com/$1">@$1</a>', $caption);

			$item["content"] = sprintf("%s<p>%s</p>", $item["content"], trim($caption));
		}

		#tags - still somewhere?
		/*if($entry["tags"])
			$item["category"] = $entry["tags"];*/

		return $item;
	}

	static function process_Insta_json($url, $timestamp, $callback, $json=NULL) {
		if(!$json) $json = self::get_Insta_JSON($url);
		//var_dump($json);
		$username = self::get_Insta_username($json);

		if ($json["is_private"] === TRUE) return; // shouldn't happen here, but whatever
		while(TRUE) {
			$media = $json["media"];

			foreach($media["nodes"] as $index => $post) {
				/* on fetches that aren't the very first fetch, don't fetch posts
				that are two weeks older than the latest db entry,
				but fetch at least 7 items
				*/
				if($timestamp && $timestamp - $post["date"] > 1200100 && $index > 6) {
					break;
				}

				$item = self::prepare_for_RSS($post);
				$item['author'] = $username;
				#var_dump($item);
				$callback($item); //TODO yield would be nicer
			}
			$info = $media["page_info"];
			//var_dump(end($media["nodes"])["date"]);
			//var_dump($media["nodes"][count($media["nodes"]) - 1]["date"]);

			# only continue fetching if there are no entries in the DB (most likely very first fetch)
			if($timestamp || ! $info["has_next_page"]) break;

			try {
				$json = self::get_Insta_JSON($url, $info["end_cursor"]);
			} catch (Exception $e) {
				user_error("Error for '$url': " . $e->getMessage());
				break;
			}
		}
	}

	static function Insta_is_private($json) {
		return $json["is_private"];
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

		$feed = new RSSGenerator_Inst\Feed();
		$feed->link = $xpath->evaluate('string(//meta[@property="og:url"]/@content)');
		$title = $xpath->evaluate('string(//meta[@property="og:title"]/@content)');
		$feed->title = preg_replace("/ photos and videos$/", '', $title);
		$feed->description = $xpath->evaluate('string(//meta[@property="og:description"]/@content)');

		return $feed->saveXML();
	}

	function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed_id) {
		if(! self::check_url($fetch_url) || $feed_data) return $feed_data;

		try {
			$this->json = self::get_Insta_JSON($fetch_url);
		} catch (Exception $e) {
			if(isset($e->fetch_last_error) && $e->fetch_last_error == "HTTP Code: 404")
			// let ttrss try to fetch it for a nice feedback in the gui.
				return '';

			$msg = $e->getMessage();
			user_error("Error for '$fetch_url': $msg");
			return "<error>$msg</error>\n";
		}
		#var_dump($this->json);
		if(self::Insta_is_private($this->json)) # TODO test this
			return "<error>Page is private</error>\n";

		$feed = new RSSGenerator_Inst\Feed();

		$username = self::get_Insta_username($this->json);
		$feed->link = $fetch_url;
		$feed->title = "$username • Instagram";
		$feed->description = $this->json["biography"];

		self::check_feed_icon($this->json["profile_pic_url"], $feed_id);

		//check latest entry in DB
		$db = $this->host->get_dbh();
		$result = $db->query("SELECT max(date_entered) AS ts FROM
				ttrss_entries, ttrss_user_entries WHERE
				ref_id = id AND feed_id = '$feed_id'", false);
		$ts = $db->fetch_result($result, 0, "ts");//NULL when no entries in DB

		if($ts) $this->ts = @strtotime($ts);
		else $this->ts = false;

		$this->url = $fetch_url;

		return $feed->saveXML();
	}

	function hook_feed_parsed($rss) {
		if (!self::check_url($rss->get_link())) return;

		static $ref;
		static $p_xpath;
		static $p_items;
		if(!$ref) { #initialize reflection
			$ref = new ReflectionClass('FeedParser');
			$p_xpath = $ref->getProperty('xpath');
			$p_xpath->setAccessible(true);
			$p_items = $ref->getProperty('items');
			$p_items->setAccessible(true);
		}
		if(count($p_items->getValue($rss))) return;

		$xpath = $p_xpath->getValue($rss);
		$doc = $xpath->document;

		$feed = new RSSGenerator_Inst\Feed(array(), $doc);
		$items = array();
		$this->urls = array();
		$urls = & $this->urls;

		$loop_func = function(&$ar) use ($feed, &$items, &$urls, $doc, $xpath) {
			$it = $feed->new_item($ar);
			if($ar['is_video'] || $ar['multi']) $urls[$ar['link']] = true;
			$items [] = new FeedItem_RSS($it->get_item(), $doc, $xpath);
		};

		self::process_Insta_json($this->url, $this->ts, $loop_func, $this->json);
		//var_dump($items);

		$p_items->setValue($rss, $items);
	}

	function hook_article_filter($article) {
		$link = $article["link"];
		if(isset($this->urls[$link])) {
			$cont = self::fetch_Insta_video($link);
			$article['content'] = "<p>$cont</p>" . $article['content'];
		}

		return $article;
	}


}
?>
