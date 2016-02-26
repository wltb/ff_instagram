<?php
namespace RSSGenerator;
/*
	* This is a quick & dirty RSS feed generator.
	* RSS elements will be supported at (my) need.
	*
	* Copyright 2014 by wltb
	*
	* @license LGPL-3.0+ <http://spdx.org/licenses/LGPL-3.0+>
*/

/*
Doesn't check if the final XML confirms to RSS specifications,
e.g. format of the element texts or required elements.

Throws a DOMException if a parameter text can't be stored in a DOMText node.
*/

/*
#Example Usage
include 'RSSGenerator.php'

$f = new RSSGenerator\Feed();
$f->link('http://www.example.com');
$f->set_feed_elements(array('description' => 'Nice Feed', 'title' => 'Feed Title'));
$f->new_item(array('description' => 'Nice item', 'author' => 'me'));
$it = $f->new_item(array('description' => 'Another nice item'));
$it->content = 'Content'; #content currently overrides description
$f->save('php://stdout');
*/

class Feed
{
	private $feed;
	private $channel;
	private $stored_elems = array();

	static private $supported_tags = array('description', 'title', 'link');
	static private $alias_tags = array('url' => 'link');

	public function __set($name, $value) {
		if(isset(self::$alias_tags[$name]))
			$name = self::$alias_tags[$name];

		if(in_array($name, self::$supported_tags)) {
			$type = DOMText;
			$this->factory_singleton($value, $name, $type);
		}
	}

	public function __get($name) {
		if(isset(self::$alias_tags[$name]))
			$name = self::$alias_tags[$name];

		if(isset($this->stored_elems[$name]))
			return $this->stored_elems[$name];
		else
			return NULL;
	}

	function __construct($ar=array(), $doc=NULL) {
		if($doc)
			$this->feed = $doc;
		else {
			$this->feed = new \DOMDocument('1.0', 'utf-8');
			$this->feed->loadXML('<rss version="2.0"><channel/></rss>');
			$this->feed->formatOutput = true;
		}

		$this->channel = $this->feed->getElementsByTagName('channel')->item(0);

		$this->set_feed_elements($ar);
	}

	function set_feed_elements($ar) {
		foreach($ar as $key => $val)
			$this->$key = $val;
	}

	/* better remove this? */
	function get_xpath() {
		return new \DOMXPath($this->feed);
	}

	/*
		Creates or replaces feed channel elements

		@param string $tag	  Tag name of the node
		@param string $text	   Text to be stored in the node $tag
		@param DOMText/DOMCdataSection $type	Type of the text node that stores $text & is appended to node $tag
	*/
	private function factory_singleton($text, $tag, $type = DOMText) {
		$text_node = create_text_node($text, $type);

		$parent = $this->channel;
		$node = $this->stored_elems[$tag]; //caching for easier access/check

		if($node)
			$parent->removeChild($node);
		$node = $parent->insertBefore(new \DOMElement($tag), $parent->firstChild);

		$node->appendChild($text_node);
		$this->stored_elems[$tag] = $node;
	}

	function new_item($ar=array()) {
		$item = $this->feed->createElement('item');
		$this->channel->appendChild($item);

		$item_obj = new FeedItem($item, $ar);

		return $item_obj;
	}

	function saveXML() {
		$this->feed->encoding = 'utf-8';
		return $this->feed->saveXML() . "\n";
	}

	function save($filename) {
		$this->feed->encoding = 'utf-8';
		$this->feed->save($filename);
	}
}

function sanitize_text($text) {
	return trim(preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $text));
}
# Throws a DOMException if $text is not convertable.
# Catching is only meaningful in Feed or FeedItem, where more context can be added.
# Seperate function because $text may have to be filtered.
function create_text_node($text, $type) {
	return new $type(sanitize_text($text));
}

class FeedItem {
	private $item;
	static private $supported_tags = array('description', 'guid', 'pubDate',
		'title', 'link', 'author');
	static private $alias_tags = array('content' => 'description',
	'date' => 'pubDate', 'url' => 'link');

	private $stored_elems = array();

	function __construct($item, $ar = array()) {
		$this->item = $item;
		//calls __set
		foreach($ar as $key => $val)
			$this->$key = $val;
	}

	function get_item() {
		return $this->item;
	}

	//see function in Feed class
	private function factory_singleton($text, $tag, $type = DOMText) {
		$text_node = create_text_node($text, $type);

		$parent = $this->item;
		$node = $this->stored_elems[$tag];
		if($node)
			$parent->removeChild($node);
		$node = $parent->appendChild(new \DOMElement($tag));
		$node->appendChild($text_node);
		$this->stored_elems[$tag] = $node;
	}

	public function __set($name, $value) {
		if(isset(self::$alias_tags[$name]))
			$name = self::$alias_tags[$name];

		if(in_array($name, self::$supported_tags)) {
			if($name == 'description')
				$type = DOMCdataSection;
			else
				$type = DOMText;
			$this->factory_singleton($value, $name, $type);
		} elseif($name == 'category') {
			$this->category($value);
		} elseif($name == 'slash_comments') {
			$this->slash_comments($value);
		}
	}

	public function __get($name) {
		if(isset(self::$alias_tags[$name]))
			$name = self::$alias_tags[$name];

		if(isset($this->stored_elems[$name]))
			return $this->stored_elems[$name]->textContent;
		else
			return NULL;
	}

	function slash_comments($num_comments) {
		$this->item;
		$node = $this->stored_elems['slash_comments'];
		if($node) {
			$node->nodeValue = $num_comments;
		} else {
			$this->item->parentNode->parentNode->setAttributeNS('http://www.w3.org/2000/xmlns/',
				'xmlns:slash', 'http://purl.org/rss/1.0/modules/slash/');
			$com = new \DOMElement('comments', $num_comments,
					'http://purl.org/rss/1.0/modules/slash/');
			$this->item->appendChild($com);
			$this->stored_elems['slash_comments'] = $com;
		}
	}


	/*
	Adds category nodes.

	@param string/array $arg	category names
	*/
	function category($arg) {
		if(!(is_string($arg) || is_array($arg)) )
			return; //could bark here a little
		elseif(is_string($arg))
			$arg = array($arg);
		foreach($arg as $cat) {
			$text_node = create_text_node($cat, DOMText);

			$node = $this->item->appendChild(new \DOMElement('category'));
			$node->appendChild($text_node);
		}
	}
}

?>
