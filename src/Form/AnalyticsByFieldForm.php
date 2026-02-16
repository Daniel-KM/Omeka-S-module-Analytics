<?php declare(strict_types=1);

namespace Analytics\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class AnalyticsByFieldForm extends Form
{
    public function init(): void
    {
        $this
            ->setAttribute('id', 'analytics')
            ->setAttribute('method', 'GET')
            // A search form doesn't need a csrf.
            ->remove('csrf')
            ->add([
                'name' => 'field',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Field', // @ŧranslate
                    'value_options' => [
                        'referrer' => 'Referrer', // @translate
                        'query' => 'query', // @translate
                        'user_agent' => 'User agent', // @translate
                        'accept_language' => 'Accept language', // @translate
                        'language' => 'Language', // @translate
                    ],
                    'empty_value' => '',
                ],
                'attributes' => [
                    'id' => 'field',
                    'value' => 'referrer',
                ],
            ])
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
                        'site_pages' => 'By page', // @translate
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
                'name' => 'submit',
                'type' => Element\Button::class,
                'options' => [
                    'label' => 'Submit', // @ŧranslate
                ],
                'attributes' => [
                    'id' => 'submit',
                    'type' => 'submit',
                    'form' => 'analytics',
                    'class' => 'button',
                ],
            ])
        ;
    }
}
