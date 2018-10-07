<!doctype html>
<html lang="en">
  <head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css" integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">
    <style>
			blockquote {
				border-left: 5px solid grey;
				padding-left: 2em;
			}
		</style>

    <title>Hello, world!</title>
  </head>
  <body>
  	<div class="container">
<?php

require_once('config.php');
require_once('vendor/autoload.php');

use Embed\Embed;
$tumblr = new Tumblr\API\Client(
    $eph_config['tumblr_key'],
    $eph_config['tumblr_secret'],
    $eph_config['tumblr_usr_key'],
    $eph_config['tumblr_usr_sec']
);

// Make the request
$response = $tumblr->getBlogPosts('nerdflavor.tumblr.com', array('reblog_info' => true, 'filter' => 'html'));
//$response = $tumblr->getBlogPosts('isthatwhy-everything-is-on-fire.tumblr.com', array('reblog_info' => true, 'filter' => 'html', 'offset' => 0));

$to_import = array();
foreach ($response->posts as $post) {
	$thisPost = array(
		'pubdate' => $post->date,
		'tmbid' => $post->id,
		'tags' => $post->tags,
		'obj' => $post,
		'source_url' => $post->source_url,
		'source_title' => $post->source_title,
	);
	
	//This is a reblog, so embed the reblogged post and add the comment
	if (!empty($post->reblogged_from_url)) {
		$rebagel = $post->reblogged_from_url;
		$embedcode = '';
		
		try {
			$embedcode = Embed::create($rebagel)->code;
			if (empty($embedcode)) {
				$embedcode = json_decode(file_get_contents('https://www.tumblr.com/oembed/1.0?url='.$rebagel))->html;
			}
			$thisPost['body'] = $embedcode;
		} catch (\Exception $error) {
			$thisPost['body'] = '<p><a href="'.$rebagel.'">'.$rebagel.'</a></p>';
		}
		
		$thisPost['body'] .= "\n\n".$post->reblog->comment;
	} else {
		//This is an original post, so let's figure out the format!
		if ($post->title) $thisPost['title'] = $post->title;
		switch($post->type) {
		  case 'text':
		  	$thisPost['body'] .= $post->body;
		  	break;
			case 'link':
				$thisPost['body'] = '<h3>🔗 <a href="'.$post->url.'">'.$post->title.'</a></h3>';
				$thisPost['body'] .= "\n\n".$post->description;
				break;
			case 'video':
				if ($post->video_type == 'youtube') {
					$thisPost['body'] = Embed::create('https://youtu.be/'.$post->video->youtube->video_id)->code;
				} elseif ($post->video_type == 'tumblr') {
					$thisPost['body'] = '<video style="width:100%;height:auto;" controls poster="'.$post->thumbnail_url.'" src="'.$post->video_url.'"></video>';
					// video embed code from $post->video_url
				} else {
					$thisPost['body'] = "";
				}
				$thisPost['body'] .= "\n\n".$post->caption;
				break;
			case 'photo':
				if (count($post->photos) > 1) {
					//look at digits of $post->photoset_layout str_split($post->photoset_layout)
					//each digit is the number of photos in a row
					$photoInd = 0;
					$thisPost['body'] = "";
					foreach (str_split($post->photoset_layout) as $rowSize) {
						$numLeftInRow = $rowSize;
						$thisPost['body'] .= '<div class="row">'."\n";
						while ($numLeftInRow > 0) {
							$currentPhoto = $post->photos[$photoInd];
							$thisPost['body'] .= '<div class="col-sm">'."\n".
																	 '  <img class="img-fluid" alt="'.$currentPhoto->caption.'" src="'.$currentPhoto->original_size->url.'">'."\n".
																	 '</div>'."\n";
							$photoInd += 1;
							$numLeftInRow -= 1;
						}
						$thisPost['body'] .= '</div>'."\n";
					}
				} else {
					$thisPost['body'] = '<p>';
					if ($post->link_url) $thisPost['body'] .= '<a href="'.$post->link_url.'">';
					$thisPost['body'] .= '<img class="img-fluid" alt="Image from tumblr" src="'.$post->photos[0]->original_size->url.'">';
					if ($post->link_url) $thisPost['body'] .= '</a>';
					$thisPost['body'] .= '</p>';
				}
				$thisPost['body'] .= "\n\n".$post->caption;
				break;
			case 'chat':
				$thisPost['body'] = '<dl class="row">'."\n";
				foreach ($post->dialogue as $dline) {
					$thisPost['body'] .= '  <dt class="col-sm-3">'.$dline->label.'</dt>'."\n".
															 '  <dd class="col-sm-9">'.$dline->phrase.'</dd>'."\n";
				}
				$thisPost['body'] .= '</dl>';
				break;
			case 'quote':
				$thisPost['body'] = '<blockquote class="blockquote">'."\n".
														'  <p>'.$post->text.'</p>'."\n".
														'  <footer class="blockquote-footer">'.$post->source.'</footer>'."\n".
														'</blockquote>';
				break;														
		}
		
		//$thisPost['body'] = $post->reblog->comment;
	}
	
	$to_import[] = $thisPost;
}

//$info = Embed::create('https://twitter.com/statuses/937871668554358784');
//echo $info->code;

/*
echo '<!--';
print_r($response);
echo '-->';
*/

foreach ($to_import as $post) :

?>

<div class="row justify-content-md-center">
	<div class="col-md-6">
		<?php if ($post['title']) echo '<h2>'.$post['title'].'</h2>'; ?>
		
		<?php echo $post['body']; ?>
		
		<?php if ($post['source_url']) : ?>
		<p><a class="badge badge-primary" href="<?php echo $post['source_url']; ?>">
			<?php echo $post['source_title'] ? 'Source: '.$post['source_title'] : 'Source' ?>
		</a></p>
		<?php endif; ?>
		
		<p>
		<?php foreach ($post['tags'] as $tag) : ?>
			<a href="#" class="badge badge-secondary">#<?php echo $tag; ?></a>
		<?php endforeach; ?>
		</p>
	</div>
</div>

<!--
<?php print_r($post['obj']); ?>
-->
<hr>

<?php endforeach; ?>

		</div>
    <!-- Optional JavaScript -->
    <!-- jQuery first, then Popper.js, then Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js" integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo" crossorigin="anonymous"></script>
		<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.3/umd/popper.min.js" integrity="sha384-ZMP7rVo3mIykV+2+9J3UJ46jBk0WLaUAdn689aCwoqbBJiSnjAK/l8WvCWPIPm49" crossorigin="anonymous"></script>
		<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/js/bootstrap.min.js" integrity="sha384-ChfqqxuZUCnJSK3+MXmPNIyE6ZbWh2IMqE241rYiqJxyMiZ6OW/JmZQ5stwEULTy" crossorigin="anonymous"></script>
	</body>
</html>