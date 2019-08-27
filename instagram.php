<?php
namespace PI\Instagram;

/*
    Copyright (C) 2018  wltb

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

use DOMElement, DOMXPath, DOMDocument, Exception;

class UserPrivateException extends Exception {}
class NoPostsException extends Exception {}
class JSONDecodeException extends Exception{
	function __construct() {
		parent::__construct("Couldn't decode json. Possible Reason: '" . json_last_error_msg() . "'.", json_last_error());
	}
}
class MissingKeyException extends Exception{
	function __construct($key) {
		parent::__construct("'$key' not in JSON.");
	}
}
class FetchException extends Exception{
	function __construct($text, $code) {
		parent::__construct($text, $code);
	}
}

class Post {
	/*
		This class tries to get meaningful information out of an array
		that is supposed to represent an instagram post.
		ATM, it will not use the additional information for videos or
		multiple media even if it was present in the array.

		Instead, it sets a marker in the content html if additional information
		is supposed to be there, and the strangely named reformat_content
		function can be called to put it there.

		If essential keys are missing, the constructor will throw an Exception.

		other functions handle markup of instagram captions, or markup of media.
	*/
	private $date, $url, $comments, $content;

	function __construct(array $post) {
		foreach(["taken_at_timestamp", "shortcode", "display_url", "__typename"] as $s) {
			if(! isset($post[$s])) {
				throw new MissingKeyException($s);
			}
		}

		$this->date = $post["taken_at_timestamp"];
		$this->url = 'https://instagram.com/p/' . $post["shortcode"];
		$this->comments = $post["edge_media_to_comment"]["count"];

		# TODO not sure if this is always right now
		# must probably test if the needed content is there or not
		$later = $post['is_video'] || ($post["__typename"] === 'GraphSidecar');
		$caption = $post["edge_media_to_caption"]['edges'][0]['node']['text'];
		$this->content = self::create_figure([[$post["display_url"], '']], $caption, $later);
	}

	function format_for_rss() {
		$item = [];
		$item["pubDate"] = date(DATE_RSS, $this->date);
		$item["link"] = $this->url;
		$item["slash_comments"] = $this->comments;
		$item["content"] = $this->content;

		return $item;
	}

	private static function markup_caption($caption) {
		//sanitize so this can be inserted into XML
		$caption = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]+/u', ' ', $caption);
		$caption = trim($caption);

		# \n -> <br>
		$caption = preg_replace("/\n*$/", '', $caption);
		$lines = explode("\n", $caption);
		//var_dump($caption, $lines);
		$s = "";
		foreach($lines as $line) {
			# heuristic: Suppose that all @xyz strings are Instagram references
			# and turn them into hyperlinks.
			$line = preg_replace('/(^|\s)@([\w.]+\w)/u',
						'$1<a href="/$2">@$2</a>', $line);

			# tags
			$line = preg_replace('/#(\w+)/u',
						'<a href="/explore/tags/$1">#$1</a>', $line);

			if($line) $line = "<span>$line</span>";
			$s .= "$line<br>\n";
		}
		$caption = preg_replace("#<br>\n$#", '', $s);

		return $caption;
	}

	static function sanitize_caption($cap) {
		if(! $cap instanceof DOMElement
			|| $cap->tagName !== 'figcaption'
			|| ! $cap->parentNode->hasAttribute('insta_gallery')) return;

		$text = $cap->textContent;
		if(! $text) return;

		while($child = $cap->firstChild) $cap->removeChild($child);

		$text = self::markup_caption($text);

		$doc = new DOMDocument();
		$doc->loadHTML(self::charset_hack . "<p>$text</p>");

		# markup anchors
		$links = $doc->getElementsbyTagName("a");
		foreach($links as $a) {
			if($a->hasAttribute('href')) {
				$url = rewrite_relative_url('https://instagram.com/', $a->getAttribute('href'));
				$a->setAttribute('href', $url);
			}
			$a->setAttribute('rel', 'noopener noreferrer');
			$a->setAttribute("target", "_blank");
		}

		$body = $doc->getElementsByTagName('body')->item(0);
		$p = $cap->ownerDocument->importNode($body->firstChild, true);

		while($child = $p->firstChild) $cap->appendChild($child);
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

	const charset_hack = '<head>
			<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
		</head>';

	/*
	 * produces a presentable HTML version (<figure>) of Instagram media.
	 *
	 * $media			used in append_media
	 * $caption			string, goes to <figcaption>. When empty, no node is created.
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

		if($caption) {
			$caption = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]+/u', ' ', $caption);
			$caption = trim($caption);
			$cap = $doc->createElement('figcaption');
			$text = $doc->createTextNode($caption);
			$cap->appendChild($text);
			$fig->appendChild($cap);

			#could be called here, but we leave this for sanitize stage
			//self::sanitize_caption($cap);
		}

		$fig = $doc->appendChild($fig);
		return $doc->saveHTML($fig);
	}

	/* should be used on HTML like the one created in create_figure */

	static function reformat_content($content, $url) {
		$doc = new DOMDocument();
		@$doc->loadHTML(self::charset_hack . $content);

		$fig = $doc->getElementsByTagName('figure')->item(0);
		if( ! $fig->hasAttribute(self::marker)) return $content;

		$media = Loader::scrap_insta_media($url);  // could throw Exception

		if($media) {
			$fig->removeAttribute(self::marker);

			$fig->removeChild($fig->firstChild);//remove img
			self::append_media($media, $fig);

			//caption below media
			$cap = $doc->getElementsByTagName('figcaption')->item(0);
			if($cap) $fig->appendChild($cap);

			return $doc->saveHTML($fig);
		} else return $content;  // shouldn't happen, but to be safe...
	}
}


