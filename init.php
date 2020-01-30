<?php

require_once __DIR__ .  '/RSSGenerator.php';
require_once __DIR__ . "/instagram.php";

use PI\Instagram\Logging;

/* compatibility with older versions */
if(! function_exists('feed_has_icon')) {
	function feed_has_icon($arg) {return Feeds::feedHasIcon($arg);}
}
if(! function_exists('is_html')) {
	function is_html($arg) {return Feeds::is_html($arg);}
}

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
		$host->add_hook($host::HOOK_FETCH_FEED, $this);
		$host->add_hook($host::HOOK_FEED_FETCHED, $this);
		$host->add_hook($host::HOOK_SUBSCRIBE_FEED, $this);
		$host->add_hook($host::HOOK_FEED_PARSED, $this);
		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
		$host->add_hook($host::HOOK_SANITIZE, $this);

		$this->host = $host;
	}

	private static function check_feed_icon($icon_url, $feed_id) {
		if(! $icon_url || feed_has_icon($feed_id)) return;

		$icon_file = ICONS_DIR . "/$feed_id.ico";
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
		$doc = new DOMDocument();
		@$doc->loadHTML(self::charset_hack . $html);
		$xpath = new DOMXPath($doc);
		$title = $xpath->evaluate('string(//meta[@property="og:title"]/@content)');
		$title = preg_replace("/ photos and videos$/", '', $title);

		$icon = $xpath->evaluate('string(//meta[@property="og:image"]/@content)');

		return [$title, $icon, NULL];
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

	private static function create_xml_icon_stamp($user, $feed_id, $db) {
		# create feed
		$feed = new RSSGenerator_Inst\Feed();

		$feed->link = $user->url();
		$feed->title = $user->title();
		$feed->description = $user->description();

		# icon
		self::check_feed_icon($user->icon_url(), $feed_id);

		//check latest entry in DB
		$result = $db->query("SELECT max(date_entered) AS ts FROM
				ttrss_entries, ttrss_user_entries WHERE
				ref_id = id AND feed_id = '$feed_id'", false);
		$ts = $db->fetch_result($result, 0, "ts");  // NULL when no entries in DB

		if($ts) $ts = @strtotime($ts);
		else $ts = false;

		return [$feed->saveXML(), $ts];
	}

	private function set_id($id, $url, $ids) {
		if($id && ! isset($ids[$url])) {
			Logging::debug("Setting id '$id' for '$url'");
			$ids[$url] = $id;
			$this->host->set($this, 'ids', json_encode($ids));
		}
	}

	function hook_fetch_feed($feed_data, $fetch_url, $o_id, $feed_id) {
		$url = self::normalize_Insta_user_url($fetch_url);

		if(! $url || $feed_data) return $feed_data;
		try { PI\Instagram\Loader::set_meta();}
		catch (Exception $e) {return '';}

		$ids = $this->host->get($this, 'ids');
		@$ids = json_decode($ids, true);
		if($ids) $id = $ids[$url];
		else $ids = [];

		$calls = ['from_url' => [$url], 'from_deskgram' => [$url]];
		if($id) $calls = array_merge(['from_id' => [$id, $url]], $calls);

		try {
			$user = NULL;

			foreach($calls as $func => $arg) {
				try {
					Logging::debug("Calling '$func'");
					$user = call_user_func_array(['PI\Instagram\UserPage', $func], $arg);
					if($user) break;
				} catch(PI\Instagram\UserPrivateException $e) { # TODO | these
					throw $e;
				} catch(PI\Instagram\NoPostsException $e) {
					throw $e;
				} catch(Exception $e) {
					Logging::exception_debug($e, "Got Exception");
					continue;
				}
			}
			if($user) {
				$this->user = $user;
				self::set_id($user->id(), $url, $ids);
			}
			else {
				if($e) throw $e;
				else {
					Logging::error("Something strange happened for '$fetch_url'");
					return "";
				}
			}
		} catch (PI\Instagram\UserPrivateException $e) {
			self::set_id($e->id(), $url, $ids);
			$s = "'$url': Set to private";
			Logging::debug($s);
			return "<error>$s</error>\n";
		} catch (PI\Instagram\NoPostsException $e) {
			self::set_id($e->id(), $url, $ids);
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

		list($con, $this->ts) = self::create_xml_icon_stamp($this->user, $feed_id, $this->host->get_dbh());

		return $con;
	}

	function hook_feed_fetched($feed_data, $fetch_url, $owner_uid, $feed_id) {
		$url = self::normalize_Insta_user_url($fetch_url);
		if(! $url || ! is_html($feed_data)) return $feed_data;

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

		list($con, $this->ts) = self::create_xml_icon_stamp($this->user, $feed_id, $this->host->get_dbh());

		return $con;
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

		foreach(self::generate_Iposts($this->user, $this->ts) as $ar) {
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
