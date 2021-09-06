# Import from Mastodon
Automatically turn toots—short messages on [Mastodon](https://joinmastodon.org/)—into WordPress posts.

This small plugin polls your Mastodon account for new toots every 15 minutes.

## Installation
For now, download the [ZIP file](https://github.com/janboddez/import-from-mastodon/archive/refs/heads/master.zip). Upload to `wp-content/plugins` and unzip. (Optionally) rename the resulting folder from `import-from-mastodon-master` to `import-from-mastodon`. (This last step may help resolve possible future conflicts.)

After activating the plugin, visit Settings > Import From Mastodon. Fill out your instance's URL as well as the other options. Press Save Changes.

Now, on the same settings page, click the Authorize Access button. This should take you to your Mastodon instance and allow you to authorize WordPress to read from your timeline. (We don't request write access.) You'll be automatically redirected to WordPress afterward.

**Note**: WordPress won't immediately start importing toots, but will take a couple minutes before doing so. I'll "fix" this in a next version.

## How It Works
Every 15 minutes—more or less, because WordPress's cron system isn't quite exact—your Mastodon timeline is polled for new toots, which are then imported as the post type of your choice.

By default, only the 40 most recent toots are considered. If somehow you think you might very well create more than 40 toots in 15 minutes, this can be overridden:
```
add_filter( 'import_from_mastodon_limit', function( $limit ) {
  return 80; // Or whatever
} );
```

### Of Note
Only the _most recent_ toots are taken into account. (We use a `since_id` API param to tell Mastodon which toots to look up for us. This `since_id` corresponds with the most recently imported _existing_, i.e., in WordPress, post.)

If all that sounds confusing, it is. Well, maybe not. Regardless, it's okay to just forget about it.

## Boosts and Replies, and Custom Formatting
It's possible to either exlude or include boosts or replies. Note that these might lack some context, like the URL of the toot being replied to, etc.

Just, uh, know that boosts and replies might look a bit _off_.

Nevertheless, it _is possible_ to modify the way imported statuses, including boosts and replies, are formatted, through filter hooks:
```
add_filter( 'import_from_mastodon_post_content', function( $content, $status ) {
  // Note that `$status` contains the entire status object (not associated
  // array!) as described by https://docs.joinmastodon.org/entities/status/
  return $content;
} );

add_filter( 'import_from_mastodon_post_title', function( $content, $status ) {
  // See remark above regarding the `$status` arg
  return $content;
} );
```
With these, developers should be able to do just about whatever.

## Tags and Blocklist
**Tags**: (Optional) Poll for toots with any of these tags only (and ignore all other toots). Separate tags by commas.  
**Blocklist**: (Optional) Ignore toots with any of these words. (One word, or part of a word, per line.) Note: Beware partial matches.

## Images
Images are downloaded and [attached](https://wordpress.org/support/article/using-image-and-file-attachments/#attachment-to-a-post) to imported toots, but **not** (yet) automatically included _in_ the post.

The first image, however, is set as the freshly imported post's Featured Image. Of course, this behavior, too, can be changed:
```
add_filter( 'import_from_mastodon_featured_image', '__return_false' ); // Do not set Featured Images
```

## Miscellaneous
There are in fact a few more filters and settings that I might eventually document a bit better (though the settings should kind of speak for themselves).
