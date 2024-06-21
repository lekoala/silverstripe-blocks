<?php

namespace LeKoala\Blocks;

use SilverStripe\Forms\Tab;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Forms\TabSet;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\SSViewer;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\View\ArrayData;
use LeKoala\Blocks\FieldType\DBJson;
use SilverStripe\Forms\TextField;
use SilverStripe\Control\Director;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Versioned\Versioned;
use LeKoala\Blocks\Types\ContentBlock;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\View\Parsers\URLSegmentFilter;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;

/**
 * The block dataobject is used to actually store the data
 *
 * @property int $Sort
 * @property string $Type
 * @property string $MenuTitle
 * @property string $HTMLID
 * @property string $Content
 * @property string $BlockData
 * @property string $Settings
 * @property int $ImageID
 * @property int $PageID
 * @method \SilverStripe\Assets\Image Image()
 * @method \LeKoala\Blocks\BlocksPage Page()
 * @method \SilverStripe\ORM\ManyManyList|\SilverStripe\Assets\Image[] Images()
 * @method \SilverStripe\ORM\ManyManyList|\SilverStripe\Assets\File[] Files()
 * @mixin \LeKoala\Extensions\SmartDataObjectExtension
 */
final class Block extends DataObject
{
    // constants
    const ITEMS_KEY = 'Items';
    const DATA_KEY = 'BlockData';
    const SETTINGS_KEY = 'Settings';

    // When using namespace, specify table name
    private static $table_name = 'Block';

    private static $db = [
        'Type' => 'Varchar(59)',
        'MenuTitle' => 'Varchar(191)',
        'HTMLID' => 'Varchar(59)',
        'Content' => 'HTMLText', // The rendered content
        // Localized data
        'BlockData' => DBJson::class,
        // Unlocalized data
        'Settings' => DBJson::class,
        "Sort" => "Int",
    ];

    private static $has_one = [
        "Image" => Image::class,
        "Page" => BlocksPage::class
    ];
    private static $many_many = [
        "Images" => Image::class,
        "Files" => File::class,
    ];
    private static $many_many_extraFields = [
        'Images' => ['SortOrder' => 'Int'],
        'Files' => ['SortOrder' => 'Int'],
    ];
    private static $cascade_deletes = [
        'Image', 'Images', 'Files'
    ];
    private static $owns = [
        'Image', "Images", "Files",
    ];
    private static $summary_fields = [
        'BlockType' => 'Block Type',
        'MenuTitle' => 'Menu Title',
        'Summary' => 'Summary',
    ];
    private static $translate = [
        "MenuTitle", "Content", "AuditData", "BlockData"
    ];
    private static $defaults = [
        'Type' => ContentBlock::class,
    ];

    private static $default_sort = 'Sort ASC';

    /**
     * Should we update the page after block update
     * Turn this off when updating all blocks of a page
     * otherwise it's really slow
     *
     * @var boolean
     */
    public static $auto_update_page = true;

    public function forTemplate()
    {
        return $this->Content;
    }

    public function getTitle()
    {
        $type = $this->BlockType();
        if ($this->ID) {
            return $type;
        }
        return 'New ' . $type;
    }

    /**
     * Each block type can have one "collection" of items
     *
     * The collection is filtered according to the blocs id
     * (a relation must be set on the related class)
     *
     * @return DataList
     */
    public function Collection()
    {
        $inst = $this->getTypeInstance();
        $list = $inst->Collection();
        if ($list) {
            return $list->filter('BlockID', $this->ID);
        }
    }

    /**
     * Each block type can have one shared "collection" of items
     *
     * The shared collection is common across all blocks of the same type
     *
     * @return DataList
     */
    public function SharedCollection()
    {
        $inst = $this->getTypeInstance();
        return $inst->SharedCollection();
    }

    /**
     * Get sorted images
     *
     * @return \SilverStripe\ORM\ManyManyList
     */
    public function SortedImages()
    {
        return $this->Images()->Sort('SortOrder');
    }

    /**
     * Get sorted files
     *
     * @return \SilverStripe\ORM\ManyManyList
     */
    public function SortedFiles()
    {
        return $this->Files()->Sort('SortOrder');
    }

