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

class MissingKeyException extends Exception{
	function __construct($key) {
		parent::__construct("'$key' not in JSON.");
	}
}

class Post {
	private $date, $url, $comments, $content;

	function __construct($post) {
		foreach(["taken_at_timestamp", "shortcode", "display_url"] as $s) {
			if(! isset($post[$s])) {
				throw new MissingKeyException($s);
			}//TODO catch this
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
			$line = preg_replace('/(^|\s)@(\S+)/u',
						'$1<a href="https://instagram.com/$2">@$2</a>', $line);

			# tags
			$line = preg_replace('/#(\w+)/u',
						'<a href="https://instagram.com/explore/tags/$1">#$1</a>', $line);

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
		return $fig->c14n();
	}

	/* should be used on HTML like the one created in create_figure */

	static function reformat_content($content, $url) {
		$doc = new DOMDocument();
		@$doc->loadHTML(self::charset_hack . $content);

		$fig = $doc->getElementsByTagName('figure')->item(0);
		if( ! $fig->hasAttribute(self::marker)) return $content;

		$media = Loader::scrap_Insta_media_url($url);  // could throw Exception

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
	private static $instance;
	private static $rhx_gis;
	private function __construct($meta) {
		self::$rhx_gis = $meta["rhx_gis"];
		if(! self::$rhx_gis) user_error("Meta information missing.");
	}

	static function get_instance($meta) {
		if(! self::$instance) self::$instance = new self($meta);  // seems to work
		return self::$instance;
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

	static function fetch_insta_user_json($user_id, $end_cursor) {
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
		$rhx_gis = self::$rhx_gis;
		$hash = md5("$rhx_gis:$variables");

		curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(self::$curl_header_keep_alive, ["X-Instagram-GIS: $hash"]));

		$api_url = 'https://www.instagram.com/graphql/query/';

		//magic number. May be deprecated?
		$url = "$api_url?query_id=17880160963012870&variables=$variables";
		curl_setopt($ch, CURLOPT_URL, $url);

		@$result = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if($http_code !== 200) {
			throw new Exception("HTTP Error $http_code. Server response: '$result'", $http_code);
		}

		$result = json_decode($result, true);
		if(! $result ) throw new Exception("Couldn't decode json. Possible Reason: '" . json_last_error_msg() . "'.");

		return $result;
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
			if(! $json) {
				throw new Exception("Couldn't decode json. Possible Reason: '" . json_last_error_msg() . "'.");
			}

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
		This scraps only media URLs and leaves caption etc alone

		TODO error reporting is a bit wordy/unnecessary (404s),
		but that shouldn't matter too much because it's called only/mostly on fresh stuff.
	*/
	static function scrap_Insta_media_url($url) {
		global $fetch_last_error;
		global $fetch_last_error_code;

		$url_ = "$url?__a=1";
		$json = fetch_file_contents($url_);
		if(! $json) user_error("'$fetch_last_error' occured for '$url_'");
		$data = json_decode($json, true);

		if(!$data) {//fallback
			user_error("Couldn't decode json for '$url_', error message '" .
			json_last_error_msg() . "'. Trying to use fallback.");

			# we fail here because the other stuff below depends on this
			$html = fetch_file_contents($url);
			if(! $html) throw new Exception("'$fetch_last_error' occured for '$url'", $fetch_last_error_code);

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
				if(! $html) throw new Exception("'$fetch_last_error' occured for '$url'", $fetch_last_error_code);
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
	private $posts;
	private $info;

	function __construct($list){
		$json = $list["edge_owner_to_timeline_media"];
		$this->posts = $json["edges"];
		$this->info = $json["page_info"];

		if(! $this->posts) throw new MissingKeyException("edges");
		if(! $this->info) user_error("No meta information provided.");
	}

	function __invoke() {
		foreach($this->posts as $key => $item) {
			$post = new Post($item["node"]);
			yield $post;
		}
	}

	function get_info() {return $this->info;}
}


class UserPage {
	private $name;
	private $private;
	private $bio;
	private $icon_url;
	private $id;
	private $gen;

	function __construct(array $json) {
		#if(! is_array($json)) $json = json_decode($json, true);
		#var_dump($json);

		$name = $json["full_name"];
		if(!$name) $name = $json["username"];
		$this->name = trim($name);

		$this->private = $json["is_private"];

		$this->bio = $json["biography"];
		$this->icon_url = $json["profile_pic_url"];
		$this->url = "https://instagram.com/" . $json["username"] . "/";
		$this->id = $json['id'];

		$this->gen = new PostGenerator($json);  // can throw Exception
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
			throw new
				MissingKeyException('["entry_data"]["ProfilePage"][0]["graphql"]["user"]');
		}
		Loader::get_instance($meta);
		return new self($json);
	}

	function generate_posts($only_first=True) {
		$gen = $this->gen;
		foreach($gen() as $post) {  // PHP7: yield from
			yield $post;  //if this throws an Exception, there is a serious problem.
		}
		if($only_first) return;

		while(True) {
			$info = $gen->get_info();
			if(! $info["has_next_page"]) break;  // TODO that could be misleading when key not there
			try {
				$json = Loader::fetch_insta_user_json($this->id, $info["end_cursor"]);
				$json = $json["data"]["user"];
				if( ! $json) throw new MissingKeyException('["data"]["user"]');
				$gen = new PostGenerator($json);
				foreach($gen() as $post) {
					yield $post;
				}
			} catch (Exception $e) {
				user_error("Further fetching didn't work for '" . $this->url() .  "': " . $e->getMessage());
				break;
			}
		}
	}
}