class Loader{
//TODO structure is messed up. better have an init function or something like that?
	private static $instance;
	private static $rhx_gis;
	private function __construct($meta) {
		#self::setup_channel();
		self::$rhx_gis = $meta["rhx_gis"];
		if(! self::$rhx_gis) user_error("Meta information missing.");
	}

	static function get_instance($meta) {
		if(! self::$instance) self::$instance = new self($meta);  // seems to work
		return self::$instance;
	}

	static function set_meta() {
		if(self::$rhx_gis) return;
		self::setup_channel();

		curl_setopt(self::$ch, CURLOPT_HTTPHEADER, self::$curl_header_keep_alive);
		curl_setopt(self::$ch, CURLOPT_URL, "https://instagram.com/");

		for($i=0; $i < 10; $i++) {
			@$result = curl_exec(self::$ch);
			if (preg_match('/"rhx_gis":"([0-9a-f]+)"/x', $result, $match)) {
				self::get_instance(["rhx_gis" => $match[1]]);
				return;
			}
		}
	}


	static $curl_header_keep_alive = array(
					'Connection: Keep-Alive',
					'Keep-Alive: 300');

	private static $ch;
	private static function setup_channel() {
		if(! function_exists('curl_init')) throw new Exception("curl needed");
		if(! self::$ch) {
			self::$ch = curl_init();
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
			curl_setopt_array(self::$ch, $opt);
			if (defined('_CURL_HTTP_PROXY')) {
				curl_setopt(self::$ch, CURLOPT_PROXY, _CURL_HTTP_PROXY);
			}
		}
	}

	static function download($url) {
		curl_setopt(self::$ch, CURLOPT_URL, $url);
		@$result = curl_exec(self::$ch);
		$http_code = curl_getinfo(self::$ch, CURLINFO_HTTP_CODE);
		if($http_code !== 200) {
			throw new FetchException("HTTP Error $http_code. Server response: '$result'", $http_code);
		}

		return $result;
	}

	private static function download_decode($url) {
		$result = self::download($url);
		$result = json_decode($result, true);
		if(! $result) throw new JSONDecodeException();

		return $result;
	}

	/*
	here a bit of magic happens. We need to set a custom HTTP header to query
	and use magic ids. docs:
	https://github.com/ping/instagram_private_api
	https://github.com/postaddictme/instagram-php-scraper
	https://github.com/rarcega/instagram-scraper
	https://stackoverflow.com/q/49786980

	TODO make graphql queries more abstract
	*/

