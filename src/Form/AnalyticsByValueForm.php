<?php declare(strict_types=1);

namespace Analytics\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class AnalyticsByValueForm extends Form
{
    /**
     * @var array
     */
    protected $years = [];

    public function __construct($name = null, array $options = [])
    {
        if (array_key_exists('years', $options) && is_array($options['years'])) {
            $this->years = array_map('intval', $options['years']);
        }
        parent::__construct($name, $options);
    }

    public function init(): void
    {
        $this
            ->setAttribute('id', 'statistics')
            ->setAttribute('method', 'GET')
            // A search form doesn't need a csrf.
            ->remove('csrf')
            ->add([
                'name' => 'resource_type',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Filter pages with resources', // @ŧranslate
                    'value_options' => [
                        '' => 'All', // @translate
                        'items' => 'By item', // @translate
                        'item_sets' => 'By item set', // @translate
                        'media' => 'By media', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'resource_type',
                    'value' => '',
                ],
            ])
            ->add([
                'type' => OmekaElement\Query::class,
                'name' => 'query',
                'options' => [
                    'label' => 'Resource query', // @translate
                    'info' => 'Filter the resources', // @translate
                    'documentation' => 'https://omeka.org/s/docs/user-manual/sites/site_pages/#browse-preview',
                    'query_resource_type' => 'resources',
                    'query_partial_excludelist' => [
                    ],
                ],
                'attributes' => [
                    'id' => 'query',
                ],
            ])
            ->add([
                'name' => 'property',
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Property',
                    'empty_option' => '',
                    'term_as_value' => true,
                    'used_terms' => true,
                ],
                'attributes' => [
                    'id' => 'property',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a property…',
                ],
            ])
            ->add([
                'name' => 'value_type',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Value type', // @ŧranslate
                    'value_options' => [
                        'value' => 'Value',
                        'resource' => 'Resource',
                        'uri' => 'Uri',
                    ],
                ],
                'attributes' => [
                    'id' => 'value_type',
                    'value' => 'value',
                ],
            ])
            ->add([
                'name' => 'by_period',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Group by period', // @ŧranslate
                    'value_options' => [
                        'all' => 'All', // @translate
                        'year' => 'By year', // @translate
                        'month' => 'By month', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'by_period',
                    'value' => 'all',
                ],
            ])
            ->add([
                'name' => 'year',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Year', // @ŧranslate
                    'value_options' => [
                        '' => 'All years', // @translate
                    ] + $this->years,
                    'empty_value' => '',
                ],
                'attributes' => [
                    'id' => 'year',
                ],
            ])
            ->add([
                'name' => 'month',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Month', // @ŧranslate
                    'value_options' => [
                        '' => 'All monthes', // @translate
                        1 => 'January',
                        2 => 'February',
                        3 => 'March',
                        4 => 'April',
                        5 => 'May',
                        6 => 'June',
                        7 => 'July',
                        8 => 'August',
                        9 => 'September',
                        10 => 'October',
                        11 => 'November',
                        12 => 'December',
                    ],
                    'empty_value' => '',
                ],
                'attributes' => [
                    'id' => 'month',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a month…',
                ],
            ])
            ->add([
                'name' => 'submit',
                'type' => Element\Button::class,
                'options' => [
                    'label' => 'Submit', // @ŧranslate
                ],
                'attributes' => [
                    'id' => 'submit',
                    'type' => 'submit',
                    'form' => 'statistics',
                    'class' => 'button',
                ],
            ])
        ;
    }

    public function setYears(array $years): self
    {
        $this->years = array_map('intval', $years);
        return $this;
    }
}
