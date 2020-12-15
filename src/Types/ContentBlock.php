<?php

namespace LeKoala\Blocks\Types;

use LeKoala\Blocks\BaseBlock;
use LeKoala\Blocks\BlockFieldList;

/**
 * The default block
 *
 * Add an editor after the image
 *
 * Do not use "Content" as it used to save rendered template
 */
class ContentBlock extends BaseBlock
{
    public function updateFields(BlockFieldList $fields)
    {
        $fields->addEditor();
    }
}
