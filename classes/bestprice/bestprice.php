<?php
/**
 * Created by PhpStorm.
 * User: vagenas
 * Date: 16/10/2014
 * Time: 3:10 μμ
 */

namespace bestprice;

if (!defined('WPINC')) {
    exit('Do NOT access this file directly: '.basename(__FILE__));
}

/**
 * Class bestprice
 *
 * @package bestprice
 * @author  Panagiotis Vagenas <pan.vagenas@gmail.com>
 * @since   150120
 */
class bestprice extends framework
{
    public $doDebugRun = false;
    /**
     * @var int
     */
    protected $progress = 0;
    /**
     * @var int
     */
    protected $progressUpdateInterval = 5;

    protected $is_fashion_store = false;
    protected $is_book_store    = false;

    public function __construct($instance)
    {
        parent::__construct($instance);

        $this->is_fashion_store = (bool)$this->©option->get('is_fashion_store');
        $this->is_book_store    = (bool)$this->©option->get('is_book_store');
    }

    /**
     * @param $post_id
     *
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    public function update_woo_product($post_id)
    {
        // If this is just a revision, don't send the email.
        if (wp_is_post_revision($post_id)) {
            return;
        }

        $product = new \WC_Product((int)$post_id);

        if (!$product->is_purchasable() || !$product->is_visible() || !$product->is_in_stock()) {
            return;
        }

        $this->©xml->parseArray(array($this->getProductArray($product)));
    }

    /**
     * @return int
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    public function do_your_woo_stuff()
    {
        $sTime = microtime(true);
        ignore_user_abort(true);

        $this->©option->update(array('log' => array()));

        $this->©diagnostic->forceDBLog('product', array(), '<strong>BestPrice XML generation started at '.date('d M, Y H:i:s').'</strong>');

        $prodInXml = $this->processProducts();

        $this->©diagnostic->forceDBLog('product', array(), '<strong>BestPrice XML generation finished at '.date('d M, Y H:i:s').'</strong><br>Time taken: '.round(microtime(true) - $sTime, 2).' sec<br>Mem details: '.$this->©env->memory_details());

        return $prodInXml;
    }

    /**
     * @throws \xd_v141226_dev\exception
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    public function generate_and_print()
    {
        $schedules = wp_get_schedules();

        if (isset($schedules[$this->©option->get('xml_interval')])) {
            $interval         = $schedules[$this->©option->get('xml_interval')]['interval'];
            $xmlCreation      = $this->©xml->getFileInfo();
            $createdTime      = strtotime($xmlCreation[$this->©xml->createdAtName]['value']);
            $nextCreationTime = $interval + $createdTime;
            $time             = time();
            if ($time > $nextCreationTime) {
                $this->do_your_woo_stuff();
            }
        }

        $this->©xml->printXML();
        exit(0);
    }

    /**
     * @return string
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    public function getGenerateXmlUrl()
    {
        return home_url().'/?'.$this->©option->get('xml_generate_var').'='.$this->©option->get('xml_generate_var_value');
    }

    /**
     * @return int
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    public function processProducts()
    {
        $prodArray = (array)$this->©db->get_col('SELECT ID FROM '.$this->©db->posts.' WHERE post_type="product" AND post_status="publish"');

        $mem = $this->getMemInM(ini_get('memory_limit')) / 1024 / 1024;

        $time = max(ceil(count($prodArray) * 0.5), 30);
        set_time_limit($time);

        $this->©diagnostic->forceDBLog('product', array(), 'Memory set to '.$mem.'M for current session<br>Time set to '.$time.' sec for current session');

        $memLimit = ($mem - 10) * 1024 * 1024;

        foreach ($prodArray as $i => $pid) {

            if (memory_get_usage() > $memLimit) {
                wp_cache_flush();
            }

            $product = WC()->product_factory->get_product((int)$pid);

            if (!is_object($product) || !($product instanceof \WC_Product)) {
                $this->©diagnostic->forceDBLog('product', $product, 'Product failed in '.__METHOD__);
                continue;
            }

            if (!$product->is_purchasable() || !$product->is_visible() || $this->getAvailabilityString($product) === false) {
                $reason = array();
                if (!$product->is_purchasable()) {
                    $reason[] = 'product is not purchasable';
                }
                if (!$product->is_visible()) {
                    $reason[] = 'product is not visible';
                }
                if ($this->getAvailabilityString($product) === false) {
                    $reason[] = 'product is unavailable';
                }
                $this->©diagnostic->forceDBLog(
                  'product', array(
                  'id'             => $product->id,
                  'SKU'            => $product->get_sku(),
                  'is_purchasable' => $product->is_purchasable(),
                  'is_visible'     => $product->is_visible(),
                  'availability'   => $this->getAvailabilityString($product)
                ), 'Product <strong>'.$product->get_formatted_name().'</strong> failed. Reason(s) is(are): '.implode(', ', $reason)
                );
                continue;
            }

            $this->©xml->appendProduct($this->getProductArray($product));
        }
        wp_cache_flush();
        return $this->©xml->saveXML() ? $this->©xml->countProductsInFile($this->©xml->simpleXML) : 0;
    }

    /**
     * @param int $value
     *
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function updateXMLGenerationProgress($value)
    {
        if ($value < $this->progress + $this->progressUpdateInterval) {
            return;
        }
        $this->progress = $value;
        $this->©option->update(array('xml.progress' => $this->progress));
    }

    /**
     * @param \WC_Product $product
     *
     * @return array
     * @throws \xd_v141226_dev\exception
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getProductArray(\WC_Product &$product)
    {
        $out = array();

        $out['productId']  = $this->getProductId($product);
        $out['title']      = $this->getProductName($product);
        $out['productURL'] = $this->getProductLink($product);
        $out['imageURL']   = $this->getProductImageLink($product);

        /***********************************************
         * Prices
         ***********************************************/
        $price     = $this->getProductPrice($product);
        $salePrice = $this->getProductPrice($product, 1);
        if ($salePrice > 0 && $salePrice < $price) {
            $out['price']    = $salePrice;
            $out['oldPrice'] = $price;
        } else {
            $out['price'] = $price;
        }
        // TODO This should be in options before implement it
        //$out['netprice']     = $this->getProductPrice( $product, 2 );

