ff_Instagram
============

Plugin for [Tiny Tiny RSS](https://tt-rss.org/) that allows to fetch posts from Instagram user sites.
The plugin needs PHP 5.5 or a newer version.

## Note
Due to recent corporate predicaments, it's not always obvious how to get data out of Instagram. I'll try to keep up as good as I can, but sometimes the plugin will be broken.

## Installation
This should be done on the command line

```sh
$ cd /your/path/to/ttrss/plugins.local
$ git clone https://github.com/wltb/ff_instagram
```

Alternatively, you can download the zip archive and unzip it into the *plugins.local* subdirectory of your Tiny Tiny RSS installation.
Note that the directory containing *init.php* **must** be named *ff_instagram*, otherwise the plugin won't be loaded, so you may have to rename it after the unzipping.

After that, the plugin must be enabled in the preferences of Tiny Tiny RSS.

### Updating
Either with

```sh
$ git pull
```

or redownload the archive and replace the existing directory.

## Subscribing
With latest trunk or version >= 1.13 of Tiny Tiny RSS it is possible to subscribe directly to Instagram URLs, so simply enter or paste *https://instagram.com/some_user_name* into the subscribe dialog.

## Private Accounts
At the moment, the plugin can't aggregate posts from private accounts even if you have access to them, so if an subscribed account is private, the error *Unknown/unsupported feed type* should appear in the UI.

Since this will very likely not be implemented in the foreseeable future, pull requests would be appreciated.