    /**
     * This is called onBeforeWrite and renders content
     * in order to store it in Content variable
     *
     * @return string
     */
    public function renderWithTemplate()
    {
        $template = 'Blocks/' . $this->BlockClass();

        // turn off source_file_comments
        // keep in mind that any cached template will have the source_file_comments if
        // it was not disabled, regardless of this setting so it may be confusing
        Config::nest();
        Config::modify()->set(SSViewer::class, 'source_file_comments', false);

        // Make sure theme exists in the list (not set in cms)
        $themes = SSViewer::get_themes();
        $configThemes = SSViewer::config()->themes;
        SSViewer::set_themes($configThemes);
        $chosenTemplate = SSViewer::chooseTemplate($template);
        // Render template
        $result = null;
        if ($chosenTemplate) {
            $typeInst = $this->getTypeInstance();
            $data = $this->DataArray();
            $settings = $this->SettingsArray();
            $extra = $typeInst->ExtraData();
            $data = array_merge($data, $settings, $extra);
            // We have items to normalize
            if (isset($data[self::ITEMS_KEY])) {
                $data[self::ITEMS_KEY] = self::normalizeIndexedList($data[self::ITEMS_KEY]);
            }
            // Somehow, data is not nested properly if not wrapped beforehand with ArrayData
            $arrayData = new ArrayData($data);
            // Maybe we need to disable hash rewriting
            if ($typeInst->hasMethod('disableAnchorRewriting')) {
                SSViewer::setRewriteHashLinksDefault($typeInst->disableAnchorRewriting());
            }
            $result = (string) $typeInst->renderWith($template, $arrayData);
            SSViewer::setRewriteHashLinksDefault(true);
        }
        // Restore themes just in case to prevent any side effect
        SSViewer::set_themes($themes);

        // Restore flag
        Config::unnest();

        return $result;
    }

    /**
     * Class helper to use in your templates
     *
     * @param string $name
     * @return string
     */
    public function Cls($name)
    {
        return 'Block-' . $this->BlockType() . '-' . $name;
    }

    /**
     * Convert an indexed array to an ArrayList
     * This allows loops, etc in the template
     *
     * @param array $indexedList
     * @return ArrayList
     */
    protected static function normalizeIndexedList($indexedList)
    {
        // this is required to add a global counter that works if multiple blocks  of the same type are used
        static $counter = 0;
        $list = new ArrayList();
        $i = 0;

        // remove empty items
        foreach ($indexedList as $index => $item) {
            $vals = array_values($item);
            $vals = array_filter($vals);
            if (empty($vals)) {
                unset($indexedList[$index]);
            }
        }

        $c = count($indexedList);
        foreach ($indexedList as $index => $item) {
            $i++;
            $counter++;
            // Add standard iterator stuff
            $FirstLast = '';
            if ($i === 1) {
                $FirstLast = 'first';
            } elseif ($i === $c) {
                $FirstLast = 'last';
            }
            $item['Pos'] = $index;
            $item['Total'] = $c;
            $item['Columns'] = 12 / $c;
            $item['Counter'] = $counter;
            $item['FirstLast'] = $FirstLast;
            $item['EvenOdd'] = $i % 2 ? 'even' : 'odd';
            foreach ($item as $k => $v) {
                // Handle files (stored in json as ["Files" => [ID]])
                if (is_array($v) && !empty($v['Files'])) {
                    $files = $v['Files'];
                    if (count($files) == 1) {
                        // Remove ID from end of string
                        // eg: if you added [$k, 'ImageID'] it should be accessible using "Image", not "ImageID"
                        $lastChars = substr($k, strlen($k) - 2, 2);
                        if ($lastChars == 'ID') {
                            $k = substr($k, 0, -2);
                        }
                        $item[$k] = self::getPublishedFileByID($files[0]);
                    } else {
                        $imageList = new ArrayList();
                        foreach ($files as $fileID) {
                            $imageList->push(self::getPublishedFileByID($fileID));
                        }
                        $item[$k] = $imageList;
                    }
                }
            }
            $list->push($item);
        }
        return $list;
    }

    /**
     * Template helper to access images
     *
     * @param string|int $ID
     * @return Image
     */
    public function ImageByID($ID)
    {
        if (!is_numeric($ID)) {
            $ID = $this->DataArray()[$ID]['Files'][0];
        }
        $Image = self::getPublishedImageByID($ID);
        if (!$Image) {
            return false;
        }
        return $Image;
    }

    /**
     * Make sure the image is published for the block
     *
     * @param int $ID
     * @return Image
     */
    public static function getPublishedImageByID($ID)
    {
        if (class_exists(Versioned::class)) {
            /** @var Image $image */
            $image = Versioned::get_one_by_stage(Image::class, 'Stage', "ID = " . (int) $ID);
        } else {
            $image = Image::get_by_id($ID);
        }
        // This is just annoying
        if ($image && $image->getVisibility() == "protected") {
            $image->publishFile();
            if (class_exists(Versioned::class)) {
                $image->publishSingle();
            }
        }
        return $image;
    }

