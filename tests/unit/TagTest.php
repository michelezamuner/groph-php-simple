<?php
use Tag\Tag;
use Tag\Factory as TagFactory;

class TagTest extends \Codeception\TestCase\Test
{
    /**
     * @var \UnitTester
     */
    protected $tester;
    
    private $logger = Null;
    private $factory = Null;
    private $coll = Null;
    private $fixtures;
    
    public function __construct()
    {
    	parent::__construct();
    	$this->logger = new Logger(ROOT.'/test.log');
    	$data = json_decode(file_get_contents('groph.json'), true);
    	$this->fixtures = $data['tags'];
    }

    protected function _before()
    {
    	exec('rm -f '.ROOT.'/test.log');
    	$this->coll = Null;
    	$db = new Database(':memory:', $this->logger);
    	$this->factory = new TagFactory($db, $this->logger);
    	$this->coll = $this->factory->getCollection();
    	$this->coll->load($this->fixtures);
    }

    protected function _after()
    {
    }

    public function testParentsAndChildrenAreSameObjectsInMemory()
    {
    	$size = $this->coll->getSize();
    	$parent = $this->factory->create(Array('Parent'));
    	$child = $this->factory->create(Array('Child'));
    	$parent->addChild($child);
    	$this->assertEquals(1, count($parent->getChildren()));
    	$children = $parent->getChildren();
    	$firstChild = $children[0];
    	$this->assertEquals($child, $firstChild);
    	$this->assertEquals($parent, $firstChild->getParent());
    	$this->assertEquals($size, $this->coll->getSize());
    }

}