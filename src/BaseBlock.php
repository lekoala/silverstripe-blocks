<?php

namespace LeKoala\Blocks;

use ReflectionClass;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\View\ArrayData;
use SilverStripe\View\ViewableData;

/**
 * This is the class you need to extend to create your own block
 *
 * Also see BlocksCreateTask to create to blocks for you
 */
class BaseBlock extends ViewableData
{
    /**
     * @var Block
     */
    protected $_block;

    public function __construct(Block $block)
    {
        $this->_block = $block;

        $data = $block->DataArray();
        $settings = $block->SettingsArray();
        $extra = $this->ExtraData();
        $arrayData = new ArrayData(array_merge($data, $settings, $extra));

        $this->setFailover($arrayData);
    }

    /**
     * Allow direct queries from the template
     *
     * Use wisely...
     *
     * @param string $class
     * @return DataList
     */
    public function Query($class)
    {
        // Allow unqualified classes
        if (!class_exists($class)) {
            $subclasses = ClassInfo::subclassesFor(DataObject::class);
            foreach ($subclasses as $lcName => $name) {
                $nameClass = (new ReflectionClass($name))->getShortName();
                if ($class == $nameClass) {
                    $class = $name;
                    break;
                }
            }
        }
        return $class::get();
    }


    public function updateFields(BlockFieldList $fields)
    {
    }

    public function updateClass(&$class)
    {
    }

    /**
     * Extra data to feed to the template
     * @return array
     */
    public function ExtraData()
    {
        return [];
    }

    /**
     * @return DataList
     */
    public function Collection()
    {
    }

    /**
     * @return DataList
     */
    public function SharedCollection()
    {
    }
}