    /**
     * Make sure the file is published for the block
     *
     * @param int $ID
     * @return File
     */
    public static function getPublishedFileByID($ID)
    {
        if (class_exists(Versioned::class)) {
            /** @var File $file */
            $file = Versioned::get_one_by_stage(File::class, 'Stage', "ID = " . (int) $ID);
        } else {
            $file = File::get_by_id($ID);
        }
        // This is just annoying
        if ($file && $file->getVisibility() == "protected") {
            $file->publishFile();
            if (class_exists(Versioned::class)) {
                $file->publishSingle();
            }
        }
        return $file;
    }

    /**
     * @return boolean
     */
    public function isInAdmin()
    {
        if (isset($_GET['live']) && Director::isDev()) {
            return true;
        }
        if (Controller::has_curr()) {
            return Controller::curr() instanceof LeftAndMain;
        }
        return false;
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        if (!$this->Sort) {
            $this->Sort = $this->getNextSort();
        }

        if (!$this->isInAdmin()) {
            return;
        }

        // If we have a menu title (for anchors) we need an HTML ID
        if (!$this->HTMLID && $this->MenuTitle) {
            $filter = new URLSegmentFilter;
            $this->HTMLID = $filter->filter($this->MenuTitle);
        }

        // Render template to content
        // We need this for summary to work properly
        $Content = $this->renderWithTemplate();
        $this->Content = $Content;

        // Clear unused data fields (see if we can remove setCastedField hijack altogether)
        $ID = $_POST['ID'] ?? 0;

        // Make sure we only assign data on currently edited block (not on other due to cascade update)
        if ($ID == $this->ID) {
            $PostedData = $_POST[self::DATA_KEY] ?? null;
            $PostedSettings = $_POST[self::SETTINGS_KEY] ?? null;
            if ($PostedData !== null) {
                $this->BlockData = $PostedData;
            }
            if ($PostedSettings !== null) {
                $this->Settings = $PostedSettings;
            }
        }
    }

    public function onAfterWrite()
    {
        parent::onAfterWrite();
        if (!$this->isInAdmin()) {
            return;
        }
        if (self::$auto_update_page) {
            // Update Page Content to reflect updated block content
            $this->Page()->write();
        }
    }

    /**
     * Get a name for this type
     * Basically calling getBlockName with the Type
     *
     * @return string
     */
    public function BlockType()
    {
        if (!$this->Type) {
            '(Undefined)';
        }
        return self::getBlockName($this->Type);
    }

    /**
     * Get a class name without namespace
     *
     * @param string $class
     * @return string
     */
    public static function getClassWithoutNamespace($class)
    {
        $parts = explode("\\", $class);
        return array_pop($parts);
    }

    /**
     * Get unqualified class of the block's type
     *
     * @return void
     */
    public function BlockClass()
    {
        return self::getClassWithoutNamespace($this->Type);
    }

    /**
     * Extend __get to allow loading data from Data store
     *
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        // A Data field
        if (strpos($name, self::DATA_KEY . '[') === 0) {
            return $this->getIn($name, $this->DataArray());
        }
        // A Settings field
        if (strpos($name, self::SETTINGS_KEY . '[') === 0) {
            return $this->getIn($name, $this->SettingsArray());
        }
        return parent::__get($name);
    }

    /**
     * Extend hasField to allow loading data from Data store
     *
     * @param string $name
     * @return mixed
     */
    public function hasField($name)
    {
        // A Data field
        if (strpos($name, self::DATA_KEY . '[') === 0) {
            return true;
        }
        // A Settings field
        if (strpos($name, self::SETTINGS_KEY . '[') === 0) {
            return true;
        }
        return parent::hasField($name);
    }

    /**
     * Split Name[Input][Sub][Value] notation
     *
     * @param string $name
     * @return array
     */
    public static function extractNameParts($name)
    {
        if (strpos($name, '[') !== false) {
            $matches = null;
            preg_match_all('/\[([a-zA-Z0-9_]+)\]/', $name, $matches);
            $matches = $matches[1];
        } else {
            $matches = [$name];
        }
        return $matches;
    }

    /**
     * Get nested data
     *
     * @param string $key
     * @param array $arr
     * @return string
     */
    public function getIn($key, $arr)
    {
        $matches = self::extractNameParts($key);
        $val = $arr;
        foreach ($matches as $part) {
            if (isset($val[$part])) {
                $val = $val[$part];
            } else {
                $val = null;
            }
        }
        return $val;
    }