	static function fetch_more_user_json($user_id, $end_cursor) {
		self::setup_channel();

		//50 is server-side limit
		$variables = ["id" => $user_id, "first" => 50, "after" => $end_cursor];
		$variables = json_encode($variables);
		$rhx_gis = self::$rhx_gis;
		$hash = md5("$rhx_gis:$variables");

		curl_setopt(self::$ch, CURLOPT_HTTPHEADER, array_merge(self::$curl_header_keep_alive, ["X-Instagram-GIS: $hash"]));

		$api_url = 'https://www.instagram.com/graphql/query/';

		//magic number. May be deprecated?
		$url = "$api_url?query_id=17880160963012870&variables=$variables";

		return self::download_decode($url);
	}

	static function fetch_inital_user_json($user_url) {
		self::setup_channel();
		//var_dump($user_url);
		$path = parse_url($user_url, PHP_URL_PATH);
		if(preg_match("~^(/[^/]+/)~", $path, $match)) {
			$rhx_gis = self::$rhx_gis;
			//var_dump("$rhx_gis:{$match[1]}");
			$hash = md5("$rhx_gis:{$match[1]}");
			curl_setopt(self::$ch, CURLOPT_HTTPHEADER, array_merge(self::$curl_header_keep_alive, ["X-Instagram-GIS: $hash"]));

			return self::download_decode($user_url . "?__a=1");
		}
	}

	const charset_hack = '<head>
			<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
		</head>';

	/*
		extract useable json out of instagram html pages.
		This is very prone to breakage.
		Can be used for single posts or user main pages ATM.
	*/

	static function scrap_insta_js($html) {
		$doc = new DOMDocument();
		@$doc->loadHTML(self::charset_hack . $html);
		#echo $doc->saveXML();
		$xpath = new DOMXPath($doc);

		$script = $xpath->query('//script[@type="text/javascript" and contains(., "window._sharedData = ")]');
		//var_dump($script);
		if($script->length === 1) {
			$script = $script->item(0)->textContent;
			$json = preg_replace('/^\s*window._sharedData\s*=\s*|\s*;\s*$/', '', $script);
			$json = json_decode($json, true);
			if(! $json) throw new JSONDecodeException();


			return $json;
		} else throw new Exception("Couldn't find script.");
	}

	/*
		helper function for below
	*/
	private static function check_insta_media($json) {
		//hope this catches all errors...
		$im = $json['display_url'];
		$vid = $json["video_url"];

		if(! isset($json["is_video"])) user_error("Key 'is_video' is missing. Fomat changed?");

		if( ! $vid && $json["is_video"]) throw new Exception("Missing Key for video");
		if( ! $im && ! $json["is_video"] && isset($json["is_video"])) throw new Exception("Missing Key for image");

		if($im || $vid) return [$im, $vid];
	}

