<?php

/**
 * The names validation messages use for these fields, so a message reads
 * "The member field is required." The field keys in the API stay as they are.
 * Laravel merges this over its own validation lines.
 */
return [
    'attributes' => [
        'borrower_id' => 'member',
        'relationship_to_borrower' => 'relationship to member',
    ],
];
