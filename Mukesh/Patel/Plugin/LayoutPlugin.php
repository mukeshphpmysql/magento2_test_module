<?php


namespace Mukesh\Patel\Plugin;

use Closure;
use Mukesh\Patel\Helper\Config as HintConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\View\Layout;
use Magento\Framework\View\Layout\Data\Structure;
use Magento\Framework\App\Request\Http;
/**
 * When a block is rendered.
 *
 * @package Ho\Templatehints\Plugin\View
 */
class LayoutPlugin
{
    /**
     * Layout model.
     *
     * @var Layout $layout
     */
    private $layout;

    /**
     * Magento directory listing.
     *
     * @var DirectoryList $directoryList
     */
    private $directoryList;

    /** @var Structure $structure */
    private $structure;
	
	 /**
     * @var Http
     */
    protected $request;

    /**
     * @param DirectoryList $directoryList
     * @param HintConfig    $hintConfig
     * @param Layout        $layout
     */
    public function __construct(
        DirectoryList $directoryList,
        Http $request,
        Layout $layout
    ) {
        $this->directoryList = $directoryList;
         $this->request = $request;
        $this->layout = $layout;

        $layoutReflection = new \ReflectionClass($this->layout);
        $structureProp = $layoutReflection->getProperty('structure');
        $structureProp->setAccessible(true);
        $this->structure = $structureProp->getValue($this->layout);
    }

    /**
     * @param Layout  $layout
     * @param Closure $proceed
     * @param string  $name
     * @param bool    $useCache
     *
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundRenderElement(Layout $layout, Closure $proceed, $name, $useCache = false) // @codingStandardsIgnoreLine
    {
        $result = $proceed($name, $useCache);
        $hint = $this->request->getParam('hint');
        if(!$hint){
            return $result;
        }

        return $this->_decorateElement($result, $name);
    }

    /**
     * @param string $result
     * @param string $name
     *
     * @return string
     */
    private function _decorateElement($result, $name)
    {
        if (! $result) {
            $result = '<div style="display:block;"></div>';
        }

        if ($this->layout->isUiComponent($name)) {
            /** @var \Magento\Framework\View\Element\AbstractBlock $block */
            $block = $this->layout->getBlock($name);
            $result = $this->decorateOuterElement($result, [
                'data-ho-hinttype' => 'ui-container',
                'data-ho-hintdata' => $this->getBlockInfo($block)
            ]);
        } elseif ($this->layout->isBlock($name)) {
            $result = $this->decorateOuterElement($result, [
                'data-ho-hinttype' => 'block',
                'data-ho-hintdata' => $this->getBlockInfo($this->layout->getBlock($name))
            ]);
        } elseif ($this->layout->isContainer($name)) {
            $result = $this->decorateOuterElement($result, [
                'data-ho-hinttype' => 'container',
                'data-ho-hintdata' => $this->getContainerInfo($name, $this->structure->getElement($name))
            ]);
        }
        return $result;
    }

    /**
     * @param string $html
     * @param array $attributes
     *
     * @return string
     */
    public function decorateOuterElement($html, $attributes)
    {
        if (! $html) {
            return $html;
        }

        $htmlAttr = [];
        foreach ($attributes as $key => $value) {
            $htmlAttr[] = sprintf('%s="%s"', $key, htmlspecialchars($value));
        }
        $htmlAttr = implode(' ', $htmlAttr);

        $html = preg_replace(
            '/(<\b[^><]*)>/i',
            '$1'.($htmlAttr ? ' '.$htmlAttr : '').'>',
            $html,
            1
        );

        return $html;
    }

    /**
     * @param string $nameInLayout
     * @param array $container
     *
     * @return string
     */
    private function getContainerInfo(string $nameInLayout, array $container)
    {
        $result = self::filterEscapeEncode([
            'info' => [
                'nameInLayout' => $nameInLayout,
                'label' => $container['label'] ?? null
            ],
            'extra' => [
                'parent' => $container['parent'] ?? null,
                'children' => isset($container['children']) ? array_values($container['children']) : null,
            ]
        ]);

        return $result;
    }

    /**
     * Returns the blockInfo as a json encoded array
     *
     * @param \Magento\Framework\View\Element\AbstractBlock $block
     * @return string
     */
    private function getBlockInfo(\Magento\Framework\View\Element\AbstractBlock $block)
    {
        $result = self::filterEscapeEncode([
            'info' => [
                'nameInLayout' => $block->getNameInLayout(),
                'moduleName' => $block->getModuleName(),
                'phpClass' => $this->getBlockClass($block),
            ],
            'extra' => [
                'cacheKeyInfo' => $block->getCacheKeyInfo()
            ],
            'paths' => [
                'template' => $block->getTemplateFile(),
            ] + $this->getBlockPaths($block)
        ]);

        return $result;
    }

    /**
     * @param array $data
     *
     * @return string
     */
    public static function filterEscapeEncode(array $data)
    {
        return json_encode(self::filterEscape($data));
    }

    /**
     * Filter and escape the complete array.
     *
     * @param $data
     *
     * @return array
     */
    private static function filterEscape($data)
    {
        return array_filter(array_map(function ($elem) {
            if (is_array($elem)) {
                return self::filterEscape($elem);
            }
            return addslashes($elem); // @codingStandardsIgnoreLine
        }, $data));
    }

    /**
     * @param \Magento\Framework\View\Element\AbstractBlock $block
     *
     * @return string[string]
     */
    private function getBlockPaths(\Magento\Framework\View\Element\AbstractBlock $block)
    {
        $reflector     = new \ReflectionClass($block); //@codingStandardsIgnoreLine
        $classFileName = $reflector->getFileName();

        if ($block instanceof \Magento\Framework\Interception\InterceptorInterface) {
            $classFileName = $reflector->getParentClass()->getFileName();
        }

        return ['class' => $classFileName];
    }

    private function getBlockClass(\Magento\Framework\View\Element\AbstractBlock $block)
    {
        $className = get_class($block);

        if ($block instanceof \Magento\Framework\Interception\InterceptorInterface) {
            $reflector = new \ReflectionClass($block); //@codingStandardsIgnoreLine
            $className = $reflector->getParentClass()->getName();
        }

        return $className;
    }
}