	/*
		$url should be a URL to an Instagram video/multi* page (/p/.+),
		but image only should work as well.
		This scraps only media URLs and leaves caption etc alone.
		Will try very hard to get information, but will log failures if some should occur.

		TODO error reporting is a bit wordy/unnecessary in some cases (404s),
		but that shouldn't matter too much because it's called only/mostly on fresh stuff.

		KEEP THIS STABLE!1!
	*/
	static function scrap_insta_media($url) {
		global $fetch_last_error;
		global $fetch_last_error_code;

		$url_ = "$url?__a=1";
		/*
		This function worked without curl and should stay this way.
		But for better efficiency (HTTP keep-alive)
		we first try to use the class channel
		and switch to the ttRSS fetch function if that fails.
		*/
		try {
			self::setup_channel();
			curl_setopt(self::$ch, CURLOPT_HTTPHEADER, self::$curl_header_keep_alive);
			$data = self::download_decode($url_);
		} catch (Exception $e) {
			$json = fetch_file_contents($url_);
			if(! $json) user_error("'$fetch_last_error' occured for '$url_'");
			$data = json_decode($json, true);
		}

		if(!$data) {//fallback
			user_error("Couldn't decode json for '$url_', error message '" .
			json_last_error_msg() . "'. Trying to use fallback.");

			# we fail here because the other stuff below depends on this
			$html = fetch_file_contents($url);
			if(! $html) throw new FetchException("'$fetch_last_error' occured for '$url'", $fetch_last_error_code);

			try {
				$json = self::scrap_insta_js($html);
				$data = $json["entry_data"]["PostPage"][0];
				if(! $data) throw new MissingKeyException('["entry_data"]["PostPage"][0]');
			} catch (Exception $e) {
				user_error("Something wrong for '$url': " . $e->getMessage());
				$data = [];
			}
		}

		$media = [];

		//missing keys will show up in the switch default eventually
		$data = $data["graphql"]["shortcode_media"];
		#unset($data["edge_media_to_comment"]); unset($data["edge_media_preview_like"]);
		#var_dump($data);

		switch($data['__typename']) {
		case "GraphImage": case "GraphVideo":
			try {
				$med = self::check_insta_media($data);
			} catch (Exception $e) {
				user_error($e->getMessage());
				$med = NULL;
			}
			if($med) $media [] = $med;
			break;
		case "GraphSidecar":
			$edges = $data["edge_sidecar_to_children"]["edges"];
			foreach($edges as $edge) {
				$node = $edge['node'];//really...
				try {
					$med = self::check_insta_media($node);
				} catch (Exception $e) {
					user_error($e->getMessage());
					$med = NULL;
				}
				if($med) $media [] = $med;
			}
			break;
		default:
			user_error("No typename for '$url'. Format changed?");
		}

		if(! $media) {  // Doesn't work for albums
			user_error("json scraping doesn't work for '$url'. Using Fallback.");
			$doc = new DOMDocument();
			if(! $html) {
				$html = fetch_file_contents($url);
				if(! $html) throw new FetchException("'$fetch_last_error' occured for '$url'", $fetch_last_error_code);
			}
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

		if(! $media) throw new Exception("No method for getting media information for '$url' worked.");

		return $media;
	}
}


class PostGenerator {
	private $posts, $info, $count;


	function __construct($list){
		$json = $list["edge_owner_to_timeline_media"];
		$this->posts = $json["edges"];
		$this->info = $json["page_info"];

		if(! is_array($this->posts)) throw new MissingKeyException("edges");
		$this->count = count($this->posts);

		if(! is_array($this->info)) user_error("No meta information provided.");
	}

	function __invoke() {
		foreach($this->posts as $key => $item) {
			$post = new Post($item["node"]);
			yield $post;
		}
	}

	function get_info() {return $this->info;}
	function count() {return $this->count;}
}


class UserPage {
	private $name;
	private $private;
	private $bio;
	private $icon_url;
	private $id;
	private $gen;

	/*
		can, and will, throw an Exception if sufficient information is missing.
	*/
	function __construct(array $json) {
		#var_dump($json);

		$name = $json["full_name"];
		if(!$name) $name = $json["username"];
		$this->name = trim($name);

		$this->private = $json["is_private"];

		$this->bio = $json["biography"];
		$this->icon_url = $json["profile_pic_url"];
		$this->url = "https://instagram.com/" . $json["username"] . "/";
		$this->id = $json['id'];

		$gen = new PostGenerator($json);  // can throw Exception

		if(! $gen->count()) {
			if($this->private) throw new UserPrivateException;
			else throw new NoPostsException;
		}

		/*
			We gather posts here because if an Exception occurs during Post creation,
			it is thrown from here then.
			Should this happen, there is a serious problem.
		*/
		foreach($gen() as $post) $this->posts [] = $post;
		$this->gen = $gen;
		if(! isset($json["username"]) || ! $this->name) {
			throw new MissingKeyException(".*name");
		}
	}

	function author() {return $this->name;}
	function title() {return $this->name . " • Instagram";}
	function description() {return $this->bio;}
	function url() {return $this->url;}
	function icon_url() {return $this->icon_url;}
	function is_private() {return $this->private;}
	//function id() {return $this->id;}

	static function from_html($html) {
		$json = Loader::scrap_insta_js($html);
		$meta = $json;
		$json = $json["entry_data"]["ProfilePage"][0]["graphql"]["user"];
		unset($meta["entry_data"]["ProfilePage"][0]["graphql"]["user"]);

		if(! $json) {
			throw new MissingKeyException('["entry_data"]["ProfilePage"][0]["graphql"]["user"]');
		}
		Loader::get_instance($meta);
		return new self($json);
	}

