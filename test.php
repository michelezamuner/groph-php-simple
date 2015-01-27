<?php
use Tag\Tag;
use Tag\Factory;

include 'vendor/autoload.php';
$logger = new Logger('test.log');
$database = new Database(':memory:', $logger);
$factory = new Factory($database, $logger);
$collection = $factory->getCollection();
$data = json_decode(file_get_contents('groph.json'), true);
$fixtures = $data['tags'];
$collection->load($fixtures);

$tag1 = $collection->findFirst('software development');
$path = Vector::create(
	'programming languages',
	'interpreted programming languages',
	'javascript'
);
$steps = 0;
$names = Vector::create();
$tag1->visitPath($path, function (Tag $tag) use(&$steps, &$names) {
	echo $tag->getName(), PHP_EOL;
	$steps++;
	$names->append($tag->getName());
});
echo $steps, PHP_EOL;
print_r($names);
