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
	$url = rtrim(build_url(parse_url($url)), "/");
	$url .= '?__a=1';

	/*$doc = new \DOMDocument();
	$html = fetch_file_contents($url);
	@$doc->loadHTML($html);
	#echo $doc->saveXML();

	$xpath = new \DOMXPath($doc);
	$js = $xpath->query('//body/script[@type="text/javascript" and string-length(text()) > 50 and not(@src)]')->item(0)->nodeValue;

	#var_dump($js);

	$start = strpos($js, '{');
	$end = strrpos($js, ';');
	$json = substr($js, $start, $end - $start);*/
	$json = fetch_file_contents($url);
	#echo $json;

	$a = json_decode($json, true);
	//var_dump($a);
	if($a === NULL)
		throw new \Exception("Couldn't extract json data on '$url'");
	return $a["user"];
}

/*
	* These function work on an array as returned by extract_Insta_JSON
*/
function get_Insta_user_media($json) {
	return $json["media"]["nodes"];
}

function get_Insta_user_id($json) {
	return $json["id"];
}

function get_Insta_username($json) {
	$name = $json["full_name"];
	if(!$name)
		$name = $json["username"];
	return trim($name);
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
	* (an entry of the array returned by get_Insta_user_media)
	* and extracts & formats its entries so they can be inserted into a RSS feed

	* @param array $entry    a deserialized Instagram entry
	* @param int $last_fetch_time   timestamp for the last fetch as provided by Tiny Tiny RSS
	*
	* @return array    formatted data, indexed with the corresponding RSS elements
*/
function convert_Insta_data_to_RSS($entry, $last_fetch_time) {
	if ($last_fetch_time !== false) { //not the first fetch
		$time = time();
		if ($entry['is_video']) {
			if ($time - $entry["date"] > 600000) //10 hours 36000
				return;
		} else {
			if ($time - $entry["date"] > 600000) //a week
				return;
		}
	}

	$item = array();

	#link
	$item["link"] = 'https://instagram.com/p/' . $entry["code"];

	#author
	#$item["author"] = get_Insta_username($entry);

	#date
	$item["pubDate"] = date(DATE_RSS, $entry["date"]);

	#title
	#$item["title"] = $entry["user"]["full_name"];

	#content
	if ($entry['is_video'] === false) {
		$item["content"] = sprintf('<img src="%s"/>', $entry["display_src"]);
	} else{
		$doc = new \DOMDocument();
		$html = fetch_file_contents($item["link"]);
		@$doc->loadHTML($html);
		#echo $doc->saveXML();
		$xpath = new \DOMXPath($doc);

		$height = $xpath->evaluate('string(//meta[@property="og:video:height"]/@content)');
		$width = $xpath->evaluate('string(//meta[@property="og:video:width"]/@content)');
		$type = $xpath->evaluate('string(//meta[@property="og:video:type"]/@content)');
		$v_url = $xpath->evaluate('string(//meta[@property="og:video:secure_url"]/@content)');

		$item["content"] = sprintf('<video controls width="%s" height="%s" poster="%s">
			<source src="%s" type="%s"></source> Your browser does not support the video tag. </video>',
			$width, $height, $entry["display_src"], $v_url, $type);
	}

	$caption = $entry["caption"];
	if($caption) {
		#heuristic: Suppose that all @xyz strings are Instagram references
		# and turn them into hyperlinks.
		$caption = preg_replace('/@([\w.]+\w)/',
				'<a href="https://instagram.com/$1">@$1</a>', $caption);

		$item["content"] = sprintf("<div>%s<p>%s</p></div>", $item["content"], trim($caption));
	}

	#tags - still there?
	/*if($entry["tags"])
		$item["category"] = $entry["tags"];*/

	return $item;
}

?>
