<?php

namespace LeKoala\Blocks;

use PageController;

/**
 * Extend page controller
 *
 * @property \LeKoala\Blocks\BlocksPage dataRecord
 * @method \LeKoala\Blocks\BlocksPage data()
 * @mixin \LeKoala\Blocks\BlocksPage dataRecord
 */
class BlocksPageController extends PageController
{
    protected function init()
    {
        parent::init();

        // Add requirements
        /** @var \LeKoala\Blocks\Block $block */
        foreach ($this->Blocks() as $block) {
            $type = $block->getTypeInstance();
            $class = get_class($type);
            if (method_exists($class, 'Requirements')) {
                $class::Requirements();
            }
        }
    }

    public function BodyClass()
    {
        $class = parent::BodyClass();

        $arr = $this->data()->getBlocksListArray();
        if (!empty($arr)) {
            $class .= ' Starts-' . $arr[0];
        }

        return $class;
    }
}
