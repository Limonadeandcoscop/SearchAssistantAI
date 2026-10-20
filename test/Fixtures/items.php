<?php
/**
 * Test fixtures for Omeka S Item entities.
 *
 * Provides reusable mock Item data structures for unit tests.
 * Each fixture represents a different testing scenario (complete metadata,
 * minimal metadata, edge cases, etc.) following Omeka S API JSON structure.
 *
 * Usage:
 *   $fixtures = require __DIR__ . '/items.php';
 *   $itemData = $fixtures['complete_item'];
 */

return [
    /**
     * Complete item with full metadata.
     *
     * Includes all standard Omeka S fields: id, title, description,
     * Dublin Core properties, resource class, media, and item sets.
     */
    'complete_item' => [
        'o:id' => 1,
        'o:title' => 'Vincent van Gogh - Starry Night',
        'o:description' => 'The Starry Night is an oil on canvas painting by Dutch Post-Impressionist painter Vincent van Gogh. Painted in June 1889, it depicts the view from the east-facing window of his asylum room at Saint-Rémy-de-Provence, just before sunrise.',
        'o:resource_class' => [
            'o:id' => 10,
            'o:term' => 'dctype:StillImage'
        ],
        'o:media' => [
            [
                'o:id' => 101,
                'o:source' => 'starry_night.jpg',
                'o:media_type' => 'image/jpeg'
            ],
            [
                'o:id' => 102,
                'o:source' => 'starry_night_detail.jpg',
                'o:media_type' => 'image/jpeg'
            ]
        ],
        'o:item_set' => [
            [
                'o:id' => 50,
                'o:title' => 'Post-Impressionist Art Collection'
            ]
        ],
        'dcterms:creator' => [
            ['@value' => 'Vincent van Gogh']
        ],
        'dcterms:date' => [
            ['@value' => '1889-06']
        ],
        'dcterms:subject' => [
            ['@value' => 'Post-Impressionism'],
            ['@value' => 'Landscape'],
            ['@value' => 'Night Sky']
        ],
        'dcterms:spatial' => [
            ['@value' => 'Saint-Rémy-de-Provence, France']
        ],
        'dcterms:medium' => [
            ['@value' => 'Oil on canvas']
        ],
        'dcterms:extent' => [
            ['@value' => '73.7 cm × 92.1 cm']
        ]
    ],

    /**
     * Minimal item with only required fields.
     *
     * Used for testing handling of items with sparse metadata.
     */
    'minimal_item' => [
        'o:id' => 2,
        'o:title' => 'Untitled Artifact'
    ],

    /**
     * Item with Dublin Core properties but no media.
     *
     * Tests metadata extraction without associated media files.
     */
    'item_with_dublin_core' => [
        'o:id' => 3,
        'o:title' => 'Ancient Roman Coin',
        'o:description' => 'Silver denarius from the reign of Emperor Augustus, circa 27 BCE.',
        'dcterms:creator' => [
            ['@value' => 'Roman Imperial Mint']
        ],
        'dcterms:date' => [
            ['@value' => '-27']
        ],
        'dcterms:type' => [
            ['@value' => 'Physical Object']
        ],
        'dcterms:subject' => [
            ['@value' => 'Numismatics'],
            ['@value' => 'Roman Empire'],
            ['@value' => 'Currency']
        ],
        'dcterms:description' => [
            ['@value' => 'Features portrait of Augustus on obverse, Capricorn symbol on reverse']
        ],
        'o:resource_class' => [
            'o:id' => 15,
            'o:term' => 'dctype:PhysicalObject'
        ]
    ],

    /**
     * Item with empty description.
     *
     * Tests handling of items where description is explicitly empty string.
     */
    'item_empty_description' => [
        'o:id' => 4,
        'o:title' => 'Photograph Collection Item',
        'o:description' => '',
        'dcterms:subject' => [
            ['@value' => 'Photography']
        ]
    ],

    /**
     * Item with multiple media attachments.
     *
     * Tests indexing of items with various media types.
     */
    'item_with_multiple_media' => [
        'o:id' => 5,
        'o:title' => 'Documentary Archive: Industrial Revolution',
        'o:description' => 'Multimedia collection documenting the Industrial Revolution in Britain, 1760-1840.',
        'o:media' => [
            [
                'o:id' => 201,
                'o:source' => 'steam_engine_diagram.pdf',
                'o:media_type' => 'application/pdf'
            ],
            [
                'o:id' => 202,
                'o:source' => 'factory_photograph_1820.jpg',
                'o:media_type' => 'image/jpeg'
            ],
            [
                'o:id' => 203,
                'o:source' => 'cotton_mill_interview.mp3',
                'o:media_type' => 'audio/mpeg'
            ]
        ],
        'dcterms:creator' => [
            ['@value' => 'British Historical Society']
        ],
        'dcterms:coverage' => [
            ['@value' => '1760-1840']
        ]
    ],

    /**
     * Item in multiple item sets (collections).
     *
     * Tests handling of items belonging to multiple collections.
     */
    'item_in_multiple_collections' => [
        'o:id' => 6,
        'o:title' => 'Mona Lisa - La Gioconda',
        'o:description' => 'Portrait painting by Leonardo da Vinci, housed in the Louvre Museum, Paris.',
        'o:item_set' => [
            [
                'o:id' => 60,
                'o:title' => 'Renaissance Masterpieces'
            ],
            [
                'o:id' => 61,
                'o:title' => 'Louvre Collection Highlights'
            ],
            [
                'o:id' => 62,
                'o:title' => 'Leonardo da Vinci Works'
            ]
        ],
        'dcterms:creator' => [
            ['@value' => 'Leonardo da Vinci']
        ],
        'dcterms:date' => [
            ['@value' => '1503-1519']
        ]
    ],

    /**
     * Item with complex nested metadata.
     *
     * Tests extraction of deeply nested property values and resource references.
     */
    'item_with_nested_metadata' => [
        'o:id' => 7,
        'o:title' => 'Egyptian Hieroglyphic Tablet',
        'o:description' => 'Limestone tablet with hieroglyphic inscriptions from the Middle Kingdom period.',
        'o:resource_class' => [
            'o:id' => 20,
            'o:term' => 'dctype:PhysicalObject'
        ],
        'dcterms:date' => [
            ['@value' => '-2000/-1800']
        ],
        'dcterms:spatial' => [
            ['@value' => 'Thebes, Egypt']
        ],
        'dcterms:language' => [
            ['@value' => 'Ancient Egyptian']
        ],
        'dcterms:subject' => [
            ['@value' => 'Hieroglyphics'],
            ['@value' => 'Ancient Egypt'],
            ['@value' => 'Middle Kingdom']
        ],
        'dcterms:relation' => [
            [
                '@value' => 'Related to Item #42: Rosetta Stone Replica',
                'o:resource' => ['o:id' => 42]
            ]
        ]
    ],

    /**
     * Item without metadata but with resource class.
     *
     * Edge case for testing items with type classification but no descriptive metadata.
     */
    'item_with_class_no_metadata' => [
        'o:id' => 8,
        'o:title' => 'Unprocessed Archive Box #47',
        'o:resource_class' => [
            'o:id' => 25,
            'o:term' => 'dctype:Collection'
        ]
    ],

    /**
     * Item with special characters in title and description.
     *
     * Tests handling of Unicode characters, accents, and special symbols.
     */
    'item_with_special_characters' => [
        'o:id' => 9,
        'o:title' => 'Café "Le Ménage à Trois" — Façade Néo-Baroque (1895)',
        'o:description' => 'Photographie d\'une façade parisienne ornée de motifs floraux & sculptures. Dimensions: 24×36″.',
        'dcterms:creator' => [
            ['@value' => 'François-Émile Müller']
        ],
        'dcterms:language' => [
            ['@value' => 'fra']
        ]
    ],

    /**
     * Item with very long description.
     *
     * Tests handling of items with extensive textual content for embedding generation.
     */
    'item_with_long_description' => [
        'o:id' => 10,
        'o:title' => 'Comprehensive Study: Climate Change Impact on Mediterranean Ecosystems',
        'o:description' => 'This extensive research document examines the multifaceted impacts of climate change on Mediterranean ecosystems over a 50-year period (1970-2020). The study encompasses detailed analysis of temperature variations, precipitation patterns, biodiversity loss, and vegetation shifts across multiple countries including Spain, France, Italy, Greece, and Turkey. Key findings indicate a 2.5°C average temperature increase, 20% reduction in annual rainfall, and significant migration of species northward. The research combines satellite imagery analysis, field observations from 150 monitoring stations, and climate modeling projections extending to 2100. Particular attention is paid to vulnerable species such as the Mediterranean monk seal, Iberian lynx, and various endemic plant species. The document includes 200 pages of data tables, 75 maps, and 50 graphs illustrating trends in temperature, precipitation, sea level rise, and biodiversity indices. Recommendations for conservation strategies, sustainable agriculture practices, and climate adaptation policies are provided for regional governments and international organizations.',
        'dcterms:creator' => [
            ['@value' => 'Mediterranean Climate Research Institute']
        ],
        'dcterms:subject' => [
            ['@value' => 'Climate Change'],
            ['@value' => 'Mediterranean Ecology'],
            ['@value' => 'Biodiversity Conservation'],
            ['@value' => 'Environmental Science']
        ],
        'dcterms:date' => [
            ['@value' => '2021-03-15']
        ],
        'dcterms:extent' => [
            ['@value' => '200 pages']
        ]
    ]
];
