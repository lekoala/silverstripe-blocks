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
