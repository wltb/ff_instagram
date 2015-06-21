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
	* Most likely only works on Instagram URLs.
	*
	* @param string $url    Should be an Instagram user URL
	* @param int $max_id   should be an int that is an Instagram post id
	*
	* @return array    deserialized JSON
*/
function extract_Insta_JSON($url, $max_id=false) {
	$url_m = rtrim(build_url(parse_url($url)), "/");
	$url_m .= '?__a=1';
	if($max_id !== false) {
		$url_m .= "&max_id=$max_id";
	}

	$json = fetch_file_contents($url_m);
	#echo $json;
	if (! $json) {
		global $fetch_last_error;
		throw new \Exception("'$fetch_last_error' occured for '$url'");
	}

	$a = json_decode($json, true);
	//var_dump($a);
	if($a === NULL)
		throw new \Exception("Couldn't extract json data from '$url_m'");
	return $a["user"];
}

/*
	* These function work on an array as returned by extract_Insta_JSON
*/

function get_Insta_username($json) {
	$name = $json["full_name"];
	if(!$name)
		$name = $json["username"];
	return trim($name);
}

/*
	* Takes an Instagram entry
	* (an entry of the array returned by get_Insta_user_media)
	* and extracts & formats its entries so they can be inserted into a RSS feed

	* @param array $entry    a deserialized Instagram entry
	*
	* @return array    formatted data, indexed with the corresponding RSS elements
*/
function convert_Insta_data_to_RSS($entry) {
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
