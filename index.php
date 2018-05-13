<!doctype html>
<html lang="en">
  <head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0-beta.3/css/bootstrap.min.css" integrity="sha384-Zug+QiDoJOrZ5t4lssLdxGhVrurbmBWopoEl+M6BdEfwnCJZtKxi1KgxUyJq13dy" crossorigin="anonymous">

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
$response = $tumblr->getBlogPosts('paperairplanemob.tumblr.com', array('reblog_info' => true, 'filter' => 'html'));

$to_import = array();
foreach ($response->posts as $post) {
	$thisPost = array(
		'pubdate' => $post->date,
		'tmbid' => $post->id,
		'tags' => $post->tags,
	);
	
	if (!empty($post->reblogged_from_url)) {
		$rebagel = $post->reblogged_from_url;
		
		try {
			$thisPost['body'] = Embed::create($rebagel)->code;
		} catch (\Exception $error) {
			$thisPost['body'] = '<p><a href="'.$rebagel.'">'.$rebagel.'</a></p>';
		}
		
		$thisPost['body'] .= "\n\n".$post->reblog->comment;
	} else {
		$thisPost['body'] = $post->reblog->comment;
	}
	
	$to_import[] = $thisPost;
}

//$info = Embed::create('https://twitter.com/statuses/937871668554358784');
//echo $info->code;

/*
echo '<pre>';
print_r($to_import);
echo '</pre>';
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
<hr>

<?php endforeach; ?>

		</div>
    <!-- Optional JavaScript -->
    <!-- jQuery first, then Popper.js, then Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.2.1.slim.min.js" integrity="sha384-KJ3o2DKtIkvYIK3UENzmM7KCkRr/rE9/Qpg6aAZGJwFDMVNA/GpGFF93hXpG5KkN" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.9/umd/popper.min.js" integrity="sha384-ApNbgh9B+Y1QKtv3Rn7W3mgPxhU9K/ScQsAP7hUibX39j7fakFPskvXusvfa0b4Q" crossorigin="anonymous"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0-beta.3/js/bootstrap.min.js" integrity="sha384-a5N7Y/aK3qNeh15eJKGWxsqtnX/wWdSZSKp+81YjTmS15nvnvxKHuzaWwXHDli+4" crossorigin="anonymous"></script>
  </body>
</html>