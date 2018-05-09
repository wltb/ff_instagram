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
		//$host->add_hook($host::HOOK_FETCH_FEED, $this);
		$host->add_hook($host::HOOK_FEED_FETCHED, $this);
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
	 * Takes a URL and if it determines it to be an Instagram user URL, returns
	 * a normalized version of it. This is useful since ttrss determines
	 * uniqueness of feeds by the fetch_url (and owner_id).
	 *
	 * If the function thinks its argument doesn't belong to an Instagram user,
	 * it returns NULL, so it's also useful to check if the plugin should hook in.
	 *
	 * hashtag or locations are not supported ATM since this would require to do
	 * some things differently (JSON names, RSS author required a lookup etc)
	*/

	static function normalize_Insta_user_url($url) {
		$path = parse_url($url, PHP_URL_PATH);
		$host = parse_url($url, PHP_URL_HOST);

		if(! preg_match('/^(www\.)?instagram\.com$/i', $host)) return;  // see issue #1

		$p_comps = array_filter(explode("/", $path, 3));
		$user = strtolower(array_shift($p_comps));  // instagram is case insensitive about user names
		if( ! $user || $p_comps) return;

		return "https://instagram.com/$user/";
	}

	/*
	These functions works on an array as returned by
	scrap_insta_user_json (first part)
	or fetch_insta_user_json
	*/

	static function get_Insta_username($json) {
		$name = $json["full_name"];
		if(!$name) $name = $json["username"];
		return trim($name);
	}

	static function get_Insta_private($json) {
		return $json["is_private"];
	}

	/*
	 * inserts Instagram media as children of a DOMElement
	 *
	 * $media			array of 2-tuples. If the second component is empty,
	 * 					the first is assumed to be an image URL, if not, the first
	 * 					is assumed to be a poster URL and the second a video URL.
	 * $node			Node the media is appended to.
	 * $muted			currently not used.
	 */

	static function append_media(array $media, DOMElement $node, $muted=TRUE) {
		$doc = $node->ownerDocument;
		foreach($media as $arr) {
			list($i_url, $v_url) = $arr;
			if($v_url) {
				$med = $doc->createElement('video');
				$src = $doc->createElement('source');
				$src->setAttribute('src', $v_url);
				$src->setAttribute('type', 'video/mp4');
				$med->setAttribute('controls', '');
				if($muted) $med->setAttribute('muted', '');
				$med->setAttribute('poster', $i_url);
				if(count($media) < 2) $med->setAttribute('autoplay', '');
				$med->appendChild($src);
			} else {
				$med = $doc->createElement('img');
				$med->setAttribute('src', $i_url);
			}
			$node->appendChild($med);
		}
	}

	const marker = "insta_scrap_later"; //must be lower case for DOM

	/*
	 * produces a presentable HTML version (<figure>) of Instagram media.
	 *
	 * $media			used in append_media
	 * $caption			string, goes to <figcaption>. HTML markup is preserved.
	 * $mark			set a marker in the figure
	 * $muted			currently not used
	 *
	 * returns			a string with HTML markup
	*/

	static function create_figure(array $media, $caption, $mark=false, $muted=TRUE) {
		$doc = new DOMDocument();
		$fig = $doc->createElement('figure');
		$fig->setAttribute('insta_gallery', '');
		if($mark) $fig->setAttribute(self::marker, '');

		self::append_media($media, $fig, $muted);

		//TODO better formatting? line breaks to <p>s etc?!?
		if($caption) {
			$cap = $doc->createElement('figcaption');
			//$text = $doc->createTextNode($caption); we want to preserve hyperlinks, so...
			//$cap->appendChild($text);

			$doc2 = new DOMDocument();
			$doc2->loadHTML(self::charset_hack . "<p>$caption</p>");/*'<?xml version="1.0" encoding="utf-8"?>'*/
			$body = $doc2->getElementsByTagName('body')->item(0);
			$p = $doc->importNode($body->firstChild, true);
			while($child = $p->firstChild) $cap->appendChild($child);

			$fig->appendChild($cap);
		}
		$fig = $doc->appendChild($fig);
		return $fig->c14n();
	}

	/* should be used on a DOMElement like the one created in create_figure */

	static function recreate_figure(array $media, DOMElement $fig) {
		$fig->removeChild($fig->firstChild);//remove img
		self::append_media($media, $fig);

		$doc = $fig->ownerDocument;
		$cap = $doc->getElementsByTagName('figcaption')->item(0);
		if($cap) $fig->appendChild($cap);

		return $doc->saveXML($fig);
	}


	static function scrap_insta_user_json($html) {
		$json = self::scrap_insta_js($html);

		$meta = $json;
		$json = $meta["entry_data"]["ProfilePage"][0]["graphql"]["user"];
		unset($meta["entry_data"]["ProfilePage"][0]["graphql"]["user"]);

		return [$json, $meta];
	}

	/*
		extract useable json out of instagram html pages.
		This is very prone to breakage.
		Can be used for single posts or user main pages ATM.
	*/

	static function scrap_insta_js($html) {
		$doc = new DOMDocument();
		@$doc->loadHTML($html);
		#echo $doc->saveXML();
		$xpath = new DOMXPath($doc);

		$script = $xpath->query('//script[@type="text/javascript" and contains(., "window._sharedData = ")]');
		//var_dump($script);
		if($script->length === 1) {
			$script = $script->item(0)->textContent;
			$json = preg_replace('/^\s*window._sharedData\s*=\s*|\s*;\s*$/', '', $script);
			$json = json_decode($json, true);
			if(! $json) {
				throw new Exception("Couldn't decode json. Possible Reason: '" . json_last_error_msg() . "'.");
			}

			return $json;
		} else throw new Exception("Couldn't find script.");
	}

	/*
		$url should be a URL to an Instagram video/multi* page (/p/.+), but image only should work as well.

		TODO error reporting is a bit wordy/unnecessary (404s),
		but that shouldn't matter too much because it's called only/mostly on fresh stuff.
	*/
	static function scrap_Insta_media_url($url) {
		global $fetch_last_error;
		global $fetch_last_error_code;

		$url_ = "$url?__a=1";
		$json = fetch_file_contents($url_);
		if(!$json) user_error("'$fetch_last_error' occured for '$url_'");
		$data = json_decode($json, true);

		if(!$data) {//fallback
			user_error("Couldn't decode json for '$url_'. Trying to use fallback.");
			$html = fetch_file_contents($url);
			if(! $html) {
				$e = new Exception("'$fetch_last_error' occured for '$url'", $fetch_last_error_code);
				$e->url = $url;
				throw $e;
			}
			$json = self::scrap_insta_js($html);//could throw exception
			$data = $json["entry_data"]["PostPage"][0];
		}

		$media = array();

		$data = $data["graphql"]["shortcode_media"];
		#unset($data["edge_media_to_comment"]); unset($data["edge_media_preview_like"]);
		#var_dump($data);

		switch($data['__typename']) {# below works when video_url isn't there
		case "GraphImage": case "GraphVideo":
			$media [] = [$data['display_url'], $data["video_url"]];
			break;
		case "GraphSidecar":
			$edges = $data["edge_sidecar_to_children"]["edges"];
			foreach($edges as $edge) {
				$node = $edge['node'];//really...
				$media [] = [$node['display_url'], $node["video_url"]];
			}
			break;
		default:
			user_error("No typename for '$url'. Format changed?");
		}

		if(! $media) {//fallback, now for real. Doesn't work for albums
			user_error("json scraping doesn't work for '$url'. Using Fallback.");
			$doc = new DOMDocument();
			@$doc->loadHTML($html);
			#echo $doc->saveXML();
			$xpath = new DOMXPath($doc);

			/*
			$type = $xpath->evaluate('string(//meta[@property="og:video:type"]/@content)');
			*/
			$v_url = $xpath->evaluate('string(//meta[@property="og:video:secure_url"]/@content)');
			$poster = $xpath->evaluate('string(//meta[@property="og:image"]/@content)');

			if($poster) $media = [[$poster, $v_url]];//also works when $v_url == NULL
		}

		return $media;
	}

	/*
	here a bit of magic happens. We need to set a custom HTTP header to query
	and use magic ids. docs:
	https://github.com/ping/instagram_private_api
	https://github.com/postaddictme/instagram-php-scraper
	https://github.com/rarcega/instagram-scraper
	https://stackoverflow.com/q/49786980

	TODO make graphql queries more abstract, also use the curl channel for video/album fetching
	TODO maybe the __a=1 trick can be made to work again with another magic header
	*/
	static $curl_header_keep_alive = array(
					'Connection: Keep-Alive',
					'Keep-Alive: 300');

	static function fetch_insta_user_json($user_id, $meta, $end_cursor) {
		static $ch;
		if(! function_exists(curl_init)) throw new Exception("curl needed");

		if(!$ch) {
			$ch = curl_init();
			$opt = array(CURLOPT_RETURNTRANSFER => true,
					CURLOPT_USERAGENT => SELF_USER_AGENT,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_MAXREDIRS => 20,
					CURLOPT_FAILONERROR => false,
					CURLOPT_FRESH_CONNECT => false,
					CURLOPT_SSL_VERIFYPEER => false,
					CURLOPT_HTTPAUTH => CURLAUTH_ANY,
					CURLOPT_COOKIEJAR => "/dev/null",
					CURLOPT_TIMEOUT => 10,
					CURLOPT_CONNECTTIMEOUT => 10,
					CURLOPT_HEADER => false,
					CURLOPT_NOBODY => false,
					);
			curl_setopt_array($ch, $opt);
			if (defined('_CURL_HTTP_PROXY')) {
				curl_setopt($ch, CURLOPT_PROXY, _CURL_HTTP_PROXY);
			}
		}

		//50 is server-side limit
		$variables = ["id" => $user_id, "first" => 50, "after" => $end_cursor];
		$variables = json_encode($variables);
		$rhx_gis = $meta["rhx_gis"];
		$hash = md5("$rhx_gis:$variables");

		curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(self::$curl_header_keep_alive, ["X-Instagram-GIS: $hash"]));

		$api_url = 'https://www.instagram.com/graphql/query/';

		//magic number. May be deprecated?
		$url = "$api_url?query_id=17880160963012870&variables=$variables";
		curl_setopt($ch, CURLOPT_URL, $url);

		@$result = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if($http_code == 200) $result = json_decode($result, true);
		else {
			throw new Exception("HTTP Error $http_code. Server response: '$result'", $http_code);
		}

		return $result["data"]["user"];
	}

	/*
		* Takes an Instagram url, an integer timestamp and
		* optionally two deserialized json arrays
		* as returned by scrap_insta_user_json for caching reasons.
		* (Note: ATM, the first one is not optional)

		* Fetches Instagram posts associated with the url and prepares them
		* for usage in RSS: Each post data/metadata is stored in an array that
		* can be used for RSSGenerator's Item class, and is yield'ed.
	*/

	static function process_Insta_json($url, $timestamp, $json=NULL, $meta=NULL) {
		#if(!$json) $json = self::fetch_Insta_json($url); # isn't used ATM, but if it is, wrap it in a try block

		$username = self::get_Insta_username($json);
		$insta_user_id = $json['id'];

		if(self::get_Insta_private($json) === TRUE) return; // shouldn't happen here, but whatever
		$LIMIT = 100;
		for($i=0; $i<$LIMIT; $i++) {
			$media = $json["edge_owner_to_timeline_media"];
			//var_dump($media);

			foreach($media["edges"] as $index => $post) {
				$post = $post["node"];
				$date = $post["taken_at_timestamp"];
				/* on fetches that aren't the very first fetch, don't fetch posts
				that are one week older than the latest db entry,
				but fetch at least 7 items
				*/
				if($timestamp && $timestamp - $date > 605102 && $index > 6) {
					break;
				}
				$item = array();

				$item["link"] = 'https://instagram.com/p/' . $post["shortcode"];
				$item['author'] = $username;
				$item["pubDate"] = date(DATE_RSS, $date);
				##comments
				$item["slash_comments"] = $post["edge_media_to_comment"]["count"];
				#content
				$later = $post['is_video'] || ($post["__typename"] === 'GraphSidecar');

				$caption = $post["edge_media_to_caption"]['edges'][0]['node']['text'];
				if($caption) {
					//sanitize caption
					$caption = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]+/u', ' ', $caption);
					$caption = trim($caption);

					#heuristic: Suppose that all @xyz strings are Instagram references
					# and turn them into hyperlinks.
					$caption = preg_replace('/@([\w.]+\w)/',
							'<a href="https://instagram.com/$1">@$1</a>', $caption);
				}

				//always use image only as placeholder
				//if($later) $media = self::scrap_Insta_media_url($item["link"]);
				$item["content"] = self::create_figure([[$post["display_url"], '']], $caption, $later);
				#sprintf('<p><img src="%s"/></p><p>%s</p>', $post["display_src"], $caption);

				#var_dump($item);
				yield $item;
			}
			$info = $media["page_info"];

			# only continue fetching if there are no entries in the DB (most likely very first fetch)
			if($timestamp || ! $info["has_next_page"]) break;

			try {
				#var_dump($meta);
				$json = self::fetch_insta_user_json($insta_user_id, $meta, $info["end_cursor"]);
			} catch (Exception $e) {
				user_error("Error for '$url', end_cursor '{$info["end_cursor"]}': " . $e->getMessage());
				break;
			}
		}
	}

	const charset_hack = '<head>
			<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
		</head>';

	static function get_insta_user_metadata_html($html) {
		$doc = new DOMDocument();
		@$doc->loadHTML(self::charset_hack . $html);
		$xpath = new DOMXPath($doc);
		$title = $xpath->evaluate('string(//meta[@property="og:title"]/@content)');
		$title = preg_replace("/ photos and videos$/", '', $title);

		$icon = $xpath->evaluate('string(//meta[@property="og:image"]/@content)');

		return [$title, $icon, NULL];
	}

	static function get_insta_user_metadata_json($json) {
		$username = self::get_Insta_username($json);
		$title = "$username • Instagram";
		$bio = $json["biography"];
		$icon = $json["profile_pic_url"];

		return [$title, $icon, $bio];
	}

	function hook_subscribe_feed($contents, &$url) {
		//TODO find the right ttrss hook for site url and feed title
		$n_url = self::normalize_Insta_user_url($url);
		if(! $n_url) return $contents;

		$url = $n_url;

		$feed = new RSSGenerator_Inst\Feed();
		$feed->link = $url;
		list($feed->title,,) = self::get_insta_user_metadata_html($contents);

		return $feed->saveXML();
	}


	//is not registered and hence not used ATM
	function hook_fetch_feed($feed_data, &$fetch_url) {
		$url = self::normalize_Insta_user_url($fetch_url);

		if(! $url || $feed_data) return $feed_data;
		else $fetch_url = $url . '?__a=1';

		return '';
	}

	function hook_feed_fetched($feed_data, $fetch_url, $owner_uid, $feed_id) {
		$url = self::normalize_Insta_user_url($fetch_url);
		if(! $url) return $feed_data;

		//TODO maybe put the data in the feed itself instead of holding it in the object
		try {
			list($this->json, $this->meta) = self::scrap_insta_user_json($feed_data);
		} catch (Exception $e) {
			user_error("Error for '$url': {$e->getMessage()}");
			return "<error>'$url': {$e->getMessage()}</error>\n";
		}
		#var_dump($this->json, $this->meta);
		if(self::get_Insta_private($this->json)) # TODO implement login
			return "<error>'$url': Page is private</error>\n";

		$feed = new RSSGenerator_Inst\Feed();

		$feed->link = $url;
		list($feed->title, $icon_url,
			$feed->description) = self::get_insta_user_metadata_json($this->json);

		self::check_feed_icon($icon_url, $feed_id);

		//check latest entry in DB
		$db = $this->host->get_dbh();
		$result = $db->query("SELECT max(date_entered) AS ts FROM
				ttrss_entries, ttrss_user_entries WHERE
				ref_id = id AND feed_id = '$feed_id'", false);
		$ts = $db->fetch_result($result, 0, "ts");//NULL when no entries in DB

		if($ts) $this->ts = @strtotime($ts);
		else $this->ts = false;

		return $feed->saveXML();
	}

	function hook_feed_parsed($rss) {
		if ( ! self::normalize_Insta_user_url($rss->get_link())) return;

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

		foreach(self::process_Insta_json($rss->get_link(), $this->ts, $this->json, $this->meta) as $ar) {
			$it = $feed->new_item($ar);
			$items [] = new FeedItem_RSS($it->get_item(), $doc, $xpath);
		}
		//var_dump($items);

		$p_items->setValue($rss, $items);
	}

	function hook_article_filter($article) {
		$link = $article["link"];
		if(stripos($link, 'instagram.com/p/') === false) return $article;

		$doc = new DOMDocument();
		@$doc->loadHTML(self::charset_hack . $article['content']);

		$fig = $doc->getElementsByTagName('figure')->item(0);
		if( ! $fig->hasAttribute(self::marker)) return $article;

		try {
			$media = self::scrap_Insta_media_url($link);
			$fig->removeAttribute(self::marker);
		} catch (Exception $e) {
			user_error("Error for '$link': {$e->getMessage()}");
			return $article;
		}

		if($media) $article['content'] = self::recreate_figure($media, $fig);

		return $article;
	}


}
?>