        $out['categoryID']   = $this->getProductCategories($product, true);
        $out['categoryPath'] = $this->getProductCategories($product, false);
        $out['brand']        = $this->getProductManufacturer($product);
        $out['stock']        = $this->isInStock($product);
        $out['availability'] = $this->getAvailabilityString($product);
        $out['ΕΑΝ']          = $this->getProductMPN($product);

        // TODO <isBundle>Y</isBundle>

        if ($product->product_type == 'variable' && $this->is_fashion_store) {
            $variableProduct = new \WC_Product_Variable($product);

            $colors = $this->getProductColors($variableProduct);
            $sizes  = $this->getProductSizes($variableProduct);

            if (!empty($colors)) {
                $out['color'] = $colors;
            }

            if (!empty($sizes)) {
                $out['size'] = $sizes;
            }
        } elseif ($this->is_book_store) {
            $isbn = $this->getProductISBN($product);
            if ($isbn) {
                $out['ISBN'] = $isbn;
            }
        }

        return $out;
    }

    /**
     * @param \WC_Product_Variable $product
     *
     * @return string
     * @throws \xd_v141226_dev\exception
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getProductColors(\WC_Product_Variable &$product)
    {
        $map = $this->©option->get('map_color');
        if (empty($map)) {
            return array();
        }

        $variations = $product->get_available_variations();
        $colors     = array();
        foreach ($map as $attrId) {
            $taxonomy = $this->getTaxonomyById($attrId);

            if (!$taxonomy) {
                break;
            }

            foreach ($variations as $variation) {
                $attrName = wc_attribute_taxonomy_name($taxonomy->attribute_name);
                $key      = 'attribute_'.$attrName;
                if (isset($variation['attributes'][$key]) && $variation['is_in_stock'] && $variation['is_purchasable']) {
                    if (empty($variation['attributes'][$key])) {
                        $attr = $product->get_attribute($attrName);
                        if (is_string($attr) && !empty($attr)) {
                            $attrValues = array_map('trim', explode(',', $attr));
                            $colors     = array_merge($colors, $attrValues);
                            break;
                        }
                    } else {
                        $term = get_terms($attrName, array('fields' => 'names', 'slug' => $variation['attributes'][$key]));
                        if(!is_wp_error($term) && !empty($term)){
                            $colors[] = array_pop($term);
                        }
                    }
                }
            }
        }

        $colors = array_unique($colors);

        return implode(', ', $colors);
    }

    /**
     * @param \WC_Product $product
     *
     * @return null|string
     * @throws \xd_v141226_dev\exception
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getProductISBN(\WC_Product &$product)
    {
        $map = $this->©option->get('map_isbn');
        if ($map == 0) {
            return $product->get_sku();
        }

        return $this->getProductAttrValue($product, $map, false);
    }

    /**
     * @param $taxonomyId
     *
     * @return null
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getTaxonomyById($taxonomyId)
    {
        foreach (wc_get_attribute_taxonomies() as $taxonomy) {
            if ($taxonomyId == $taxonomy->attribute_id) {
                return $taxonomy;
            }
        }

        return null;
    }

    /**
     * @param \WC_Product_Variable $product
     *
     * @return string
     * @throws \xd_v141226_dev\exception
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getProductSizes(\WC_Product_Variable &$product)
    {
        $map = $this->©option->get('map_size');
        if (empty($map)) {
            return array();
        }

        $variations = $product->get_available_variations();
        $sizes      = array();
        foreach ($map as $attrId) {
            $taxonomy = $this->getTaxonomyById($attrId);

            if (!$taxonomy) {
                break;
            }

            foreach ($variations as $variation) {
                $attrName = wc_attribute_taxonomy_name($taxonomy->attribute_name);
                $key      = 'attribute_'.$attrName;
                if (isset($variation['attributes'][$key]) && $variation['is_in_stock'] && $variation['is_purchasable']) {
                    if (empty($variation['attributes'][$key])) {
                        $attr = $product->get_attribute($attrName);
                        if (is_string($attr) && !empty($attr)) {
                            $attrValues = array_map('trim', explode(',', $attr));
                            $sizes      = array_merge($sizes, $attrValues);
                            break;
                        }
                    } else {
                        $term = get_terms($attrName, array('fields' => 'names', 'slug' => $variation['attributes'][$key]));
                        if(!is_wp_error($term) && !empty($term)){
                            $sizes[] = array_pop($term);
                        }
                    }
                }
            }
        }
        $sizes = array_unique($sizes);

        return implode(', ', $sizes);
    }

    /**
     * @param \WC_Product $product
     *
     * @return null|string
     * @throws \xd_v141226_dev\exception
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getProductManufacturer(\WC_Product &$product)
    {
        $option = $this->©option->get('map_manufacturer');

        $manufacturer = '';
        if (is_numeric($option)) {
            $manufacturer = $this->getProductAttrValue($product, $option, '');
        }
        if (empty($manufacturer)) {
            $manufacturer = $this->getFormattedTextFromTerms($product, $option);
        }

        return $manufacturer;
    }

    /**
     * @param \WC_Product $product
     *
     * @return string
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function isInStock(\WC_Product &$product)
    {
        return $product->is_in_stock() ? 'Y' : 'N';
    }

    /**
     * @param \WC_Product $product
     *
     * @param             $option 1: sale price, 2 tax excluded price, any other value regular price tax included. Default is last regular price tax included
     *
     * @return string
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getProductPrice(\WC_Product &$product, $option = 0)
    {
        switch ($option) {
            case 1:
                $price = $product->get_sale_price();
                break;
            case 2:
                $price = $product->get_price_excluding_tax();
                break;
            default:
                $price = $product->get_price();
                break;
        }
        return number_format(floatval($price), 2, ',', '.');
    }

    /**
     * @param \WC_Product $product
     *
     * @param bool        $ids
     *
     * @return null|string
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getProductCategories(\WC_Product &$product, $ids = false)
    {
        $option     = $this->©option->get('map_category');
        $categories = '';
        if (is_numeric($option)) {
            $categories = $this->getProductAttrValue($product, $option, '');
        }
        if (empty($categories)) {
            $categories = $ids ? $this->getIdsFromTerms($product, $option) : $this->getFormattedTextFromTerms($product, $option, false, '->');
        }

        return is_array($categories) ? implode('-', $categories) : $categories;
    }

    /**
     * @param \WC_Product $product
     *
     * @return string
     * @throws \xd_v141226_dev\exception
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getProductImageLink(\WC_Product &$product)
    {
        $option = $this->©option->get('map_image');

        // Maybe we will implement some additional functionality in the future
        $imageLink = array();
        $i         = 1;
        if (true || $option == 0) {
            $src = wp_get_attachment_image_src($product->get_image_id());
            if (is_array($src)) {
                $imageLink['img'.$i] = urldecode($src[0]);
                $i++;
            }

            foreach ($product->get_gallery_attachment_ids() as $k => $id) {
                $src = wp_get_attachment_image_src($id);
                if (is_array($src)) {
                    $imageLink['img'.$i] = urldecode($src[0]);
                    $i++;
                }
            }
        }

        return $imageLink;
    }

    /**
     * @param \WC_Product $product
     *
     * @return int|string
     * @throws \xd_v141226_dev\exception
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getProductId(\WC_Product &$product)
    {
        $option = $this->©option->get('map_id');
        if ($option == 0) {
            return $product->get_sku();
        } else {
            return $product->id;
        }
    }

    /**
     * @param \WC_Product $product
     *
     * @return null|string
     * @throws \xd_v141226_dev\exception
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getProductMPN(\WC_Product &$product)
    {
        $option = $this->©option->get('map_mpn');

        if ($option == 0) {
            return $product->get_sku();
        }

        return $this->getProductAttrValue($product, $option, $product->get_sku());
    }

    /**
     * @param \WC_Product $product
     *
     * @return string
     * @throws \xd_v141226_dev\exception
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getProductLink(\WC_Product &$product)
    {
        $option = $this->©option->get('map_link');

        // Maybe we will implement some additional functionality in the future
        $link = '';
        if (true || $option == 0) {
            $link = $product->get_permalink();
        }

        return urldecode($link);
    }

    /**
     * @param \WC_Product $product
     *
     * @return null|string
     * @throws \xd_v141226_dev\exception
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getProductName(\WC_Product &$product)
    {
        $option    = $this->©option->get('map_name');
        $appendSKU = $this->©option->get('map_name_append_sku');
        $name      = '';

        if ($option != 0) {
            $name = $this->getProductAttrValue($product, $option, '');
        }

        if (empty($name)) {
            $name = $product->get_title();
        }

        $name = trim($name);
        $pid  = $this->getProductId($product);
        if ($appendSKU && !empty($pid) && !is_numeric(strpos($product->get_title(), $pid))) {
            $name .= ' '.$pid;
        }

        return $name;
    }

    /**
     * @param \WC_Product $product
     * @param             $attrId
     * @param null        $defaultValue
     *
     * @return null|string
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getProductAttrValue(\WC_Product &$product, $attrId, $defaultValue = null)
    {
        $return = $product->get_attribute($this->getAttributeNameFromId($attrId));

        return empty($return) ? $defaultValue : $return;
    }

    /**
     * @param $attrId
     *
     * @return bool|string
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getAttributeNameFromId($attrId)
    {
        foreach (wc_get_attribute_taxonomies() as $taxonomy) {
            if ($taxonomy->attribute_id == $attrId) {
                return trim($taxonomy->attribute_name);
            }
        }

        return false;
    }

    /**
     * @param \WC_Product $product
     *
     * @return bool
     * @throws \xd_v141226_dev\exception
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getAvailabilityString(\WC_Product &$product)
    {
        // If product is in stock
        $out = false;
        if ($product->is_in_stock()) {
            $out = $this->©option->get('avail_inStock');
        } elseif ($product->backorders_allowed()) {
            // if product is out of stock and no backorders then return false
            $backOrdersString = $this->©option->get('avail_backorders');
            if ($this->©string->is_not_empty($backOrdersString)) {
                $out = $backOrdersString;
            }
        } else {
            $outOfStockString = $this->©option->get('avail_outOfStock');
            if ($this->©string->is_not_empty($outOfStockString)) {
                $out = $outOfStockString;
            }
        }

        return $out;
    }

    /**
     * @param $string
     *
     * @return mixed
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function formatSizeColorStrings($string)
    {
        if (is_array($string)) {
            $that = $this;
            array_walk(
              $string, function ($item, $key) use ($that) {
                return $that->formatSizeColorStrings($item);
            }
            );

            return implode(',', $string);
        }

        $patterns        = array();
        $patterns[0]     = '/\|/';
        $patterns[1]     = '/\s+/';
        $replacements    = array();
        $replacements[2] = ',';
        $replacements[1] = '';

        return preg_replace($patterns, $replacements, $string);
    }

    /**
     * @param \WC_Product $product
     * @param             $term
     * @param bool        $removeDuplicates
     *
     * @return string
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getFormattedTextFromTerms(\WC_Product &$product, $term, $removeDuplicates = true, $glue = ' - ')
    {
        $terms = get_the_terms($product->id, $term);
        $out   = array();
        if (is_array($terms)) {
            foreach ($terms as $k => $term) {
                $name  = rtrim(ltrim($term->name));
                $out[] = $name;
            }
        }

        return implode($glue, ($removeDuplicates ? array_unique($out) : $out));
    }

    /**
     * @param \WC_Product $product
     * @param             $term
     *
     * @return array
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150120
     */
    protected function getIdsFromTerms(\WC_Product &$product, $term)
    {
        $terms = get_the_terms($product->id, $term);
        $out   = array();
        if (is_array($terms)) {
            foreach ($terms as $k => $term) {
                $out[] = $term->term_id;
            }
        }

        return $out;
    }

    /**
     * @param $mem
     *
     * @return bool
     * @author Panagiotis Vagenas <pan.vagenas@gmail.com>
     * @since  150501
     */
    protected function getMemInM($mem)
    {
        if (is_numeric($mem)) {
            return $mem;
        }
        preg_match('/^(\d+)([MmKkGg]?)$/', $mem, $matches);
        if (is_string($mem)) {
            if (isset($matches[2])) {
                switch ($matches[2]) {
                    case 'k':
                    case 'K':
                        return $matches[1] * 1024;
                    case 'm':
                    case 'M':
                        return $matches[1] * 1024 * 1024;
                    case 'g':
                    case 'G':
                        return $matches[1] * 1024 * 1024 * 1024;
                    default:
                        return $matches[1];
                }
            }
        }

        return false;
    }
}