	/*
		Doesn't download the $url and passes the html to from_html,
		but tries to fetch the json directly.
	*/
	static function from_url($url) {
		$json = Loader::fetch_inital_user_json($url);
		$json = $json["graphql"]["user"];
		if(! $json) throw new MissingKeyException('["graphql"]["user"]');

		return new self($json);
	}

	static function from_deskgram($url) {
		$url = str_ireplace('://instagram.com/', '://deskgram.net/', $url);
		$html = Loader::download($url);
		$doc = new DOMDocument();
		@$doc->loadHTML($html);
		$xpath = new DOMXPath($doc);

		if($xpath->query("//div[@class='nothing-found']")->item(0)) throw new UserPrivateException();

		$profile = $xpath->query("//div[@id='profile-header']")->item(0);
		$ar = [];
		if($profile) {
			$ar["full_name"] = $xpath->evaluate("string(.//div[@class='profile-bio']/h2/text())", $profile);
			$ar["username"] = $xpath->evaluate("string(.//div[@class='profile-bio']/h1/text())", $profile);
			$ar["profile_pic_url"] = $xpath->evaluate("string(.//div[@class='profile-pic']//img[@src]/@src)", $profile);
			//id is set in the post section
		}
		$ar["is_private"] = false;

		$posts = $xpath->query("//div[@class='post-box' and @data-id]");
		$media = [];
		foreach($posts as $post) {
			$ig_post = [];
			$ig_post["id"] = $xpath->evaluate("string(./@data-id)", $post);
			$id = explode('_', $ig_post["id"], 2)[1];
			if(is_int($id)) $ar['id'] = $id;

			$ig_post["shortcode"] = self::mediaid_to_shortcode($ig_post["id"]);
			$ig_post["__typename"] = $xpath->evaluate("string(.//div[@class='post-img']/a[@class]/@class)", $post);
			$ig_post["taken_at_timestamp"] = 0;  // set below
			$ig_post["is_video"] = $ig_post["__typename"] === "GraphVideo";
			$ig_post["display_url"] = $xpath->evaluate("string(.//div[@class='post-img']/a[@class]/img[@src]/@src)", $post);

			$con_node = $xpath->evaluate(".//div[@class='post-caption']/p", $post)->item(0);
			$time_node = $xpath->evaluate(".//span[@class='time']", $con_node)->item(0);
			if($time_node) {
				$ts = strtotime($time_node->textContent);
				if($ts) $ig_post["taken_at_timestamp"] = $ts;
				$time_node->parentNode->removeChild($time_node);
			}
			$con = $con_node->textContent;
			$con = preg_replace("/\s*-\s*$/", '', $con);

			$ig_post["edge_media_to_caption"]['edges'][0]['node']['text'] = $con;

			$media [] = ["node" => $ig_post];
		}
		$ar["edge_owner_to_timeline_media"] = ["edges" => $media, "page_info" => ["has_next_page" => false]];

		return new self($ar);
	}

	private static function mediaid_to_shortcode($id){
		/* taken from https://stackoverflow.com/a/37246231
		*/
		if(strpos($id, '_') !== false){
			$pieces = explode('_', $id);
			$mediaid = $pieces[0];
			$userid = $pieces[1];
		}

		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
		$shortcode = '';
		while($mediaid > 0){
			$remainder = $mediaid % 64;
			$mediaid = ($mediaid-$remainder) / 64;
			$shortcode = $alphabet{$remainder} . $shortcode;
		};

		return $shortcode;

	}

	/*
		No Exception should be thrown or passthroughed from here.
	*/
	function generate_posts($only_first=True) {
		foreach($this->posts as $post) {  // PHP7: yield from
			yield $post;
		}

		if($only_first) return;

		$gen = $this->gen;
		while(True) {
			$info = $gen->get_info();
			if(! $info["has_next_page"]) break;  // TODO that could be misleading when key not there
			try {
				$json = Loader::fetch_more_user_json($this->id, $info["end_cursor"]);
				$json = $json["data"]["user"];
				if( ! $json) throw new MissingKeyException('["data"]["user"]');
				$gen = new PostGenerator($json);
				foreach($gen() as $post) {
					yield $post;
				}
			} catch (Exception $e) {
				user_error(PI_format_exception(
					"Further fetching didn't work for '{$this->url()}'", $e));
				break;
			}
		}
	}
}
