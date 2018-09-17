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
$response = $tumblr->getBlogPosts('oddevan.tumblr.com', array('reblog_info' => true, 'filter' => 'html'));
//$response = $tumblr->getBlogPosts('paperairplanemob.tumblr.com', array('reblog_info' => true, 'filter' => 'html', 'offset' => 20));

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
		switch($post->type) {
			case 'link':
				$thisPost['body'] = '<h2>🔗 <a href="'.$post->url.'">'.$post->title.'</a></h2>';
				$thisPost['body'] .= "\n\n".$post->description;
				break;
			case: 'video':
				if ($post->video_type == 'unknown') {
					
				}
				$thisPost['body'] .= "\n\n".$post->caption;
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
		<?php echo $post['body']; ?>
		
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