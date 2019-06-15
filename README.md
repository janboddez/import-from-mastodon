# Import from Mastodon
Automatically turn toots—short messages on [Mastodon](https://joinmastodon.org/)—into WordPress posts.

This small plugin, which polls your Mastodon account for new toots every 15 minutes, relies on [Share on Mastodon's](https://github.com/janboddez/share-on-mastodon) settings, and thus requires it. _Actually_ using them simultaneously, however, _might_ result in duplicate posts—though careful use of the available filter hooks will help preventing this. 

## Post Statuses
The default value of 'publish' can be easily overridden.
```
add_filter( 'import_from_mastodon_post_status', 'draft' );
```

## Example Use Cases
1. Disable automatic sharing of WordPress posts, and solely enable the import function. (Both plugins—see above—must still be active, counterintuitive or not!)

   ```
   // Despite using Share on Mastodon's settings, disable sharing itself.
   add_filter( 'share_on_mastodon_enabled', '__return_false' );
   ```

2. Import from _another_—you'll need to manually acquire an access token—Mastodon account, and share on your main account.

   ```
   add_filter( 'import_from_mastodon_host', function() {
       // Replace with your instance (if different from your main account). Leave
       // out trailing slash.
       return 'https://mastodon.social'; 
   } );

   add_filter( 'import_from_mastodon_token', function() {
       // Replace with your access token. Don't ever share this with anyone!
       return 'MY_ACCESS_TOKEN';
   } );

   // Force automatic resharing. (Overrides the custom post field otherwise set
   // from WP Admin.)
   add_filter( 'share_on_mastodon_enabled', '__return_true' );
   ```

   To manually register a new Mastodon app, log into your Mastodon account and head over to your preferences and choose 'Development'. Register a new app with at least the `read:accounts read:statuses write:media write:statuses` scopes—choose any name you like—and generate an access token. The other settings are best left untouched.

3. Share one Post Type, and convert toots into another.

   ```
   // Import toots as a Custom Post Type named 'note'.
   add_filter( 'import_from_mastodon_post_type', function() {
       return 'note';
   } );
   ```

   Whatever you share is set through Share on Mastodon's settings page.

4. Share only, and forget about the import thing.

   Er, simply disable _this_ plugin, and set up Share on Mastodon only.
