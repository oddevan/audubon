<?php
require_once('vendor/autoload.php');

echo 'Loading Twitter...';

$settings = array(
    'oauth_access_token' => $eph_config['twitter_key'],
    'oauth_access_token_secret' => $eph_config['twitter_secret'],
    'consumer_key' => $eph_config['twitter_usr_key'],
    'consumer_secret' => $eph_config['twitter_usr_sec']
);

$url = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
$getfield = '?count=100&trim_user=true&exclude_replies=false&include_rts=true&tweet_mode=extended';
$requestMethod = 'GET';

$twitter = new TwitterAPIExchange($settings);
$twitter_response = json_decode($twitter->setGetfield($getfield)->buildOauth($url, $requestMethod)->performRequest());

$to_import = array();
$to_thread = array();

echo 'Processing...';

foreach ($twitter_response as $tweet) {
	$thisPost = array(
		'pubdate' => $tweet->created_at,
		'body' => mb_substr($tweet->full_text, $tweet->display_text_range[0], ($tweet->display_text_range[1] - $tweet->display_text_range[0])),
		'twid' => $tweet->id,
	);

	if (!empty($tweet->retweeted_status)) {
		$thisPost['type'] = 'embed';
		$thisPost['embed_url'] = 'https://twitter.com/statuses/'.$tweet->retweeted_status->id;
		unset($thisPost['body']);
	} else {
		if ($tweet->in_reply_to_status_id && $tweet->in_reply_to_user_id != $tweet->user->id) {
		  $thisPost['type'] = 'embed';
			$thisPost['embed_url'] = 'https://twitter.com/statuses/'.$tweet->in_reply_to_status_id;
		} elseif ($tweet->is_quote_status) {
			$thisPost['type'] = 'embed';
			$thisPost['embed_url'] = 'https://twitter.com/statuses/'.$tweet->quoted_status_id;
		} else {
			$thisPost['type'] = 'status';
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
		
		$thisPost['tags'] = array();
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
					if ($thisPost['type'] != 'embed') $thisPost['type'] = 'media';
					if (!array_key_exists('media', $thisPost)) $thisPost['media'] = array();
					$thisPost['media'][] = array('url' => $media->media_url_https, 'type' => 'img');
				} elseif ($media->type == 'video' || $media->type == 'animated_gif') {
					if ($thisPost['type'] != 'embed') $thisPost['type'] = 'media';
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
				}
			}
		}
	}
	
	if ($tweet->in_reply_to_user_id == $tweet->user->id) {
		$thisPost['tags'][] = '_thread_to_'.$tweet->in_reply_to_status_id;
		//$to_thread[] = $thisPost;
	} //else {
		$to_import[] = $thisPost;
	//}
}

echo 'Saving...';

$db = new PDO('mysql:host='.$eph_config['mysql_server'].';dbname='.$eph_config['mysql_database'].';charset=utf8mb4', $eph_config['mysql_user'], $eph_config['mysql_password']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$post_check = $db->prepare("SELECT 1 FROM `integrations` WHERE `twitter_id` = :twid");
$status_insert = $db->prepare("INSERT INTO `posts`(`site_id`, `body`, `post_date`, `display_type`) VALUES (1,:body,:post_date,:display_type)");
$embed_insert = $db->prepare("INSERT INTO `posts`(`site_id`, `body`, `post_date`, `display_type`, `link_url`) VALUES (1,:body,:post_date,:display_type,:link_url)");
$int_insert = $db->prepare("INSERT INTO `integrations`(`post_id`, `twitter_id`) VALUES (:post_id,:twitter_id)");
$tag_insert = $db->prepare("INSERT INTO `tags`(`tag`) VALUES (:tag)");
$tag_check = $db->prepare("SELECT `id` FROM `tags` WHERE `tag` = :tag");
$tag_link = $db->prepare("INSERT INTO `post_tags`(`post_id`, `tag_id`) VALUES (:post_id,:tag_id)");
$media_insert = $db->prepare("INSERT INTO `media`(`post_id`, `url`, `type`, `gif`) VALUES (:post_id,:url,:type,:gif)");

foreach ($to_import as $insert) {
	$post_check->execute(array(':twid' => $insert['twid']));
	if (empty($post_check->fetchAll(PDO::FETCH_ASSOC))) {
	
		if ($insert['type'] == 'embed') {
			$embed_insert->execute(array(':body' => empty($insert['body']) ? ' ' : $insert['body'], 
																	 ':post_date' => date('Y-m-d H:i:s', strtotime($insert['pubdate'])), 
																	 ':display_type' => $insert['type'], 
																	 ':link_url' => $insert['embed_url'] ));
		} else {
			$status_insert->execute(array( ':body' => $insert['body'], 
																		 ':post_date' => date('Y-m-d H:i:s', strtotime($insert['pubdate'])), 
																		 ':display_type' => $insert['type'] ));
		}
		$newPostID = $db->lastInsertId();

		$int_insert->execute(array(':post_id' => $newPostID, ':twitter_id' => $insert['twid']));
	
		foreach ($insert['tags'] as $tag) {
			$tagID = -1;
		
			$tag_check->execute(array(':tag' => $tag));
			$tag_rows = $tag_check->fetchAll(PDO::FETCH_ASSOC);
			if (empty($tag_rows)) {
				$tag_insert->execute(array(':tag' => $tag));
				$tagID = $db->lastInsertId();
			} else {
				$tagID = $tag_rows[0]['id'];
			}
		
			$tag_link->execute(array(':post_id' => $newPostID, ':tag_id' => $tagID));
		}
	
		foreach ($insert['media'] as $media) {
			$media_insert->execute(array(	':post_id' => $newPostID,
																		':url' => $media['url'],
																		':type' => $media['type'],
																		':gif' => (!empty($media['gif']) && $media['gif'])));
		}
	}
}

echo 'done!';