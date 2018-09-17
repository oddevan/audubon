<?php

require_once('vendor/autoload.php');

use Embed\Embed;

$testurl = 'https://www.tumblr.com/oembed/1.0?url=http://mellenabrave.tumblr.com/post/176449990320/thefingerfuckingfemalefury-brookietf';
$result = json_decode(file_get_contents('https://www.tumblr.com/oembed/1.0?url=http://mellenabrave.tumblr.com/post/176449990320/thefingerfuckingfemalefury-brookietf'));

?>

<?php echo $result->html; ?>

<pre>
<?php //print_r($result); ?>
</pre>