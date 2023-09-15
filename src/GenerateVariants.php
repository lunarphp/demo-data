<?php

namespace Lunar\DemoData;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Lunar\Models\Product;
use Lunar\Models\ProductOptionValue;
use Lunar\Models\ProductVariant;

class GenerateVariants
{
    /**
     * The product instance.
     *
     * @var \Lunar\Models\Product
     */
    protected $product;

    /**
     * The option values to use to generate variants.
     */
    protected array $optionValues;

    protected bool $isAdditional = false;

    public function __construct(Product $product, iterable $optionValues, $additional = false)
    {
        $this->product = $product;

        if ($optionValues instanceof Collection) {
            $optionValues = $optionValues->toArray();
        }

        $this->optionValues = $optionValues;
        $this->isAdditional = $additional;
    }

    public function handle()
    {
        Validator::make([
            'optionValues' => $this->optionValues,
        ], [
            'optionValues' => 'array',
            'optionValues.*' => 'numeric',
        ])->validate();

        $valueModels = ProductOptionValue::findMany($this->optionValues);

        if ($valueModels->count() != count($this->optionValues)) {
            throw new \Exception(
                'One or more option values do not exist in the database'
            );
        }

        $permutations = $this->getPermutations();

        $this->product->refresh();

        $baseVariant = $this->product->variants->first();

        foreach ($permutations as $key => $optionsToCreate) {
            $variant = new ProductVariant();

            $uoms = ['length', 'width', 'height', 'weight', 'volume'];

            $attributesToCopy = [
                'sku',
                'gtin',
                'mpn',
                'ean',
                'shippable',
            ];

            foreach ($uoms as $uom) {
                $attributesToCopy[] = "{$uom}_value";
                $attributesToCopy[] = "{$uom}_unit";
            }

            $attributes = $baseVariant->only($attributesToCopy);

            $pricing = $baseVariant->prices->map(function ($price) {
                return $price->only([
                    'customer_group_id',
                    'currency_id',
                    'price',
                    'compare_price',
                    'tier',
                ]);
            });

            $variant->product_id = $baseVariant->product_id;
            $variant->tax_class_id = $baseVariant->tax_class_id;
            $variant->attribute_data = $baseVariant->attribute_data;
            $variant->fill($attributes);
            $variant->save();
            $variant->values()->attach($optionsToCreate);
            $variant->prices()->createMany($pricing->toArray());
        }

        if ($baseVariant && ! $this->isAdditional) {
            $baseVariant->values()->detach();
            $baseVariant->prices()->delete();
            $baseVariant->delete();
        }

    }

    /**
     * Gets permutations array from the option values.
     *
     * @return array
     */
    protected function getPermutations()
    {
        return Arr::permutate(
            ProductOptionValue::findMany($this->optionValues)
                ->groupBy('product_option_id')
                ->mapWithKeys(function ($values) {
                    $optionId = $values->first()->product_option_id;

                    return [$optionId => $values->map(function ($value) {
                        return $value->id;
                    })];
                })->toArray()
        );
    }
}
