<?php
require_once('vendor/autoload.php');
require_once('config.php');

use Embed\Embed;

echo 'Loading Tumblr...';

$tumblr = new Tumblr\API\Client(
    $eph_config['tumblr_key'],
    $eph_config['tumblr_secret'],
    $eph_config['tumblr_usr_key'],
    $eph_config['tumblr_usr_sec']
);

function try_embed( $url ) {
	try {
		$code = Embed::create($url)->code;
		if (empty($code)) $code = '<p><a href="'.$url.'">'.$url.'</a></p>';
		return $code;
	} catch (\Exception $error) {
		return '<p><a href="'.$url.'">'.$url.'</a></p>';
	}
}

$response = $tumblr->getBlogPosts('paperairplanemob.tumblr.com', array('reblog_info' => true, 'filter' => 'html'));

chdir($eph_config['hugo_base_dir']);
//exec("git pull origin");

echo 'Processing...';

foreach ($response->posts as $post) {

  //$postDate = date_create_from_format('Y-m-d H:i:s O', $post->date);
  $postDate = date_create($post->date);
  $postdir = $eph_config['hugo_base_dir'].'content/post/'.$postDate->format('Y/m').'/'.$post->id;
	
	if (!is_dir($postdir)) {
		//Make the status, year, and month directories if needed
		if (!is_dir($eph_config['hugo_base_dir'].'content/post'))
			mkdir($eph_config['hugo_base_dir'].'content/post');
		if (!is_dir($eph_config['hugo_base_dir'].'content/post/'.$postDate->format('Y')))
			mkdir($eph_config['hugo_base_dir'].'content/post/'.$postDate->format('Y'));
		if (!is_dir($eph_config['hugo_base_dir'].'content/post/'.$postDate->format('Y/m')))
			mkdir($eph_config['hugo_base_dir'].'content/post/'.$postDate->format('Y/m'));
		
		//Make the leaf bundle for this post
		mkdir($postdir);
		
		$frontmatter = array(
			'date' => $post->date,
			'slug' => $post->id,
			'tumblr_id' => $post->id,
			'tags' => array() + $post->tags,
			'categories' => array(),
			'resources' => array()
		);
	
		if (isset($post->source_url)) $frontmatter['source_url'] = $post->source_url;
		if (isset($post->source_title)) $frontmatter['source_title'] = $post->source_title;
	
		$is_reblog = (!empty($post->reblogged_from_url));
	
		$frontmatter['post_type'] = $is_reblog ? 'reblog' : $post->type;
		$body = '';
		
		//This is a reblog, so embed the reblogged post and add the comment
		if ($is_reblog) {
			$rebagel = $post->reblogged_from_url;
			$embedcode = '';
			$burntbagel = '';
			$body = '';
			$stepDown = -1;
			if (!empty($post->trail)) $stepDown = count($post->trail) - 1;
		
			while ($rebagel) {
				try {
					$embedcode = Embed::create($rebagel)->code;
					if (empty($embedcode)) {
						$embedcode = json_decode(file_get_contents('https://www.tumblr.com/oembed/1.0?url='.$rebagel))->html;
					}
					if (empty($embedcode)) {
						throw new Exception('Still blank!');
					}
					$body = $embedcode;
					$rebagel = false;
				} catch (\Exception $error) {
					if ($stepDown < 0 || (isset($post->trail[$stepDown]->is_root_item) && $post->trail[$stepDown]->is_root_item)) {
						// The root post is missing; treat this like an original post
						if ($rebagel == $post->reblogged_root_url || $post->reblogged_from_url == $post->reblogged_root_url) {
							$rebagel = false;
							$is_reblog = false;
						} else {
							$rebagel = $post->reblogged_root_url;
						}
					} else {
						$thisbagel = $post->trail[$stepDown];
						if (!$thisbagel->is_current_item) {
							$burntbagel = '<p><a href="'.$rebagel.'">'.$thisbagel->blog->name.'</a>:</p>'.
														"\n".'<blockquote>'."\n".
														$thisbagel->content.
														"\n".'</blockquote>'."\n";
						}
						$stepDown -= 1;
						if ($stepDown >= 0) {
							$thisbagel = $post->trail[$stepDown];
							$rebagel = 'http://'.$thisbagel->blog->name.'.tumblr.com/post/'.$thisbagel->post->id;
						} else {
							if ($post->reblogged_from_url == $post->reblogged_root_url) {
								$rebagel = false;
								$is_reblog = false;
							} else {
								$rebagel = $post->reblogged_root_url;
							}
						}         
					}
				}
			}
		
			if ($burntbagel) $body .= "\n\n".$burntbagel;
			$body .= "\n\n".$post->reblog->comment;
		}
	
		if (!$is_reblog) {
			//This is an original post, so let's figure out the format!
			if (isset($post->title)) $thisPost['title'] = $post->title;
			switch($post->type) {
				case 'text':
					$body = $post->body;
					break;
				case 'link':
					unset($thisPost['title']);
					$body = '<h3>🔗 <a href="'.$post->url.'">'.$post->title.'</a></h3>';
					$body .= "\n\n".$post->description;
					break;
				case 'video':
					if ($post->video_type == 'youtube') {
						if (isset($post->video) && isset($post->video->youtube)) {
							$body = try_embed('https://youtu.be/'.$post->video->youtube->video_id);
						} else {
							$body = '<p class="text-muted">Video removed or otherwise unavailable.</p>';
						}
					} elseif ($post->video_type == 'tumblr') {
						$body = '<video style="width:100%;height:auto;" controls poster="'.$post->thumbnail_url.'" src="'.$post->video_url.'"></video>';
					} elseif ($post->video_type == 'vine') {
						//because Vine apparently didn't support OEmbed?!
						$body = $post->player[count($post->player) - 1]->embed_code;
					}else {
						$body = try_embed($post->permalink_url);
					}
					$body .= "\n\n".$post->caption;
					break;
				case 'photo':
					if (count($post->photos) > 1) {
						//look at digits of $post->photoset_layout str_split($post->photoset_layout)
						//each digit is the number of photos in a row
						$photoInd = 0;
						$body = '<div class="photoset">';
						foreach (str_split($post->photoset_layout) as $rowSize) {
							$numLeftInRow = $rowSize;
							$body .= '<div class="row">'."\n";
							while ($numLeftInRow > 0) {
								$currentPhoto = $post->photos[$photoInd];
								$body .= '<div class="col-sm">'."\n".
																		 '  <img class="img-fluid" alt="'.$currentPhoto->caption.'" src="'.$currentPhoto->original_size->url.'">'."\n".
																		 '</div>'."\n";
								$photoInd += 1;
								$numLeftInRow -= 1;
							}
							$body .= '</div>'."\n";
						}
						$body.= '</div>';
					} else {
						$body = '<p>';
						if (isset($post->link_url)) $body .= '<a href="'.$post->link_url.'">';
						$body .= '<img class="img-fluid" alt="Image from tumblr" src="'.$post->photos[0]->original_size->url.'">';
						if (isset($post->link_url)) $body .= '</a>';
						$body .= '</p>';
					}
					$body .= "\n\n".$post->caption;
					break;
				case 'chat':
					$body = '<dl class="row">'."\n";
					foreach ($post->dialogue as $dline) {
						$body .= '  <dt class="col-sm-3">'.$dline->label.'</dt>'."\n".
																 '  <dd class="col-sm-9">'.$dline->phrase.'</dd>'."\n";
					}
					$body .= '</dl>';
					break;
				case 'quote':
					$body = '<blockquote class="blockquote">'."\n".
															'  <p>'.$post->text.'</p>'."\n".
															'  <footer class="blockquote-footer">'.$post->source.'</footer>'."\n".
															'</blockquote>';
					break;
				case 'audio':
					if (isset($post->is_external) && $post->is_external) {
						$body = try_embed($post->audio_source_url);
					} elseif ($post->audio_type == 'tumblr') {
						$body = '<audio style="width:100%;height:auto;" controls src="'.$post->audio_url.'"></audio>';
					} else {
						$body = "";
					}
					$body .= "\n\n".$post->caption;
					break;
				case 'answer':
					if (!empty($post->asking_url))
						$body = '<p><a href="'.$post->asking_url.'">'.$post->asking_name.'</a> asked:</p>';
					else $body = '<p>An anonymous visitor asked:</p>';
				
					$body .= "\n".'<blockquote>'.$post->question.'</blockquote>'."\n\n".
															 $post->answer;
					break;
			}
		}
		/*
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
		}*/
	
		$fileout = fopen($postdir.'/index.html', 'w');
		fwrite($fileout, json_encode($frontmatter)."\n\n".$body);
		fclose($fileout);
	} else {
		echo 'Directory '.$postDir.' already exists; skipping import.'."\n";
	}
}

//exec("git add .");  
//exec("git commit -m 'Update from Audubon-tumblr'");
//exec("git push origin master");

echo 'done!';
