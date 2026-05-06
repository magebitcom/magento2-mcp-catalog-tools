<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpCatalogTools\Model\FieldResolver\Product;

use Magebit\McpCatalogTools\Api\ProductFieldResolverInterface;
use Magento\Bundle\Api\Data\LinkInterface as BundleLinkInterface;
use Magento\Bundle\Api\Data\OptionInterface as BundleOptionInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\ConfigurableProduct\Api\Data\OptionInterface as ConfigurableOptionInterface;
use Magento\ConfigurableProduct\Api\Data\OptionValueInterface;

/**
 * Type-driven option slice for configurable and bundle products.
 *
 * Returns `null` for other types. Module dependence on
 * `Magento_ConfigurableProduct` / `Magento_Bundle` is declared in
 * `etc/module.xml` `<sequence>`; per-type branches only fire when those
 * modules are enabled (no `configurable`/`bundle` products can exist
 * otherwise), so the typed helpers are never autoloaded on minimal installs.
 */
class TypeOptionsResolver implements ProductFieldResolverInterface
{
    public const KEY = 'type_options';

    private const TYPE_CONFIGURABLE = 'configurable';
    private const TYPE_BUNDLE = 'bundle';

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return self::KEY;
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return 75;
    }

    /**
     * @inheritDoc
     */
    public function resolve(ProductInterface $product, array $args): array
    {
        unset($args);
        $typeId = (string) $product->getTypeId();

        return match ($typeId) {
            self::TYPE_CONFIGURABLE => ['type_options' => $this->resolveConfigurable($product)],
            self::TYPE_BUNDLE => ['type_options' => $this->resolveBundle($product)],
            default => ['type_options' => null],
        };
    }

    /**
     * Build the configurable-product slice (configuration attributes + variant ids).
     *
     * @param ProductInterface $product
     * @return array<string, mixed>
     */
    private function resolveConfigurable(ProductInterface $product): array
    {
        $extension = $product->getExtensionAttributes();
        $options = [];
        $variantIds = [];

        if ($extension !== null && method_exists($extension, 'getConfigurableProductOptions')) {
            $rawOptions = $extension->getConfigurableProductOptions() ?? [];
            foreach ($rawOptions as $option) {
                if (!$option instanceof ConfigurableOptionInterface) {
                    continue;
                }
                $options[] = [
                    'attribute_id' => $option->getAttributeId() !== null
                        ? (int) $option->getAttributeId()
                        : null,
                    'label' => $option->getLabel() !== null ? (string) $option->getLabel() : null,
                    'position' => $option->getPosition() !== null
                        ? (int) $option->getPosition()
                        : null,
                    'values' => $this->collectOptionValues($option),
                ];
            }
        }

        if ($extension !== null && method_exists($extension, 'getConfigurableProductLinks')) {
            $rawLinks = $extension->getConfigurableProductLinks() ?? [];
            foreach ($rawLinks as $linkId) {
                if (is_numeric($linkId)) {
                    $variantIds[] = (int) $linkId;
                }
            }
        }

        return [
            'type' => self::TYPE_CONFIGURABLE,
            'attributes' => $options,
            'variant_ids' => array_values(array_unique($variantIds)),
        ];
    }

    /**
     * Build the bundle-product slice (options with their linked SKUs).
     *
     * @param ProductInterface $product
     * @return array<string, mixed>
     */
    private function resolveBundle(ProductInterface $product): array
    {
        $extension = $product->getExtensionAttributes();
        $options = [];

        if ($extension !== null && method_exists($extension, 'getBundleProductOptions')) {
            $rawOptions = $extension->getBundleProductOptions() ?? [];
            foreach ($rawOptions as $option) {
                if (!$option instanceof BundleOptionInterface) {
                    continue;
                }
                $options[] = [
                    'option_id' => $option->getOptionId() !== null
                        ? (int) $option->getOptionId()
                        : null,
                    'title' => $option->getTitle() !== null ? (string) $option->getTitle() : null,
                    'type' => $option->getType() !== null ? (string) $option->getType() : null,
                    'required' => $option->getRequired() !== null
                        ? (bool) $option->getRequired()
                        : null,
                    'position' => $option->getPosition() !== null
                        ? (int) $option->getPosition()
                        : null,
                    'links' => $this->collectBundleLinks($option),
                ];
            }
        }

        return [
            'type' => self::TYPE_BUNDLE,
            'options' => $options,
        ];
    }

    /**
     * Collect the value indexes of a single configurable option.
     *
     * @param ConfigurableOptionInterface $option
     * @return array<int, array<string, int|null>>
     */
    protected function collectOptionValues(ConfigurableOptionInterface $option): array
    {
        $out = [];
        foreach ($option->getValues() ?? [] as $value) {
            if (!$value instanceof OptionValueInterface) {
                continue;
            }
            $out[] = ['value_index' => (int) $value->getValueIndex()];
        }
        return $out;
    }

    /**
     * Collect the linked-product details for a single bundle option.
     *
     * @param BundleOptionInterface $option
     * @return array<int, array<string, mixed>>
     */
    protected function collectBundleLinks(BundleOptionInterface $option): array
    {
        $out = [];
        foreach ($option->getProductLinks() ?? [] as $link) {
            if (!$link instanceof BundleLinkInterface) {
                continue;
            }
            $out[] = [
                'sku' => $link->getSku() !== null ? (string) $link->getSku() : null,
                'qty' => $link->getQty() !== null ? (float) $link->getQty() : null,
                'position' => $link->getPosition() !== null ? (int) $link->getPosition() : null,
                'is_default' => (bool) $link->getIsDefault(),
                'price' => (float) $link->getPrice(),
                'price_type' => (int) $link->getPriceType(),
                'can_change_quantity' => $link->getCanChangeQuantity() !== null
                    ? (int) $link->getCanChangeQuantity()
                    : null,
            ];
        }
        return $out;
    }
}
