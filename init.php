<?php

require_once __DIR__ .  '/RSSGenerator.php';
require_once __DIR__ . "/instagram.php";

use PI\Instagram\Logging;

class ff_Instagram extends Plugin {
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
		$host->add_hook($host::HOOK_FETCH_FEED, $this);
		$host->add_hook($host::HOOK_FEED_FETCHED, $this);
		$host->add_hook($host::HOOK_SUBSCRIBE_FEED, $this);
		$host->add_hook($host::HOOK_FEED_BASIC_INFO, $this);
		$host->add_hook($host::HOOK_FEED_PARSED, $this);
		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
		$host->add_hook($host::HOOK_SANITIZE, $this);

		$this->host = $host;
	}

	private $ids;  # do not use this directly, use the getter/setter functions

	private function init_ids() {  # TODO does not work in init?
		if(! $this->ids) {
			$ids = $this->host->get($this, 'ids');
			@$ids = json_decode($ids, true);
			if(! $ids) $ids = [];
			$this->ids = $ids;
		}
	}

	private function get_id($url) {
		$this->init_ids();
		return $this->ids[$url];
	}

	private function set_id($url, $id) {
		$this->init_ids();
		if($id && is_numeric($id)) {
			$passthru = false;
			if(! isset($this->ids[$url]) || $this->ids[$url] != $id) $passthru = true;
			$this->ids[$url] = $id;
			if($passthru) {
				Logging::debug("Setting id '$id' for '$url'");
				$this->host->set($this, 'ids', json_encode($this->ids));
			}
		}
	}

	private static function check_feed_icon($icon_url, $feed_id) {
		if(! $icon_url || Feeds::feedHasIcon($feed_id)) return;

		$icon_file = Feeds::getIconFile($feed_id);
		$contents = fetch_file_contents($icon_url);
		if ($contents && mb_strlen($contents, '8bit') < 65535) {
			$fp = @fopen($icon_file, "w");

			if ($fp) {
				fwrite($fp, $contents);
				fclose($fp);
				chmod($icon_file, 0644);
				Logging::debug("Saving feed icon: '$icon_file' <-- '$icon_url'");
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

	private static function normalize_Insta_user_url($url) {
		$path = parse_url($url, PHP_URL_PATH);
		$host = parse_url($url, PHP_URL_HOST);

		if(! preg_match('/^(www\.)?instagram\.com$/i', $host)) return;  // see issue #1

		$p_comps = array_filter(explode("/", $path, 3));
		$user = strtolower(array_shift($p_comps));  // instagram is case insensitive about user names
		if( ! $user || $p_comps) return;

		return "https://instagram.com/$user/";
	}

	/*
		* Takes an object representing a user page and an integer timestamp.
		*
		* yield arrays that can be inserted into RSSGenerator's Item class
	*/

	private static function generate_Iposts($user, $timestamp) {
		if($user->is_private()) return; // shouldn't happen here, but whatever

		$LIMIT = 5000;
		foreach($user->generate_posts($timestamp > 0) as $i => $post) {
			#var_dump($post);
			$item = $post->format_for_rss();
			$item["author"] = $user->author();
			yield $item;
			if($i > $LIMIT) break;
		}
	}

	const charset_hack = '<head>
			<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
		</head>';

	private static function get_insta_user_metadata_html($html) {
	/* This will not be called if everything goes alright, but is kept as fallback
	since it should be more robust than haggling with json
	*/
		$doc = new DOMDocument();
		@$doc->loadHTML(self::charset_hack . $html);
		$xpath = new DOMXPath($doc);
		$title = $xpath->evaluate('string(//meta[@property="og:title"]/@content)');
		$title = preg_replace("/ photos and videos$/", '', $title);

		$icon = $xpath->evaluate('string(//meta[@property="og:image"]/@content)');

		return [$title, $icon, NULL];
	}


	function hook_subscribe_feed($contents, &$url) {
		$n_url = self::normalize_Insta_user_url($url);
		if(! $n_url) return $contents;

		$url = $n_url;

		try {
			if($contents) $this->user = PI\Instagram\UserPage::from_html($contents);
			else $this->user = $this->create_ig_user($url);
		} catch (Exception $e) {
			user_error("Error while trying to get Instagram data for '$url'");
		}

		if(! $this->user) {
			list($this->title, $this->icon_url,) = self::get_insta_user_metadata_html($contents);
		}

		$feed = new RSSGenerator_Inst\Feed();
		# Setting these has no purpose ATM
		$feed->link = $url;
		$feed->title = $this->user? $this->user->title() : $this->title;

		return $feed->saveXML();
	}


	function hook_feed_basic_info($basic_info, $fetch_url, $owner_uid, $feed_id) {
		$url = self::normalize_Insta_user_url($fetch_url);
		if(! $url || $basic_info) return $basic_info;

		Logging::debug("Trying to get basic feed info for '$fetch_url'");
		$basic_info['site_url'] = $url;

		if(! $this->user) {
			try {
				$this->user = $this->create_ig_user($url);
			} catch(Exception $e) {}
		}

		# Try to get data set by earlier hook, or our call above
		if($this->user) {
			$title = $this->user->title();
			$icon_url = $this->user->icon_url();
			$this->set_id($url, $this->user->id());
		} else {
			if($this->title) $title = $this->title;
			if($this->icon_url) $icon_url = $this->icon_url;
		}

		if($title) $basic_info['title'] = $title;
		if($icon_url) self::check_feed_icon($icon_url, $feed_id);

		Logging::debug("Found basic feed info for '$fetch_url': " . json_encode($basic_info));

		return $basic_info;
	}


	protected function create_ig_user($url) {
		$url_ = $url;
		$url = self::normalize_Insta_user_url($url);
		if(! $url) throw new Exception("No instagram user URL: '$url_'");

		$id = $this->get_id($url);

		#PI\Instagram\Loader::set_meta();  # may throw Exception

		$calls = ['from_url' => [$url], 'from_deskgram' => [$url]];
		if($id) $calls = array_merge(['from_id' => [$id, $url]], $calls);

		foreach($calls as $func => $arg) {
			try {
				Logging::debug("Calling '$func'");
				$user = call_user_func_array(['PI\Instagram\UserPage', $func], $arg);
				if($user) break;
			} catch(PI\Instagram\UserPrivateException $e) { # TODO | these
				$this->set_id($url, $e->id());
				throw $e;
			} catch(PI\Instagram\NoPostsException $e) {
				$this->set_id($url, $e->id());
				throw $e;
			} catch(Exception $e) {
				Logging::exception_debug($e, "Got Exception");
				continue;
			}
		}

		if($user) {
			$this->set_id($url, $user->id());
			return $user;
		} else {
			if($e) throw $e;
			else {
				$s = "Something strange happened for '$url_'";
				Logging::error($s);
				throw new Exception($s);
			}
		}
	}


	function hook_fetch_feed($feed_data, $fetch_url) {
		$url = self::normalize_Insta_user_url($fetch_url);
		if(! $url || $feed_data) return $feed_data;

		try {
			$this->user = $this->create_ig_user($url);
		} catch (PI\Instagram\UserPrivateException $e) {
			$s = "'$url': Set to private";
			Logging::debug($s);
			return "<error>$s</error>\n";
		} catch (PI\Instagram\NoPostsException $e) {
			$s = "'$url': No Posts.";
			Logging::error($s);
			return "<error>$s</error>\n";
		} catch (PI\Instagram\FetchException $e) {
			#if($e->getCode() == 404)
			return "";  // for better UI feedback
		} catch (PI\Instagram\CantTellException $e) {
			return "";  # TODO
		} catch (Exception $e) {
			return "";
		}

		# create feed
		$feed = new RSSGenerator_Inst\Feed(['link' => $this->user->url(),
				'title' => $this->user->title(), 'description' => $this->user->description()]);

		return $feed->saveXML();
	}


	function hook_feed_fetched($feed_data, $fetch_url) {
		$url = self::normalize_Insta_user_url($fetch_url);
		if(! $url || ! Feeds::is_html($feed_data)) return $feed_data;

		try {
			$this->user = PI\Instagram\UserPage::from_html($feed_data);
		} catch (PI\Instagram\UserPrivateException $e) {
			$s = "'$url': Set to private";
			Logging::debug($s);
			return "<error>$s</error>\n";
		} catch (PI\Instagram\NoPostsException $e) {
			$s = "'$url': No Posts.";
			Logging::error($s);
			return "<error>$s</error>\n";
		} catch(PI\Instagram\CantTellException $e) {
			return $feed_data;
		} catch (Exception $e) {
			Logging::exception_error($e, "Error for '$url'");
			return "<error>'$url': {$e->getMessage()}</error>\n";
		}

		# create feed
		$feed = new RSSGenerator_Inst\Feed(['link' => $this->user->url(),
				'title' => $this->user->title(), 'description' => $this->user->description()]);

		return $feed->saveXML();
	}


	function hook_feed_parsed($rss, $feed_id) {
		if ( ! self::normalize_Insta_user_url($rss->get_link()) || ! isset($this->user)) return;

		// We also check here in case of deleted favicon
		self::check_feed_icon($this->user->icon_url(), $feed_id);

		# check latest entry in DB
		# TODO use $row["last_modified"] or $row["last_unconditional"] from ttrss_feeds here?
		$db = $this->host->get_pdo();
		$sth = $db->prepare("SELECT max(date_entered) AS ts FROM
				ttrss_entries, ttrss_user_entries WHERE
				ref_id = id AND feed_id = ?");

		$sth->execute([$feed_id]);
		$ts = $sth->fetchColumn();

		if($ts) {
			Logging::debug("Found latest DB entry timestamp: '$ts'");
			$ts = @strtotime($ts);
		} else {
			Logging::debug("No entries in DB.");
			$ts = false;
		}

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

		foreach(self::generate_Iposts($this->user, $ts) as $ar) {
			$it = $feed->new_item($ar);
			$items [] = new FeedItem_RSS($it->get_item(), $doc, $xpath);
		}
		//var_dump($items);

		$p_items->setValue($rss, $items);
	}

	function hook_article_filter($article) {
		$link = $article["link"];
		if(stripos($link, 'instagram.com/p/') === false) return $article;

		try {
			$article['content'] = PI\Instagram\Post::reformat_content($article['content'], $link);
		} catch (PI\Instagram\ServerSideException $e) {
			Logging::exception_debug($e, "'$link': Server error");
		} catch (Exception $e) {
			Logging::exception_error($e, "Error for '$link'");
		}

		return $article;
	}

	function hook_sanitize($doc, $site_url, $allowed_elements, $disallowed_attributes) {
		if(stripos($site_url, 'instagram.com/') !== False) {
			$cap = $doc->getElementsByTagName("figcaption")->item(0);
			PI\Instagram\Post::sanitize_caption($cap);
		}

		return array($doc, $allowed_elements, $disallowed_attributes);
	}
}
