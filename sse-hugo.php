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

$url = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
$getfield = '?count=30&trim_user=true&exclude_replies=false&include_rts=true&tweet_mode=extended';
$requestMethod = 'GET';

$twitter = new TwitterAPIExchange($settings);
$twitter_response = json_decode($twitter->setGetfield($getfield)->buildOauth($url, $requestMethod)->performRequest());

$to_import = array();

function getTweetEmbed($twid) {
	try {
		$embedObj = Embed::create('https://twitter.com/statuses/'.$twid);
		return $embedObj->code;
	} catch (\Exception $error) {
			return '<!-- Tweet at https://twitter.com/statuses/'.$twid.' is either deleted or protected. -->'.
			  "\n".'<!-- '.$error->getMessage().' -->';
	}
}

chdir($eph_config['hugo_base_dir']);
exec("git pull origin");

echo 'Processing...';

foreach ($twitter_response as $tweet) {

  $tweetDate = date_create_from_format('D M d H:i:s O Y', $tweet->created_at);
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
}
/*
foreach ($to_import as $post) {
	$output  = "---\n";
	$output .= "date: ".$post['pubdate']->format('Y-m-d H:i:s')."\n";
	$output .= "twitter_id: ".$post['twid']."\n";
	if (!empty($post['tags'])) {
		$output .= "tags:\n";
		foreach ($post['tags'] as $tag) {
			$output .= "  - ".$tag."\n";
		}
	}
	if (!empty($post['categories'])) {
		$output .= "categories:\n";
		foreach ($post['categories'] as $cat) {
			$output .= "  - ".$cat."\n";
		}
	}
	if (array_key_exists('threadto', $post)) $output .= 'thread_to: '.$post['threadto']."\n";
	$output .= "title: ''\n";
	$output .= "---\n\n";
	$output .= $post['body'];
	$output .= "\n";
	$fileout = fopen($eph_config['hugo_base_dir'].'_posts/'.$post['pubdate']->format('Y-m-d').'-'.$post['twid'].'.md', 'w');
	fwrite($fileout, $output);
	fclose($fileout);
}
*/
exec("git add .");  
exec("git commit -m 'Update from Audubon'");
exec("git push origin master");

echo 'done!';
