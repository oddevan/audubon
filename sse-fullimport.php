<?php
require_once('vendor/autoload.php');
require_once('config.php');

use Embed\Embed;

echo 'Loading Twitter...';

$settings = array(
    'oauth_access_token' => $eph_config['twitter_key'],
    'oauth_access_token_secret' => $eph_config['twitter_secret'],
    'consumer_key' => $eph_config['twitter_usr_key'],
    'consumer_secret' => $eph_config['twitter_usr_sec']
);

function getTweetEmbed($twid) {
	try {
		$embedObj = Embed::create('https://twitter.com/statuses/'.$twid);
		return $embedObj->code;
	} catch (\Exception $error) {
			return '<!-- Tweet at https://twitter.com/statuses/'.$twid.' is either deleted or protected. -->';
	}
}

chdir($eph_config['hugo_base_dir']);
exec("git pull origin");

$url = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
$getfield = '?count=100&trim_user=true&exclude_replies=false&include_rts=true&tweet_mode=extended';
$requestMethod = 'GET';

$twitter = new TwitterAPIExchange($settings);
$twitter_response = json_decode($twitter->setGetfield($getfield)->buildOauth($url, $requestMethod)->performRequest());

/*
$twitter_archive_folder = '/Users/eph/Downloads/15293682_bee11a2714a407afb6bcac9f83a3392f2f945ecf/';
$raw_archive = file_get_contents($twitter_archive_folder.'data/js/tweets/2013_01.js');
*/

$max_twid = -1;

while (!empty($twitter_response)) {

	$to_import = array();

	echo 'Processing...'."\n";

foreach ($twitter_response as $tweet) {
	echo 'Importing '.$tweet->created_at."\n";
	
  $tweetDate = date_create_from_format('D M d H:i:s O Y', $tweet->created_at);
  if ($tweetDate) {
		$postdir = $eph_config['hugo_base_dir'].'content/status/'.$tweetDate->format('Y/m').'/'.$tweet->id;
	
		if (!is_dir($postdir)) {
			//Make the status, year, and month directories if needed
			if (!is_dir($eph_config['hugo_base_dir'].'content/status'))
				mkdir($eph_config['hugo_base_dir'].'content/status');
			if (!is_dir($eph_config['hugo_base_dir'].'content/status/'.$tweetDate->format('Y')))
				mkdir($eph_config['hugo_base_dir'].'content/status/'.$tweetDate->format('Y'));
			if (!is_dir($eph_config['hugo_base_dir'].'content/status/'.$tweetDate->format('Y/m')))
				mkdir($eph_config['hugo_base_dir'].'content/status/'.$tweetDate->format('Y/m'));
		
			//Make the leaf bundle for this post
			mkdir($postdir);
		
			$frontmatter = array(
				'date' => $tweet->created_at,
				'slug' => $tweet->id,
				'twitter_id' => $tweet->id,
				'tags' => array(),
				'categories' => array(),
				'resources' => array()
			);
			$body = mb_substr($tweet->full_text, $tweet->display_text_range[0], ($tweet->display_text_range[1] - $tweet->display_text_range[0]));

			if (!empty($tweet->retweeted_status)) {
				unset($body);
				$body = getTweetEmbed($tweet->retweeted_status->id);
			} else {
				if ($tweet->in_reply_to_status_id && $tweet->in_reply_to_user_id != $tweet->user->id) {
					$body = getTweetEmbed($tweet->in_reply_to_status_id)."\n\n".$body;
				} elseif ($tweet->is_quote_status) {
					$body = getTweetEmbed($tweet->quoted_status_id)."\n\n".$body;
				} else {
					$frontmatter['categories'][] = 'micropost';
				}
	
				foreach ($tweet->entities->urls as $tacolink) {
					if ($tweet->is_quote_status) {
						$ind = strrpos($tacolink->expanded_url, '/');
						if (substr($tacolink->expanded_url, $ind + 1) == $tweet->quoted_status_id) {
							$body = str_replace($tacolink->url,	'',	$body);
						}
					}
		
					$body = str_replace(
						$tacolink->url,
						'['.$tacolink->display_url.']('.$tacolink->expanded_url.')',
						$body
					);
				}
		
				$alreadyMentioned = array();
				foreach ($tweet->entities->user_mentions as $atmention) {
					if (!in_array($atmention->screen_name, $alreadyMentioned)) {
						$body = str_replace(
							'@'.$atmention->screen_name,
							'[@'.$atmention->screen_name.'](https://twitter.com/'.$atmention->screen_name.')',
							$body
						);
						$alreadyMentioned[] = $atmention->screen_name;
					}
				}
		
				if (!empty($tweet->entities->hashtags)) {
					foreach ($tweet->entities->hashtags as $hashtag) {
						$body = str_replace(
							'#'.$hashtag->text,
							'[#'.$hashtag->text.'](https://twitter.com/hashtag/'.$hashtag->text.')',
							$body
						);
						$frontmatter['tags'][] = $hashtag->text;
					}
				}
		
				if (!empty($tweet->extended_entities->media)) {
					foreach ($tweet->extended_entities->media as $media) {
						if ($media->type == 'photo') {
							$filename = substr(strrchr($media->media_url_https, "/"), 1);
							file_put_contents($postdir.'/'.$filename, file_get_contents($media->media_url_https));
							$frontmatter['resources'][] = array('src' => $filename, 'name' => $media->id.'');
						
							$body .= "\n\n".'{{< eph_resource "'.$media->id.'" >}}';
						} elseif ($media->type == 'video' || $media->type == 'animated_gif') {
							$videoURL = '#';
							$videoBitrate = -1;
							foreach ($media->video_info->variants as $vidinfo) {
								if ($vidinfo->content_type == 'video/mp4' && $vidinfo->bitrate > $videoBitrate) {
									$videoBitrate = $vidinfo->bitrate;
									$videoURL = $vidinfo->url;
								}
							}
						
							$filename = substr(strrchr($videoURL, "/"), 1);
							file_put_contents($postdir.'/'.$filename, file_get_contents($videoURL));
							$frontmatter['resources'][] = array(
								'src' => $filename,
								'name' => $media->id.'',
								'params' => array('gif' => ($media->type == 'animated_gif'))
							);
						
							$body .= "\n\n".'{{< eph_resource "'.$media->id.'" >}}';
						}
					}
				}
			}
	
		/*
			if ($tweet->in_reply_to_user_id == $tweet->user->id) {
				$thisPost['threadto'] = $tweet->in_reply_to_status_id;
				//$to_thread[] = $thisPost;
			} //else {
				$to_import[] = $thisPost;
			//}
		*/
	
			$fileout = fopen($postdir.'/index.md', 'w');
			fwrite($fileout, json_encode($frontmatter)."\n\n".$body);
			fclose($fileout);
			
		}
		
		if ($max_twid > $tweet->id || $max_twid == -1) $max_twid = $tweet->id;
	}
}

	$max_twid -= 1;
	$twitter_response = json_decode($twitter->setGetfield($getfield.'&max_id='.$max_twid)->buildOauth($url, $requestMethod)->performRequest());
}

exec("git add .");  
exec("git commit -m 'Update from Audubon'");
exec("git push origin master");

echo 'done!';