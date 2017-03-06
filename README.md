ff_Instagram
============

Plugin for [Tiny Tiny RSS](https://tt-rss.org/) that allows to fetch posts from Instagram user sites.

## Installation
This should be done on the command line

```sh
$ cd /your/path/to/ttrss/plugins.local
$ git clone https://github.com/wltb/ff_instagram
```

Alternatively, you can download the zip archive and unzip it into the *plugins.local* subdirectory of your Tiny Tiny RSS installation.
Note that the directory containing *init.php* **must** be named *ff_instagram*, otherwise  the plugin can't be loaded, so you may have to rename it after the unzipping.

After that, the plugin must be enabled in the preferences of Tiny Tiny RSS.

##Subscribing
With latest trunk or version >= 1.13 of Tiny Tiny RSS it is possible to subscribe directly to Instagram URLs, so simply enter or paste *https://instagram.com/<username>* into the subscribe dialog.

##Private Accounts
At the moment, the plugin can't aggregate posts from private accounts even if you have access to them.
I will very likely not implement this in the foreseeable future, but pull requests would be appreciated.