    /**
     * Hijack setCastedField to ensure form saving works properly
     *
     * Add value on a per field basis
     *
     * @param string $fieldName
     * @param string $value
     * @return $this
     */
    public function setCastedField($fieldName, $value)
    {
        // A Data field
        if (strpos($fieldName, self::DATA_KEY . '[') === 0) {
            $obj = $this->dbObject(self::DATA_KEY)->addValue(self::extractNameParts($fieldName), $value);
            $obj->saveInto($this);
            return $this;
        }
        // A Settings field
        if (strpos($fieldName, self::SETTINGS_KEY . '[') === 0) {
            $obj = $this->dbObject(self::SETTINGS_KEY)->addValue(self::extractNameParts($fieldName), $value);
            $obj->saveInto($this);
            return $this;
        }
        return parent::setCastedField($fieldName, $value);
    }

    /**
     * Consistently returns an array regardless of what is in BlockData
     *
     * @return array
     */
    public function DataArray()
    {
        return $this->dbObject('BlockData')->decodeArray();
    }

    /**
     * Consistently returns an array regardless of what is in Settings
     *
     * @return array
     */
    public function SettingsArray()
    {
        return $this->dbObject('Settings')->decodeArray();
    }

    /**
     * When looping in template, wrap the blocks content is wrapped in a
     * div with theses classes
     *
     * @return string
     */
    public function getClass()
    {
        $inst = $this->getTypeInstance();
        $class = 'Block Block-' . $this->BlockType();
        $inst->updateClass($class);
        return $class;
    }

    /**
     * Get a viewable block instance wrapping this block
     *
     * @return BaseBlock
     */
    public function getTypeInstance()
    {
        if ($this->Type) {
            $class = $this->Type;
            if (class_exists($class)) {
                return new $class($this);
            }
        }
        // If there is no type or type does not exist, render as content block
        return new ContentBlock($this);
    }
    /**
     * Returns a summary to be displayed in the gridfield
     *
     * @return DBHTMLText
     */
    public function Summary()
    {
        // Read from content
        $summary = trim(\strip_tags($this->Content));
        $shortSummary = \substr($summary, 0, 100);
        // Collapse whitespace
        $shortSummary = preg_replace('/\s+/', ' ', $shortSummary);
        if (!$shortSummary) {
            if ($this->ImageID) {
                $shortSummary = $this->Image()->getTitle();
            }
        }
        // Avoid escaping issues
        $text = new DBHTMLText('Summary');
        $text->setValue($shortSummary);
        return $text;
    }

    public function getCMSActions()
    {
        $actions = parent::getCMSActions();
        return $actions;
    }

