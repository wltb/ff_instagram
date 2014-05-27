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


	function create_feed($url, $timestamp) {
		$json = Insta\extract_Insta_JSON($url);
		#var_dump($json);

		$feed = new RSSGenerator\Feed();
		$feed->link($url);
		$feed->title(sprintf("%s / Instagram", Insta\get_Insta_username($json)));

		$loop_func = function($json) use ($feed) {
			foreach($json as $post) {
				/*unset($post["comments"]);
				unset($post["likes"]);
				var_dump($post);
				*/

				$item = Insta\convert_Insta_data_to_RSS($post);
				#var_dump($item);
				$feed->new_item($item);
			}
		};

		if($timestamp === false && isset($this->Insta_client_id)) {
			Insta\Insta_API_user_recent(Insta\get_Insta_user_id($json),
				$this->Insta_client_id, $loop_func);
		}
		else {
			$loop_func(Insta\get_Insta_user_data($json));
		}

		return $feed->saveXML();
	}

	function hook_subscribe_feed($contents, $url) {
		if(preg_match('%^http://instagram.com/\w+#?$%i',  $url) !== 1)
			return $contents;

		return '<rss version="2.0"><channel/></rss>';
	}

	function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, $timestamp) {
		if(preg_match('%^http://instagram.com/\w+#?$%i',  $fetch_url) !== 1 || $feed_data)
			return $feed_data;

		try {
			return $this->create_feed($fetch_url, $timestamp);
		} catch (Exception $e) {
			user_error("Error for '$fetch_url': " . $e->getMessage());
		}
	}

}
?>
