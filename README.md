# Audubon #

A system to intelligently synchronize your Twitter timeline and Tumblr blog
with your own website.

## Principles ##

- Never copy content that isn't the user's
- _Never_ copy content that _isn't the user's_
- Use embedded tweets (OEmbed) to provide context
- In the spirit of "Reblog, don't repost," embed reblogged
	posts whenever possible.

## Installation ##

1. Don't. This isn't anywhere close to done.
2. Seriously, don't.
3. Fine.
4. Copy `config-blank.php` to `config.php` and fill out the appropriate info
5. Run the appropriate file, changing values as needed:
	- `sse.php` will get the most recent 20 tweets and copy them to the given folder
		in a [Jekyll]-friendly format.
	- `sse-hugo` will do the same as `sse`, but in a [Hugo]-friendly format. (This is
		recommended if you have a Twitter history of any significant size!)
	- `sse-fullimport.php` builds off of `sse-hugo` but iterates through your Twitter
		timeline to get all available tweets. (Note that the API is limited to your
		most recent 3200 tweets.)
	- `sse-hugo-tumblr.php` is the latest work-in-progress to adapt this to Tumblr.
		You will need to change the blog url on line 39.

[Jekyll]: https://jekyllrb.com
[Hugo]: https://gohugo.io

## Still to come: ##

- Copy images down from Tumblr
- Web interface for debugging
- Potential for multiple users?
- Decide on a CMS to import to!
	- Stick with Hugo?
	- Try WordPress again?

Feedback is welcome.