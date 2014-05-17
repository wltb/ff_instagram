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
	* Takes a webpage and tries to extract a serialzed JSON from its content.
	* Most likely only works on Instagram webpages.
	*
	* @param string $html    Should be an Instagram page
	*
	* @return array    deserialized JSON
*/
function extract_Insta_JSON($html) {
	$doc = new \DOMDocument();
	libxml_use_internal_errors(true);
	$doc->loadHTML($html);

	$xpath = new \DOMXPath($doc);
	$js = $xpath->query('//body/script')->item(1)->nodeValue;

	#var_dump($js);

	$start = strpos($js, '{');
	$end = strrpos($js, ';');
	$json = substr($js, $start, $end - $start);
	#echo $json;

	$a = json_decode($json, true);
	return $a["entry_data"]["UserProfile"][0];
}

/*
	* These function return data from an array as returned by extract_Insta_JSON
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

/*
	* Takes an Instagram entry
	* (e.g. on entry of the array returned by get_Insta_user_data
	*  or Instagram's API "data" field)
	* and extracts & formats its entries so they can be inserted into a RSS feed

	* @param array $post    a deserialized Instagram entry
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
	#Not yet tested (No data found)
	$tags = $entry["tags"];
	if($tags) {
		$item["category"] = array();
		foreach($tags as $tag) {
			echo $tag;
			$item["category"] [] = $tag;
		}
	}

	return $item;
}

?>
