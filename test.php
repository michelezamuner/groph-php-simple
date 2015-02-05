<?php
use Tag\Tag;
use Tag\Factory;

include 'vendor/autoload.php';
$logger = new Logger('test.log');
exec('rm -f test.sqlite');
$database = new Database(':memory:', $logger);
// $database = new Database('test.sqlite', $logger);
$factory = new Factory($database, $logger);
$collection = $factory->getCollection();
$data = json_decode(file_get_contents('groph.json'), true);
$fixtures = $data['tags'];
$collection->load($fixtures);

$t = $collection->findFirst('programming languages');
echo $t->ref, PHP_EOL;
$tag1 = $collection->findFirst('software development');
$path = Vector::create(
	'programming languages',
	'interpreted programming languages',
	'javascript'
);
$steps = 0;
$names = Vector::create();
$tag1->visitPath($path, function(Tag $tag) use (&$steps, &$names) {
	echo "$tag [".$tag->getId().']', PHP_EOL;
	$steps++;
	$names->append($tag->getName());
});
echo $path->count().' = '.$steps, PHP_EOL;