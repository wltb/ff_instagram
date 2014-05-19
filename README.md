ff_Instagram
============

Plugin for [Tiny Tiny RSS](https://github.com/gothfox/Tiny-Tiny-RSS) that allows to fetch data from Instagram user sites.

## Installation
This should be done on the command line

```sh
$ cd /your/path/to/ttrss/plugins
$ git clone https://github.com/wltb/ff_instagram
```

Alternatively, you can download the zip archive and unzip it into the *plugins* subdirectory of your Tiny Tiny RSS installation.
Note that the directory containing *init.php* **must** be named *ff_instagram*, otherwise Tiny Tiny RSS won't load the plugin, so you may have to rename it after the unzipping.

After that, the plugin must be enabled in the preferences of Tiny Tiny RSS.

##Subscribing
With latest trunk or version >= 1.13 of Tiny Tiny RSS it is possible to subscribe directly to instagram URLs.
For older versions of Tiny Tiny RSS, this plugin needs an existing feed in Tiny Tiny RSS.
If you have none, you can subscribe to a dummy feed
with the content

`````
<?xml version="1.0"?>
<rss version="2.0"><channel><item/></channel></rss>
````

that you have put into a file on your host. To subscribe to it in Tiny Tiny RSS, use the URL
*http://localhost/yourpath/andfile*.
This should put a feed with feed title *[Unknown]* into the feed list.
You can change its URL to the URL of the Instagram user you want to follow then.
