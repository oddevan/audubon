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

	echo 'Processing...';

	foreach ($twitter_response as $tweet) {
		$thisPost = array(
			'pubdate' => date_create_from_format('D M d H:i:s O Y', $tweet->created_at)->setTimezone(new DateTimeZone('America/New_York')),
			'body' => mb_substr($tweet->full_text, $tweet->display_text_range[0], ($tweet->display_text_range[1] - $tweet->display_text_range[0])),
			'twid' => $tweet->id,
		);

		if (!empty($tweet->retweeted_status)) {
			unset($thisPost['body']);
			$thisPost['body'] = getTweetEmbed($tweet->retweeted_status->id);
		} else {
			$thisPost['tags'] = array();
		
			if ($tweet->in_reply_to_status_id && $tweet->in_reply_to_user_id != $tweet->user->id) {
				$thisPost['body'] = getTweetEmbed($tweet->in_reply_to_status_id)."\n\n".$thisPost['body'];
			} elseif ($tweet->is_quote_status) {
				$thisPost['body'] = getTweetEmbed($tweet->quoted_status_id)."\n\n".$thisPost['body'];
			} else {
				$thisPost['tags'][] = 'micropost';
			}
	
			foreach ($tweet->entities->urls as $tacolink) {
				if ($tweet->is_quote_status) {
					$ind = strrpos($tacolink->expanded_url, '/');
					if (substr($tacolink->expanded_url, $ind + 1) == $tweet->quoted_status_id) {
						$thisPost['body'] = str_replace($tacolink->url,	'',	$thisPost['body']);
					}
				}
		
				$thisPost['body'] = str_replace(
					$tacolink->url,
					'['.$tacolink->display_url.']('.$tacolink->expanded_url.')',
					$thisPost['body']
				);
			}
		
			$alreadyMentioned = array();
			foreach ($tweet->entities->user_mentions as $atmention) {
				//if ($atmention->indicies[0] >= $tweet->display_text_range[0]) {
				if (!in_array($atmention->screen_name, $alreadyMentioned)) {
					$thisPost['body'] = str_replace(
						'@'.$atmention->screen_name,
						'[@'.$atmention->screen_name.'](https://twitter.com/'.$atmention->screen_name.')',
						$thisPost['body']
					);
					$alreadyMentioned[] = $atmention->screen_name;
				}
			}
		
			if (!empty($tweet->entities->hashtags)) {
				foreach ($tweet->entities->hashtags as $hashtag) {
					$thisPost['body'] = str_replace(
						'#'.$hashtag->text,
						'[#'.$hashtag->text.'](https://twitter.com/hashtag/'.$hashtag->text.')',
						$thisPost['body']
					);
					$thisPost['tags'][] = $hashtag->text;
				}
			}
		
			if (!empty($tweet->extended_entities->media)) {
				foreach ($tweet->extended_entities->media as $media) {
					if ($media->type == 'photo') {
						if (!array_key_exists('media', $thisPost)) $thisPost['media'] = array();
						$thisPost['media'][] = array('url' => $media->media_url_https, 'type' => 'img');
					
						file_put_contents($eph_config['hugo_base_dir'].'images/'.substr(strrchr($media->media_url_https, "/"), 1), file_get_contents($media->media_url_https));
						$thisPost['body'] .= "\n\n![Image from twitter]({{ \"/\" | relative_url  }}images/".substr(strrchr($media->media_url_https, "/"), 1).")";
					} elseif ($media->type == 'video' || $media->type == 'animated_gif') {
						if (!array_key_exists('media', $thisPost)) $thisPost['media'] = array();
					
						$videoURL = '#';
						$videoBitrate = -1;
						foreach ($media->video_info->variants as $vidinfo) {
							if ($vidinfo->content_type == 'video/mp4' && $vidinfo->bitrate > $videoBitrate) {
								$videoBitrate = $vidinfo->bitrate;
								$videoURL = $vidinfo->url;
							}
						}
						$thisPost['media'][] = array('url' => $videoURL, 'type' => 'video', 'gif' => ($media->type == 'animated_gif'));
					
						file_put_contents($eph_config['hugo_base_dir'].'images/'.substr(strrchr($videoURL, "/"), 1), file_get_contents($videoURL));
						$thisPost['body'] .= "\n\n".'<video src="{{ \"/\" | relative_url  }}images/'.substr(strrchr($videoURL, "/"), 1).'"';
						if ($media->type == 'animated_gif') $thisPost['body'] .= ' autoplay loop';
						$thisPost['body'] .= '></video>';
					}
				}
			}
		}
	
		if ($tweet->in_reply_to_user_id == $tweet->user->id) {
			$thisPost['threadto'] = $tweet->in_reply_to_status_id;
			//$to_thread[] = $thisPost;
		} //else {
			$to_import[] = $thisPost;
		//}
		
		if ($max_twid > $tweet->id || $max_twid == -1) $max_twid = $tweet->id;
	}

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
		if ($post['threadto']) $output .= 'thread_to: '.$post['threadto']."\n";
		$output .= "title: ''\n";
		$output .= "---\n\n";
		$output .= $post['body'];
		$output .= "\n";
	
		$fileout = fopen($eph_config['hugo_base_dir'].'_posts/'.$post['pubdate']->format('Y-m-d').'-'.$post['twid'].'.md', 'w');
		fwrite($fileout, $output);
		fclose($fileout);
	}

	$max_twid -= 1;
	$twitter_response = json_decode($twitter->setGetfield($getfield.'&max_id='.$max_twid)->buildOauth($url, $requestMethod)->performRequest());
}

exec("git add .");  
exec("git commit -m 'Update from Audubon'");
exec("git push origin master");

echo 'done!';