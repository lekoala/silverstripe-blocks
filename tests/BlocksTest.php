<?php

namespace LeKoala\DeferBackend\Test;

use LeKoala\Blocks\BaseBlock;
use LeKoala\Blocks\Block;
use SilverStripe\Dev\SapphireTest;

class BlocksTest extends SapphireTest
{
    /**
     * Defines the fixture file to use for this test class
     * @var string
     */
    protected static $fixture_file = 'BlocksTest.yml';

    public function getDefaultBlock()
    {
        return $this->objFromFixture(Block::class, 'default');
    }

    public function testBlocks()
    {
        $block = $this->getDefaultBlock();
        $baseBlock = new BaseBlock($block);
    }
}