    public function getCMSFields()
    {
        // $fields = parent::getCMSFields();
        $fields = new BlockFieldList();
        $mainTab = new Tab("Main");
        $settingsTab = new Tab("Settings");
        $fields->push(new TabSet("Root", $mainTab, $settingsTab));
        // (!) Fields must be added to Root.Main to work properly
        $mainTab->push(new HiddenField('ID'));
        $mainTab->push(new HiddenField(self::DATA_KEY));
        $mainTab->push(new HiddenField(self::SETTINGS_KEY));
        $mainTab->push(new HiddenField('PageID'));
        // Show debug infos
        if (Director::isDev() && isset($_GET['debug'])) {
            $json = '';
            if ($this->AuditData) {
                $json = $this->dbObject('AuditData')->pretty();
                $debugData = new LiteralField('JsonData', '<pre>Data: <code>' . $json . '</code></pre>');
            } else {
                $debugData = new LiteralField('JsonData', '<div class="message info">Does not contain any data</div>');
            }
            $fields->addFieldsToTab('Root.Debug', $debugData);
            if ($this->Settings) {
                $json = $this->dbObject('Settings')->pretty();
                $debugSettings = new LiteralField('JsonSettings', '<pre>Settings: <code>' . $json . '</code></pre>');
            } else {
                $debugSettings = new LiteralField('JsonSettings', '<div class="message info">Does not contain any settings</div>');
            }
            $fields->addFieldsToTab('Root.Debug', $debugSettings);
        }
        // Only show valid types in a dropdown
        $ValidTypes = self::listValidTypes();
        $Type = new DropdownField('Type', $this->fieldLabel('Type'), $ValidTypes);
        $Type->setAttribute('onchange', "jQuery('#Form_ItemEditForm_action_doSave').click()");
        if ($this->ID) {
            $settingsTab->push($Type);
        } else {
            $mainTab->push($Type);
        }
        // Other settings
        $settingsTab->push(new TextField('MenuTitle', 'Menu Title'));
        $settingsTab->push(new TextField('HTMLID', 'HTML ID'));
        // Show uploader
        // TODO: let injector do the job?
        $uploaderClass = BlockFieldList::getUploaderClass();
        $Image = $uploaderClass::create('Image');
        $fields->addFieldsToTab('Root.Main', $Image);
        // Handle Collection GridField
        $list = $this->Collection();
        if ($list) {
            $class = $list->dataClass();
            $singleton = $class::singleton();
            $gridConfig = GridFieldConfig_RecordEditor::create();
            if ($singleton->hasField('Sort')) {
                $gridConfig->addComponent(new GridFieldOrderableRows());
            }
            $grid = new GridField($class, $singleton->plural_name(), $list, $gridConfig);
            $fields->addFieldToTab('Root.Main', $grid);
        }
        // Handle Shared Collection GridField
        $list = $this->SharedCollection();
        if ($list) {
            $class = $list->dataClass();
            $singleton = $class::singleton();
            $gridConfig = GridFieldConfig_RecordEditor::create();
            $gridConfig->removeComponentsByType(GridFieldDeleteAction::class);
            if ($singleton->hasField('Sort')) {
                $gridConfig->addComponent(new GridFieldOrderableRows());
            }
            $grid = new GridField($class, $singleton->plural_name() . ' (shared)', $list, $gridConfig);
            $fields->addFieldToTab('Root.Main', $grid);
        }
        // Allow type instance to extends fields
        // Defined fields are processed later on for default behaviour
        $inst = $this->getTypeInstance();
        $inst->updateFields($fields);
        // Allow regular extension to work
        $this->extend('updateCMSFields', $fields);
        // Adjust uploaders
        $uploadFolder = 'Blocks/' . $this->PageID;
        // Adjust the single image field
        $Image = $fields->dataFieldByName('Image');
        if ($Image) {
            $Image->setFolderName($uploadFolder);
            $Image->setAllowedMaxFileNumber(1);
            $Image->setIsMultiUpload(false);
        }
        // Adjust any items fields
        $dataFields = $fields->dataFields();
        foreach ($dataFields as $dataField) {
            if ($dataField instanceof UploadField) {
                $dataField->setFolderName($uploadFolder . '/' . $this->BlockType());
                $fieldName = $dataField->getName();

                // Items uploader match only one file
                if (strpos($fieldName, '[') !== false) {
                    $dataField->setAllowedMaxFileNumber(1);
                    $dataField->setIsMultiUpload(false);
                }
            }
        }

        $fields->removeByName('Sort');

        return $fields;
    }

    /**
     * This allows you to define your own method if needed
     * Like when you have page dependent data objects that shouldn't use a global sort
     * Or if you want to sort by a given multiple to allow inserts later on
     * @return int
     */
    public function getNextSort()
    {
        $max = (int) self::get()->max('Sort');
        return $max + 1;
    }

    public function validate()
    {
        $result = parent::validate();
        return $result;
    }

    /**
     * List all classes extending BaseBlock
     *
     * @return array
     */
    public static function listBlocks()
    {
        $blocks = ClassInfo::subclassesFor(BaseBlock::class);
        // Remove BaseBlock
        \array_shift($blocks);
        return $blocks;
    }

    /**
     * Get a list of blocks mapped by class => name
     *
     * @return void
     */
    public static function listValidTypes()
    {
        $list = [];
        foreach (self::listBlocks() as $lcClass => $class) {
            $list[$class] = self::getBlockName($class);
        }
        return $list;
    }

    /**
     * Get a list of blocks mapped by unqualified class => class
     *
     * @return void
     */
    public static function listTemplates()
    {
        $list = [];
        foreach (self::listBlocks() as $lcClass => $class) {
            $className = self::getClassWithoutNamespace($class);
            $list[$className] = $class;
        }
        return $list;
    }

    /**
     * Get a more human readable name
     * TODO: i18n
     *
     * @param string $class
     * @return string
     */
    protected static function getBlockName($class)
    {
        if (!$class) {
            return;
        }
        $className = Block::getClassWithoutNamespace($class);
        return preg_replace('/Block$/', '', $className);
    }

    /**
     * The place where to store assets
     * We create a folder for each record to easily clean up after deletion
     *
     * @return string
     */
    public function getFolderName()
    {
        $className = Block::getClassWithoutNamespace(get_class($this->owner));
        return $className . '/' . $this->owner->ID;
    }
}
