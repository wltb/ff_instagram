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
$it->content('Content'); #content currently overrides description
$f->save('php://stdout');
*/

class Feed
{
	private $feed;
	private $channel;
	private $stored_elems = array();

	function __construct($ar=array()) {
		$this->feed = new \DOMDocument('1.0', 'utf-8');
		$this->feed->loadXML('<rss version="2.0"><channel/></rss>');
		$this->feed->formatOutput = true;

		$this->channel = $this->feed->getElementsByTagName('channel')->item(0);

		$this->set_feed_elements($ar);
	}

	function set_feed_elements($ar) {
		foreach(array('link', 'title', 'description', 'url') as $elem) #, 'category'
			if(isset($ar[$elem]))
				$this->$elem($ar[$elem]);
	}

	/* better remove this? */
	function get_xpath() {
		return new \DOMXPath($this->feed);
	}

	/*
		Creates or replaces feed channel elements

		@param string $tag    Tag name of the node
		@param string $text    Text to be stored in the node $tag
		@param DOMText/DOMCdataSection $type    Type of the text node that stores $text & is appended to node $tag
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

	function description($text) {
		$this->factory_singleton($text, 'description');
	}

	function title($text) {
		$this->factory_singleton($text, 'title');
	}

	function link($text) {
		$this->factory_singleton($text, 'link');
	}

	function url($text) {
		$this->link($text);
	}

	function new_item($ar=array()) {
		$item = $this->feed->createElement('item');
		$this->channel->appendChild($item);

		$item_obj = new FeedItem($item, $ar);

		return $item_obj;
	}

	function saveXML() {
		return $this->feed->saveXML() . "\n";
	}

	function save($filename) {
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
	static private $methods = array();
	private $stored_elems = array();

	function __construct($item, $ar = array()) {
		$this->item = $item;

		//see ::init for the defintion of ::$methods.
		foreach(self::$methods as $method)
			if(isset($ar[$method]))
				$this->$method($ar[$method]);
	}

	/*
	Stores methods that correspond to item elements in self::$methods
	Is called below. Should be idempotent.
	*/
	static function init() {
		$cls = new \ReflectionClass(new self(""));
		self::$methods = array_map(function ($v) {return $v->name;},
			$cls->getMethods(\ReflectionMethod::IS_PUBLIC |
							\ReflectionMethod::IS_PROTECTED) );

		/*
		if other publicly available methods are added
		that shouldn't be called in the constructor,
		put them in the array right below.
		*/
		foreach(array('__construct') as $non_elem) {
			$key = array_search($non_elem, self::$methods);
    		unset(self::$methods[$key]);
    	}
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

	function description($text) {
		$this->factory_singleton($text, 'description', DOMCdataSection);
	}

	function guid($text) {
		$this->factory_singleton($text, 'guid');
	}

	function pubDate($text) {
		$this->factory_singleton($text, 'pubDate');
	}

	function title($text) {
		$this->factory_singleton($text, 'title');
	}

	function link($text) {
		$this->factory_singleton($text, 'link');
	}

	function author($text) {
		$this->factory_singleton($text, 'author');
	}

	function content($text) {
		$this->description($text);
	}

	function date($text) {
		$this->pubDate($text);
	}

	function url($text) {
		$this->link($text);
	}

	/*
	Adds category nodes.

	@param string/array $arg    category names
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
FeedItem::init();//call this for correct initialization of FeedItem class


?>
