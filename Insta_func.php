<?php
namespace Insta;
/*
	* Collection of some useful functions to deal with Instagram data.
	*
	* Copyright 2014 by wltb
	*
	* @license LGPL-3.0+ <http://spdx.org/licenses/LGPL-3.0+>
*/

/*
	* Takes a URL, loads and tries to extract a serialzed JSON.
	* Most likely only works on Instagram webpages.
	*
	* @param string $url    Should be an Instagram user URL
	*
	* @return array    deserialized JSON
*/
function extract_Insta_JSON($url) {
	$doc = new \DOMDocument();
	libxml_use_internal_errors(true);
	$doc->loadHTMLFile($url);
	#echo $doc->saveXML();

	$xpath = new \DOMXPath($doc);
	$js = $xpath->query('//body/script[@type="text/javascript"]')->item(1)->nodeValue;

	#var_dump($js);

	$start = strpos($js, '{');
	$end = strrpos($js, ';');
	$json = substr($js, $start, $end - $start);
	#echo $json;

	$a = json_decode($json, true);
	if($a === NULL)
		throw new \Exception("Couldn't extract json data on '$url'");
	return $a["entry_data"]["UserProfile"][0];
}

/*
	* These function work on an array as returned by extract_Insta_JSON
*/
function get_Insta_user_data($json) {
	return $json["userMedia"]; //Same structure as Instagram API returns
}

function get_Insta_user_id($json) {
	return $json["user"]["id"];
}

function get_Insta_username($json) {
	return $json["user"]["full_name"];
}

//Some functions that deal with Instagram API requests
function decode_Insta_API_response($url) {
	$json = file_get_contents($url);
	$json = json_decode($json, true);
	if($json === NULL)
		throw new \Exception("'$url' returned no json.");

	if(isset($json["meta"]))
		$status	= $json["meta"];
	else
		$status	= $json;

	if($status["code"] != 200)
		throw new \Exception($status["error_message"], $status["code"]);

	return $json;
}

function sanity_check($ids)
{
	$s = "https://api.instagram.com/v1/users/44291/media/recent/?min_timestamp=%d&client_id=%s";
	if(!is_array($ids))
		$ids = array($ids);
	shuffle($ids);
	foreach($ids as $id) {
		$url = sprintf($s, time(), $id);
		try {
			decode_Insta_API_response($url);
			return $id;
		} catch (\Exception $e) {
			continue;
		}
	}

	throw $e;
}

function Insta_API_user_recent($user_id, $id, $callback, $timestamp = false) {
	$s = "https://api.instagram.com/v1/users/%s/media/recent/?client_id=%s";

	$id = sanity_check($id);
	$url = sprintf($s, $user_id, $id);

	while(TRUE) {
		$response = decode_Insta_API_response($url);
		$callback($response['data']); //yield would be nicer
		$url = $response["pagination"]["next_url"];
		if(!$url) break;
	}
}

/*
	* Takes an Instagram entry
	* (e.g. on entry of the array returned by get_Insta_user_data
	*  or Instagram's API "data" field)
	* and extracts & formats its entries so they can be inserted into a RSS feed

	* @param array $entry    a deserialized Instagram entry
	*
	* @return array    formatted data, indexed with the corresponding RSS elements
*/
function convert_Insta_data_to_RSS($entry) {
	$item = array();

	#link
	$item["link"] = $entry["link"];

	#author
	$item["author"] = $entry["user"]["full_name"];

	#date
	$item["pubDate"] = date(DATE_RSS, $entry["created_time"]);

	#title
	$item["title"] = $entry["user"]["full_name"];

	#content
	if($entry["type"] == "image")
		$media_data = $entry["images"]["standard_resolution"];
	elseif($entry["type"] == "video")
		$media_data = $entry["videos"]["standard_resolution"];

	$url = $media_data["url"];
	$width = $media_data["width"];
	$height = $media_data["height"];

	if($entry["type"] == "image") {
		$item["content"] = sprintf('<p><img src="%s" width="%s" height="%s" /></p>', $url, $width, $height);
	}
	elseif($entry["type"] == "video") {
		$thumb = '';
		$pic_url = $entry["images"]["standard_resolution"]["url"];
		if($pic_url)
			$thumb = sprintf('poster="%s"', $pic_url);

		#it may be cleaner to put src and type into a <source>,
		# but Firefox doesn't like that
		$item["content"] = sprintf('<p><video src="%s" type="video/mp4" controls width="%s" height="%s" %s><source >Your browser does not support the video tag.</video></p>', $url, $width, $height, $thumb);
	}

	$caption = $entry["caption"]["text"];
	if($caption) {
		#heuristic: Supposes that all @xyz strings are Instagram references
		# and turns them into hyperlinks
		$caption = preg_replace('/@(\w+)/', '<a href="http://instagram.com/$1">@$1</a>', $caption);
		$item["content"] .= sprintf("<p>%s</p>", $caption);
	}

	#tags

	if($entry["tags"])
		$item["category"] = $entry["tags"];

	return $item;
}

?>
