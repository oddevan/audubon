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

function download_resource( $url, $postdir, &$frontmatter, $alt = false, $name = false ) {
	$filename = substr(strrchr($url, "/"), 1);
	file_put_contents($postdir.'/'.$filename, file_get_contents($url));
	
	if (!$name) $name = substr(strchr($url, "."), 1);
	if (!$alt) $alt = 'Image from tumblr';
	
	$frontmatter['resources'][] = array('src' => $filename, 'name' => $name.'', 'alt' => $alt);
	
	return '{{< eph_resource "'.$name.'" >}}';
}

//$response = $tumblr->getBlogPosts('isthatwhy-everything-is-on-fire.tumblr.com', array('reblog_info' => true, 'filter' => 'html', 'offset' => 0));
$response = $tumblr->getBlogPosts('nerdflavor.tumblr.com', array('reblog_info' => true, 'filter' => 'html', 'offset' => 0));

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
			'date' => $postDate->format('c'), //Tue Jan 02 00:13:58 +0000 2018
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
			if (isset($post->title)) $frontmatter['title'] = $post->title;
			switch($post->type) {
				case 'text':
					$body = $post->body;
					break;
				case 'link':
					unset($frontmatter['title']);
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
						$body = download_resource($post->video_url, $postdir, $frontmatter);
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
								         download_resource($currentPhoto->original_size->url, 
								         								   $postdir, 
								         								   $frontmatter, 
								         								   $currentPhoto->caption ).
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
						$body .= download_resource($post->photos[0]->original_size->url, $postdir, $frontmatter);
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
						//TODO: need to add a .mp3 or appropriate file extension here!
						$body = download_resource($post->audio_url, $postdir, $frontmatter);
					} else {
						$body = "";
					}
					$body .= "\n\n".$post->caption;
					break;
				case 'answer':
					if (!empty($post->asking_url))
						$body = '<p><a href="'.$post->asking_url.'">'.$post->asking_name.'</a> asked:</p>';
					else $body = '<p>An anonymous visitor asked:</p>';
				
					$body .= "\n".'<blockquote>'.$post->question.'</blockquote>'."\n\n".$post->answer;
					break;
			}
		}
	
		$fileout = fopen($postdir.'/index.html', 'w');
		fwrite($fileout, json_encode($frontmatter)."\n\n".$body);
		fclose($fileout);
	} else {
		echo 'Directory '.$postdir.' already exists; skipping import.'."\n";
	}
}

//exec("git add .");  
//exec("git commit -m 'Update from Audubon-tumblr'");
//exec("git push origin master");

echo 'done!';
