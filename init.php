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


	static function create_feed($url, $timestamp) {
		$json = Insta\extract_Insta_JSON($url);
		#var_dump($json);

		$feed = new RSSGenerator\Feed();
		$username = Insta\get_Insta_username($json);
		$feed->link = $url;
		$feed->title = sprintf("%s / Instagram", $username);

		$loop_func = function($json) use ($feed, $username, $timestamp) {
            //if(!$json) return;
            $time = time();
			foreach($json as $post) {
				$diff = $time - $post["date"];
				if ($timestamp !== false  //not the first fetch
					&& ($timestamp > $post["date"] + 3600)  //post was already seen, or force refetch
					&& ($diff > 600000  //a week
							|| ($post['is_video'] && $diff > 7200) //2 hours because of fetch overhead
						)
					)
					continue;

				$item = Insta\convert_Insta_data_to_RSS($post);
				$item['author'] = $username;
				#var_dump($item);
				$feed->new_item($item);
			}
		};

		if($timestamp === false && isset(self::$Insta_client_id)) {
			Insta\Insta_API_user_recent(Insta\get_Insta_user_id($json),
				self::$Insta_client_id, $loop_func);
		}
		else {
			$loop_func(Insta\get_Insta_user_media($json));
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
			return self::create_feed($fetch_url, $timestamp);
		} catch (Exception $e) {
			user_error("Error for '$fetch_url': " . $e->getMessage());
		}
	}

}
?>
